<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Notifications\StaffNotifier;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\PaymentRecorded;
use App\Modules\Finance\Events\TransferSubmitted;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Inventory\Models\Sale;
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
     * The money lands in the account chosen (a cash box or bank account suiting the method) or, by default, the one the
     * finance settings give the method; it is kept with the payment.
     *
     * A point-of-sale device's settlement account is taken when it still suits (else the default), never refusing a sale.
     *
     * @param  array{reference?: string|null, notes?: string|null, proof_path?: string|null, branch_id?: int|null, paid_at?: mixed, account_id?: int|null, settlement_account_id?: int|null}  $options
     */
    public function record(Invoice $invoice, string|float $amount, string $method, array $options = []): Payment
    {
        if (! in_array($method, Payment::METHODS, true) || $method === 'paymob') {
            throw new BusinessRuleException('payment.method', 'طريقة دفع غير مدعومة.', 'method');
        }
        $account = $this->accountFor($method, $options['account_id'] ?? null);
        if (! isset($options['account_id']) && ($settlement = $options['settlement_account_id'] ?? null)) {
            $account = rescue(fn () => $this->accountFor($method, (int) $settlement), $account, false);
        }

        // Money dated back into a closed month would never reach its books.
        if (filled($options['paid_at'] ?? null)) {
            app(AccountingPeriods::class)->assertOpen($options['paid_at'], 'payment_date');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $options, $account) {
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
                'account_id' => $account->id,
                'cash_shift_id' => app(CashShifts::class)->current($account, $options['branch_id'] ?? $invoice->branch_id),
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

    /**
     * Finance confirms a bank transfer after seeing it on the statement — or that a refund waiting to be made (a bank
     * transfer out, a refund through Paymob's dashboard) was actually made, with its reference.
     */
    public function confirm(Payment $payment, ?string $reference = null): Payment
    {
        return DB::transaction(function () use ($payment, $reference) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== Payment::PENDING) {
                throw new BusinessRuleException('payment.not_pending', 'هذه الدفعة ليست بانتظار التأكيد.');
            }
            if ($payment->kind === Payment::KIND_REFUND) {
                return $this->completeRefund($payment, $reference);
            }

            // The money reached the bank whatever the invoice became meanwhile (paid at the desk, for one): what it
            // still owes is applied, and the rest is the customer's credit, applied to another invoice or paid back.
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $fits = $invoice->isOpen() ? Money::min($payment->amount, $invoice->balance()) : '0';
            $credit = Money::sub($payment->amount, $fits);

            $payment->forceFill([
                'confirmed_by' => Auth::guard('web')->id(),
                'confirmed_at' => now(),
                'credit_amount' => $credit,
            ]);
            if (! Money::isZero($credit)) {
                $payment->notes = trim(($payment->notes ? $payment->notes.' — ' : '').'تحويل يتجاوز رصيد الفاتورة عند تأكيده: '.$credit.' رصيد دائن للعميل، يُخصَّص لفاتورة أخرى أو يُرد.');
                $this->audit->log('payment.transfer_overpayment', $payment, ['invoice' => $invoice->number, 'credit' => $credit]);
            }
            if (Money::isZero($fits)) {
                $payment->forceFill(['status' => Payment::COMPLETED, 'receipt_number' => $payment->receipt_number ?? NumberSequence::next('receipt', $invoice->tenant_id)])->save();
            } else {
                $this->apply($invoice, $payment, $fits);
            }

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

                // Money arrived even if the invoice was meanwhile paid at the desk or voided: what the invoice still
                // owes is applied, the rest is the customer's credit — applied to another invoice or paid back.
                $fits = $invoice->isOpen() ? Money::min($amount, $invoice->balance()) : '0';
                $credit = Money::sub($amount, $fits);

                $payment = Payment::create([
                    'branch_id' => $invoice->branch_id,
                    'invoice_id' => $invoice->id,
                    'payer_type' => $invoice->billable_type,
                    'payer_id' => $invoice->billable_id,
                    'kind' => Payment::KIND_PAYMENT,
                    'method' => $gateway,
                    'account_id' => app(ChartOfAccounts::class)->moneyFor($gateway)->id,
                    'status' => Payment::COMPLETED,
                    'amount' => Money::normalize($amount),
                    'credit_amount' => $credit,
                    'currency' => $invoice->currency,
                    'reference' => $reference,
                    'gateway' => $gateway,
                    'gateway_transaction_id' => $transactionId,
                    'paid_at' => now(),
                ]);

                if (! Money::isZero($credit)) {
                    $payment->forceFill(['notes' => 'دفعة إلكترونية تتجاوز رصيد الفاتورة: '.$credit.' رصيد دائن للعميل، يُخصَّص لفاتورة أخرى أو يُرد.'])->save();
                    $this->audit->log('payment.gateway_overpayment', $payment, ['invoice' => $invoice->number, 'credit' => $credit]);
                }

                if (Money::isZero($fits)) {
                    $payment->forceFill(['receipt_number' => NumberSequence::next('receipt', $invoice->tenant_id)])->save();
                } else {
                    $this->apply($invoice, $payment, $fits);
                }

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
     *
     * Cash and card refunds are made at the desk and recorded as made. A bank transfer out, or a refund through
     * Paymob (made in Paymob's dashboard), waits until finance confirms it was made (confirm()); while it waits its
     * amount is held, so it cannot be refunded a second time, and nothing changes in the books.
     */
    public function refund(CreditNote $note, string|float $amount, string $method, ?string $reference = null, ?int $accountId = null): Payment
    {
        if (! in_array($method, Payment::REFUND_METHODS, true)) {
            throw new BusinessRuleException('refund.method', 'طريقة رد غير مدعومة.', 'method');
        }
        $account = $this->accountFor($method, $accountId);

        return DB::transaction(function () use ($note, $amount, $method, $reference, $account) {
            $invoice = Invoice::whereKey($note->invoice_id)->lockForUpdate()->firstOrFail();
            $note = CreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();

            if (Money::toMinor($amount) <= 0) {
                throw new BusinessRuleException('refund.amount', 'مبلغ الرد يجب أن يكون أكبر من صفر.', 'amount');
            }

            $waiting = fn ($q) => $q->where('kind', Payment::KIND_REFUND)->where('status', Payment::PENDING);
            $noteLeft = Money::sub($note->refundable(), (string) Payment::where('credit_note_id', $note->id)->tap($waiting)->sum('amount'));
            $invoiceLeft = Money::sub($invoice->refundDue(), (string) Payment::where('invoice_id', $invoice->id)->where('from_credit', false)->tap($waiting)->sum('amount'));
            $available = Money::max('0', Money::min($noteLeft, $invoiceLeft));
            if (Money::isZero($available)) {
                throw new BusinessRuleException('refund.nothing', 'لا يوجد مبلغ متاح للرد على هذا الإشعار.', 'amount');
            }
            if (Money::cmp($amount, $available) > 0) {
                throw new BusinessRuleException('refund.amount', 'مبلغ الرد أكبر من المتاح ('.$available.').', 'amount');
            }

            $source = Payment::where('invoice_id', $invoice->id)->where('kind', Payment::KIND_PAYMENT)
                ->where('status', Payment::COMPLETED)->when($method === 'paymob', fn ($q) => $q->where('method', 'paymob'))->latest('id')->first();
            if ($method === 'paymob' && ! $source) {
                throw new BusinessRuleException('refund.no_gateway_payment', 'لا توجد دفعة إلكترونية على هذه الفاتورة تُرد عبرها. اختر طريقة رد أخرى.', 'method');
            }
            $waits = in_array($method, Payment::REFUND_METHODS_CONFIRMED, true);

            $refund = Payment::create([
                'branch_id' => $note->branch_id ?? $invoice->branch_id,
                'invoice_id' => $invoice->id,
                'payer_type' => $invoice->billable_type,
                'payer_id' => $invoice->billable_id,
                'receipt_number' => $waits ? null : NumberSequence::next('receipt', $invoice->tenant_id),
                'kind' => Payment::KIND_REFUND,
                'method' => $method,
                'account_id' => $account->id,
                'cash_shift_id' => app(CashShifts::class)->current($account, $note->branch_id ?? $invoice->branch_id),
                'status' => $waits ? Payment::PENDING : Payment::COMPLETED,
                'amount' => Money::normalize($amount),
                'currency' => $invoice->currency,
                'reference' => $reference,
                'notes' => 'رد مقابل الإشعار الدائن '.$note->number.': '.$note->reason,
                'refund_of_id' => $source?->id,
                'credit_note_id' => $note->id,
                'paid_at' => now(),
                'received_by' => Auth::guard('web')->id(),
            ]);

            if ($waits) {
                $this->audit->log('payment.refund_requested', $refund, ['credit_note' => $note->number, 'invoice' => $invoice->number, 'amount' => $refund->amount, 'method' => $method]);

                return $refund;
            }

            $note->forceFill(['refunded_total' => Money::add($note->refunded_total, $amount)])->save();
            $invoice->forceFill(['paid_total' => Money::sub($invoice->paid_total, $amount)]);
            $invoice->forceFill(['status' => $invoice->settledStatus()])->save();

            $this->audit->log('payment.refunded', $refund, ['credit_note' => $note->number, 'invoice' => $invoice->number, 'amount' => $refund->amount]);

            return $refund;
        });
    }

    /**
     * Pay back (part of) a customer's credit — money received and never applied to an invoice. Same rules as a refund:
     * at the desk it is made at once, by bank transfer or through Paymob it waits for confirmation, holding its amount.
     */
    public function refundCredit(Payment $credit, string|float $amount, string $method, ?string $reference = null, ?int $accountId = null): Payment
    {
        if (! in_array($method, Payment::REFUND_METHODS, true)) {
            throw new BusinessRuleException('refund.method', 'طريقة رد غير مدعومة.', 'method');
        }
        $account = $this->accountFor($method, $accountId);

        return DB::transaction(function () use ($credit, $amount, $method, $reference, $account) {
            $credit = Payment::whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $this->assertCredit($credit, $amount);
            if ($method === 'paymob' && $credit->method !== 'paymob') {
                throw new BusinessRuleException('refund.no_gateway_payment', 'هذا الرصيد لم يُدفع إلكترونيًا؛ اختر طريقة رد أخرى.', 'method');
            }
            $waits = in_array($method, Payment::REFUND_METHODS_CONFIRMED, true);

            $refund = Payment::create([
                'branch_id' => $credit->branch_id,
                'invoice_id' => $credit->invoice_id,
                'payer_type' => $credit->payer_type,
                'payer_id' => $credit->payer_id,
                'receipt_number' => $waits ? null : NumberSequence::next('receipt', $credit->tenant_id),
                'kind' => Payment::KIND_REFUND,
                'method' => $method,
                'account_id' => $account->id,
                'cash_shift_id' => app(CashShifts::class)->current($account, $credit->branch_id),
                'status' => $waits ? Payment::PENDING : Payment::COMPLETED,
                'amount' => Money::normalize($amount),
                'currency' => $credit->currency,
                'reference' => $reference,
                'notes' => 'رد من الرصيد الدائن للدفعة '.($credit->receipt_number ?? '#'.$credit->id),
                'refund_of_id' => $credit->id,
                'from_credit' => true,
                'paid_at' => now(),
                'received_by' => Auth::guard('web')->id(),
            ]);
            if (! $waits) {
                $credit->forceFill(['credit_used' => Money::add($credit->credit_used, $amount)])->save();
            }
            $this->audit->log($waits ? 'payment.refund_requested' : 'payment.refunded', $refund, ['credit_of' => $credit->receipt_number, 'amount' => $refund->amount, 'method' => $method]);

            return $refund;
        });
    }

    /**
     * Apply (part of) a customer's credit to another open invoice of the same customer: the invoice is paid by it as by
     * any payment (InvoicePaid when it is settled), and the books move the amount out of the customer's credit.
     */
    public function allocate(Payment $credit, Invoice $invoice, string|float $amount): PaymentAllocation
    {
        return DB::transaction(function () use ($credit, $invoice, $amount) {
            $credit = Payment::whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $this->assertCredit($credit, $amount);
            $invoice = $this->lockOpen($invoice);
            $this->assertAmountFits($invoice, $amount);
            if ($credit->payer_id !== null && ($credit->payer_type !== $invoice->billable_type || (int) $credit->payer_id !== (int) $invoice->billable_id)) {
                throw new BusinessRuleException('credit.other_customer', 'الرصيد يُخصَّص لفاتورة العميل نفسه فقط.', 'invoice_id');
            }

            $allocation = PaymentAllocation::create([
                'payment_id' => $credit->id, 'invoice_id' => $invoice->id, 'amount' => Money::normalize($amount), 'allocated_by' => Auth::guard('web')->id(),
            ]);
            $credit->forceFill(['credit_used' => Money::add($credit->credit_used, $amount)])->save();

            $chart = app(ChartOfAccounts::class);
            app(Ledger::class)->post([
                'date' => now(), 'description' => 'تخصيص رصيد الدفعة '.($credit->receipt_number ?? '#'.$credit->id).' للفاتورة '.$invoice->number, 'branch_id' => $invoice->branch_id,
                'source_type' => 'payment_allocation', 'source_id' => $allocation->id, 'event' => 'apply',
            ], [
                ['account' => $chart->account('customer_credits'), 'debit' => $allocation->amount],
                ['account' => $chart->account('receivable'), 'credit' => $allocation->amount],
            ]);

            $invoice->forceFill(['paid_total' => Money::add($invoice->paid_total, $allocation->amount)]);
            $fullyPaid = $invoice->settledStatus() === Invoice::PAID;
            $invoice->forceFill(['status' => $fullyPaid ? Invoice::PAID : Invoice::PARTIALLY_PAID, 'paid_at' => $fullyPaid ? now() : null])->save();
            $this->audit->log('payment.credit_applied', $allocation, ['credit_of' => $credit->receipt_number, 'invoice' => $invoice->number, 'amount' => $allocation->amount]);

            if ($fullyPaid) {
                InvoicePaid::dispatch($invoice);
            }

            return $allocation;
        });
    }

    /**
     * Undo a payment entered by mistake (the wrong method, the wrong invoice), with a reason: it no longer counts, the
     * invoice owes it again, and its entry is reversed in an open period. Online payments are not cancelled here (the
     * money did arrive: it is refunded), nor point-of-sale sales (cancelled from the sales page), nor a payment whose
     * credit was already used. What it paid for (a membership) stays as it is.
     */
    public function cancel(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->kind !== Payment::KIND_PAYMENT || $payment->status !== Payment::COMPLETED) {
                throw new BusinessRuleException('payment.not_cancellable', 'تُلغى الدفعة المكتملة فقط.');
            }
            if ($payment->gateway !== null) {
                throw new BusinessRuleException('payment.gateway', 'الدفعة الإلكترونية وصلت فعلًا ولا تُلغى؛ اردد مبلغها بإشعار دائن.');
            }
            if (! Money::isZero($payment->credit_used)) {
                throw new BusinessRuleException('payment.credit_used', 'استُخدم رصيد هذه الدفعة؛ لا تُلغى.');
            }
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            if (Sale::where('invoice_id', $invoice->id)->exists()) {
                throw new BusinessRuleException('payment.sale', 'دفعة عملية بيع: ألغِ العملية من صفحة المبيعات ليعود المخزون أيضًا.');
            }
            $applied = $payment->appliedAmount();
            if (Money::cmp($applied, $invoice->paid_total) > 0) {
                throw new BusinessRuleException('payment.refunded_after', 'رُدّ من الفاتورة مبلغ بعد هذه الدفعة؛ لا تُلغى.');
            }
            // A refund waiting to be made counts on the money paid; cancelling under it would refund money never received.
            if (Payment::where('invoice_id', $invoice->id)->where('kind', Payment::KIND_REFUND)->where('status', Payment::PENDING)->exists()) {
                throw new BusinessRuleException('payment.refund_pending', 'على الفاتورة رد مبلغ بانتظار التنفيذ؛ أكّده أو ألغِ طلبه قبل إلغاء الدفعة.');
            }
            // Cash counted in a closed shift: its closing already put the drawer right (the difference is in the books).
            $shift = $payment->cash_shift_id ? CashShift::find($payment->cash_shift_id) : null;
            if ($shift && ! $shift->isOpen() && (int) $payment->account_id === (int) $shift->account_id) {
                throw new BusinessRuleException('payment.shift_closed', "دخلت هذه الدفعة النقدية في الوردية {$shift->number} وقد أُقفلت وحُسب فرقها؛ لا تُلغى. صحّح بقيد يومية أو بإشعار دائن.");
            }

            $payment->forceFill(['status' => Payment::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => Auth::guard('web')->id(), 'cancel_reason' => $reason])->save();
            $invoice->forceFill(['paid_total' => Money::sub($invoice->paid_total, $applied)]);
            $status = $invoice->settledStatus();
            $invoice->forceFill(['status' => $status, 'paid_at' => $status === Invoice::PAID ? $invoice->paid_at : null])->save();
            $this->audit->log('payment.cancelled', $payment, ['invoice' => $invoice->number, 'amount' => $payment->amount, 'method' => $payment->method, 'reason' => $reason]);

            return $payment;
        });
    }

    /** A refund waiting to be made, now made: it counts, the note and the invoice (or the credit) carry it. */
    private function completeRefund(Payment $refund, ?string $reference): Payment
    {
        $invoice = $refund->invoice_id ? Invoice::whereKey($refund->invoice_id)->lockForUpdate()->first() : null;
        $note = $refund->credit_note_id ? CreditNote::whereKey($refund->credit_note_id)->lockForUpdate()->first() : null;
        // Still owed back now that it is made: nothing paid on the invoice was cancelled under it meanwhile.
        if (! $refund->from_credit && $invoice && Money::cmp($refund->amount, $invoice->refundDue()) > 0) {
            throw new BusinessRuleException('refund.not_due', 'لم يعد المبلغ مستحق الرد على الفاتورة (تغيّرت دفعاتها)؛ ألغِ طلب الرد.');
        }

        $refund->forceFill([
            'status' => Payment::COMPLETED,
            'receipt_number' => $refund->receipt_number ?? NumberSequence::next('receipt', $refund->tenant_id),
            'reference' => $reference ?: $refund->reference,
            'confirmed_by' => Auth::guard('web')->id(),
            'confirmed_at' => now(),
        ])->save();

        if ($refund->from_credit) {
            $credit = Payment::whereKey($refund->refund_of_id)->lockForUpdate()->firstOrFail();
            $credit->forceFill(['credit_used' => Money::add($credit->credit_used, $refund->amount)])->save();
        } else {
            $note?->forceFill(['refunded_total' => Money::add($note->refunded_total, $refund->amount)])->save();
            if ($invoice) {
                $invoice->forceFill(['paid_total' => Money::sub($invoice->paid_total, $refund->amount)]);
                $invoice->forceFill(['status' => $invoice->settledStatus()])->save();
            }
        }
        $this->audit->log('payment.refunded', $refund, ['credit_note' => $note?->number, 'invoice' => $invoice?->number, 'amount' => $refund->amount, 'confirmed' => true]);

        return $refund;
    }

    private function assertCredit(Payment $credit, string|float $amount): void
    {
        if ($credit->kind !== Payment::KIND_PAYMENT || $credit->status !== Payment::COMPLETED) {
            throw new BusinessRuleException('credit.none', 'لا رصيد دائن لهذه الدفعة.', 'amount');
        }
        if (Money::toMinor($amount) <= 0) {
            throw new BusinessRuleException('credit.amount', 'المبلغ يجب أن يكون أكبر من صفر.', 'amount');
        }
        $left = $credit->creditLeft();
        if (Money::cmp($amount, $left) > 0) {
            throw new BusinessRuleException('credit.amount', Money::isZero($left) ? 'لا رصيد متبقٍ لهذه الدفعة.' : 'المبلغ أكبر من الرصيد المتبقي ('.$left.').', 'amount');
        }
    }

    /**
     * The money account a payment or refund by this method uses: the one chosen (an active cash box, bank account or
     * clearing account suiting the method), else the finance settings' account for the method.
     */
    /**
     * The account the invoice's money came into by this method (the till's drawer, a card device's settlement bank),
     * for a refund made the same way to go back out of it; null (the method's default account) when it was paid
     * another way or that account no longer takes postings.
     */
    public function paidInto(Invoice $invoice, string $method): ?int
    {
        $account = Payment::where('invoice_id', $invoice->id)->where('kind', Payment::KIND_PAYMENT)->where('status', Payment::COMPLETED)
            ->where('method', $method)->whereNotNull('account_id')->latest('id')->value('account_id');

        return $account && Account::money()->postable()->whereKey($account)->whereIn('kind', Payment::METHOD_ACCOUNT_KINDS[$method] ?? [])->exists() ? (int) $account : null;
    }

    private function accountFor(string $method, ?int $chosen): Account
    {
        $chart = app(ChartOfAccounts::class);
        if ($chosen === null) {
            return $chart->moneyFor($method);
        }
        $account = Account::money()->postable()->find($chosen);
        if (! $account || ! in_array($account->kind, Payment::METHOD_ACCOUNT_KINDS[$method] ?? [], true)) {
            throw new BusinessRuleException('payment.account', 'الحساب المختار لا يناسب طريقة الدفع.', 'account_id');
        }

        return $account;
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

    /** Move a completed payment (or the part of it that fits) onto the invoice and fire InvoicePaid when the balance hits zero. */
    private function apply(Invoice $invoice, Payment $payment, ?string $amount = null): void
    {
        $payment->forceFill([
            'status' => Payment::COMPLETED,
            'receipt_number' => $payment->receipt_number ?? NumberSequence::next('receipt', $invoice->tenant_id),
        ])->save();

        $invoice->forceFill(['paid_total' => Money::add($invoice->paid_total, $amount ?? $payment->amount)]);
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
