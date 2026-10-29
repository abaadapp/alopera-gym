<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Notifications\StaffNotifier;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\PaymentRecorded;
use App\Modules\Finance\Events\TransferSubmitted;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only place money is applied to invoices.
 *
 * Every path locks the invoice row first, so two receptionists (or a cashier
 * and a webhook) can never apply the same balance twice.
 */
final class PaymentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record money received at the desk.
     *
     * A bank transfer is recorded as `pending` and counts toward nothing until
     * finance confirms it against the bank statement.
     *
     * @param  array{reference?: string|null, notes?: string|null, proof_path?: string|null, branch_id?: int|null, paid_at?: mixed}  $options
     */
    public function record(Invoice $invoice, string|float $amount, string $method, array $options = []): Payment
    {
        if (! in_array($method, Payment::METHODS, true) || $method === 'paymob') {
            throw new BusinessRuleException('payment.method', 'طريقة دفع غير مدعومة.', 'method');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $options) {
            $invoice = $this->lockOpen($invoice);
            $this->assertAmountFits($invoice, $amount);

            $pending = $method === 'bank_transfer';

            $payment = Payment::create([
                'branch_id' => $options['branch_id'] ?? $invoice->branch_id,
                'invoice_id' => $invoice->id,
                'payer_type' => $invoice->billable_type,
                'payer_id' => $invoice->billable_id,
                'kind' => Payment::KIND_PAYMENT,
                'method' => $method,
                'status' => $pending ? Payment::PENDING : Payment::COMPLETED,
                'amount' => Money::normalize($amount),
                'currency' => $invoice->currency,
                'reference' => $options['reference'] ?? null,
                'notes' => $options['notes'] ?? null,
                'proof_path' => $options['proof_path'] ?? null,
                'paid_at' => $options['paid_at'] ?? now(),
                'received_by' => Auth::guard('web')->id(),
            ]);

            if ($pending) {
                TransferSubmitted::dispatch($payment);
                app(StaffAlerts::class)->pendingPayment($payment);
            } else {
                $this->apply($invoice, $payment);
            }

            return $payment;
        });
    }

    /** Finance confirms a bank transfer after seeing it on the statement. */
    public function confirm(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== Payment::PENDING) {
                throw new BusinessRuleException('payment.not_pending', 'هذه الدفعة ليست بانتظار التأكيد.');
            }

            $invoice = $this->lockOpen($payment->invoice);
            $this->assertAmountFits($invoice, $payment->amount);

            $payment->forceFill([
                'confirmed_by' => Auth::guard('web')->id(),
                'confirmed_at' => now(),
            ]);
            $this->apply($invoice, $payment);

            app(StaffNotifier::class)->resolve('payment-pending:'.$payment->id);

            return $payment;
        });
    }

    public function reject(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== Payment::PENDING) {
                throw new BusinessRuleException('payment.not_pending', 'هذه الدفعة ليست بانتظار التأكيد.');
            }

            $payment->forceFill([
                'status' => Payment::REJECTED,
                'rejection_reason' => $reason,
                'confirmed_by' => Auth::guard('web')->id(),
                'confirmed_at' => now(),
            ])->save();

            app(StaffNotifier::class)->resolve('payment-pending:'.$payment->id);

            return $payment;
        });
    }

    /**
     * Record money confirmed by a gateway webhook. Idempotent per gateway
     * transaction id: a repeated callback returns the existing payment.
     *
     * @return array{payment: Payment, created: bool}
     */
    public function recordGatewayPayment(Invoice $invoice, string|float $amount, string $gateway, string $transactionId, ?string $reference = null): array
    {
        $existing = Payment::where('gateway', $gateway)->where('gateway_transaction_id', $transactionId)->first();
        if ($existing) {
            return ['payment' => $existing, 'created' => false];
        }

        try {
            $payment = DB::transaction(function () use ($invoice, $amount, $gateway, $transactionId, $reference) {
                $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                // Checked again under the lock: a concurrent duplicate may have just committed.
                $again = Payment::where('gateway', $gateway)->where('gateway_transaction_id', $transactionId)->first();
                if ($again) {
                    return $again;
                }

                $payment = Payment::create([
                    'branch_id' => $invoice->branch_id,
                    'invoice_id' => $invoice->id,
                    'payer_type' => $invoice->billable_type,
                    'payer_id' => $invoice->billable_id,
                    'kind' => Payment::KIND_PAYMENT,
                    'method' => $gateway,
                    'status' => Payment::COMPLETED,
                    'amount' => Money::normalize($amount),
                    'currency' => $invoice->currency,
                    'reference' => $reference,
                    'gateway' => $gateway,
                    'gateway_transaction_id' => $transactionId,
                    'paid_at' => now(),
                ]);

                // Money arrived even if the invoice was meanwhile paid at the desk or voided:
                // record it, but flag it for a refund instead of over-applying.
                if (! $invoice->isOpen() || Money::cmp($amount, $invoice->balance()) > 0) {
                    $payment->forceFill(['notes' => 'دفعة إلكترونية تتجاوز رصيد الفاتورة — تحتاج مراجعة/رد.'])->save();
                    $this->audit->log('payment.gateway_overpayment', $payment, ['invoice' => $invoice->number]);

                    return $payment;
                }

                $this->apply($invoice, $payment);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            return ['payment' => Payment::where('gateway', $gateway)->where('gateway_transaction_id', $transactionId)->firstOrFail(), 'created' => false];
        }

        return ['payment' => $payment, 'created' => $payment->wasRecentlyCreated];
    }

    /**
     * Money paid back to the customer. Always against a credit note (which already reduced what
     * the invoice is worth) and never more than the note still covers or than was overpaid, both
     * checked under row locks — so a double click or a second cashier cannot refund twice.
     */
    public function refund(CreditNote $note, string|float $amount, string $method, ?string $reference = null): Payment
    {
        if (! in_array($method, ['cash', 'bank_transfer', 'card', 'other'], true)) {
            throw new BusinessRuleException('refund.method', 'طريقة رد غير مدعومة.', 'method');
        }

        return DB::transaction(function () use ($note, $amount, $method, $reference) {
            $invoice = Invoice::whereKey($note->invoice_id)->lockForUpdate()->firstOrFail();
            $note = CreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();

            if (Money::toMinor($amount) <= 0) {
                throw new BusinessRuleException('refund.amount', 'مبلغ الرد يجب أن يكون أكبر من صفر.', 'amount');
            }

            $available = Money::cmp($note->refundable(), $invoice->refundDue()) <= 0 ? $note->refundable() : $invoice->refundDue();
            if (Money::isZero($available)) {
                throw new BusinessRuleException('refund.nothing', 'لا يوجد مبلغ متاح للرد على هذا الإشعار.', 'amount');
            }
            if (Money::cmp($amount, $available) > 0) {
                throw new BusinessRuleException('refund.amount', 'مبلغ الرد أكبر من المتاح ('.$available.').', 'amount');
            }

            $source = Payment::where('invoice_id', $invoice->id)->where('kind', Payment::KIND_PAYMENT)
                ->where('status', Payment::COMPLETED)->latest('id')->first();

            $refund = Payment::create([
                'branch_id' => $note->branch_id ?? $invoice->branch_id,
                'invoice_id' => $invoice->id,
                'payer_type' => $invoice->billable_type,
                'payer_id' => $invoice->billable_id,
                'receipt_number' => NumberSequence::next('receipt', $invoice->tenant_id),
                'kind' => Payment::KIND_REFUND,
                'method' => $method,
                'status' => Payment::COMPLETED,
                'amount' => Money::normalize($amount),
                'currency' => $invoice->currency,
                'reference' => $reference,
                'notes' => 'رد مقابل الإشعار الدائن '.$note->number.': '.$note->reason,
                'refund_of_id' => $source?->id,
                'credit_note_id' => $note->id,
                'paid_at' => now(),
                'received_by' => Auth::guard('web')->id(),
            ]);

            $note->forceFill(['refunded_total' => Money::add($note->refunded_total, $amount)])->save();
            $invoice->forceFill(['paid_total' => Money::sub($invoice->paid_total, $amount)]);
            $invoice->forceFill(['status' => $invoice->settledStatus()])->save();

            $this->audit->log('payment.refunded', $refund, ['credit_note' => $note->number, 'invoice' => $invoice->number, 'amount' => $refund->amount]);

            return $refund;
        });
    }

    private function lockOpen(Invoice $invoice): Invoice
    {
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        if (! $invoice->isOpen()) {
            throw new BusinessRuleException('invoice.not_open', $invoice->status === Invoice::PAID
                ? 'الفاتورة مدفوعة بالكامل.'
                : 'لا يمكن الدفع على فاتورة بهذه الحالة.');
        }

        return $invoice;
    }

    private function assertAmountFits(Invoice $invoice, string|float $amount): void
    {
        if (Money::toMinor($amount) <= 0) {
            throw new BusinessRuleException('payment.amount', 'المبلغ يجب أن يكون أكبر من صفر.', 'amount');
        }

        if (Money::cmp($amount, $invoice->balance()) > 0) {
            throw new BusinessRuleException('payment.overpayment', 'المبلغ أكبر من المتبقي على الفاتورة ('.$invoice->balance().').', 'amount');
        }
    }

    /** Move a completed payment onto the invoice and fire InvoicePaid when the balance hits zero. */
    private function apply(Invoice $invoice, Payment $payment): void
    {
        $payment->forceFill([
            'status' => Payment::COMPLETED,
            'receipt_number' => $payment->receipt_number ?? NumberSequence::next('receipt', $invoice->tenant_id),
        ])->save();

        $invoice->forceFill(['paid_total' => Money::add($invoice->paid_total, $payment->amount)]);
        $fullyPaid = $invoice->settledStatus() === Invoice::PAID;

        $invoice->forceFill([
            'status' => $fullyPaid ? Invoice::PAID : Invoice::PARTIALLY_PAID,
            'paid_at' => $fullyPaid ? now() : null,
        ])->save();

        PaymentRecorded::dispatch($payment);

        if ($fullyPaid) {
            InvoicePaid::dispatch($invoice);
        }
    }
}
