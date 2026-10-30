<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Notifications\StaffNotifier;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orders placed on the club's website. Placing one holds the stock and issues an unpaid invoice; the order is
 * paid only when money is applied to that invoice (Paymob's signed webhook, or cash/card at hand-over) — never
 * because a browser came back from a payment page. Staff then prepare it, hand it over, or cancel it, which
 * puts the stock back and voids or refunds the invoice. The books follow the invoice and its payments.
 */
final class OrderService
{
    public const REFUND_METHODS = ['cash', 'card', 'bank_transfer', 'other'];

    public function __construct(
        private readonly SaleLines $lines,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly CreditNoteService $credits,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int, addons?: list<array{addon_id: int, quantity: int}>}>  $items
     * @param  array{name: string, phone: string, email?: string|null, notes?: string|null}  $customer
     * @param  string  $method  paymob|pickup
     */
    public function place(array $items, array $customer, string $method, ?Member $member = null, ?int $branchId = null): Sale
    {
        if ($items === []) {
            throw new BusinessRuleException('order.empty', 'السلة فارغة.', 'items');
        }
        if (! in_array($method, ['paymob', 'pickup'], true)) {
            throw new BusinessRuleException('order.method', 'اختر طريقة دفع متاحة.', 'payment_method');
        }
        $window = ShopSettings::of($this->tenants->require())['payment_window_minutes'];

        return DB::transaction(function () use ($items, $customer, $method, $member, $branchId, $window) {
            $products = Product::onWebsite()->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
            ['lines' => $lines, 'subtotal' => $subtotal] = $this->lines->price($products, $items, 'website');

            $sale = Sale::create([
                'branch_id' => $branchId,
                'customer_type' => $member?->getMorphClass(),
                'customer_id' => $member?->id,
                'customer_name' => Str::limit(trim($customer['name']), 120, ''),
                'customer_phone' => $customer['phone'],
                'customer_email' => $customer['email'] ?? null,
                'notes' => $customer['notes'] ?? null,
                'number' => NumberSequence::next('web_order'),
                'source' => Sale::WEBSITE,
                'status' => Sale::PENDING,
                'fulfillment' => 'new',
                'subtotal' => $subtotal,
                'discount' => '0',
                'tax' => '0',
                'total' => $subtotal,
                'payment_method' => $method,
                'public_token' => Str::random(40),
                'reserved_until' => $method === 'paymob' ? now()->addMinutes($window) : null,
            ]);

            // Held for this order (no overselling); handed back if it is cancelled or never paid.
            $invoiceLines = $this->lines->record($sale, $lines, 'order');

            $invoice = $this->invoices->issue($member, $invoiceLines, ['type' => 'pos', 'branch_id' => $branchId, 'notes' => 'طلب من الموقع '.$sale->number], $sale->customer_name);
            if (! $member) {
                $invoice->forceFill(['customer_phone' => $sale->customer_phone, 'customer_email' => $sale->customer_email])->save();
            }
            $sale->forceFill(['invoice_id' => $invoice->id, 'tax' => $invoice->tax_total, 'total' => $invoice->total])->save();

            $this->audit->as('customer', $member?->id, $sale->customer_name, fn () => $this->audit->log('sale.order_placed', $sale, ['total' => $sale->total, 'method' => $method]));

            app(StaffAlerts::class)->order($sale);

            return $sale->load('items');
        });
    }

    /** The order's invoice is fully paid (gateway webhook, desk payment, or nothing to pay): note it once. */
    public function markPaid(Invoice $invoice): void
    {
        $sale = Sale::where('invoice_id', $invoice->id)->where('source', Sale::WEBSITE)->lockForUpdate()->first();
        if (! $sale || $sale->paid_at) {
            return;
        }
        $sale->forceFill(['paid_at' => now(), 'reserved_until' => null])->save();
        $this->audit->log('sale.order_paid', $sale, ['invoice' => $invoice->number]);
    }

    /** Move a pending order forward: preparing, then ready for pickup. */
    public function advance(Sale $sale, string $step): Sale
    {
        return DB::transaction(function () use ($sale, $step) {
            $sale = $this->lockOrder($sale);
            $from = array_search($sale->fulfillment ?? 'new', Sale::FULFILLMENT, true);
            $to = array_search($step, Sale::FULFILLMENT, true);
            if (! in_array($step, ['preparing', 'ready'], true) || $to <= $from) {
                throw new BusinessRuleException('order.step', 'لا يمكن نقل الطلب إلى هذه المرحلة.', 'status');
            }
            $sale->forceFill(['fulfillment' => $step])->save();
            $this->audit->log('sale.order_'.$step, $sale);
            app(StaffNotifier::class)->resolve('order:'.$sale->id); // someone is on it

            return $sale;
        });
    }

    /**
     * Hand the order over. An order not paid yet is paid now at the desk, in full, by cash or card.
     *
     * @param  string|null  $method  cash|card — required when something is still due
     */
    public function deliver(Sale $sale, ?string $method = null, ?string $reference = null): Sale
    {
        return DB::transaction(function () use ($sale, $method, $reference) {
            $sale = $this->lockOrder($sale);
            $invoice = Invoice::whereKey($sale->invoice_id)->lockForUpdate()->firstOrFail();

            if ($invoice->isOpen() && ! Money::isZero($invoice->balance())) {
                if (Payment::where('invoice_id', $invoice->id)->where('status', Payment::PENDING)->exists()) {
                    throw new BusinessRuleException('order.pending_payment', 'على الطلب دفعة بانتظار التأكيد. أكّدها من المالية قبل التسليم.');
                }
                if (! in_array($method, ['cash', 'card'], true)) {
                    throw new BusinessRuleException('order.collect', 'الطلب غير مدفوع: اختر طريقة التحصيل (نقدًا أو بطاقة) قبل التسليم.', 'method');
                }
                if ($method === 'card' && ! $reference) {
                    throw new BusinessRuleException('order.reference', 'أدخل رقم العملية من جهاز الشبكة.', 'reference');
                }
                $this->payments->record($invoice, $invoice->balance(), $method, ['reference' => $reference ?: $sale->number, 'branch_id' => $sale->branch_id]);
                $sale->payment_method = $method;
            }

            $sale->forceFill([
                'status' => Sale::COMPLETED,
                'fulfillment' => 'delivered',
                'delivered_at' => now(),
                'delivered_by' => Auth::guard('web')->id(),
                'cashier_id' => $sale->cashier_id ?? Auth::guard('web')->id(),
                'paid_at' => $sale->fresh()->paid_at ?? now(),
                'reserved_until' => null,
            ])->save();
            $this->audit->log('sale.order_delivered', $sale, ['method' => $sale->payment_method]);

            app(StaffNotifier::class)->resolve('order:'.$sale->id);

            return $sale;
        });
    }

    /**
     * Cancel an order that was not handed over: the held stock goes back, an unpaid invoice is voided, a paid one
     * is credited in full and the money recorded as refunded (the actual refund is made outside the system).
     */
    public function cancel(Sale $sale, string $reason, ?string $refundMethod = null): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $refundMethod) {
            $sale = $this->lockOrder($sale);
            $invoice = Invoice::whereKey($sale->invoice_id)->lockForUpdate()->first();
            $paid = $invoice && ! Money::isZero($invoice->paid_total);

            if ($paid && ! in_array($refundMethod, self::REFUND_METHODS, true)) {
                throw new BusinessRuleException('order.refund_method', 'الطلب مدفوع: اختر طريقة رد المبلغ.', 'refund_method');
            }

            // Marked first, so the invoice-voided listener sees a closed order and does not cancel it again.
            $sale->forceFill([
                'status' => Sale::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::guard('web')->id(),
                'cancel_reason' => Str::limit($reason, 250),
                'reserved_until' => null,
            ])->save();

            $this->lines->restock($sale, 'release', $reason);

            if ($invoice && $invoice->status !== Invoice::VOID) {
                if ($paid) {
                    $this->credits->issue($invoice, $invoice->netTotal(), 'إلغاء طلب '.$sale->number.': '.$reason, ['method' => $refundMethod, 'reference' => $sale->number]);
                } else {
                    $this->invoices->void($invoice, 'إلغاء طلب '.$sale->number.': '.$reason);
                }
            }
            $this->audit->log('sale.order_cancelled', $sale, ['reason' => $reason, 'refunded' => $paid]);

            app(StaffNotifier::class)->resolve('order:'.$sale->id);

            return $sale;
        });
    }

    /** The invoice of a pending order was voided from the finance screens: release the order too. */
    public function cancelForVoidedInvoice(Invoice $invoice): void
    {
        $sale = Sale::where('invoice_id', $invoice->id)->where('source', Sale::WEBSITE)->where('status', Sale::PENDING)->first();
        if ($sale) {
            $this->cancel($sale, $invoice->void_reason ?: 'إلغاء الفاتورة');
        }
    }

    /**
     * Online-payment orders whose payment window passed unpaid: stock released, invoice voided. An order with a
     * payment already applied or awaiting confirmation is left for staff. Returns how many were cancelled.
     */
    public function expireOverdue(): int
    {
        $count = 0;
        Sale::where('source', Sale::WEBSITE)->where('status', Sale::PENDING)->whereNull('paid_at')
            ->whereNotNull('reserved_until')->where('reserved_until', '<', now())->orderBy('id')
            ->each(function (Sale $sale) use (&$count) {
                try {
                    $this->audit->as('system', null, 'scheduler', fn () => $this->cancel($sale, 'انتهت مهلة الدفع الإلكتروني'));
                    $count++;
                } catch (BusinessRuleException) {
                    // Paid meanwhile, or a payment awaits confirmation: staff decide.
                }
            });

        return $count;
    }

    private function lockOrder(Sale $sale): Sale
    {
        $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
        if ($sale->source !== Sale::WEBSITE || $sale->status !== Sale::PENDING) {
            throw new BusinessRuleException('order.closed', 'الطلب مغلق: سُلّم أو أُلغي.');
        }

        return $sale;
    }
}
