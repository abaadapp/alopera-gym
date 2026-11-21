<?php

namespace App\Modules\Payments\Paymob;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\Phone;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Paymob's own payment links (QuickLink), made with the club's API key (owner's decision, 2026-11-21).
 *
 * Paymob's documents: the API key — one for test and live — buys a short-lived token (POST /api/auth/tokens), sent as a
 * Bearer token; a link is made by POST /api/ecommerce/payment-links (multipart: amount_cents, one `payment_methods`
 * part per integration id whose status matches `is_live`, reference_id, expires_at, notification_url…) and answers its
 * id, its address (shorten_url, client_url) and the Paymob order it pays; POST /api/ecommerce/payment-links/cancel with
 * payment_link_id closes it. The order id is among the fields Paymob signs in its callback (PaymobSignature): that is
 * what ties the money to the invoice, as for a checkout. Never a key in a message or a log.
 */
final class PaymobQuickLink
{
    public static function configured(?TenantIntegration $integration): bool
    {
        return $integration !== null && filled($integration->credential('api_key'));
    }

    /**
     * Make the link at Paymob for this attempt.
     *
     * @return array{id: string, order_id: string, url: string}
     *
     * @throws BusinessRuleException paymob.quicklink_refused (Paymob said no: nothing was made), paymob.quicklink_unknown
     *                               (no answer: Paymob may or may not have made it)
     */
    public function create(TenantIntegration $integration, PaymentIntent $intent, Invoice $invoice, string $description, string $notificationUrl): array
    {
        $base = rtrim((string) config('services.paymob.base_url'), '/');
        $token = $this->token($integration);
        $phone = $invoice->customer_phone ? Phone::normalize($invoice->customer_phone) : '';

        $parts = [
            ['name' => 'amount_cents', 'contents' => (string) Money::toMinor($intent->amount)],
            ['name' => 'reference_id', 'contents' => $intent->reference],
            ['name' => 'expires_at', 'contents' => $intent->expires_at->copy()->utc()->format('Y-m-d\TH:i:s')],
            ['name' => 'notification_url', 'contents' => $notificationUrl],
            ['name' => 'description', 'contents' => Str::limit($description, 200, '')],
            ['name' => 'full_name', 'contents' => Str::limit(filled($invoice->customer_name) ? (string) $invoice->customer_name : 'Customer', 100, '')],
            ['name' => 'is_live', 'contents' => PaymobClient::live($integration) ? 'true' : 'false'],
        ];
        if ($phone !== '') {
            $parts[] = ['name' => 'phone_number', 'contents' => '+'.$phone];
        }
        foreach (PaymobClient::checkoutIds($integration) as $id) {
            $parts[] = ['name' => 'payment_methods', 'contents' => (string) $id];
        }

        try {
            $response = Http::withToken($token)->acceptJson()->asMultipart()->timeout(20)
                ->post($base.'/api/ecommerce/payment-links', $parts);
        } catch (ConnectionException) {
            throw new BusinessRuleException('paymob.quicklink_unknown', 'لم تُجب Paymob عند إنشاء الرابط، فقد يكون أُنشئ لديها أو لا.');
        }
        if (! $response->successful()) {
            throw new BusinessRuleException('paymob.quicklink_refused', 'رفضت Paymob إنشاء الرابط: '.self::reason($response));
        }

        $id = trim((string) $response->json('id', ''));
        $order = trim((string) $response->json('order', ''));
        $url = trim((string) ($response->json('shorten_url') ?: $response->json('client_url', '')));
        if ($id === '' || $order === '' || ! str_starts_with($url, 'https://')) {
            // Made, perhaps, but without what ties its money to the invoice: it is not sent to anyone.
            throw new BusinessRuleException('paymob.quicklink_unknown', 'أجابت Paymob دون رقم الطلب أو عنوان الرابط، فلم يُستعمل.');
        }

        return ['id' => $id, 'order_id' => $order, 'url' => $url];
    }

    /** @throws BusinessRuleException paymob.quicklink_cancel when Paymob does not confirm it closed the link */
    public function cancel(TenantIntegration $integration, string $linkId): void
    {
        $base = rtrim((string) config('services.paymob.base_url'), '/');
        try {
            $response = Http::withToken($this->token($integration))->acceptJson()->timeout(20)
                ->post($base.'/api/ecommerce/payment-links/cancel', ['payment_link_id' => $linkId]);
        } catch (ConnectionException) {
            throw new BusinessRuleException('paymob.quicklink_cancel', 'تعذّر الاتصال بـ Paymob لإلغاء الرابط لديها، فلم يُلغَ شيء. حاول مرة أخرى.');
        }
        $said = $response->json('success');
        if (! $response->successful() || ($said !== true && $said !== 'true')) {
            throw new BusinessRuleException('paymob.quicklink_cancel', 'لم تؤكد Paymob إلغاء الرابط لديها ('.self::reason($response).')، فلم يُلغَ شيء. حاول مرة أخرى.');
        }
    }

    /** A token for the next request, never kept. */
    private function token(TenantIntegration $integration): string
    {
        $key = (string) $integration->credential('api_key');
        if ($key === '') {
            throw new BusinessRuleException('paymob.api_key', 'أدخل API key من لوحة Paymob أولًا.');
        }
        $base = rtrim((string) config('services.paymob.base_url'), '/');
        try {
            $response = Http::acceptJson()->timeout(20)->post($base.'/api/auth/tokens', ['api_key' => $key]);
        } catch (ConnectionException) {
            throw new BusinessRuleException('paymob.quicklink_refused', 'تعذّر الاتصال بـ Paymob، فلم يُنشأ شيء.');
        }
        $token = (string) $response->json('token', '');
        if (! $response->successful() || $token === '') {
            throw new BusinessRuleException('paymob.quicklink_refused', 'رفضت Paymob مفتاح API ('.self::reason($response).')، فلم يُنشأ شيء.');
        }

        return $token;
    }

    /** Paymob's own words (status and detail), never the request or a key. */
    private static function reason(Response $response): string
    {
        $detail = $response->json('detail') ?? $response->json('message');

        return 'HTTP '.$response->status().(is_string($detail) && $detail !== '' ? ': '.Str::limit($detail, 120) : '');
    }
}
