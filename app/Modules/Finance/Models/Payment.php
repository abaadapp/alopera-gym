<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    /** Entered by mistake and undone with a reason; its entry is reversed. */
    public const CANCELLED = 'cancelled';

    public const KIND_PAYMENT = 'payment';

    public const KIND_REFUND = 'refund';

    public const METHODS = ['cash', 'bank_transfer', 'card', 'paymob', 'other'];

    /** How money is paid back. A bank transfer out and a refund through Paymob wait until finance confirms they were made. */
    public const REFUND_METHODS = ['cash', 'bank_transfer', 'card', 'other', 'paymob'];

    public const REFUND_METHODS_CONFIRMED = ['bank_transfer', 'paymob'];

    /** Why confirmed gateway money was held for review rather than applied (PaymentService::recordGatewayPayment). */
    public const HOLD_REASONS = [
        'invoice_closed' => 'الفاتورة لم تعد مفتوحة',
        'checkout_expired' => 'وصل التأكيد بعد انتهاء مهلة صفحة الدفع',
        'order_closed' => 'طلب المتجر أُلغي أو انتهى حجزه',
        'membership_not_pending' => 'الاشتراك لم يعد بانتظار الدفع',
        'membership_ended' => 'انتهت مدة الاشتراك',
        'membership_overlap' => 'للعضو اشتراك آخر يغطي الفترة نفسها',
        'pt_not_pending' => 'باقة التدريب لم تعد بانتظار الدفع',
    ];

    /** The kinds of money account a method may land in when one is chosen. */
    public const METHOD_ACCOUNT_KINDS = ['cash' => ['cash'], 'bank_transfer' => ['bank'], 'card' => ['card', 'bank'], 'paymob' => ['gateway'], 'other' => ['cash', 'bank']];

    /** Arabic labels for exports (the UI has its own copy in resources/js/lib/labels.ts). */
    public const METHOD_LABELS = ['cash' => 'نقدًا', 'bank_transfer' => 'تحويل بنكي', 'card' => 'بطاقة (جهاز الشبكة)', 'paymob' => 'دفع إلكتروني', 'other' => 'أخرى'];

    public const KIND_LABELS = [self::KIND_PAYMENT => 'دفعة', self::KIND_REFUND => 'رد مبلغ'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'credit_amount' => 'decimal:3',
            'credit_used' => 'decimal:3',
            'from_credit' => 'boolean',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payer(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    /** The cash box or bank account the money went into (a receipt) or left from (a refund). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** What is left of the customer's credit from this payment (refunds of it waiting to be made already count as used). */
    public function creditLeft(): string
    {
        $pending = self::where('refund_of_id', $this->id)->where('from_credit', true)->where('status', self::PENDING)->sum('amount');

        return Money::max('0', Money::sub(Money::sub($this->credit_amount, $this->credit_used), $pending));
    }

    /** The part of the payment applied to its own invoice. */
    public function appliedAmount(): string
    {
        return Money::sub($this->amount, $this->credit_amount);
    }

    /**
     * Confirmed gateway money still the customer's on its own invoice — held for review, or more than the invoice took:
     * not applied, not yet paid back. While its invoice is open, that invoice is not asked for (or paid) again.
     */
    public function scopeGatewayCreditLeft(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('gateway'), 'paymob')->where($query->qualifyColumn('kind'), self::KIND_PAYMENT)
            ->where($query->qualifyColumn('status'), self::COMPLETED)->whereColumn($query->qualifyColumn('credit_amount'), '>', $query->qualifyColumn('credit_used'));
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::COMPLETED);
    }

    /** Signed amount for cash-flow sums: refunds count negative. */
    public function signedAmount(): float
    {
        return $this->kind === self::KIND_REFUND ? -(float) $this->amount : (float) $this->amount;
    }
}
