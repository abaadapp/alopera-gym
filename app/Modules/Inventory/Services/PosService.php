<?php

namespace App\Modules\Inventory\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
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

/**
 * Front-desk shop: one call sells the items, takes the stock off the shelf,
 * issues a paid invoice and records the payment — or does none of it.
 */
final class PosService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly CreditNoteService $credits,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: float|int}>  $items
     * @param  string|null  $cardReference  transaction number printed by the bank card terminal
     * @param  string|null  $customerName  a walk-in buyer's name, when no member is chosen
     */
    public function checkout(array $items, string $method, ?Member $member = null, string|float $discount = 0, ?int $branchId = null, ?string $cardReference = null, ?string $customerName = null): Sale
    {
        if (! in_array($method, ['cash', 'card'], true)) {
            throw new BusinessRuleException('pos.method', 'طريقة الدفع في نقطة البيع: نقدًا أو بطاقة.', 'payment_method');
        }
        if ($items === []) {
            throw new BusinessRuleException('pos.empty', 'السلة فارغة.', 'items');
        }

        return DB::transaction(function () use ($items, $method, $member, $discount, $branchId, $cardReference, $customerName) {
            $products = Product::whereIn('id', array_column($items, 'product_id'))->where('is_active', true)->get()->keyBy('id');
            $lines = [];
            $subtotal = '0';

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $qty = (float) $item['quantity'];
                if (! $product || $qty <= 0) {
                    throw new BusinessRuleException('pos.item', 'منتج غير متاح في السلة.', 'items');
                }
                $lineTotal = Money::mul($product->price, $qty);
                $subtotal = Money::add($subtotal, $lineTotal);
                $lines[] = compact('product', 'qty', 'lineTotal');
            }

            if (Money::toMinor($discount) < 0 || Money::cmp($discount, $subtotal) > 0) {
                throw new BusinessRuleException('pos.discount', 'الخصم أكبر من قيمة السلة.', 'discount');
            }

            $sale = Sale::create([
                'branch_id' => $branchId,
                'customer_type' => $member?->getMorphClass(),
                'customer_id' => $member?->id,
                'customer_name' => $member ? null : ($customerName ?: null),
                'number' => NumberSequence::next('sale'),
                'source' => Sale::POS,
                'status' => Sale::COMPLETED,
                'fulfillment' => 'delivered',
                'subtotal' => $subtotal,
                'discount' => Money::normalize($discount),
                'tax' => '0',
                'total' => Money::sub($subtotal, $discount),
                'payment_method' => $method,
                'cashier_id' => Auth::guard('web')->id(),
            ]);

            $invoiceLines = [];
            foreach ($lines as $i => $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'name' => $line['product']->name,
                    'quantity' => $line['qty'],
                    'unit_price' => $line['product']->price,
                    'unit_cost' => $line['product']->cost,
                    'line_total' => $line['lineTotal'],
                ]);
                $this->stock->move($line['product'], -$line['qty'], 'sale', $sale);
                $invoiceLines[] = [
                    'description' => $line['product']->name,
                    'quantity' => $line['qty'],
                    'unit_price' => $line['product']->price,
                    // the basket discount sits on the first line so totals match the sale
                    'discount' => $i === 0 ? $discount : 0,
                    'itemable' => $line['product'],
                ];
            }

            $invoice = $this->invoices->issue($member, $invoiceLines, ['type' => 'pos', 'branch_id' => $branchId], $customerName ?: 'عميل نقدي');
            if ($invoice->isOpen()) {
                $reference = $method === 'card' && $cardReference ? $cardReference : $sale->number;
                $this->payments->record($invoice, $invoice->balance(), $method === 'card' ? 'card' : 'cash', ['reference' => $reference, 'branch_id' => $branchId]);
            }

            $sale->forceFill(['invoice_id' => $invoice->id, 'tax' => $invoice->tax_total, 'total' => $invoice->total])->save();

            return $sale->load('items');
        });
    }

    /**
     * Void a completed sale (from the point of sale, or a website order already handed over): stock goes back,
     * the payment is refunded — by the same means unless another is chosen (a Paymob payment is refunded outside
     * the system, so it is recorded as "other" by default).
     */
    public function void(Sale $sale, string $reason, ?string $refundMethod = null): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $refundMethod) {
            $sale = Sale::with('items.product')->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== Sale::COMPLETED) {
                throw new BusinessRuleException('pos.voided', 'لا يمكن إلغاء هذه العملية: ملغاة بالفعل أو لم تكتمل.');
            }

            foreach ($sale->items as $item) {
                $this->stock->move($item->product, (float) $item->quantity, 'void', $sale, $reason);
            }

            // A full credit note keeps the original invoice, cancels what it is worth and pays the money
            // back the way it came; the sale leaves no open receivable behind.
            $invoice = $sale->invoice_id ? Invoice::find($sale->invoice_id) : null;
            if ($invoice && ! Money::isZero($invoice->netTotal())) {
                $payment = Payment::where('invoice_id', $invoice->id)->where('kind', Payment::KIND_PAYMENT)->where('status', Payment::COMPLETED)->first();
                $method = $refundMethod ?? match ($payment?->method) {
                    'card' => 'card', 'cash', null => 'cash', 'bank_transfer' => 'bank_transfer', default => 'other',
                };
                $this->credits->issue($invoice, $invoice->netTotal(), 'إلغاء عملية بيع '.$sale->number.': '.$reason,
                    ['method' => $method, 'reference' => $payment?->reference]);
            }

            $sale->forceFill(['status' => Sale::VOIDED, 'voided_at' => now(), 'cancel_reason' => $reason])->save();

            return $sale;
        });
    }
}
