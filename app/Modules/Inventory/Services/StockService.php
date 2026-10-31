<?php

namespace App\Modules\Inventory\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\BranchContext;
use App\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductStock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every stock change goes through here and leaves a movement row with the running balances, so the stock card
 * always reconciles. Stock is kept per branch (product_stocks); the product's own quantity is the club total and
 * moves with it in the same transaction, so the total is never counted twice. A movement without a branch is the
 * main branch's (BranchContext). Movements are never edited: a mistake is put right by another movement.
 */
final class StockService
{
    /** Adjustment reasons (stock counts, damage, loss…), as shown in the stock log. */
    public const REASONS = [
        'count' => 'جرد', 'damaged' => 'تالف', 'lost' => 'فاقد', 'expired' => 'منتهي الصلاحية',
        'internal' => 'استخدام داخلي', 'correction' => 'تصحيح', 'opening' => 'رصيد افتتاحي',
    ];

    public function __construct(private readonly BranchContext $branches) {}

    /**
     * @param  float  $quantity  signed: positive in, negative out
     * @param  int|null  $branchId  the branch whose shelf changes; none: the main branch
     */
    public function move(Product $product, float $quantity, string $type, ?Model $reference = null, ?string $note = null, ?float $unitCost = null, bool $allowNegative = false, ?int $branchId = null, ?string $reason = null, ?string $document = null): StockMovement
    {
        return DB::transaction(function () use ($product, $quantity, $type, $reference, $note, $unitCost, $allowNegative, $branchId, $reason, $document) {
            $branch = $branchId ?? $this->branches->mainId() ?? throw new BusinessRuleException('stock.branch', 'لا يوجد فرع لتسجيل المخزون عليه.');
            // The product row is locked first, then its branch row: one order for everyone, so no deadlock and no
            // two sales of the last piece. A product deleted since still takes its stock back on a void.
            $product = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $stock = ProductStock::where('product_id', $product->id)->where('branch_id', $branch)->lockForUpdate()->first()
                ?? tap(new ProductStock(['product_id' => $product->id, 'branch_id' => $branch]), fn ($s) => $s->forceFill(['quantity' => 0])->save());

            $current = (float) $stock->quantity;
            $balance = round($current + $quantity, 3);
            if ($product->track_stock && $balance < 0 && ! $allowNegative) {
                $where = Branch::count() > 1 ? ' في «'.Branch::whereKey($branch)->value('name').'»' : '';
                throw new BusinessRuleException('stock.insufficient', "الكمية المتوفرة من «{$product->name}»{$where} غير كافية ({$current}).", 'items');
            }

            // Weighted average cost, for the club, on incoming stock with a known cost.
            $total = (float) $product->stock_quantity;
            $newTotal = round($total + $quantity, 3);
            $cost = (float) $product->cost;
            if ($quantity > 0 && $unitCost !== null) {
                $cost = $newTotal > 0 ? round((max(0, $total) * $cost + $quantity * $unitCost) / max($newTotal, 0.001), 3) : $unitCost;
            }

            $stock->forceFill(['quantity' => $balance])->save();
            $product->forceFill(['stock_quantity' => $newTotal, 'cost' => $cost])->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'branch_id' => $branch,
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $product->cost,
                'balance_after' => $newTotal,
                'branch_balance' => $balance,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'reason' => $reason,
                'document' => $document ?? ($reference?->getAttribute('number')),
                'user_id' => Auth::guard('web')->id(),
            ]);
        });
    }

    /** Stock count correction: set a branch's shelf quantity, record the difference. */
    public function adjustTo(Product $product, float $counted, string $note, ?int $branchId = null, string $reason = 'count', ?Model $reference = null): ?StockMovement
    {
        return DB::transaction(function () use ($product, $counted, $note, $branchId, $reason, $reference) {
            $current = (float) $this->quantity($product, $branchId);
            $diff = round($counted - $current, 3);

            return $diff == 0.0 ? null : $this->move($product, $diff, 'adjustment', $reference, $note, allowNegative: true, branchId: $branchId, reason: $reason);
        });
    }

    /** A product's quantity in a branch (none: the main branch). */
    public function quantity(Product $product, ?int $branchId = null): string
    {
        $branch = $branchId ?? $this->branches->mainId();

        return (string) (ProductStock::where('product_id', $product->id)->where('branch_id', $branch)->value('quantity') ?? '0.000');
    }

    /**
     * Each product's quantity in one branch.
     *
     * @return array<int, string> keyed by product id
     */
    public function quantities(?int $branchId = null): array
    {
        return ProductStock::where('branch_id', $branchId ?? $this->branches->mainId())->pluck('quantity', 'product_id')->all();
    }
}
