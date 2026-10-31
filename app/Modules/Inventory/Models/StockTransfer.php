<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock sent from one branch to another: requested, approved, dispatched (it leaves the sending shelf and is in
 * transit, on no shelf), received at the other branch — in full, or short and then settled line by line (lost,
 * returned to the sender, or arriving late). It concerns two branches, so a user tied to either one reaches it
 * (checked by the controller, not BranchOwned).
 */
class StockTransfer extends Model
{
    use Auditable, BelongsToTenant;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const IN_TRANSIT = 'in_transit';

    public const DISCREPANCY = 'discrepancy';

    public const RECEIVED = 'received';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::PENDING => 'بانتظار الموافقة', self::APPROVED => 'معتمد — بانتظار الإرسال', self::IN_TRANSIT => 'قيد النقل',
        self::DISCREPANCY => 'استُلم بفرق', self::RECEIVED => 'اكتمل الاستلام', self::REJECTED => 'مرفوض', self::CANCELLED => 'ملغى',
    ];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::APPROVED, self::IN_TRANSIT, self::DISCREPANCY], true);
    }
}
