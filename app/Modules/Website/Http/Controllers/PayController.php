<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        // A payment link (PaymentLink) past its time or cancelled: the invoice is shown, with why it is not payable here.
        $link = PaymentLink::where('invoice_id', $invoice->id)->first();

        return $this->page('Site/Pay', [
            'invoice' => $this->present($invoice),
            'token' => $token,
            'onlinePayments' => $online->available(),
            'closed' => match (true) {
                ! $link || ! $invoice->isOpen() => null,
                ! $link->opensCheckout() => OnlinePaymentService::closedLinkMessage($link),
                OnlinePaymentService::singleBaisa($invoice->balance()) => OnlinePaymentService::singleBaisaMessage($invoice->balance()),
                default => null,
            },
        ], __('دفع الفاتورة :number', ['number' => $invoice->number]));
    }

    public function start(string $token, OnlinePaymentService $online): HttpResponse
    {
        $intent = $online->start($this->invoiceByToken($token), 'whatsapp');

        return Inertia::location($intent->checkout_url);
    }

    /**
     * A link's own address, /pay/link/{token} (payment links v2, PR #218), served here so that the links v2 sent keep
     * working if the code goes back to this release (R1). It exists only once v2's migration added the columns: before,
     * every such address is 404. The invoice's current link (payment_links.token) opens its invoice's pay page — the same
     * page, gate and checkout as the invoice's own address (start() asks the current link). An earlier link of the
     * invoice (payment_link_history) shows the invoice with why it no longer opens a page, and starts nothing.
     */
    public function link(string $token, OnlinePaymentService $online): Response
    {
        [$invoice, $closed] = $this->linkOf($token);
        $page = $this->show($invoice->public_token, $online);

        return $closed === null ? $page : $page->with('closed', $invoice->isOpen() ? $closed : null);
    }

    /** Paying from a link's own address: the current link only, as the invoice's address does; an earlier one never. */
    public function startLink(string $token, OnlinePaymentService $online): HttpResponse
    {
        [$invoice, $closed] = $this->linkOf($token);
        if ($closed !== null) {
            throw new BusinessRuleException('payment_link.closed', $closed);
        }

        return $this->start($invoice->public_token, $online);
    }

    /**
     * The invoice of a link's address, and why it opens no page when it is an earlier link (null when it is the current
     * one). This club's only (the link's tenant scope, and the club's id for the earlier links).
     *
     * @return array{0: Invoice, 1: string|null}
     */
    private function linkOf(string $token): array
    {
        abort_unless(Schema::hasColumn('payment_links', 'token'), 404);
        $current = PaymentLink::where('token', $token)->value('invoice_id');
        if ($current !== null) {
            return [Invoice::findOrFail($current), null];
        }
        abort_unless(Schema::hasTable('payment_link_history'), 404);
        $earlier = DB::table('payment_link_history')->where('tenant_id', app(TenantContext::class)->require()->id)->where('token', $token)->first(['invoice_id', 'replaced_at', 'cancelled_at']);
        abort_if($earlier === null, 404);

        return [Invoice::findOrFail($earlier->invoice_id), $earlier->replaced_at !== null
            ? 'هذا رابط دفع قديم حلّ محله رابط أحدث. اطلب من النادي الرابط الجديد، أو ادفع في الاستقبال.'
            : 'أُلغي رابط الدفع هذا. تواصل مع النادي إن كان عليك مبلغ.'];
    }

    public function return(string $reference): Response
    {
        $intent = PaymentIntent::with('invoice')->where('reference', $reference)->firstOrFail();

        return $this->page('Site/PaymentReturn', [
            'reference' => $intent->reference,
            'status' => self::shownStatus($intent),
            'held' => self::held($intent),
            'amount' => $intent->amount,
            'currency' => $intent->currency,
            'invoice' => $intent->invoice?->only('number') + ['token' => $intent->invoice?->public_token],
            // A website order's payment returns to the order's own page link.
            'order' => $intent->invoice_id ? Sale::where('invoice_id', $intent->invoice_id)->where('source', Sale::WEBSITE)->value('public_token') : null,
            // A gift's payment (#192) returns to the gift's card, which shows it usable only once the webhook paid it.
            'gift' => $intent->invoice_id ? GiftMembership::where('invoice_id', $intent->invoice_id)->first()?->linkToken() : null,
        ], __('نتيجة الدفع'));
    }

    /** Polled by the return page until the webhook has arrived. */
    public function status(string $reference): JsonResponse
    {
        $intent = PaymentIntent::where('reference', $reference)->firstOrFail();

        return response()->json(['status' => self::shownStatus($intent), 'held' => self::held($intent)]);
    }

    /**
     * What the return page says: the webhook's result, or «review» while Paymob's confirmation of this attempt did not
     * match and the club checks it (PaymentReview) — never «failed», never «paid».
     */
    private static function shownStatus(PaymentIntent $intent): string
    {
        return PaymentReview::where('payment_intent_id', $intent->id)->open()->exists() ? 'review' : $intent->status;
    }

    /**
     * Paymob confirmed the money and it is in the books, but held for the club's review (applied to nothing yet): the
     * customer is told it arrived and is being reviewed — never that it failed, never that it is all done.
     */
    private static function held(PaymentIntent $intent): bool
    {
        return $intent->status === PaymentIntent::PAID && $intent->payment_id !== null
            && Payment::whereKey($intent->payment_id)->gatewayCreditLeft()->whereColumn('credit_amount', 'amount')->exists();
    }

    /** The invoice link sent to the member: the invoice paper itself (as printed and as the PDF), and a pay button. */
    public function invoice(string $token, OnlinePaymentService $online): Response
    {
        $invoice = $this->invoiceByToken($token);

        return $this->page('Site/Invoice', [
            'invoice' => ['number' => $invoice->number, 'balance' => $invoice->status === Invoice::VOID ? '0.000' : $invoice->balance(), 'status' => $invoice->status],
            'paper' => Papers::for('invoice', $invoice)->toArray(),
            'pay_url' => $invoice->isOpen() && $online->available() ? route('site.pay.show', ['token' => $token]) : null,
            'print_url' => route('site.invoice.print', ['token' => $token]),
            'pdf_url' => route('site.invoice.pdf', ['token' => $token]),
        ], __('فاتورة :number', ['number' => $invoice->number]));
    }

    public function invoicePrint(string $token): HttpResponse
    {
        return Papers::for('invoice', $this->invoiceByToken($token))->page();
    }

    public function invoicePdf(Request $request, string $token): HttpResponse
    {
        return Papers::for('invoice', $this->invoiceByToken($token))->pdf($request->boolean('download'));
    }

    /** @return array<string, mixed> */
    private function present(Invoice $invoice): array
    {
        return $invoice->only('number', 'status', 'customer_name', 'subtotal', 'discount_total', 'tax_total', 'tax_rate', 'total', 'credited_total', 'paid_total', 'currency') + [
            'balance' => $invoice->balance(),
            'issue_date' => $invoice->issue_date->toDateString(),
            'items' => $invoice->items->map(fn ($i) => $i->only('description', 'quantity', 'unit_price', 'discount', 'line_total')),
        ];
    }
}
