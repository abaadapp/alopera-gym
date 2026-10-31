<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $total
 * @property string $credited_total
 * @property string $paid_total
 */
class Invoice extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** Its whole value was cancelled by credit notes (the original invoice is kept). */
    public const CREDITED = 'credited';

    public const OPEN_STATUSES = [self::ISSUED, self::PARTIALLY_PAID];

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['paid_total' => 0, 'credited_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'tax_rate' => 0];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'subtotal' => 'decimal:3',
            'discount_total' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_total' => 'decimal:3',
            'total' => 'decimal:3',
            'credited_total' => 'decimal:3',
            'paid_total' => 'decimal:3',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'issuer' => 'array',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->latest('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull($query->qualifyColumn('due_date'))->where($query->qualifyColumn('due_date'), '<', today());
    }

    /** What the invoice is worth after credit notes. */
    public function netTotal(): string
    {
        return Money::sub($this->total, $this->credited_total);
    }

    /** Still to be paid (never negative). */
    public function balance(): string
    {
        return Money::max('0', Money::sub($this->netTotal(), $this->paid_total));
    }

    /** Paid more than the invoice is now worth (after a credit note): owed back to the customer. */
    public function refundDue(): string
    {
        return Money::max('0', Money::sub($this->paid_total, $this->netTotal()));
    }

    /** The status that follows from total, credit notes and payments (void stays void). */
    public function settledStatus(): string
    {
        if ($this->status === self::VOID) {
            return self::VOID;
        }
        if (Money::isZero($this->netTotal())) {
            return self::CREDITED;
        }
        if (Money::cmp($this->paid_total, $this->netTotal()) >= 0) {
            return self::PAID;
        }

        return Money::isZero($this->paid_total) ? self::ISSUED : self::PARTIALLY_PAID;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}
