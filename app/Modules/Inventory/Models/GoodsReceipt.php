<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A goods receipt note: what arrived against a purchase order, recorded by whoever received it. The branch's stock
 * goes up only when it is approved — once; a rejected note moves nothing.
 */
class GoodsReceipt extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'total' => 'decimal:3'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
