<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobClient;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Online payment of club invoices through Paymob.
 *
 * The browser returning from the payment page proves nothing and changes
 * nothing. Only a webhook with a valid HMAC, for the exact amount of a known
 * intent, records a payment — once.
 */
final class OnlinePaymentService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymobClient $paymob,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {
    }

    public function integration(): ?TenantIntegration
    {
        return TenantIntegration::where('provider', TenantIntegration::PAYMOB)->first();
    }

    public function available(): bool
    {
        return PaymobClient::ready($this->integration());
    }

    public function start(Invoice $invoice, string $channel = 'portal'): PaymentIntent
    {
        $tenant = $this->tenants->require();
        $integration = $this->integration();

        if (! PaymobClient::ready($integration)) {
            throw new BusinessRuleException('paymob.unavailable', 'الدفع الإلكتروني غير مفعّل لهذا النادي حاليًا. يمكنك الدفع في الاستقبال.');
        }
        if (! $invoice->isOpen() || Money::isZero($invoice->balance())) {
            throw new BusinessRuleException('invoice.not_open', 'لا يوجد مبلغ مستحق على هذه الفاتورة.');
        }

        $intent = PaymentIntent::create([
            'invoice_id' => $invoice->id,
            'provider' => 'paymob',
            'reference' => 'PI-'.Str::upper(Str::random(20)),
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::PENDING,
            'channel' => $channel,
            'expires_at' => now()->addSeconds((int) config('services.paymob.intent_ttl_seconds', 3600)),
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

                return $this->log($intent->tenant_id, $transactionId, $reference, false, 'rejected_signature', $payload, $ip);
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
                }

                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'failed_payment', $payload, $ip);
            }

            $amountMinor = (int) Arr::get($obj, 'amount_cents', -1);
            $currency = (string) Arr::get($obj, 'currency', '');
            if ($amountMinor !== Money::toMinor($intent->amount) || strtoupper($currency) !== strtoupper($intent->currency)) {
                $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('payment.webhook_amount_mismatch', $intent, [
                    'expected' => Money::toMinor($intent->amount), 'received' => $amountMinor, 'currency' => $currency,
                ]));

                return $this->log($intent->tenant_id, $transactionId, $reference, true, 'amount_mismatch', $payload, $ip);
            }

            $outcome = DB::transaction(function () use ($intent, $transactionId) {
                $intent = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

                $result = $this->audit->as('gateway', null, 'paymob', fn () => $this->payments->recordGatewayPayment(
                    $intent->invoice()->firstOrFail(), $intent->amount, 'paymob', $transactionId, $intent->reference
                ));

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

            return $this->log($intent->tenant_id, $transactionId, $reference, true, $outcome, $payload, $ip);
        });
    }

    /** @param array<string, mixed> $payload */
    private function log(?int $tenantId, string $transactionId, string $reference, bool $valid, string $outcome, array $payload, ?string $ip): string
    {
        // Card data is never in Paymob callbacks beyond the masked PAN; keep the payload for investigations.
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
}
