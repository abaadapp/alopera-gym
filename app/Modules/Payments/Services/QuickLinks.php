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

    /**
     * Paymob did not answer making the link (a timeout, or an answer without its order or address): it may or may not
     * exist there — and Paymob may text it to the customer. Kept pending under this reason until its time is over or
     * the staff checked Paymob: no page of the invoice is opened and the desk takes nothing meanwhile (owner,
     * 2026-11-21: no second page while its state is not known).
     */
    public const UNCONFIRMED = 'paymob.quicklink_unknown';

    /** An unconfirmed attempt the staff checked at Paymob (and closed there whatever they found) when cancelling. */
    public const UNCONFIRMED_CHECKED = 'quicklink.unknown_checked';

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

    /** The invoice's attempt whose Paymob link may exist, not known (UNCONFIRMED), while its time runs, or null. */
    public static function unconfirmedFor(int $invoiceId, ?Carbon $since = null): ?PaymentIntent
    {
        return PaymentIntent::where('invoice_id', $invoiceId)->where('channel', self::CHANNEL)
            ->where('status', PaymentIntent::PENDING)->whereNull('checkout_url')->where('failure_reason', self::UNCONFIRMED)
            ->where('expires_at', '>', now())
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->latest('id')->first();
    }

    /** What the customer and the desk are told while an attempt's link at Paymob is not known. */
    public static function unconfirmedMessage(PaymentIntent $intent): string
    {
        return 'لم تؤكد Paymob إنشاء رابط الدفع لهذه الفاتورة (المرجع '.$intent->reference.')، وقد يكون أُنشئ لديها. لا تُفتح صفحة دفع ولا تُسجَّل دفعة في الاستقبال حتى تتأكد حالته: '
            .'افحص لوحة Paymob ← روابط الدفع وألغِ أي رابط بهذا المرجع، ثم ألغِ رابط الفاتورة هنا مؤكدًا أنك فحصت.';
    }

    /** The desk takes nothing on an invoice whose Paymob link may still be paid, or may exist without being known. */
    public static function refuseDeskWhileOpen(Invoice $invoice): void
    {
        $open = self::openFor($invoice->id);
        if ($open !== null) {
            throw new BusinessRuleException('payment.quicklink_open', 'لهذه الفاتورة رابط دفع Paymob مفتوح حتى '
                .$open->expires_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i')
                .' وقد يدفع العميل منه. ألغِ الرابط أولًا (روابط الدفع أو صفحة الفاتورة) ثم سجّل الدفعة، حتى لا يُدفع مرتين.');
        }
        if (($unknown = self::unconfirmedFor($invoice->id)) !== null) {
            throw new BusinessRuleException('payment.quicklink_unconfirmed', self::unconfirmedMessage($unknown));
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
            // A page of the invoice that may still take money, or a Paymob link not known: no second one at Paymob.
            $open = PaymentIntent::where('invoice_id', $invoice->id)->where('status', PaymentIntent::PENDING)->whereNotNull('checkout_url')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)))
                ->exists();
            if ($open || self::unconfirmedFor($invoice->id) !== null) {
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
            if ($e->rule === self::UNCONFIRMED) {
                // Not known whether Paymob made it: kept pending for review — no page of the invoice opens and the desk
                // takes nothing until its time is over or the staff checked Paymob (cancel). Were it paid, its callback
                // finds this attempt by our reference with no order to match: held for review, never recorded unasked.
                $intent->forceFill(['failure_reason' => self::UNCONFIRMED])->save();
                $this->audit->log('payment_link.quicklink_unconfirmed', $intent->invoice, ['link' => $link->id, 'reference' => $intent->reference]);

                return self::unconfirmedMessage($intent);
            }
            // Refused: nothing was made there, and the link opens the club's own page.
            $intent->forceFill(['status' => PaymentIntent::FAILED, 'failure_reason' => $e->rule])->save();
            $this->audit->log('payment_link.quicklink_failed', $intent->invoice, ['link' => $link->id, 'reason' => $e->rule]);

            return $e->getMessage().' الرابط يفتح صفحة الدفع من موقع النادي.';
        }

        $intent->forceFill(['checkout_url' => $made['url'], 'provider_order_id' => $made['order_id'], 'provider_link_id' => $made['id']])->save();
        $this->audit->log('payment_link.quicklink_created', $intent->invoice, ['link' => $link->id, 'order' => $made['order_id'], 'amount' => $intent->amount]);

        return null;
    }

    /**
     * Before a link is cancelled, outside any lock (PaymentLinks::cancel): its Paymob link closed at Paymob, and that
     * kept on its attempt at once — whatever the cancel then does, the attempt says what Paymob said, and a cancel
     * tried again finds nothing left to close. Throws, nothing changed, when Paymob does not confirm. An attempt whose
     * link is not known (UNCONFIRMED) ends only once the staff say they checked Paymob ($paymobChecked).
     */
    public function closeAtPaymob(PaymentLink $link, bool $paymobChecked = false): void
    {
        $intent = self::openFor($link->invoice_id, $link->issuedAt());
        if ($intent !== null && $intent->provider_link_id !== null) {
            $integration = $this->online->integration();
            if ($integration === null) {
                throw new BusinessRuleException('paymob.quicklink_cancel', 'إعدادات Paymob غير موجودة، فلا يُلغى الرابط لديها. لم يُلغَ شيء.');
            }
            $this->paymob->cancel($integration, $intent->provider_link_id);
            $intent->forceFill(['status' => PaymentIntent::EXPIRED, 'failure_reason' => self::CANCELLED])->save();
            $this->audit->log('payment_link.quicklink_cancelled', $intent->invoice, ['link' => $link->id, 'order' => $intent->provider_order_id]);
        }

        $unknown = self::unconfirmedFor($link->invoice_id, $link->issuedAt());
        if ($unknown !== null) {
            if (! $paymobChecked) {
                throw new BusinessRuleException('payment_link.quicklink_unconfirmed', self::unconfirmedMessage($unknown));
            }
            $unknown->forceFill(['status' => PaymentIntent::FAILED, 'failure_reason' => self::UNCONFIRMED_CHECKED])->save();
            $this->audit->log('payment_link.quicklink_checked', $unknown->invoice, ['link' => $link->id, 'reference' => $unknown->reference]);
        }
    }
}
