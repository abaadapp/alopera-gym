<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills and what is paid on them, in the club's books:
 *
 *   bill approved     Dr purchases of goods (subtotal − discount)   Dr VAT (input tax, offsets VAT payable)   Cr suppliers
 *   bill voided       the approval entry is reversed (only while nothing is paid on it)
 *   payment           Dr suppliers                                  Cr the cash box or bank it left
 *   payment reversed  the payment entry is reversed
 *
 * A purchase order and a goods receipt post nothing: the bill is the one place a purchase reaches the books, so it
 * is never counted twice, and a bill linked to an order may not exceed the order. Every entry has a source key, so a
 * retry posts nothing more. No payment is recorded that was not made: a payment needs its account, date and amount,
 * and never exceeds what is still owed.
 */
final class SupplierBillService
{
    public function __construct(private readonly Ledger $ledger, private readonly ChartOfAccounts $chart, private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  reference, issue_date, due_date, subtotal, discount, tax, purchase_order_id, branch_id, notes, attachment_path, attachment_name */
    public function create(Supplier $supplier, array $data): SupplierBill
    {
        return DB::transaction(function () use ($supplier, $data) {
            $order = ! empty($data['purchase_order_id']) ? PurchaseOrder::whereKey($data['purchase_order_id'])->lockForUpdate()->firstOrFail() : null;
            $this->check($supplier, $order, $data);
            [$subtotal, $discount, $tax, $total] = $this->amounts($data);
            $bill = new SupplierBill([
                'number' => NumberSequence::next('supplier_bill'),
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'branch_id' => $order?->branch_id ?? ($data['branch_id'] ?? null),
                'reference' => trim($data['reference']),
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? ($supplier->payment_terms_days ? now()->parse($data['issue_date'])->addDays($supplier->payment_terms_days) : null),
                'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total,
                'status' => SupplierBill::PENDING,
                'attachment_path' => $data['attachment_path'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::guard('web')->id(),
            ]);
            $bill->save();
            $this->audit->log('supplier_bill.created', $bill, ['total' => $total]);

            return $bill;
        });
    }

    /** The debt to the supplier enters the books, once. */
    public function approve(SupplierBill $bill): SupplierBill
    {
        return DB::transaction(function () use ($bill) {
            $bill = $this->lock($bill, [SupplierBill::PENDING], 'السند معتمد مسبقًا أو مغلق.');
            $order = $bill->purchase_order_id ? PurchaseOrder::whereKey($bill->purchase_order_id)->lockForUpdate()->first() : null;
            $this->check($bill->supplier, $order, ['reference' => $bill->reference, 'total' => $bill->total], $bill->id);

            $cost = Money::sub($bill->subtotal, $bill->discount);
            $lines = [['account' => $this->chart->account('purchases'), 'debit' => $cost, 'memo' => $bill->supplier->name]];
            if (! Money::isZero($bill->tax)) {
                $lines[] = ['account' => $this->chart->account('vat_payable'), 'debit' => $bill->tax, 'memo' => 'ضريبة مدخلات'];
            }
            $lines[] = ['account' => $this->chart->account('suppliers_payable'), 'credit' => $bill->total, 'memo' => $bill->supplier->name];
            $this->ledger->post([
                'date' => $bill->issue_date, 'description' => 'سند مورد '.$bill->number.' — '.$bill->supplier->name.' ('.$bill->reference.')', 'branch_id' => $bill->branch_id,
                'source_type' => 'supplier_bill', 'source_id' => $bill->id, 'event' => 'approve',
            ], $lines);

            $bill->forceFill(['status' => SupplierBill::APPROVED, 'approved_by' => Auth::guard('web')->id(), 'approved_at' => now()])->save();
            $this->audit->log('supplier_bill.approved', $bill, ['total' => $bill->total]);

            return $bill;
        });
    }

    public function reject(SupplierBill $bill, string $reason): SupplierBill
    {
        return DB::transaction(function () use ($bill, $reason) {
            $bill = $this->lock($bill, [SupplierBill::PENDING], 'لا يُرفض إلا سند بانتظار الاعتماد.');
            $bill->forceFill(['status' => SupplierBill::REJECTED, 'reason' => $reason])->save();
            $this->audit->log('supplier_bill.rejected', $bill, ['reason' => $reason]);

            return $bill;
        });
    }

    /** An approved bill with nothing paid on it: its entry is reversed, it stays on record as void. */
    public function void(SupplierBill $bill, string $reason): SupplierBill
    {
        return DB::transaction(function () use ($bill, $reason) {
            $bill = $this->lock($bill, [SupplierBill::APPROVED], 'لا يُلغى إلا سند معتمد.');
            if (! Money::isZero($bill->paid_total)) {
                throw new BusinessRuleException('supplier_bill.paid', 'على السند مدفوعات؛ اعكسها أولًا ثم ألغِ السند.');
            }
            $entry = JournalEntry::where(['source_type' => 'supplier_bill', 'source_id' => $bill->id, 'source_event' => 'approve'])->first();
            if ($entry && $entry->reversed_at === null) {
                $this->ledger->reverse($entry, 'إلغاء سند المورد '.$bill->number.': '.$reason, ['source_type' => 'supplier_bill', 'source_id' => $bill->id, 'event' => 'void']);
            }
            $bill->forceFill(['status' => SupplierBill::VOID, 'reason' => $reason, 'voided_by' => Auth::guard('web')->id(), 'voided_at' => now()])->save();
            $this->audit->log('supplier_bill.voided', $bill, ['reason' => $reason]);

            return $bill;
        });
    }

    /**
     * Pay (part of) an approved bill from a cash box or bank account — a payment actually made. A retried form with
     * the same key records it once.
     */
    public function pay(SupplierBill $bill, Account $account, string|float $amount, string $paidOn, ?string $reference = null, ?string $notes = null, ?string $clientKey = null): SupplierPayment
    {
        if ($clientKey && ($existing = SupplierPayment::where('client_key', $clientKey)->first())) {
            return $existing;
        }
        if (! $account->isMoney() || ! in_array($account->kind, Account::PAYING_KINDS, true) || ! $account->isPostable()) {
            throw new BusinessRuleException('supplier_payment.account', 'اختر صندوقًا أو حسابًا بنكيًا مفعّلًا.', 'account_id');
        }

        try {
            return DB::transaction(function () use ($bill, $account, $amount, $paidOn, $reference, $notes, $clientKey) {
                $bill = $this->lock($bill, [SupplierBill::APPROVED], 'لا يُسدَّد إلا سند معتمد.');
                $amount = Money::normalize($amount);
                if (Money::toMinor($amount) <= 0 || Money::cmp($amount, $bill->balance()) > 0) {
                    throw new BusinessRuleException('supplier_payment.amount', 'المبلغ أكبر من المتبقي على السند ('.$bill->balance().').', 'amount');
                }
                $payment = SupplierPayment::create([
                    'number' => NumberSequence::next('supplier_payment'), 'supplier_bill_id' => $bill->id, 'supplier_id' => $bill->supplier_id,
                    'account_id' => $account->id, 'amount' => $amount, 'paid_on' => $paidOn, 'reference' => $reference, 'notes' => $notes,
                    'created_by' => Auth::guard('web')->id(), 'client_key' => $clientKey,
                ]);
                $this->ledger->post([
                    'date' => $paidOn, 'description' => 'سداد للمورد '.$bill->supplier->name.' — سند '.$bill->number, 'branch_id' => $bill->branch_id,
                    'source_type' => 'supplier_payment', 'source_id' => $payment->id, 'event' => 'pay',
                ], [
                    ['account' => $this->chart->account('suppliers_payable'), 'debit' => $amount, 'memo' => $bill->supplier->name],
                    ['account' => $account, 'credit' => $amount, 'memo' => $reference],
                ]);
                $bill->forceFill(['paid_total' => Money::add($bill->paid_total, $amount)])->save();
                $this->audit->log('supplier_payment.made', $payment, ['bill' => $bill->number, 'amount' => $amount, 'account' => $account->name]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            return SupplierPayment::where('client_key', $clientKey)->first() ?? throw $e;
        }
    }

    /** A payment entered by mistake: its entry is reversed and the bill owes it again. */
    public function reversePayment(SupplierPayment $payment, string $reason): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->reversed_at) {
                throw new BusinessRuleException('supplier_payment.reversed', 'هذه الدفعة معكوسة مسبقًا.');
            }
            $bill = SupplierBill::whereKey($payment->supplier_bill_id)->lockForUpdate()->firstOrFail();
            $entry = JournalEntry::where(['source_type' => 'supplier_payment', 'source_id' => $payment->id, 'source_event' => 'pay'])->first();
            if ($entry && $entry->reversed_at === null) {
                $this->ledger->reverse($entry, 'عكس سداد '.$payment->number.': '.$reason, ['source_type' => 'supplier_payment', 'source_id' => $payment->id, 'event' => 'reverse']);
            }
            $payment->forceFill(['reversed_at' => now(), 'reversed_by' => Auth::guard('web')->id(), 'reverse_reason' => $reason])->save();
            $bill->forceFill(['paid_total' => Money::max(0, Money::sub($bill->paid_total, $payment->amount))])->save();
            $this->audit->log('supplier_payment.reversed', $payment, ['reason' => $reason]);

            return $payment;
        });
    }

    /**
     * What an order received so far is worth (approved receipt notes), to compare with a bill.
     */
    public static function receivedValue(PurchaseOrder $order): string
    {
        return Money::normalize(GoodsReceipt::where('purchase_order_id', $order->id)->where('status', GoodsReceipt::APPROVED)->sum('total'));
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} subtotal, discount, tax, total */
    private function amounts(array $data): array
    {
        $subtotal = Money::normalize($data['subtotal']);
        $discount = Money::normalize($data['discount'] ?? 0);
        $tax = Money::normalize($data['tax'] ?? 0);
        if (Money::toMinor($subtotal) <= 0 || Money::toMinor($discount) < 0 || Money::toMinor($tax) < 0 || Money::cmp($discount, $subtotal) > 0) {
            throw new BusinessRuleException('supplier_bill.amounts', 'تحقق من المبالغ: الخصم لا يتجاوز المبلغ قبل الضريبة.', 'subtotal');
        }

        return [$subtotal, $discount, $tax, Money::add(Money::sub($subtotal, $discount), $tax)];
    }

    /** One supplier invoice number once per supplier; a linked order of the same supplier, billed once, not beyond its total. */
    private function check(Supplier $supplier, ?PurchaseOrder $order, array $data, ?int $ignore = null): void
    {
        $duplicate = SupplierBill::where('supplier_id', $supplier->id)->where('reference', trim($data['reference']))
            ->whereNotIn('status', [SupplierBill::VOID, SupplierBill::REJECTED])->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists();
        if ($duplicate) {
            throw new BusinessRuleException('supplier_bill.duplicate', 'رقم فاتورة المورد هذا مسجّل مسبقًا لنفس المورد.', 'reference');
        }
        if (! $order) {
            return;
        }
        if ($order->supplier_id !== $supplier->id) {
            throw new BusinessRuleException('supplier_bill.supplier', 'أمر الشراء لمورد آخر.', 'purchase_order_id');
        }
        if (in_array($order->status, [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED], true)) {
            throw new BusinessRuleException('supplier_bill.order_status', 'لا يُربط سند بأمر شراء مسودة أو ملغى.', 'purchase_order_id');
        }
        if (SupplierBill::where('purchase_order_id', $order->id)->whereNotIn('status', [SupplierBill::VOID, SupplierBill::REJECTED])->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw new BusinessRuleException('supplier_bill.order_billed', 'لأمر الشراء '.$order->number.' سند مورد مسجّل؛ لا يُحتسب مرتين.', 'purchase_order_id');
        }
        $total = $data['total'] ?? $this->amounts($data)[3];
        if (Money::cmp($total, $order->total) > 0) {
            throw new BusinessRuleException('supplier_bill.over_order', 'إجمالي السند ('.$total.') أكبر من إجمالي أمر الشراء ('.$order->total.').', 'subtotal');
        }
    }

    private function lock(SupplierBill $bill, array $statuses, string $message): SupplierBill
    {
        $bill = SupplierBill::with('supplier')->whereKey($bill->id)->lockForUpdate()->firstOrFail();
        if (! in_array($bill->status, $statuses, true)) {
            throw new BusinessRuleException('supplier_bill.status', $message);
        }

        return $bill;
    }
}
