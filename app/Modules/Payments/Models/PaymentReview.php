<?php

namespace App\Modules\Payments\Models;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Paymob money the books do not hold yet and staff must follow: confirmed (signed, live, captured) but not matching its
 * invoice, or an attempt the bank left «pending» for a day (OnlinePaymentService). While it is open: no second checkout
 * for the invoice, no dues reminder, no automatic cancellation of its website order, and the customer is told the
 * result is being checked. It is closed only on what Paymob itself said about that money (a signed callback, or the
 * payment it made in the books) — never on a note alone — and closing it moves no money.
 */
class PaymentReview extends Model implements BranchOwned
{
    use BelongsToTenant;

    public const OPEN = 'open';

    /** The money is in the books: the signed callback of this transaction was recorded as a payment (PaymentService). */
    public const RECORDED = 'recorded';

    /** Paymob returned it all to the customer: a signed refund (or void) callback for this order and this amount. */
    public const REFUNDED = 'refunded';

    /** Paymob says it was not taken: a signed decline or void of this transaction. */
    public const NOT_COLLECTED = 'not_collected';

    /** How a review ends, each on its evidence (evidence()). */
    public const OUTCOMES = [
        self::RECORDED => 'سُجّل المبلغ في الدفاتر',
        self::REFUNDED => 'رُدّ المبلغ كاملًا للعميل من Paymob',
        self::NOT_COLLECTED => 'لم يُحصَّل: رفضته Paymob أو ألغته',
    ];

    /** Why it was not recorded, as staff read it. */
    public const REASONS = [
        'amount' => 'المبلغ المستلم لا يطابق المطلوب',
        'currency' => 'العملة لا تطابق',
        'order' => 'رقم طلب Paymob الموقّع لا يطابق هذه العملية',
        'integration' => 'وصلت عبر تكامل Paymob غير مُدخل في إعدادات النادي',
        'pending' => 'عملية «قيد التنفيذ» لدى البنك لم تُحسم بعد يوم',
        'error' => 'دفعة مؤكدة مطابقة تعذّر تسجيلها في الدفاتر بسبب خطأ',
    ];

    /** What the customer is told while it is open. */
    public const CUSTOMER_MESSAGE = 'استلمنا نتيجة الدفع، وجارٍ التحقق منها. يرجى عدم إعادة الدفع حتى تتأكد الحالة.';

    /** What staff are told when an invoice is not voided because of it (refuseVoidWhileOpen). */
    public const VOID_REFUSED = 'لا يمكن إلغاء هذه الفاتورة لوجود دفعة إلكترونية قيد المراجعة. يرجى مراجعة مسؤول النظام.';

    /** What staff are told when no credit note is issued on an invoice because of it (refuseCreditWhileOpen). */
    public const CREDIT_REFUSED = 'لا يمكن إصدار إشعار دائن لهذه الفاتورة حاليًا لوجود دفعة إلكترونية قيد المراجعة. يرجى حسم المراجعة أولًا';

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

    /**
     * An invoice with an open review is not voided, whatever cancels it — the invoice screen, a membership, gift or
     * website order cancelled, a payment link (all through InvoiceService::void): the money under review may be the
     * customer's payment for it, and a void invoice would leave that money nothing to pay. Asked under the invoice's lock
     * (FOR UPDATE), the lock a review is written under (OnlinePaymentService::keepForReview: the intent, then the
     * invoice): a review written first is seen here; one coming after the void waits for it and is kept all the same,
     * open on the void invoice, its money not lost. A closed review no longer stops it. Moves no money.
     */
    public static function refuseVoidWhileOpen(Invoice $invoice): void
    {
        if (self::openOn($invoice)) {
            throw new BusinessRuleException('invoice.payment_review', self::VOID_REFUSED);
        }
    }

    /**
     * Owner's decision (2026-11-20, PR #217 option C): no credit note, in part or in full, on an invoice with an open
     * review, whatever issues it — the credit-note form, a paid or part-credited website order cancelled, a point-of-sale
     * sale voided (all through CreditNoteService::issue) — until the review is closed on Paymob's evidence: lowering
     * what is owed while that money is unsettled would leave it paying a debt the books no longer hold. Asked under the
     * invoice's lock, before anything is written (as refuseVoidWhileOpen): a review written first is seen here; one
     * coming after the note waits for it and is kept, open, its money not lost. A closed review no longer stops it; notes
     * issued before stay as they are. Moves no money and closes nothing.
     */
    public static function refuseCreditWhileOpen(Invoice $invoice): void
    {
        if (self::openOn($invoice)) {
            throw new BusinessRuleException('credit.payment_review', self::CREDIT_REFUSED);
        }
    }

