<?php

namespace App\Modules\Inventory\Services;

use App\Core\Support\BusinessRuleException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every stock change goes through here and leaves a movement row with the
 * running balance, so the stock card always reconciles.
 */
final class StockService
{
    /**
     * @param  float  $quantity  signed: positive in, negative out
     */
    public function move(Product $product, float $quantity, string $type, ?Model $reference = null, ?string $note = null, ?float $unitCost = null, bool $allowNegative = false): StockMovement
    {
        return DB::transaction(function () use ($product, $quantity, $type, $reference, $note, $unitCost, $allowNegative) {
            // A product deleted since still takes its stock back when an old sale is voided.
            $product = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $current = (float) $product->stock_quantity;
            $balance = round($current + $quantity, 3);

            if ($product->track_stock && $balance < 0 && ! $allowNegative) {
                throw new BusinessRuleException('stock.insufficient', "الكمية المتوفرة من «{$product->name}» غير كافية ({$current}).", 'items');
            }

            // Weighted average cost on incoming stock with a known cost.
            $cost = (float) $product->cost;
            if ($quantity > 0 && $unitCost !== null) {
                $cost = $balance > 0 ? round((max(0, $current) * $cost + $quantity * $unitCost) / max($balance, 0.001), 3) : $unitCost;
            }

            $product->forceFill(['stock_quantity' => $balance, 'cost' => $cost])->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $product->cost,
                'balance_after' => $balance,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'user_id' => Auth::guard('web')->id(),
            ]);
        });
    }

    /** Stock count correction: set the shelf quantity, record the difference. */
    public function adjustTo(Product $product, float $counted, string $reason): ?StockMovement
    {
        $diff = round($counted - (float) $product->stock_quantity, 3);

        return $diff == 0.0 ? null : $this->move($product, $diff, 'adjustment', null, $reason, allowNegative: true);
    }
}
