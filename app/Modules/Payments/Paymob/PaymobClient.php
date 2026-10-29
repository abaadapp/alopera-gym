<?php

namespace App\Modules\Payments\Paymob;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Paymob "Intention" API (unified checkout), Oman region by default.
 *
 * Per club: secret key, public key, HMAC secret and one or more integration
 * ids (card, Apple Pay…) from the club's own Paymob dashboard.
 */
final class PaymobClient
{
    public const REQUIRED = ['secret_key', 'public_key', 'hmac_secret', 'integration_ids'];

    public static function ready(?TenantIntegration $integration): bool
    {
        if (! $integration || ! $integration->is_enabled) {
            return false;
        }

        foreach (self::REQUIRED as $key) {
            if ($integration->credential($key) === null) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    public static function integrationIds(TenantIntegration $integration): array
    {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $integration->credential('integration_ids')))));
    }

    /**
     * Open a checkout and return its URL.
     *
     * @return array{url: string, order_id: string|null}
     */
    public function createIntention(TenantIntegration $integration, PaymentIntent $intent, Invoice $invoice, string $returnUrl, string $notificationUrl): array
    {
        $base = rtrim((string) config('services.paymob.base_url'), '/');
        $nameParts = preg_split('/\s+/', trim($invoice->customer_name)) ?: ['Member'];

        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$integration->credential('secret_key')])
                ->acceptJson()
                ->timeout(20)
                ->post($base.'/v1/intention/', [
                    'amount' => Money::toMinor($intent->amount),
                    'currency' => $intent->currency,
                    'payment_methods' => self::integrationIds($integration),
                    'items' => [[
                        'name' => Str::limit('Invoice '.$invoice->number, 50, ''),
                        'amount' => Money::toMinor($intent->amount),
                        'quantity' => 1,
                    ]],
                    'billing_data' => [
                        'first_name' => Str::limit($nameParts[0] ?: 'Member', 50, ''),
                        'last_name' => Str::limit($nameParts[count($nameParts) - 1] ?: 'Member', 50, ''),
                        'phone_number' => '+'.ltrim((string) ($invoice->customer_phone ?: '96800000000'), '+'),
                        // Paymob rejects an empty/invalid email; example.com is reserved and never delivers (RFC 2606).
                        'email' => $invoice->customer_email ?: 'no-email@example.com',
                        'country' => 'OM',
                    ],
                    'special_reference' => $intent->reference,
                    'expiration' => (int) config('services.paymob.intent_ttl_seconds', 3600),
                    'notification_url' => $notificationUrl,
                    'redirection_url' => $returnUrl,
                ]);
        } catch (ConnectionException $e) {
            $integration->markFailed('تعذّر الاتصال بخادم Paymob عند فتح صفحة دفع.');
            throw new BusinessRuleException('paymob.connection', 'تعذّر الاتصال ببوابة الدفع. حاول مرة أخرى.');
        }

        $clientSecret = (string) $response->json('client_secret', '');
        if (! $response->successful() || $clientSecret === '') {
            $integration->markFailed('رفضت Paymob فتح صفحة دفع: '.self::reason($response));
            throw new BusinessRuleException('paymob.rejected', 'رفضت بوابة الدفع فتح عملية الدفع. تواصل مع النادي.');
        }
        $integration->markVerified();

        return [
            'url' => $base.'/unifiedcheckout/?publicKey='.urlencode((string) $integration->credential('public_key')).'&clientSecret='.urlencode($clientSecret),
            'order_id' => $response->json('intention_order_id') !== null ? (string) $response->json('intention_order_id') : null,
        ];
    }

    /**
     * Check the club's keys with Paymob itself: open a checkout of 1.000 that nobody is sent to. Paymob answers with
     * a client secret only when the secret key and the integration ids are valid. No money moves and the checkout
     * expires on its own; were it ever paid, the callback would find no invoice and be logged as unknown.
     * The HMAC secret cannot be checked this way: the first signed callback proves it.
     *
     * @return array{ok: bool, message: string}
     */
    public function probe(TenantIntegration $integration, string $currency): array
    {
        if (self::integrationIds($integration) === [] || $integration->credential('secret_key') === null) {
            return ['ok' => false, 'message' => 'أدخل Secret key وIntegration IDs أولًا.'];
        }

        $base = rtrim((string) config('services.paymob.base_url'), '/');
        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$integration->credential('secret_key')])
                ->acceptJson()
                ->timeout(20)
                ->post($base.'/v1/intention/', [
                    'amount' => Money::toMinor('1.000'),
                    'currency' => $currency,
                    'payment_methods' => self::integrationIds($integration),
                    'items' => [['name' => 'Connection check', 'amount' => Money::toMinor('1.000'), 'quantity' => 1]],
                    'billing_data' => [
                        'first_name' => 'Connection', 'last_name' => 'Check', 'phone_number' => '+96800000000',
                        'email' => 'no-email@example.com', 'country' => 'OM',
                    ],
                    'special_reference' => 'CHECK-'.Str::upper(Str::random(16)),
                    'expiration' => 600,
                    'notification_url' => route('webhooks.paymob'),
                ]);
        } catch (ConnectionException) {
            $integration->markFailed('تعذّر الاتصال بخادم Paymob ('.$base.').');

            return ['ok' => false, 'message' => 'تعذّر الاتصال بخادم Paymob. تحقق من الاتصال بالإنترنت وحاول مرة أخرى.'];
        }

        if (! $response->successful() || (string) $response->json('client_secret', '') === '') {
            $message = 'رفضت Paymob المفاتيح: '.self::reason($response);
            $integration->markFailed($message);

            return ['ok' => false, 'message' => $message];
        }

        $integration->markVerified(['paymob_checked_mode' => $integration->mode]);

        return ['ok' => true, 'message' => 'قبلت Paymob المفاتيح وفتحت صفحة دفع تجريبية لم تُرسل لأحد.'];
    }

    /** Paymob's own words for a refusal (HTTP status and detail), never the request or its keys. */
    private static function reason(Response $response): string
    {
        $body = $response->json();
        $detail = is_array($body) ? ($body['detail'] ?? $body['message'] ?? null) : null;
        if ($detail === null && is_array($body) && $body !== []) {
            $first = reset($body);
            $detail = key($body).': '.(is_array($first) ? implode(' ', array_map('strval', array_filter($first, 'is_scalar'))) : (string) $first);
        }

        return 'HTTP '.$response->status().($detail ? ' — '.Str::limit((string) $detail, 160) : '');
    }

    /** A test key under live mode (or the reverse) is a common mistake; the key's own prefix tells. */
    public static function modeMismatch(TenantIntegration $integration): ?string
    {
        $key = (string) $integration->credential('secret_key');
        if (str_contains($key, '_test_') && $integration->mode === 'live') {
            return 'المفتاح السري مفتاح اختبار بينما الوضع «حقيقي».';
        }
        if (str_contains($key, '_live_') && $integration->mode === 'test') {
            return 'المفتاح السري مفتاح حقيقي بينما الوضع «اختبار».';
        }

        return null;
    }
}
