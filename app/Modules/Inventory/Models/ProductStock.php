<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product's quantity in one branch. Changed only by StockService, which also keeps the product's club total
 * (products.stock_quantity) equal to the sum of its branches, in the same transaction.
 */
class ProductStock extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'min_quantity' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
