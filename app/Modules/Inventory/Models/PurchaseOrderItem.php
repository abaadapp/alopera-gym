<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'unit_factor' => 'decimal:3', 'unit_cost' => 'decimal:3', 'line_total' => 'decimal:3'];
    }

    /** Still to arrive, in purchase units. */
    public function remaining(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->received_quantity, 3));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
