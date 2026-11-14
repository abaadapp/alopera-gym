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
use App\Modules\Inventory\Models\Sale;
use App\Modules\Payments\Events\OnlinePaymentFailed;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobClient;
use App\Modules\Payments\Paymob\PaymobSignature;
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

    public const TEST_PREFIX = 'PT-';

    /**
     * A checkout ends at expires_at, but its money can still be on its way (a payment finished just before, a callback
     * sent again): the account's mode, keys and integrations stay as they are this long after.
     */
    public const LATE_CALLBACK_MINUTES = 60;

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
    public function start(Invoice $invoice, string $channel = 'portal'): PaymentIntent
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
        if (! $invoice->isOpen() || Money::isZero($invoice->balance())) {
            throw new BusinessRuleException('invoice.not_open', 'لا يوجد مبلغ مستحق على هذه الفاتورة.');
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

        // Given again only if opened in the same environment.
        $open = PaymentIntent::where('invoice_id', $invoice->id)->where('status', PaymentIntent::PENDING)->whereNotNull('checkout_url')
            ->where('reference', 'like', $prefix.'%')
            ->where('expires_at', '>', now()->addMinutes(10))->where('expires_at', '<=', $expires)->latest('id')->first();
        if ($open && Money::cmp($open->amount, $invoice->balance()) === 0) {
            return $open;
        }

        $intent = PaymentIntent::create([
            'invoice_id' => $invoice->id,
            'provider' => 'paymob',
            'reference' => $prefix.Str::upper(Str::random(20)),
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::PENDING,
            'channel' => $channel,
            'expires_at' => $expires,
        ]);

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

                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'integration_mismatch', $payload, $ip);
            }

            // The reference that found the intent is not signed; the order id is, and it is the only signed field that
            // names this intent. A signed callback of another order (same amount) is not let onto it, and an intent
            // Paymob gave no order has nothing signed to be told by: no callback is its money (asked again under the
            // intent's lock before any is recorded).
            $signedOrder = (string) Arr::get($obj, 'order.id', '');
            if (($why = self::orderProblem($intent, $signedOrder)) !== null) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_rejected', $intent, ['reason' => $why, 'order' => $signedOrder]));

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
                $outcome = DB::transaction(function () use ($intent, $transactionId, $signedOrder, $integration, $obj) {
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

                    $result = $this->audit->as('gateway', null, 'paymob', fn () => $this->payments->recordGatewayPayment(
                        $intent->invoice()->firstOrFail(), $intent->amount, 'paymob', $transactionId, $intent->reference
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

                    return $result['created'] ? 'processed' : 'duplicate';
                });
            } catch (Throwable $e) {
                report($e);
                $outcome = $this->recorded($transactionId, $intent->reference) ? 'recorded_with_error' : 'error';
            }

            if ($outcome === 'error') {
                return $this->log($intent->tenant_id, $transactionId, $reference, true, $outcome, $payload, $ip);
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
