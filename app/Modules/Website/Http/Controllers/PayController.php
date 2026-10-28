<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Payment links (sent by WhatsApp), the gateway return page and the public
 * invoice view. Links carry a 48-character random token, not an id.
 *
 * The return page only *reads* the intent status written by the webhook.
 */
class PayController extends Controller
{
    use RendersSite;

    private function invoiceByToken(string $token): Invoice
    {
        return Invoice::where('public_token', $token)->firstOrFail();
    }

    public function show(string $token, OnlinePaymentService $online): Response
    {
        $invoice = $this->invoiceByToken($token)->load('items');

        return $this->page('Site/Pay', [
            'invoice' => $this->present($invoice),
            'token' => $token,
            'onlinePayments' => $online->available(),
        ], 'دفع الفاتورة '.$invoice->number);
    }

    public function start(string $token, OnlinePaymentService $online): HttpResponse
    {
        $intent = $online->start($this->invoiceByToken($token), 'whatsapp');

        return Inertia::location($intent->checkout_url);
    }

    public function return(string $reference): Response
    {
        $intent = PaymentIntent::with('invoice')->where('reference', $reference)->firstOrFail();

        return $this->page('Site/PaymentReturn', [
            'reference' => $intent->reference,
            'status' => $intent->status,
            'amount' => $intent->amount,
            'currency' => $intent->currency,
            'invoice' => $intent->invoice?->only('number') + ['token' => $intent->invoice?->public_token],
        ], 'نتيجة الدفع');
    }

    /** Polled by the return page until the webhook has arrived. */
    public function status(string $reference): JsonResponse
    {
        $intent = PaymentIntent::where('reference', $reference)->firstOrFail();

        return response()->json(['status' => $intent->status]);
    }

    public function invoice(string $token, TenantContext $tenants): Response
    {
        $invoice = $this->invoiceByToken($token)->load(['items', 'payments' => fn ($q) => $q->where('status', 'completed')]);
        $tenant = $tenants->require();

        return $this->page('Site/Invoice', [
            'invoice' => $this->present($invoice) + [
                'payments' => $invoice->payments->map(fn ($p) => $p->only('receipt_number', 'kind', 'method', 'amount') + ['paid_at' => $p->paid_at?->format('Y-m-d H:i')]),
                'pay_url' => $invoice->isOpen() ? route('site.pay.show', ['token' => $token]) : null,
            ],
            'club' => $tenant->only('name', 'legal_name', 'phone', 'address') + ['vat_number' => $tenant->setting('vat_number'), 'footer' => $tenant->setting('invoice_footer')],
        ], 'فاتورة '.$invoice->number);
    }

    /** @return array<string, mixed> */
    private function present(Invoice $invoice): array
    {
        return $invoice->only('number', 'status', 'customer_name', 'subtotal', 'discount_total', 'tax_total', 'tax_rate', 'total', 'paid_total', 'currency') + [
            'balance' => $invoice->balance(),
            'issue_date' => $invoice->issue_date->toDateString(),
            'items' => $invoice->items->map(fn ($i) => $i->only('description', 'quantity', 'unit_price', 'discount', 'line_total')),
        ];
    }
}