    private static function openOn(Invoice $invoice): bool
    {
        return self::where('invoice_id', $invoice->id)->open()->exists();
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

    /**
     * What Paymob itself said about this money that ends the review as $outcome, or null when it said nothing of the
     * kind: never a note, never staff's word alone.
     *
     * @return array{summary: string, event?: int, transaction?: string, payment?: int}|null
     */
    public function evidence(string $outcome): ?array
    {
        if ($outcome === self::RECORDED) {
            $payment = Payment::where('gateway', 'paymob')->where('gateway_transaction_id', $this->transaction_id)->where('kind', Payment::KIND_PAYMENT)->first();

            return $payment ? ['summary' => 'دفعة في الدفاتر'.($payment->receipt_number ? ' بإيصال '.$payment->receipt_number : '').' لعملية Paymob '.$this->transaction_id, 'payment' => $payment->id] : null;
        }
        if (! in_array($outcome, [self::REFUNDED, self::NOT_COLLECTED], true)) {
            return null;
        }
        $order = (string) $this->provider_order_id;
        $events = PaymentWebhookEvent::where('tenant_id', $this->tenant_id)->where('provider', 'paymob')->where('signature_valid', true)
            ->where(fn ($q) => $q->where('payload->obj->id', $this->transaction_id)->when($order !== '', fn ($q) => $q->orWhere('payload->obj->order->id', $order)))
            ->orderBy('id')->get();
        foreach ($events as $event) {
            $obj = (array) ($event->payload['obj'] ?? []);
            $same = (string) ($obj['id'] ?? '') === $this->transaction_id;
            $ofOrder = $order !== '' && (string) ($obj['order']['id'] ?? '') === $order;
            $whole = $this->received_minor !== null && (int) ($obj['amount_cents'] ?? -1) === $this->received_minor;
            $child = ($obj['is_refund'] ?? null) === true || ($obj['is_void'] ?? null) === true
                || (($obj['has_parent_transaction'] ?? null) === true && ($obj['is_capture'] ?? null) !== true);
            $returned = ($same && ($obj['is_refunded'] ?? null) === true)
                || ($ofOrder && $child && ($obj['success'] ?? null) === true && $whole && ($obj['is_void'] ?? null) !== true);
            $notTaken = ($same && (($obj['is_voided'] ?? null) === true || (($obj['success'] ?? null) !== true && ($obj['pending'] ?? null) !== true)))
                || ($ofOrder && ($obj['is_void'] ?? null) === true && ($obj['success'] ?? null) === true && $whole);
            if (($outcome === self::REFUNDED && $returned) || ($outcome === self::NOT_COLLECTED && $notTaken)) {
                return [
                    'summary' => ($outcome === self::REFUNDED ? 'إشعار Paymob موقّع برد' : 'إشعار Paymob موقّع بأنها لم تُحصَّل').' (عملية '.($obj['id'] ?? '—').')',
                    'event' => $event->id,
                    'transaction' => (string) ($obj['id'] ?? ''),
                ];
            }
        }

        return null;
    }

    /** What is missing for each outcome, as staff are told. */
    public const NO_EVIDENCE = [
        self::RECORDED => 'لا توجد في الدفاتر دفعة لعملية Paymob هذه، فلا تُغلق على أنها مسجّلة. لا يُسجَّل مبلغ لا يطابق فاتورته إلا بموافقة على طريقة تسويته.',
        self::REFUNDED => 'لم يصل من Paymob إشعار موقّع برد هذا المبلغ كاملًا لهذه العملية. نفّذ الرد من لوحة Paymob وانتظر إشعاره، ثم أغلق المراجعة.',
        self::NOT_COLLECTED => 'لم يصل من Paymob إشعار موقّع بأن هذه العملية رُفضت أو أُلغيت، فلا تُغلق على أن المبلغ لم يُحصَّل.',
    ];

    /**
     * Close review $id as $outcome on its evidence, by $userId: under the review's lock, so two requests at once close it
     * once (the second gets false: already closed). No evidence: a validation error on «outcome», nothing written. The
     * amounts and references stay; the evidence goes with the note, and the audit trail records all of it.
     */
    public static function closeOn(int $id, string $outcome, string $note, int $userId, AuditLogger $audit): bool
    {
        return DB::transaction(function () use ($id, $outcome, $note, $userId, $audit) {
            $review = self::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($review->status !== self::OPEN) {
                return false;
            }
            $evidence = $review->evidence($outcome);
            if ($evidence === null) {
                throw ValidationException::withMessages(['outcome' => self::NO_EVIDENCE[$outcome] ?? 'اختر ما الذي حدث للمبلغ.']);
            }
            $review->forceFill([
                'status' => $outcome,
                'closed_at' => now(),
                'closed_by' => $userId,
                'close_note' => Str::limit('الدليل: '.$evidence['summary'].'. — '.trim($note), 500, ''),
            ])->save();
            $audit->log('payment.review_closed', $review, [
                'outcome' => $outcome, 'evidence' => Arr::except($evidence, 'summary'), 'transaction' => $review->transaction_id,
                'invoice' => $review->invoice_id, 'reason' => $review->reason, 'expected' => $review->expected_minor,
                'received' => $review->received_minor, 'note' => trim($note),
            ]);

            return true;
        });
    }

    /** @return array<string, mixed> what the invoice page shows */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'outcome' => self::OUTCOMES[$this->status] ?? null,
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
