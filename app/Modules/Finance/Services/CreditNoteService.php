<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Partial refunds and after-the-fact reductions.
 *
 * The invoice keeps its original total and lines; a numbered credit note lowers what is owed
 * on it. Issuing the note and paying money back are separate steps: a note on an unpaid
 * invoice simply lowers the balance, and money goes back only through PaymentService::refund.
 */
final class CreditNoteService
{
    public function __construct(private readonly PaymentService $payments, private readonly AuditLogger $audit) {}

    /**
     * @param  array{method: string, reference?: string|null}|null  $refund  pay back what the note makes overpaid, now
     */
    public function issue(Invoice $invoice, string|float $amount, string $reason, ?array $refund = null): CreditNote
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $refund) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === Invoice::VOID) {
                throw new BusinessRuleException('credit.void', 'لا يمكن إصدار إشعار دائن لفاتورة ملغاة.');
            }
            if (Money::toMinor($amount) <= 0) {
                throw new BusinessRuleException('credit.amount', 'المبلغ يجب أن يكون أكبر من صفر.', 'amount');
            }
            if (Money::cmp($amount, $invoice->netTotal()) > 0) {
                throw new BusinessRuleException('credit.amount', 'المبلغ أكبر من صافي الفاتورة ('.$invoice->netTotal().').', 'amount');
            }
            if (Money::isZero($invoice->paid_total) && Money::cmp($amount, $invoice->netTotal()) === 0) {
                throw new BusinessRuleException('credit.use_void', 'الفاتورة غير مدفوعة: لإلغائها بالكامل استخدم «إلغاء الفاتورة».', 'amount');
            }

            $wasOpen = $invoice->isOpen();

            $note = CreditNote::create([
                'branch_id' => $invoice->branch_id,
                'invoice_id' => $invoice->id,
                'number' => NumberSequence::next('credit_note', $invoice->tenant_id),
                'amount' => Money::normalize($amount),
                'currency' => $invoice->currency,
                'reason' => $reason,
                'created_by' => Auth::guard('web')->id(),
            ]);

            $invoice->forceFill(['credited_total' => Money::add($invoice->credited_total, $amount)]);
            $status = $invoice->settledStatus();
            $invoice->forceFill([
                'status' => $status,
                'paid_at' => $status === Invoice::PAID ? ($invoice->paid_at ?? now()) : $invoice->paid_at,
            ])->save();

            $this->audit->log('invoice.credited', $invoice, ['credit_note' => $note->number, 'amount' => $note->amount, 'reason' => $reason]);

            if ($refund !== null && ! Money::isZero($invoice->refundDue())) {
                $owed = Money::cmp($invoice->refundDue(), $note->amount) <= 0 ? $invoice->refundDue() : $note->amount;
                $this->payments->refund($note, $owed, $refund['method'], $refund['reference'] ?? null);
            }

            // The note settled what was left (e.g. an agreed discount on the remainder).
            if ($wasOpen && $status === Invoice::PAID) {
                InvoicePaid::dispatch($invoice);
            }

            return $note->fresh();
        });
    }
}
