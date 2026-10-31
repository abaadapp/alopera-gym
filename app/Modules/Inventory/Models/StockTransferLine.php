<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:3', 'approved_quantity' => 'decimal:3', 'sent_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3',
            'lost_quantity' => 'decimal:3', 'returned_quantity' => 'decimal:3', 'late_quantity' => 'decimal:3', 'unit_cost' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** Sent but neither received nor settled (lost, returned, late). */
    public function outstanding(): float
    {
        return round((float) $this->sent_quantity - (float) $this->received_quantity - (float) $this->lost_quantity - (float) $this->returned_quantity - (float) $this->late_quantity, 3);
    }
}
