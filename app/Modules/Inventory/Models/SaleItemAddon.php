<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on as sold on a sale line: its name and price on the day, how many per unit of the product, and the stock
 * it took (so a void or cancellation puts back exactly that, whatever the add-on's settings are now).
 */
class SaleItemAddon extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:3',
            'quantity' => 'decimal:3',
            'line_total' => 'decimal:3',
            'stock_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(ProductAddon::class, 'product_addon_id')->withTrashed();
    }

    public function stockProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_product_id')->withTrashed();
    }
}
