<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's invoice: approved, it is the club's debt to the supplier in the books (posted once); paid in one or
 * more payments from a cash box or bank. Voided only while nothing is paid on it, by reversing its entry.
 */
class SupplierBill extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const VOID = 'void';

    protected $guarded = ['id', 'tenant_id', 'paid_total'];

    protected $auditExclude = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date', 'due_date' => 'immutable_date', 'approved_at' => 'datetime', 'voided_at' => 'datetime',
            'subtotal' => 'decimal:3', 'discount' => 'decimal:3', 'tax' => 'decimal:3', 'total' => 'decimal:3', 'paid_total' => 'decimal:3',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function balance(): string
    {
        return $this->status === self::APPROVED ? Money::max(0, Money::sub($this->total, $this->paid_total)) : '0.000';
    }

    /** unpaid|partial|paid|overdue — for an approved bill. */
    public function paymentState(): ?string
    {
        if ($this->status !== self::APPROVED) {
            return null;
        }
        if (Money::isZero($this->balance())) {
            return 'paid';
        }
        if ($this->due_date && $this->due_date->isPast() && ! $this->due_date->isToday()) {
            return 'overdue';
        }

        return Money::isZero($this->paid_total) ? 'unpaid' : 'partial';
    }
}
