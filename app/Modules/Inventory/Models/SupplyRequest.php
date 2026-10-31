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

/** A branch asking for stock; approved, it becomes transfers from other branches and/or a purchase order. */
class SupplyRequest extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['needed_by' => 'immutable_date', 'reviewed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplyRequestLine::class)->orderBy('id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Where it stands: waiting, being supplied (its transfers or order still open), supplied, or closed. */
    public function progress(): string
    {
        if ($this->status !== self::APPROVED) {
            return $this->status;
        }
        $open = $this->transfers->contains(fn (StockTransfer $t) => $t->isOpen())
            || ($this->purchaseOrder && in_array($this->purchaseOrder->status, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL], true));

        return $open ? 'in_progress' : 'fulfilled';
    }
}
