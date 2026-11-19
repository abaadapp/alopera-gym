<?php

namespace App\Modules\Payments\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paymob confirmed money (signed, live, captured) that was not recorded as its invoice's payment because something did
 * not match (OnlinePaymentService::handleWebhook). While it is open: no second checkout for the invoice, no dues
 * reminder, no automatic cancellation of its website order, and the customer is told the result is being checked.
 * Closing it (payments.review) moves no money: the books change only through the existing payment screens.
 */
class PaymentReview extends Model implements BranchOwned
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /** Why it was not recorded, as staff read it. */
    public const REASONS = [
        'amount' => 'المبلغ المستلم لا يطابق المطلوب',
        'currency' => 'العملة لا تطابق',
        'order' => 'رقم طلب Paymob الموقّع لا يطابق هذه العملية',
        'integration' => 'وصلت عبر تكامل Paymob غير مُدخل في إعدادات النادي',
    ];

    /** What the customer is told while it is open. */
    public const CUSTOMER_MESSAGE = 'استلمنا نتيجة الدفع، وجارٍ التحقق منها. يرجى عدم إعادة الدفع حتى تتأكد الحالة.';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'expected_minor' => 'integer',
            'received_minor' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::OPEN);
    }

    /** Its invoice's branch: a user tied to another branch gets 404 for it, as for the invoice. */
    public function owningBranchId(): ?int
    {
        $branch = Invoice::whereKey($this->invoice_id)->value('branch_id');

        return $branch ? (int) $branch : null;
    }

    public function expected(): string
    {
        return Money::fromMinor($this->expected_minor);
    }

    public function received(): ?string
    {
        return $this->received_minor === null ? null : Money::fromMinor($this->received_minor);
    }

    /** @return array<string, mixed> what the invoice page shows */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => self::REASONS[$this->reason] ?? $this->reason,
            'expected' => $this->expected(),
            'expected_currency' => $this->expected_currency,
            'received' => $this->received(),
            'received_currency' => $this->received_currency,
            'transaction_id' => $this->transaction_id,
            'order_id' => $this->provider_order_id,
            'integration_id' => $this->integration_id,
            'card_brand' => $this->card_brand,
            'reference' => $this->intent?->reference,
            'at' => $this->created_at?->format('Y-m-d H:i'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i'),
            'closed_by' => $this->closer?->name,
            'close_note' => $this->close_note,
        ];
    }
}
