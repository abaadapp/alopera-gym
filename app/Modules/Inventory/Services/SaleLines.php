<?php

namespace App\Modules\Inventory\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * The lines of a sale, for both the point of sale and website orders: priced from the database, written onto the
 * sale with their add-ons, the stock taken off (the product's and whatever its add-ons use up), and the invoice
 * lines that bill them — the product, then each add-on on its own line. A void or a cancellation puts back exactly
 * what the sale took.
 */
final class SaleLines
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Price the basket. Only ids and quantities come from the browser: each product is priced at its price, each
     * add-on at its own, after checking it is offered on this channel, goes with that product and stays within its
     * allowed quantity.
     *
     * @param  Collection<int, Product>  $products  keyed by id: only what may be sold on this channel
     * @param  list<array{product_id: int, quantity: float|int|string, addons?: list<array{addon_id: int, quantity: int|string}>}>  $items
     * @param  string  $channel  pos|website
     * @return array{lines: list<array<string, mixed>>, subtotal: string}
     */
    public function price(Collection $products, array $items, string $channel): array
    {
        $prefix = $channel === 'website' ? 'order' : 'pos';
        $requested = collect($items)->flatMap(fn ($i) => array_column($i['addons'] ?? [], 'addon_id'))->map(fn ($id) => (int) $id)->unique();
        $addons = $requested->isEmpty() ? collect() : ProductAddon::offered($channel)->whereIn('id', $requested)
            ->with(['products:products.id', 'categories:product_categories.id', 'stockProduct'])->get()->keyBy('id');

        $lines = [];
        $subtotal = '0';
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $qty = $channel === 'website' ? (int) $item['quantity'] : (float) $item['quantity'];
            if (! $product || $qty <= 0) {
                throw new BusinessRuleException($prefix.'.item', $channel === 'website' ? 'أحد المنتجات في السلة لم يعد متاحًا. حدّث السلة وحاول مرة أخرى.' : 'منتج غير متاح في السلة.', 'items');
            }

            $chosen = [];
            $addonsTotal = '0';
            foreach ($item['addons'] ?? [] as $pick) {
                $addon = $addons->get((int) $pick['addon_id']);
                $count = (int) $pick['quantity'];
                if (! $addon || ! $addon->fits($product) || isset($chosen[$addon->id])) {
                    throw new BusinessRuleException($prefix.'.addon', 'إحدى الإضافات المختارة لم تعد متاحة مع «'.$product->name.'». حدّث السلة وحاول مرة أخرى.', 'items');
                }
                if ($count < 1 || $count > max(1, $addon->max_quantity)) {
                    throw new BusinessRuleException($prefix.'.addon_quantity', 'الحد المسموح من «'.$addon->name.'» هو '.max(1, $addon->max_quantity).' لكل قطعة.', 'items');
                }
                $units = round($count * $qty, 3);
                $total = Money::mul($addon->price, $units);
                $addonsTotal = Money::add($addonsTotal, $total);
                $chosen[$addon->id] = ['addon' => $addon, 'qty' => $count, 'units' => $units, 'total' => $total];
            }

            $lineTotal = Money::add(Money::mul($product->price, $qty), $addonsTotal);
            $subtotal = Money::add($subtotal, $lineTotal);
            $lines[] = ['product' => $product, 'qty' => $qty, 'addons' => array_values($chosen), 'addonsTotal' => $addonsTotal, 'lineTotal' => $lineTotal];
        }

        return ['lines' => $lines, 'subtotal' => $subtotal];
    }

    /**
     * Write the priced lines onto the sale and take the stock (as $movement: sale|order).
     *
     * @param  list<array<string, mixed>>  $lines  from price()
     * @return list<array<string, mixed>> the invoice lines
     */
    public function record(Sale $sale, array $lines, string $movement): array
    {
        $invoiceLines = [];
        foreach ($lines as $line) {
            $product = $line['product'];
            $rows = [];
            $addonsCost = '0';
            foreach ($line['addons'] as $pick) {
                $addon = $pick['addon'];
                $stock = $addon->stockProduct; // null when none, or deleted since
                $used = $stock ? round($pick['units'] * (float) $addon->stock_usage, 3) : 0.0;
                $addonsCost = Money::add($addonsCost, $stock ? Money::mul($stock->cost, $used) : '0');
                $rows[] = [
                    'product_addon_id' => $addon->id,
                    'name' => $addon->name,
                    'unit_price' => $addon->price,
                    'quantity' => $pick['qty'],
                    'line_total' => $pick['total'],
                    'stock_product_id' => $stock?->id,
                    'stock_quantity' => $used,
                    'unit_cost' => $stock ? Money::mul($stock->cost, (float) $addon->stock_usage) : '0',
                    'stock' => $stock,
                ];
            }

            $item = $sale->items()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $line['qty'],
                'unit_price' => $product->price,
                'unit_cost' => $product->cost,
                'addons_total' => $line['addonsTotal'],
                'addons_cost' => $addonsCost,
                'line_total' => $line['lineTotal'],
            ]);
            $this->stock->move($product, -$line['qty'], $movement, $sale, branchId: $sale->branch_id);
            $invoiceLines[] = ['description' => $product->name, 'quantity' => $line['qty'], 'unit_price' => $product->price, 'itemable' => $product];

            foreach ($rows as $i => $row) {
                $stock = $row['stock'];
                unset($row['stock']);
                $item->addons()->create($row);
                if ($stock && $row['stock_quantity'] > 0) {
                    $this->stock->move($stock, -$row['stock_quantity'], $movement, $sale, 'إضافة «'.$row['name'].'» مع «'.$product->name.'»', branchId: $sale->branch_id);
                }
                $pick = $line['addons'][$i];
                $invoiceLines[] = ['description' => '+ '.$row['name'].' — '.$product->name, 'quantity' => $pick['units'], 'unit_price' => $row['unit_price'], 'itemable' => $pick['addon']];
            }
        }

        return $invoiceLines;
    }

    /**
     * Put back everything the sale took off the shelf (as $movement: void|release): each product, add-on stock
     * included, to the branch it was taken from, as its movements recorded (an old movement without a branch is the
     * main branch's).
     */
    public function restock(Sale $sale, string $movement, ?string $note = null): void
    {
        $taken = StockMovement::where('reference_type', $sale->getMorphClass())->where('reference_id', $sale->id)
            ->selectRaw('product_id, branch_id, sum(quantity) as quantity')->groupBy('product_id', 'branch_id')->orderBy('product_id')->get();
        foreach ($taken as $row) {
            if ((float) $row->quantity < 0) {
                $this->stock->move(Product::withTrashed()->findOrFail($row->product_id), -(float) $row->quantity, $movement, $sale, $note, branchId: $row->branch_id);
            }
        }
    }
}
