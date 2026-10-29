<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const DRAFT = 'draft';

    public const ORDERED = 'ordered';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'order_date' => 'immutable_date',
            'expected_on' => 'immutable_date',
            'total' => 'decimal:3',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
