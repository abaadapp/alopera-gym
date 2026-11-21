<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Payments\Events\OnlinePaymentFailed;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentLinkHistory;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobClient;
use App\Modules\Payments\Paymob\PaymobSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Online payment of club invoices through Paymob.
 *
 * The browser returning from the payment page proves nothing and changes
 * nothing. Only a webhook with a valid HMAC, for the exact amount of a known
 * intent, records a payment — once. And only what that HMAC signs binds it to
 * the intent: one of the club's own integrations, the intent's Paymob order,
 * money captured (an authorization alone is not), and a transaction not
 * already in the books for another payment.
 *
 * Test and live money never mix on the production server: a checkout is opened there only by a live integration
 * (PaymobClient::live), each intent carries the environment it was opened in (its reference: PL- live, PT- test), and a
 * callback moves money there only for a live intent of a live integration.
 */
final class OnlinePaymentService
{
    /** The environment an intent was opened in, written once in its reference. Older references (PI-) do not say. */
    public const LIVE_PREFIX = 'PL-';

    /**
     * How long a checkout being opened (claimed, Paymob not answered yet) keeps a second one from being opened for the
     * same invoice: longer than any wait for Paymob (PaymobClient waits 20 seconds at most).
     */
    public const OPENING_SECONDS = 60;

    public const TEST_PREFIX = 'PT-';

    /**
     * A checkout ends at expires_at, but its money can still be on its way (a payment finished just before, a callback
     * sent again): the account's mode, keys and integrations stay as they are this long after.
     */
    public const LATE_CALLBACK_MINUTES = 60;

    /**
     * A signed «pending» callback (the bank still processing the payment) keeps any new checkout of its invoice from
     * opening until its final result comes — however long that takes, since no time proves it failed. After this long
     * without one it is handed to staff (a PaymentReview «pending», with an alert), still without a second checkout.
     */
    public const PENDING_AT_BANK_HOURS = 24;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymobClient $paymob,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    public function integration(): ?TenantIntegration
    {
        return TenantIntegration::where('provider', TenantIntegration::PAYMOB)->first();
    }

    public function available(): bool
    {
        return PaymobClient::takesPayments($this->integration());
    }

    /** Why online payments are off here, for the staff (null while available()). */
    public function unavailableReason(): ?string
    {
        return PaymobClient::unavailableReason($this->integration());
    }

    /**
     * Checkouts whose money may still arrive: opened (or being opened) and not past their end by LATE_CALLBACK_MINUTES.
     * While there are any, the account's mode, keys and integrations are not changed under them.
     */
    public function openCheckouts(): int
    {
        return PaymentIntent::query()
            ->where(fn ($q) => $q->where('status', PaymentIntent::PENDING)
                ->orWhere(fn ($q) => $q->where('status', PaymentIntent::FAILED)->whereNotNull('checkout_url')))
            ->where(fn ($q) => $q->where('expires_at', '>', now()->subMinutes(self::LATE_CALLBACK_MINUTES))
                ->orWhere(fn ($q) => $q->whereNull('expires_at')->where('created_at', '>', now()->subDay())))
            ->count();
    }

