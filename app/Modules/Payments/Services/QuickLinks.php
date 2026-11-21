<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Paymob\PaymobClient;
use App\Modules\Payments\Paymob\PaymobQuickLink;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A payment link made inside Paymob (QuickLink) when the club saved its API key (owner's decision, 2026-11-21): the
 * address sent by WhatsApp is Paymob's own. It is one more attempt of the invoice (channel «quicklink») holding the
 * Paymob order it pays, so its signed callback goes the one way every payment goes (OnlinePaymentService::handleWebhook:
 * signature, environment, integration, the signed order, the amount, once). Without the key nothing changes: the
 * link opens the club's own page.
 *
 * While it is open: the club's own page of the invoice leads to it (never a second page), the desk takes no payment
 * on the invoice (owner: no paying twice), and cancelling the link closes it at Paymob first — refused, nothing changed,
 * when Paymob does not confirm (owner: the exception to «nothing is cancelled at Paymob», for these links only).
 */
final class QuickLinks
{
    public const CHANNEL = 'quicklink';

    /** An attempt whose link Paymob confirmed it closed: no money can come of it, nothing to check by hand. */
    public const CANCELLED = 'quicklink.cancelled';

    public function __construct(
        private readonly PaymobQuickLink $paymob,
        private readonly OnlinePaymentService $online,
        private readonly AuditLogger $audit,
    ) {}

    /** The invoice's Paymob link still payable (made since $since, when given), or null. */
    public static function openFor(int $invoiceId, ?Carbon $since = null): ?PaymentIntent
    {
        return PaymentIntent::where('invoice_id', $invoiceId)->where('channel', self::CHANNEL)
            ->where('status', PaymentIntent::PENDING)->whereNotNull('checkout_url')->where('expires_at', '>', now())
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->latest('id')->first();
    }

    /** The desk takes nothing on an invoice whose Paymob link may still be paid. */
    public static function refuseDeskWhileOpen(Invoice $invoice): void
    {
        $open = self::openFor($invoice->id);
        if ($open !== null) {
            throw new BusinessRuleException('payment.quicklink_open', 'لهذه الفاتورة رابط دفع Paymob مفتوح حتى '
                .$open->expires_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i')
                .' وقد يدفع العميل منه. ألغِ الرابط أولًا (روابط الدفع أو صفحة الفاتورة) ثم سجّل الدفعة، حتى لا يُدفع مرتين.');
        }
    }

    /**
     * Make the link's Paymob link, after the link is saved (never inside a lock: Paymob is called with none held).
     * Null when made, or when not wanted (no API key, payments off, another page of the invoice still open); else why
     * not, the link then opening the club's own page as before.
     */
    public function issue(PaymentLink $link): ?string
    {
        $integration = $this->online->integration();
        if (! PaymobQuickLink::configured($integration) || ! PaymobClient::takesPayments($integration)) {
            return null;
        }
        $prefix = PaymobClient::live($integration) ? OnlinePaymentService::LIVE_PREFIX : OnlinePaymentService::TEST_PREFIX;

        $intent = DB::transaction(function () use ($link, $prefix) {
            $invoice = Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
            $link = PaymentLink::whereKey($link->id)->lockForUpdate()->first();
            if ($link === null || ! $link->opensCheckout() || ! $invoice->isOpen() || Money::isZero($invoice->balance())) {
                return null;
            }
            // A page of the invoice that may still take money: no second one is made at Paymob.
            $open = PaymentIntent::where('invoice_id', $invoice->id)->where('status', PaymentIntent::PENDING)->whereNotNull('checkout_url')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)))
                ->exists();
            if ($open) {
                return null;
            }

            return PaymentIntent::create([
                'invoice_id' => $invoice->id,
                'provider' => 'paymob',
                'reference' => $prefix.Str::upper(Str::random(20)),
                'amount' => $invoice->balance(),
                'currency' => $invoice->currency,
                'status' => PaymentIntent::PENDING,
                'channel' => self::CHANNEL,
                'expires_at' => $link->expires_at,
            ]);
        });
        if ($intent === null) {
            return null;
        }

        try {
            $made = $this->paymob->create($integration, $intent, $intent->invoice, (string) $link->description, route('webhooks.paymob'));
        } catch (BusinessRuleException $e) {
            // Refused, or unanswered: nothing of it is sent (its address was never known), and the link opens the
            // club's own page. Were it paid all the same, its callback finds this attempt with no order to match:
            // held for review, never recorded unasked.
            $intent->forceFill(['status' => PaymentIntent::FAILED, 'failure_reason' => $e->rule])->save();
            $this->audit->log('payment_link.quicklink_failed', $intent->invoice, ['link' => $link->id, 'reason' => $e->rule]);

            return $e->getMessage().' الرابط يفتح صفحة الدفع من موقع النادي.';
        }

        $intent->forceFill(['checkout_url' => $made['url'], 'provider_order_id' => $made['order_id'], 'provider_link_id' => $made['id']])->save();
        $this->audit->log('payment_link.quicklink_created', $intent->invoice, ['link' => $link->id, 'order' => $made['order_id'], 'amount' => $intent->amount]);

        return null;
    }

    /**
     * Close the link's Paymob link before the link is cancelled (under the invoice's lock, its checks passed). Throws,
     * nothing changed, when Paymob does not confirm.
     */
    public function closeAtPaymob(PaymentLink $link): void
    {
        $intent = self::openFor($link->invoice_id, $link->issuedAt());
        if ($intent === null || $intent->provider_link_id === null) {
            return;
        }
        $integration = $this->online->integration();
        if ($integration === null) {
            throw new BusinessRuleException('paymob.quicklink_cancel', 'إعدادات Paymob غير موجودة، فلا يُلغى الرابط لديها. لم يُلغَ شيء.');
        }
        $this->paymob->cancel($integration, $intent->provider_link_id);
        $intent->forceFill(['status' => PaymentIntent::EXPIRED, 'failure_reason' => self::CANCELLED])->save();
        $this->audit->log('payment_link.quicklink_cancelled', $intent->invoice, ['link' => $link->id, 'order' => $intent->provider_order_id]);
    }
}
