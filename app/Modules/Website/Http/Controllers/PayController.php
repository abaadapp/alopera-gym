<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\SiteLanguage;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipCheckout;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentLinkHistory;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return $this->payPage($this->invoiceByToken($token), null, $online);
    }

    public function start(string $token, OnlinePaymentService $online): HttpResponse
    {
        $intent = $online->start($this->invoiceByToken($token), 'whatsapp');

        return Inertia::location($intent->checkout_url);
    }

    /**
     * A payment link's own address: its invoice's pay page, payable only while it is the invoice's current link. An
     * earlier link's address (PaymentLinkHistory) shows the invoice and why it is not payable here.
     */
    public function link(string $token, OnlinePaymentService $online): Response
    {
        return $this->payPage($this->invoiceOfLink($token), $token, $online);
    }

    public function startLink(string $token, OnlinePaymentService $online): HttpResponse
    {
        $intent = $online->start($this->invoiceOfLink($token), 'whatsapp', $token);

        return Inertia::location($intent->checkout_url);
    }

    private function invoiceOfLink(string $token): Invoice
    {
        $invoiceId = PaymentLink::where('token', $token)->value('invoice_id') ?? PaymentLinkHistory::where('token', $token)->value('invoice_id');
        abort_if($invoiceId === null, 404);

        return Invoice::findOrFail($invoiceId);
    }

    /**
     * The invoice and its pay button. With payment links, through the live one only: an ended link (over, cancelled,
     * replaced) shows the invoice with why it is not payable here, and opens nothing (OnlinePaymentService::linkFor).
     */
    private function payPage(Invoice $invoice, ?string $viaToken, OnlinePaymentService $online): Response
    {
        $invoice->load('items');
        $closed = null;
        if ($invoice->isOpen()) {
            try {
                $link = OnlinePaymentService::linkFor($invoice, $viaToken);
                if ($link && OnlinePaymentService::singleBaisa($invoice->balance())) {
                    $closed = OnlinePaymentService::singleBaisaMessage($invoice->balance());
                }
            } catch (BusinessRuleException $e) {
                $closed = $e->getMessage();
            }
        }

        return $this->page('Site/Pay', [
            'invoice' => $this->present($invoice),
            'token' => $invoice->public_token,
            'start_url' => $viaToken !== null ? route('site.pay.link.start', ['token' => $viaToken]) : route('site.pay.start', ['token' => $invoice->public_token]),
            'onlinePayments' => $online->available(),
            'closed' => $closed,
        ], __('دفع الفاتورة :number', ['number' => $invoice->number]));
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
            // A membership bought online has no invoice until its money is confirmed (MembershipCheckouts): none to show yet.
            'invoice' => ($intent->invoice?->only('number') ?? []) + ['token' => $intent->invoice?->public_token],
            // Its attempt failed and nothing is sold: tried again from the link she was sent, or from her account.
            'retry_url' => self::retryUrl($intent),
            // A website order's payment returns to the order's own page link.
            'order' => $intent->invoice_id ? Sale::where('invoice_id', $intent->invoice_id)->where('source', Sale::WEBSITE)->value('public_token') : null,
            // A gift's payment (#192) returns to the gift's card, which shows it usable only once the webhook paid it.
            'gift' => $intent->invoice_id ? GiftMembership::where('invoice_id', $intent->invoice_id)->first()?->linkToken() : null,
        ], __('نتيجة الدفع'));
    }

    /** Polled by the return page until the webhook has arrived. */
    /** Where a failed attempt of a membership not sold yet is paid again (null: not such an attempt). */
    private static function retryUrl(PaymentIntent $intent): ?string
    {
        $checkout = $intent->invoice_id ? MembershipCheckout::where('invoice_id', $intent->invoice_id)->where('status', MembershipCheckout::OPEN)->first() : null;

        return match (true) {
            $checkout === null => null,
            $checkout->token !== null => route('site.checkout.show', ['token' => $checkout->token]),
            default => route('portal.dashboard'),
        };
    }

    /**
     * The page of a payment link reception sent: what she pays for and how much, and «ادفعي الآن». Nothing is sold
     * until the money is confirmed (MembershipCheckouts); a link paid, or withdrawn, says so.
     */
    public function checkout(string $token): Response
    {
        $checkout = MembershipCheckout::with(['plan', 'member:id,full_name', 'invoice.items'])->where('token', $token)->firstOrFail();
        $invoice = $checkout->invoice;

        return $this->page('Site/CheckoutPay', [
            'state' => $checkout->status,
            'name' => explode(' ', trim((string) $checkout->member?->full_name))[0] ?? '',
            'plan' => SiteLanguage::pick($checkout->plan?->name, $checkout->plan?->name_en) ?? $checkout->plan?->name,
            'lines' => $checkout->status === MembershipCheckout::OPEN && $invoice ? $invoice->items->map(fn ($i) => $i->only('description', 'line_total'))->values() : [],
            'start_url' => route('site.checkout.start', ['token' => $token]),
            'total' => $invoice?->total,
            'currency' => $invoice?->currency,
            // Paid: the invoice and receipt it became.
            'invoice_url' => $checkout->status === MembershipCheckout::COMPLETED && $invoice?->public_token ? route('site.invoice', ['token' => $invoice->public_token]) : null,
        ], __('الدفع'));
    }

    public function startCheckout(string $token, OnlinePaymentService $online): HttpResponse
    {
        $checkout = MembershipCheckout::where('token', $token)->firstOrFail();
        if ($checkout->status !== MembershipCheckout::OPEN || ! $checkout->invoice) {
            return redirect()->route('site.checkout.show', ['token' => $token]);
        }

        return Inertia::location($online->start($checkout->invoice, 'whatsapp')->checkout_url);
    }

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
            // With payment links: the live one's address while it runs; none once they all ended (no new one is made here).
            'pay_url' => $invoice->isOpen() && $online->available() ? app(MemberNotifier::class)->payPageOrNull($invoice) : null,
            'print_url' => route('site.invoice.print', ['token' => $token]),
            'pdf_url' => route('site.invoice.pdf', ['token' => $token]),
            // A membership or training package waiting on it starts once it is paid in full: said to the customer.
            'starts_when_paid' => $invoice->isOpen() && (Membership::where('invoice_id', $invoice->id)->where('status', Membership::PENDING)->exists()
                || PtSubscription::where('invoice_id', $invoice->id)->where('status', PtSubscription::PENDING)->exists()),
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