    /**
     * The checkout for what the invoice still owes. A checkout already opened for that same amount and still valid is
     * given again rather than a second one, so a customer who opens the link twice cannot pay the invoice twice.
     */
    public function start(Invoice $invoice, string $channel = 'portal', ?string $viaToken = null): PaymentIntent
    {
        $tenant = $this->tenants->require();
        $integration = $this->integration();

        if (! PaymobClient::ready($integration)) {
            throw new BusinessRuleException('paymob.unavailable', 'الدفع الإلكتروني غير مفعّل لهذا النادي حاليًا. يمكنك الدفع في الاستقبال.');
        }
        // A test account never opens a checkout for a real invoice on the production server.
        if (! PaymobClient::takesPayments($integration)) {
            throw new BusinessRuleException('paymob.test_mode', 'الدفع الإلكتروني غير مفعّل لهذا النادي حاليًا. يمكنك الدفع في الاستقبال.');
        }
        $live = PaymobClient::live($integration);
        $prefix = $live ? self::LIVE_PREFIX : self::TEST_PREFIX;
        // An earlier attempt the bank left undecided for a day is handed to staff first (outside the invoice's lock:
        // the review is written under the intent's, then the invoice's, as the callback takes them).
        $this->awaitsBank($invoice);

        // Under the invoice's lock: what it owes now, and the one checkout for it — given again, being opened by another
        // request (two tabs, a double press), or claimed here. Paymob itself is asked after the lock is let go, so a
        // slow gateway keeps no one waiting on the invoice. The lock is the one the callback takes after its intent's
        // (intent → invoice); nothing here waits on an intent's row, so the two never wait on each other.
        [$invoice, $intent] = DB::transaction(function () use ($invoice, $prefix, $channel, $viaToken) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isOpen() || Money::isZero($invoice->balance())) {
                throw new BusinessRuleException('invoice.not_open', 'لا يوجد مبلغ مستحق على هذه الفاتورة.');
            }
            // Paymob confirmed money for this invoice that did not match it, and the club is checking it (PaymentReview);
            // or the bank still processes an earlier payment of it: no second checkout, or the customer could pay twice.
            if (PaymentReview::where('invoice_id', $invoice->id)->open()->exists()) {
                throw new BusinessRuleException('paymob.review', PaymentReview::CUSTOMER_MESSAGE);
            }
            if (self::unresolvedAtBank($invoice) !== null) {
                throw new BusinessRuleException('paymob.pending', 'عملية دفع سابقة لهذه الفاتورة ما زالت قيد التنفيذ لدى البنك. انتظر نتيجتها ولا تدفع مرة أخرى، أو تواصل مع النادي.');
            }
            // Confirmed online money already waits on this invoice for the club's review: no second checkout, or the
            // customer would pay twice.
            if (Payment::where('invoice_id', $invoice->id)->gatewayCreditLeft()->exists()) {
                throw new BusinessRuleException('paymob.held', 'وصلنا تأكيد دفعة إلكترونية لهذه الفاتورة وهي قيد المراجعة لدى النادي؛ لا حاجة للدفع مرة أخرى.');
            }
            // A gift bought on the website is paid only once its recipient confirmed her WhatsApp number with a message
            // from it (#192), whichever page the checkout is asked from (the gift's page or the invoice's pay link).
            $gift = $invoice->type === 'gift' ? GiftMembership::where('invoice_id', $invoice->id)->first() : null;
            if ($gift && $gift->source === 'site' && ! $gift->phoneVerified()) {
                throw new BusinessRuleException('gift.phone_unverified', 'أكّد رقم واتساب المستلمة أولًا، ثم ادفع قيمة الهدية.');
            }
            // An invoice with a payment link opens a page only through its current link, until its time (read under the
            // invoice's lock, which making, cancelling and replacing a link take first); an earlier link opens nothing, ever.
            $paymentLink = self::linkFor($invoice, $viaToken);
            // What a link still owes may end in a single baisa (a part paid at the desk): not opened online, never rounded.
            if ($paymentLink && self::singleBaisa($invoice->balance())) {
                throw new BusinessRuleException('payment_link.single_baisa', self::singleBaisaMessage($invoice->balance()));
            }

            // A website order holds its stock only until reserved_until: its checkout must not outlive the hold, or the
            // customer could pay for an order already cancelled whose stock was sold to someone else.
            $expires = now()->addSeconds((int) config('services.paymob.intent_ttl_seconds', 3600));
            $hold = Sale::where('invoice_id', $invoice->id)->where('source', Sale::WEBSITE)->where('status', Sale::PENDING)->value('reserved_until');
            if ($hold !== null) {
                $hold = Carbon::parse($hold);
                if ($hold->lte(now()->addMinute())) {
                    throw new BusinessRuleException('order.not_payable', 'انتهت مهلة الدفع لهذا الطلب.');
                }
                $expires = $hold->lt($expires) ? $hold : $expires;
            }
            // Nor a payment link's page its link: what is paid on it after the link's time is held for review, not applied.
            if ($paymentLink) {
                if ($paymentLink->expires_at->lte(now()->addMinute())) {
                    throw new BusinessRuleException('payment_link.closed', self::closedLinkMessage($paymentLink));
                }
                $expires = $paymentLink->expires_at->lt($expires) ? $paymentLink->expires_at : $expires;
            }

            // The invoice's checkouts, read in ONE statement: the claim of another request and its URL are saved at
            // different moments (the URL after Paymob answers, outside this lock), so asking for "a page" and then for
            // "a claim" in two statements could see neither and open a second checkout.
            $attempts = PaymentIntent::where('invoice_id', $invoice->id)
                ->whereIn('status', [PaymentIntent::PENDING, PaymentIntent::FAILED, PaymentIntent::EXPIRED])->orderByDesc('id')->get();
            // A page handed out (it has a URL) in this environment. A declined card does not close it: Paymob may take
            // another card on it, and its success is recorded (handleWebhook) — so it is still a page of this invoice.
            $pages = $attempts->filter(fn (PaymentIntent $i) => $i->checkout_url !== null && str_starts_with($i->reference, $prefix));

            // Given again: the page for what is owed now, with time left to pay it.
            $open = $pages->first(fn (PaymentIntent $i) => $i->status !== PaymentIntent::EXPIRED && $i->expires_at !== null
                && $i->expires_at->gt(now()->addMinutes(10)) && $i->expires_at->lte($expires) && Money::cmp($i->amount, $invoice->balance()) === 0);
            if ($open) {
                return [$invoice, $open];
            }

            // Any other page of the invoice: nothing here can withdraw it from Paymob, and its end here is no proof
            // that Paymob closed it — a payment may still come through it until LATE_CALLBACK_MINUTES after its end
            // (the window the settings wait for too). A second page beside it would let the customer pay both: none is
            // opened until then. What is paid on it above what is owed stays in the books as the customer's credit,
            // for the club's review (PaymentService), and a late payment is held (holdReason) — never applied twice.
            $live = $pages->filter(fn (PaymentIntent $i) => $i->expires_at === null || $i->expires_at->gt(now()->subMinutes(self::LATE_CALLBACK_MINUTES)))
                ->sortByDesc(fn (PaymentIntent $i) => $i->expires_at?->getTimestamp() ?? PHP_INT_MAX)->first();
            if ($live) {
                throw new BusinessRuleException('paymob.page_open', self::pageOpenMessage($live, $invoice->balance()));
            }

            // Another request claimed this invoice's checkout and is still waiting for Paymob's answer: not a second
            // checkout. A claim older than any wait for Paymob is from a request that died on the way: it was never
            // handed to anyone (no URL), so it is no reason to refuse.
            if ($attempts->contains(fn (PaymentIntent $i) => $i->status === PaymentIntent::PENDING && $i->checkout_url === null && $i->created_at->gt(now()->subSeconds(self::OPENING_SECONDS)))) {
                throw new BusinessRuleException('paymob.opening', 'تُفتح صفحة الدفع لهذه الفاتورة الآن في نافذة أخرى. انتظر لحظات ثم أعد فتح الرابط.');
            }

            return [$invoice, PaymentIntent::create([
                'invoice_id' => $invoice->id,
                'provider' => 'paymob',
                'reference' => $prefix.Str::upper(Str::random(20)),
                'amount' => $invoice->balance(),
                'currency' => $invoice->currency,
                'status' => PaymentIntent::PENDING,
                'channel' => $channel,
                'expires_at' => $expires,
            ])];
        });
        if ($intent->checkout_url !== null) {
            return $intent;
        }

        try {
            $checkout = $this->paymob->createIntention(
                $integration,
                $intent,
                $invoice,
                route('site.pay.return', ['club' => $tenant->slug, 'reference' => $intent->reference]),
                route('webhooks.paymob'),
            );
        } catch (BusinessRuleException $e) {
            $intent->forceFill(['status' => PaymentIntent::FAILED, 'failure_reason' => $e->rule])->save();
            throw $e;
        }

        // Paymob signs the order of every callback, never the reference: a checkout without an order could not be told
        // apart from another order's payment, so it is never offered (the customer pays at the desk instead).
        if ($checkout['order_id'] === null) {
            $intent->forceFill(['status' => PaymentIntent::FAILED, 'failure_reason' => 'paymob.no_order'])->save();
            throw new BusinessRuleException('paymob.no_order', 'تعذّر فتح صفحة الدفع الآن. يمكنك الدفع في الاستقبال.');
        }

        // The account changed while Paymob was opening the checkout (another environment, or no longer taking money):
        // the page is not handed out under settings it was not opened with.
        $now = TenantIntegration::whereKey($integration->id)->first();
        if (! PaymobClient::takesPayments($now) || PaymobClient::live($now) !== $live) {
            $intent->forceFill(['status' => PaymentIntent::FAILED, 'failure_reason' => 'paymob.mode_changed'])->save();
            throw new BusinessRuleException('paymob.mode_changed', 'تعذّر فتح صفحة الدفع الآن. يمكنك الدفع في الاستقبال.');
        }

        $intent->forceFill(['checkout_url' => $checkout['url'], 'provider_order_id' => $checkout['order_id']])->save();

        return $intent;
    }

    /**
     * An amount ending in a single baisa (4.725). Until Paymob's test environment shows such an amount charged and called
     * back to the baisa (a pre-launch condition, docs/REMAINING.md), a payment link does not take it online: the card may
     * be charged a rounded amount, and the callback's exact check would then leave it unrecorded. Nothing is rounded.
     */
    public static function singleBaisa(string $amount): bool
    {
        return Money::toMinor($amount) % 10 !== 0;
    }

    public static function singleBaisaMessage(string $amount): string
    {
        return 'المبلغ المستحق '.$amount.' ينتهي ببيسة مفردة، والدفع الإلكتروني لمثل هذا المبلغ غير متاح مؤقتًا. ادفع في الاستقبال أو اطلب من النادي رابطًا جديدًا.';
    }

    /** What the customer is told by a payment link that no longer opens a page. */
    public static function closedLinkMessage(PaymentLink|PaymentLinkHistory $link): string
    {
        return match (true) {
            $link instanceof PaymentLinkHistory && $link->replaced_at !== null => 'هذا رابط دفع قديم حلّ محله رابط أحدث. اطلب من النادي الرابط الجديد، أو ادفع في الاستقبال.',
            $link->cancelled_at !== null => 'أُلغي رابط الدفع هذا. تواصل مع النادي إن كان عليك مبلغ.',
            default => 'انتهت صلاحية رابط الدفع هذا. اطلب من النادي رابطًا جديدًا، أو ادفع في الاستقبال.',
        };
    }

    /**
     * The payment link a page of this invoice opens under: its current link (the invoice's one link row) while it runs —
     * asked from that link's own address ($viaToken, which must be the row's) or from the invoice's (the member's invoice
     * message, the portal). Null when the invoice never had a link (its pay page works as before). An earlier link's
     * address (PaymentLinkHistory) opens nothing, ever; nor does the row once cancelled or past its time, until a new link
     * takes its place.
     *
     * @throws BusinessRuleException payment_link.closed, with what the customer is told
     */
    public static function linkFor(Invoice $invoice, ?string $viaToken = null): ?PaymentLink
    {
        $row = PaymentLink::currentFor($invoice->id);
        if ($viaToken !== null && ($row === null || $row->token === null || ! hash_equals($row->token, $viaToken))) {
            $earlier = PaymentLinkHistory::where('invoice_id', $invoice->id)->where('token', $viaToken)->first();
            throw new BusinessRuleException('payment_link.closed', $earlier ? self::closedLinkMessage($earlier) : 'رابط الدفع هذا لم يعد صالحًا. اطلب من النادي رابطًا جديدًا، أو ادفع في الاستقبال.');
        }
        if ($row !== null && ! $row->opensCheckout()) {
            throw new BusinessRuleException('payment_link.closed', self::closedLinkMessage($row));
        }

        return $row;
    }

    /**
     * What the customer is told when a page of the invoice handed out earlier may still take money: that it exists,
     * what is owed now, and the two ways on — wait until a new link can be opened, or pay at the desk. Paying on the old
     * page is offered only when it asks exactly what is owed; otherwise the customer is told not to, or they would pay
     * more than they owe.
     */
    private static function pageOpenMessage(PaymentIntent $page, string $owed): string
    {
        $tz = (string) config('app.timezone');
        $after = $page->expires_at?->copy()->addMinutes(self::LATE_CALLBACK_MINUTES)->timezone($tz);
        $wait = $after ? 'انتظر حتى الساعة '.$after->format('H:i').($after->isToday() ? '' : ' من يوم '.$after->format('Y-m-d')).' ثم افتح الرابط من جديد' : 'انتظر ثم افتح الرابط من جديد';

        if (Money::cmp($page->amount, $owed) === 0) {
            return 'يوجد رابط دفع سابق لهذه الفاتورة بالمبلغ نفسه ('.$owed.' '.$page->currency.'). أكمل الدفع منه إن كان ما زال مفتوحًا عندك، أو '
                .$wait.'، أو ادفع في الاستقبال. لا تُفتح صفحة دفع ثانية حتى لا يُخصم المبلغ مرتين.';
        }

        return 'يوجد رابط دفع سابق لهذه الفاتورة بمبلغ '.$page->amount.' '.$page->currency.' فُتح قبل أن يتغير المتبقي، والمتبقي الآن '
            .$owed.' '.$page->currency.'. لا تدفع من الرابط السابق حتى لا يُخصم منك غير المتبقي. '
            .$wait.' لتدفع المتبقي وحده، أو ادفعه الآن في الاستقبال.';
    }

    /**
     * Process a Paymob transaction callback.
     *
     * @param  array<string, mixed>  $payload
     * @return string outcome code (also stored in payment_webhook_events)
     */
    public function handleWebhook(array $payload, ?string $hmac, ?string $ip = null): string
    {
        $obj = (array) Arr::get($payload, 'obj', []);
        $reference = (string) (Arr::get($obj, 'order.merchant_order_id') ?? Arr::get($obj, 'special_reference') ?? '');
        $orderId = (string) Arr::get($obj, 'order.id', '');
        $transactionId = (string) Arr::get($obj, 'id', '');

        // The callback is not tied to a club URL; find the intent first, then verify with that club's secret.
        $intent = $this->tenants->bypass(fn () => PaymentIntent::query()
            ->when($reference !== '', fn ($q) => $q->where('reference', $reference))
            ->when($reference === '' && $orderId !== '', fn ($q) => $q->where('provider_order_id', $orderId))
            ->when($reference === '' && $orderId === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first());

        if (! $intent) {
            return $this->log(null, $transactionId, $reference, false, 'unknown_intent', $payload, $ip);
        }

        $tenant = Tenant::find($intent->tenant_id);

        return $this->tenants->run($tenant, function () use ($intent, $obj, $hmac, $transactionId, $reference, $payload, $ip) {
            $integration = $this->integration();
            $valid = PaymobSignature::verify($obj, $hmac, $integration?->credential('hmac_secret'));

            if (! $valid) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, ['reason' => 'signature']));
                $integration?->markFailed('وصل إشعار من Paymob بتوقيع غير صحيح، فرُفض. تحقق من HMAC secret.');

                return $this->log($intent->tenant_id, $transactionId, $reference, false, 'rejected_signature', $payload, $ip);
            }

            $integration->markWebhook();

            // On the production server, money only from a live checkout of a live account — asked before anything
            // changes, a decline included (a test decline neither fails a real attempt nor messages a real customer).
            if (($why = self::environmentProblem($intent, $integration, $obj)) !== null) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, ['reason' => $why]));

                return $this->log($intent->tenant_id, $transactionId, $reference, true, $why, $payload, $ip);
            }

            // Money through one of this club's own integrations (cards, Apple Pay…) only. The id is signed; the list
            // is the club's, never the callback's. A callback naming none is not taken for one.
            $signedIntegration = Arr::get($obj, 'integration_id');
            $integrationId = is_int($signedIntegration) || (is_string($signedIntegration) && ctype_digit($signedIntegration)) ? (int) $signedIntegration : 0;
            if (! in_array($integrationId, PaymobClient::integrationIds($integration), true)) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, [
                    'reason' => 'integration', 'integration' => is_scalar($signedIntegration) ? (string) $signedIntegration : null,
                ]));
                if (! $this->reviewIfCaptured($intent, $obj, 'integration', $transactionId)) {
                    return $this->log($intent->tenant_id, $transactionId, $reference, true, 'error', $payload, $ip);
                }

                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'integration_mismatch', $payload, $ip);
            }

            // The reference that found the intent is not signed; the order id is, and it is the only signed field that
            // names this intent. A signed callback of another order (same amount) is not let onto it, and an intent
            // Paymob gave no order has nothing signed to be told by: no callback is its money (asked again under the
            // intent's lock before any is recorded).
            $signedOrder = (string) Arr::get($obj, 'order.id', '');
            if (($why = self::orderProblem($intent, $signedOrder)) !== null) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, ['reason' => $why, 'order' => $signedOrder]));
                if (! $this->reviewIfCaptured($intent, $obj, 'order', $transactionId)) {
                    return $this->log($intent->tenant_id, $transactionId, $reference, true, 'error', $payload, $ip);
                }

                return $this->log($intent->tenant_id, $transactionId, $reference, true, $why, $payload, $ip);
            }

            // A refund or void made on Paymob's side arrives as its own (child) transaction, signed and successful, for
            // the same order: it is money going back, never a new payment. Refunds are confirmed by finance (the
            // pending refund's reference), so it is only logged here.
            $moneyBack = Arr::get($obj, 'is_refund') === true || Arr::get($obj, 'is_void') === true
                || (Arr::get($obj, 'has_parent_transaction') === true && Arr::get($obj, 'is_capture') !== true);
            if ($moneyBack) {
                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'ignored_refund', $payload, $ip);
            }

            $succeeded = Arr::get($obj, 'success') === true
                && Arr::get($obj, 'pending') !== true
                && Arr::get($obj, 'is_voided') !== true
                && Arr::get($obj, 'is_refunded') !== true
                && Arr::get($obj, 'error_occured') !== true;

            // The bank has not decided yet (a signed «pending»): neither a failure — no «nothing was taken», no failure
            // message or alert — nor money. The attempt stays open; its final callback decides it, and meanwhile no
            // second checkout opens for the invoice (start()).
            if (! $succeeded && self::stillAtBank($obj)) {
                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'pending', $payload, $ip);
            }

            if (! $succeeded) {
                if ($intent->status === PaymentIntent::PENDING) {
                    $intent->forceFill([
                        'status' => PaymentIntent::FAILED,
                        'provider_transaction_id' => $transactionId,
                        'failure_reason' => Str::limit((string) Arr::get($obj, 'data.message', 'declined'), 250),
                    ])->save();
                    OnlinePaymentFailed::dispatch($intent);
                    app(StaffAlerts::class)->paymentFailed($intent);
                }
                // The bank decided: an attempt handed to staff for being «pending» ends on this signed decline.
                $this->endPendingReviews($intent, PaymentReview::NOT_COLLECTED, $transactionId);

                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'failed_payment', $payload, $ip);
            }

            // An authorization only holds the money on the card; its capture takes it, as a transaction of its own.
            // Counting both would record one payment twice (the second as the customer's credit). Nothing changes on
            // the intent: the capture, or the end of the checkout, decides it.
            if (self::flag($obj, 'is_auth') && ! self::flag($obj, 'is_capture')) {
                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'authorized_only', $payload, $ip);
            }

            $amountMinor = (int) Arr::get($obj, 'amount_cents', -1);
            $currency = (string) Arr::get($obj, 'currency', '');
            if ($amountMinor !== Money::toMinor($intent->amount) || strtoupper($currency) !== strtoupper($intent->currency)) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_amount_mismatch', $intent, [
                    'expected' => Money::toMinor($intent->amount), 'received' => $amountMinor, 'currency' => $currency,
                ]));
                $why = strtoupper($currency) !== strtoupper($intent->currency) ? 'currency' : 'amount';
                if (! $this->reviewIfCaptured($intent, $obj, $why, $transactionId)) {
                    return $this->log($intent->tenant_id, $transactionId, $reference, true, 'error', $payload, $ip);
                }

                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'amount_mismatch', $payload, $ip);
            }

            // Paymob has taken this money. The answer follows what the books hold, not where an error came from:
            // - rolled back (a step of recording it failed): nothing is written (no payment, no stock, no order change),
            //   the signed callback is kept while the database can take it, and the answer is an error (500), never a
            //   success or a redirect;
            // - committed, then work queued for after the commit failed: the payment stands and is answered as recorded;
            //   the error is reported, never turned into «not recorded»;
            // - not known (the database gone): not acknowledged, so a resend finds out; recording is idempotent.
            try {
                $signedAt = is_scalar(Arr::get($obj, 'created_at')) ? (string) Arr::get($obj, 'created_at') : null;
                $outcome = DB::transaction(function () use ($intent, $transactionId, $signedOrder, $integration, $obj, $signedAt) {
                    $intent = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

                    // Read again under the lock, before any money: the order this intent holds now, not when the
                    // callback came in.
                    if (($why = self::orderProblem($intent, $signedOrder)) !== null) {
                        $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, ['reason' => $why, 'order' => $signedOrder]));

                        return $why;
                    }
                    // And the account as it is now: not switched to test (or to other keys) since the callback came in.
                    if (($why = self::environmentProblem($intent, TenantIntegration::whereKey($integration->id)->first(), $obj)) !== null) {
                        $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, ['reason' => $why]));

                        return $why;
                    }

                    // The invoice, then what it pays for (the order every payment takes), and only then the decision: the
                    // money is applied — the membership activated, the order paid — only while all of it still stands as
                    // it was sold; otherwise it is recorded and held for staff, nothing activated.
                    $invoice = Invoice::whereKey($intent->invoice_id)->lockForUpdate()->firstOrFail();
                    $hold = $this->holdReason($intent, $invoice);
                    $result = $this->audit->as('gateway', null, 'paymob', fn () => $this->payments->recordGatewayPayment(
                        $invoice, $intent->amount, 'paymob', $transactionId, $intent->reference, $hold, $signedAt
                    ));

                    // This transaction is already in the books for another payment: never this intent's money, and
                    // never a reason to call it paid.
                    if (! $result['created'] && $result['payment']->reference !== $intent->reference) {
                        $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, [
                            'reason' => 'transaction', 'transaction' => $transactionId, 'payment' => $result['payment']->id,
                        ]));

                        return 'transaction_elsewhere';
                    }

                    if ($intent->status !== PaymentIntent::PAID) {
                        $intent->forceFill([
                            'status' => PaymentIntent::PAID,
                            'payment_id' => $result['payment']->id,
                            'provider_transaction_id' => $transactionId,
                            'paid_at' => now(),
                        ])->save();
                    }

                    return $result['created'] ? ($hold !== null ? 'held_for_review' : 'processed') : 'duplicate';
                });
            } catch (Throwable $e) {
                report($e);
                $outcome = $this->recorded($transactionId, $intent->reference) ? 'recorded_with_error' : 'error';
                // Confirmed, matching money the books could not take: still answered as an error (Paymob may send it
                // again, and the resend records it), but handed to staff meanwhile — told, no second checkout — and the
                // review ends by itself when this transaction is recorded. When even that cannot be written, the error
                // stands as before.
                if ($outcome === 'error') {
                    $this->reviewIfCaptured($intent, $obj, 'error', $transactionId);
                }
            }

            // The order checked again under the lock no longer matched: the same confirmed money, kept for review.
            if (in_array($outcome, ['order_mismatch', 'order_unverified'], true) && ! $this->reviewIfCaptured($intent, $obj, 'order', $transactionId)) {
                $outcome = 'error';
            }

            if ($outcome === 'error') {
                return $this->log($intent->tenant_id, $transactionId, $reference, true, $outcome, $payload, $ip);
            }
            // The money is in the books as this transaction's payment: an attempt handed to staff for being «pending»
            // ends on it.
            if (in_array($outcome, ['processed', 'held_for_review', 'duplicate', 'recorded_with_error'], true)) {
                $this->endPendingReviews($intent, PaymentReview::RECORDED, $transactionId);
            }

            // The payment is in the books: a callback log line that cannot be written does not make it an error.
            try {
                return $this->log($intent->tenant_id, $transactionId, $reference, true, $outcome, $payload, $ip);
            } catch (Throwable $e) {
                report($e);

                return $outcome;
            }
        });
    }

    /**
     * Is this Paymob transaction in the books, as this intent's payment? False when it is not (or is another payment's),
     * or when the database cannot say.
     */
    private function recorded(string $transactionId, string $reference): bool
    {
        if ($transactionId === '') {
            return false;
        }
        try {
            return Payment::where('gateway', 'paymob')->where('gateway_transaction_id', $transactionId)->where('reference', $reference)->exists();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Why confirmed money cannot simply pay this invoice now (null when it can). Read with the invoice locked, and the
     * order and the memberships it pays for locked in turn: the checkout's time is over (asked of our clock when the
     * callback is handled — Paymob's own time is kept for review, never read as the capture's), the invoice is no
     * longer open, the order is cancelled or its hold is over, or a membership is no longer waiting, has ended, or now
     * overlaps another period of the member (an upgrade replaces its previous one, as activate() does). A sale has no
     * overlap when it is made (MembershipService::assertNoOverlap): one found here came after it.
     */
    private function holdReason(PaymentIntent $intent, Invoice $invoice): ?string
    {
        if (! $invoice->isOpen()) {
            return 'invoice_closed';
        }
        if ($intent->expires_at !== null && now()->gt($intent->expires_at)) {
            return 'checkout_expired';
        }

        $sale = Sale::where('invoice_id', $invoice->id)->where('source', Sale::WEBSITE)->lockForUpdate()->first();
        if ($sale && ($sale->status !== Sale::PENDING || $sale->reserved_until === null || now()->gt($sale->reserved_until))) {
            return 'order_closed';
        }

        $items = $invoice->items()->whereIn('itemable_type', ['membership', 'pt_subscription', 'gift_membership'])->get(['itemable_type', 'itemable_id']);
        $today = CarbonImmutable::today();
        $memberships = Membership::whereIn('id', $items->where('itemable_type', 'membership')->pluck('itemable_id')->unique())->orderBy('id')->lockForUpdate()->get();
        foreach ($memberships as $membership) {
            if ($membership->status !== Membership::PENDING) {
                return 'membership_not_pending';
            }
            if ($membership->ends_on->lt($today)) {
                return 'membership_ended';
            }
            if ($membership->kind !== Membership::KIND_UPGRADE && Membership::where('member_id', $membership->member_id)->whereKeyNot($membership->id)
                ->whereIn('status', [Membership::ACTIVE, Membership::SCHEDULED, Membership::FROZEN])
                ->where('starts_on', '<=', $membership->ends_on->toDateString())->where('ends_on', '>=', $membership->starts_on->toDateString())->exists()) {
                return 'membership_overlap';
            }
            // An upgrade is priced less what was left of the membership it replaces: applied only while that one still
            // stands to be replaced (as activate() replaces it), not cancelled or ended since — or its credit could be
            // given twice (off the upgrade, then again when the cancelled one is paid back). Locked after the upgrade,
            // as activate() takes them.
            if ($membership->kind === Membership::KIND_UPGRADE && $membership->previous_membership_id) {
                $previous = Membership::whereKey($membership->previous_membership_id)->lockForUpdate()->first();
                if (! $previous || ! in_array($previous->status, [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED], true)) {
                    return 'membership_previous_closed';
                }
            }
        }
        $subscriptions = PtSubscription::whereIn('id', $items->where('itemable_type', 'pt_subscription')->pluck('itemable_id')->unique())->orderBy('id')->lockForUpdate()->get();
        foreach ($subscriptions as $subscription) {
            if ($subscription->status !== PtSubscription::PENDING) {
                return 'pt_not_pending';
            }
        }
        // A gift (#192) cancelled while its invoice was still open (a desk payment on part of it kept the invoice from
        // being voided): the money is held for review, not applied to a gift nobody can use. Invoice then gift, the
        // order GiftService takes.
        $gifts = GiftMembership::whereIn('id', $items->where('itemable_type', 'gift_membership')->pluck('itemable_id')->unique())->orderBy('id')->lockForUpdate()->get();
        foreach ($gifts as $gift) {
            if ($gift->status !== GiftMembership::PENDING) {
                return 'gift_not_pending';
            }
        }

        return null;
    }

    /**
     * Why this callback's money is not real here: on the production server, an intent opened in test (or a callback
     * that says it is not live, or an account no longer live) is a test payment, and an intent older than the
     * environment mark cannot be shown to be live. Null when it moves money: always off production (nothing is real
     * there); on it, a live intent of a live account. `is_live` is not signed: it can stop money, never let it in.
     *
     * @param  array<string, mixed>  $obj
     */
    private static function environmentProblem(PaymentIntent $intent, ?TenantIntegration $integration, array $obj): ?string
    {
        if (! app()->isProduction()) {
            return null;
        }
        $isLive = Arr::get($obj, 'is_live');
        if (str_starts_with($intent->reference, self::TEST_PREFIX) || ! PaymobClient::live($integration)
            || $isLive === false || (is_string($isLive) && strcasecmp($isLive, 'false') === 0)) {
            return 'test_payment';
        }

        return str_starts_with($intent->reference, self::LIVE_PREFIX) ? null : 'environment_unverified';
    }

    /**
     * Why this signed order is not the intent's: the intent has no Paymob order to check it against, or holds another.
     * Null when it is the intent's own.
     */
    private static function orderProblem(PaymentIntent $intent, string $signedOrder): ?string
    {
        if ($intent->provider_order_id === null || (string) $intent->provider_order_id === '') {
            return 'order_unverified';
        }

        return $signedOrder === (string) $intent->provider_order_id ? null : 'order_mismatch';
    }

    /**
     * A Paymob flag as it arrives: a JSON boolean, or the text "true".
     *
     * @param  array<string, mixed>  $obj
     */
    /**
     * A signed callback that says Paymob took the money: successful, captured (not an authorization alone), not
     * pending, voided, refunded or in error, and not a refund or void of its own. Only such a callback, from a live
     * checkout (environmentProblem is asked before), opens a review when it cannot be recorded.
     */
    private static function captured(array $obj): bool
    {
        return Arr::get($obj, 'success') === true
            && Arr::get($obj, 'pending') !== true
            && Arr::get($obj, 'is_voided') !== true
            && Arr::get($obj, 'is_refunded') !== true
            && Arr::get($obj, 'error_occured') !== true
            && Arr::get($obj, 'is_refund') !== true && Arr::get($obj, 'is_void') !== true
            && ! (Arr::get($obj, 'has_parent_transaction') === true && Arr::get($obj, 'is_capture') !== true)
            && ! (self::flag($obj, 'is_auth') && ! self::flag($obj, 'is_capture'));
    }

    /** A signed «pending»: the bank has not decided, and nothing says it failed (no error, void or refund). */
    private static function stillAtBank(array $obj): bool
    {
        return Arr::get($obj, 'pending') === true
            && Arr::get($obj, 'error_occured') !== true
            && Arr::get($obj, 'is_voided') !== true
            && Arr::get($obj, 'is_refunded') !== true;
    }

    /**
     * The oldest signed «pending» callback of an attempt of this invoice that has no final result yet (the attempt is
     * still pending: a success makes it paid, a decline failed), or null. No time limit: a day without news is not a
     * failure.
     */
    public static function unresolvedAtBank(Invoice $invoice): ?PaymentWebhookEvent
    {
        $references = PaymentIntent::where('invoice_id', $invoice->id)->where('status', PaymentIntent::PENDING)->pluck('reference');

        return $references->isEmpty() ? null : PaymentWebhookEvent::where('tenant_id', $invoice->tenant_id)->whereIn('reference', $references)
            ->where('outcome', 'pending')->where('signature_valid', true)->orderBy('id')->first();
    }

    /**
     * Does an attempt of this invoice wait for the bank's result? When it has waited a day, it is handed to staff now
     * (a PaymentReview «pending», with an alert, once). Asked by start() and by the website orders' expiry, outside any
     * invoice lock.
     */
    public function awaitsBank(Invoice $invoice): bool
    {
        $event = self::unresolvedAtBank($invoice);
        if ($event === null) {
            return false;
        }
        if ($event->created_at->lte(now()->subHours(self::PENDING_AT_BANK_HOURS)) && (string) $event->transaction_id !== '') {
            $intent = PaymentIntent::where('reference', $event->reference)->first();
            if ($intent) {
                $this->keepForReview($intent, (array) ($event->payload['obj'] ?? []), 'pending', (string) $event->transaction_id, whilePending: true);
            }
        }

        return true;
    }

    /**
     * An attempt handed to staff for being «pending» ends once Paymob's signed result for it came: recorded in the books,
     * or declined. Confirmed money that could not be recorded («error») ends only when that same transaction is recorded.
     * Nothing else closes it automatically; moves no money.
     */
    private function endPendingReviews(PaymentIntent $intent, string $outcome, string $transactionId): void
    {
        try {
            DB::transaction(function () use ($intent, $outcome, $transactionId) {
                PaymentReview::where('payment_intent_id', $intent->id)
                    ->where(fn ($q) => $q->where('reason', 'pending')->when($outcome === PaymentReview::RECORDED,
                        fn ($q) => $q->orWhere(fn ($q) => $q->where('reason', 'error')->where('transaction_id', $transactionId))))
                    ->open()->lockForUpdate()->get()
                    ->each(function (PaymentReview $review) use ($outcome, $transactionId) {
                        $review->forceFill([
                            'status' => $outcome,
                            'closed_at' => now(),
                            'closed_by' => null,
                            'close_note' => 'أُغلقت آليًا: وصلت من Paymob نتيجة موقّعة للعملية '.$transactionId.'.',
                        ])->save();
                        $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.review_closed', $review, [
                            'outcome' => $outcome, 'transaction' => $transactionId, 'automatic' => true,
                        ]));
                    });
            });
        } catch (Throwable $e) {
            // The result itself is recorded; the review stays open for staff, whose evidence is now there.
            report($e);
        }
    }

    /**
     * Confirmed money that cannot be recorded as this intent's payment is kept for staff (PaymentReview), once per
     * Paymob transaction, with what they need to find it at Paymob: never the card's number, never a secret. True when
     * there is nothing to keep (not confirmed money) or it is kept; false when it could not be written — the callback
     * is then not acknowledged (an error, 500) so that Paymob sends it again, and the resend finds or writes the same row.
     */
    private function reviewIfCaptured(PaymentIntent $intent, array $obj, string $reason, string $transactionId): bool
    {
        if (! self::captured($obj) || $transactionId === '') {
            return true;
        }

        return $this->keepForReview($intent, $obj, $reason, $transactionId);
    }

    /**
     * Write (or find) the review of this Paymob transaction. With $whilePending, only while the attempt is still pending
     * and undecided (asked again under its lock: a result may just have come). A review handed over for «pending» whose
     * transaction is then confirmed with a mismatch becomes that mismatch's review (and staff are told again).
     */
    private function keepForReview(PaymentIntent $intent, array $obj, string $reason, string $transactionId, bool $whilePending = false): bool
    {
        try {
            $amount = Arr::get($obj, 'amount_cents');
            $currency = Arr::get($obj, 'currency');
            $integration = Arr::get($obj, 'integration_id');
            $brand = Arr::get($obj, 'source_data.sub_type');
            $values = [
                'payment_intent_id' => $intent->id,
                'invoice_id' => $intent->invoice_id,
                'provider_order_id' => is_scalar(Arr::get($obj, 'order.id')) ? Str::limit((string) Arr::get($obj, 'order.id'), 60, '') : null,
                'integration_id' => is_scalar($integration) ? Str::limit((string) $integration, 30, '') : null,
                'reason' => $reason,
                'expected_minor' => Money::toMinor($intent->amount),
                'received_minor' => is_int($amount) || (is_string($amount) && ctype_digit($amount)) ? (int) $amount : null,
                'expected_currency' => $intent->currency,
                'received_currency' => is_scalar($currency) ? Str::limit((string) $currency, 8, '') : null,
                'card_brand' => is_string($brand) ? Str::limit($brand, 30, '') : null,
                'status' => PaymentReview::OPEN,
            ];
            // Under the invoice's lock, as start() and the website order's expiry read it: a checkout or a cancellation
            // asked at the same moment sees the review, or comes before it. Taken in the callback's order (intent, then
            // invoice): the row written names the intent, and a callback recording money holds that intent first.
            [$review, $changed] = DB::transaction(function () use ($intent, $transactionId, $values, $reason, $whilePending) {
                $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
                $invoice = Invoice::whereKey($intent->invoice_id)->lockForUpdate()->firstOrFail();
                if ($whilePending && ($locked->status !== PaymentIntent::PENDING || self::unresolvedAtBank($invoice) === null)) {
                    return [null, false];
                }
                $review = PaymentReview::createOrFirst(['gateway' => 'paymob', 'transaction_id' => $transactionId], $values);
                if (! $review->wasRecentlyCreated && $review->status === PaymentReview::OPEN && $review->reason === 'pending' && $reason !== 'pending') {
                    $review->forceFill(Arr::except($values, ['payment_intent_id', 'invoice_id', 'status']))->save();

                    return [$review, true];
                }

                return [$review, false];
            });
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        if ($review === null) {
            return true;
        }
        if ($review->wasRecentlyCreated || $changed) {
            try {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.review_opened', $intent, [
                    'review' => $review->id, 'reason' => $reason, 'transaction' => $transactionId,
                    'expected' => $review->expected_minor, 'received' => $review->received_minor, 'currency' => $review->received_currency,
                ]));
                app(StaffAlerts::class)->paymentReview($review);
            } catch (Throwable $e) {
                // The row is written: a missed log line or alert does not lose it (the invoice page shows it).
                report($e);
            }
        }

        return true;
    }

    private static function flag(array $obj, string $key): bool
    {
        $value = Arr::get($obj, $key);

        return $value === true || (is_string($value) && strcasecmp($value, 'true') === 0);
    }

    /** @param array<string, mixed> $payload */
    private function log(?int $tenantId, string $transactionId, string $reference, bool $valid, string $outcome, array $payload, ?string $ip): string
    {
        // Card data is never in Paymob callbacks beyond the masked PAN; keep the payload for investigations. One that is
        // not signed, or names no payment of ours, could come from anyone with a body of any size: only what identifies it.
        if (! $valid) {
            $payload = self::identifying($payload);
        }
        PaymentWebhookEvent::create([
            'tenant_id' => $tenantId,
            'provider' => 'paymob',
            'transaction_id' => $transactionId ?: null,
            'reference' => $reference ?: null,
            'signature_valid' => $valid,
            'outcome' => $outcome,
            'payload' => $payload,
            'ip_address' => $ip,
        ]);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the type, transaction, order, amount and result of a callback, nothing else
     */
    private static function identifying(array $payload): array
    {
        $keep = fn (array $from, array $keys) => array_filter(Arr::only($from, $keys), fn ($v) => is_scalar($v) && strlen((string) $v) <= 120);
        $obj = (array) Arr::get($payload, 'obj', []);
        $order = $keep((array) Arr::get($obj, 'order', []), ['id', 'merchant_order_id']);

        return array_filter([
            'type' => is_scalar($payload['type'] ?? null) ? mb_substr((string) $payload['type'], 0, 40) : null,
            'obj' => $keep($obj, ['id', 'amount_cents', 'currency', 'success', 'pending', 'special_reference']) + ($order ? ['order' => $order] : []),
        ], fn ($v) => $v !== null && $v !== []) + ['trimmed' => true];
    }
}
