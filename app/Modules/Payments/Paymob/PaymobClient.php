<?php

namespace App\Modules\Payments\Paymob;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
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
            throw new BusinessRuleException('paymob.connection', 'تعذّر الاتصال ببوابة الدفع. حاولي مرة أخرى.');
        }

        $clientSecret = (string) $response->json('client_secret', '');
        if (! $response->successful() || $clientSecret === '') {
            throw new BusinessRuleException('paymob.rejected', 'رفضت بوابة الدفع فتح عملية الدفع. تواصلي مع النادي.');
        }

        return [
            'url' => $base.'/unifiedcheckout/?publicKey='.urlencode((string) $integration->credential('public_key')).'&clientSecret='.urlencode($clientSecret),
            'order_id' => $response->json('intention_order_id') !== null ? (string) $response->json('intention_order_id') : null,
        ];
    }
}
