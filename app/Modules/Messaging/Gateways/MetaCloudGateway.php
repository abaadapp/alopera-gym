<?php

namespace App\Modules\Messaging\Gateways;

use App\Models\TenantIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WhatsApp Business Platform — the Cloud API, reached directly at Meta or through a provider that speaks the same
 * message format (360dialog). The club's integration says which (settings.vendor):
 *
 * - meta: graph.facebook.com/<version>/<phone_number_id>/…, a system-user access token (Bearer).
 * - 360dialog: waba-v2.360dialog.io/…, the number's D360-API-KEY; the number is in the key, not the path. Files are
 *   fetched through 360dialog's host in place of Meta's lookaside host.
 *
 * Both work with a number connected by Coexistence: it stays on the WhatsApp Business app on the phone. Nothing here
 * registers, deregisters or migrates a number (that would take it off the app). Templates must already be approved.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
 */
final class MetaCloudGateway implements WhatsAppGateway
{
    public const VENDOR_META = 'meta';

    public const VENDOR_360DIALOG = '360dialog';

    public const VENDORS = [self::VENDOR_META => 'Meta Cloud API', self::VENDOR_360DIALOG => '360dialog'];

    public function sendTemplate(TenantIntegration $integration, string $to, string $template, string $language, array $params): GatewayResult
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($params !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => Str::limit((string) $v, 1000, '')], array_values($params)),
            ]];
        }

        return $this->post($integration, $payload);
    }

    public function sendText(TenantIntegration $integration, string $to, string $body): GatewayResult
    {
        return $this->post($integration, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => Str::limit($body, 4000, ''), 'preview_url' => true],
        ]);
    }

    public function sendMedia(TenantIntegration $integration, string $to, string $kind, string $mediaId, ?string $caption, ?string $filename): GatewayResult
    {
        $object = ['id' => $mediaId];
        if ($caption !== null && $caption !== '' && $kind !== 'audio') {
            $object['caption'] = Str::limit($caption, 1000, '');
        }
        if ($kind === 'document' && $filename) {
            $object['filename'] = Str::limit($filename, 200, '');
        }

        return $this->post($integration, ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $kind, $kind => $object]);
    }

    public function uploadMedia(TenantIntegration $integration, string $path, string $mime, string $filename): GatewayResult
    {
        $url = $this->url($integration, 'media');
        if ($url === null) {
            return GatewayResult::failed('not_configured', 'مفاتيح واتساب ناقصة.');
        }

        try {
            $response = $this->client($integration)->timeout(60)
                ->attach('file', (string) file_get_contents($path), $filename, ['Content-Type' => $mime])
                ->post($url, ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException $e) {
            return GatewayResult::failed('connection', Str::limit($e->getMessage(), 250), true);
        }

        $id = $response->json('id');

        return $response->successful() && is_string($id) ? GatewayResult::sent($id) : $this->failure($response);
    }

    public function fetchMedia(TenantIntegration $integration, string $mediaId, ?string $url = null): MediaDownload
    {
        if (! $this->configured($integration)) {
            return MediaDownload::failed('مفاتيح واتساب ناقصة.');
        }

        try {
            if ($url === null || $url === '') {
                $info = $this->client($integration)->timeout(15)->get($this->root($integration).'/'.rawurlencode($mediaId));
                if (! $info->successful() || ! is_string($info->json('url'))) {
                    $failure = $this->failure($info);

                    return MediaDownload::failed('تعذّر الحصول على الملف (رمز '.$failure->errorCode.').', $failure->retryable);
                }
                $url = (string) $info->json('url');
            }
            if ($this->vendor($integration) === self::VENDOR_360DIALOG) {
                // 360dialog serves Meta's files through its own host.
                $url = str_replace('https://lookaside.fbsbx.com', rtrim((string) config('services.whatsapp.d360_url'), '/'), stripslashes($url));
            }
            $file = $this->client($integration)->timeout(60)->get($url);
        } catch (ConnectionException) {
            return MediaDownload::failed('تعذّر الاتصال لتنزيل الملف.', true);
        }

        if (! $file->successful()) {
            return MediaDownload::failed('رفض المزود تنزيل الملف (رمز '.$file->status().').', $file->serverError() || $file->status() === 429);
        }

        return MediaDownload::file($file->body(), $file->header('Content-Type') ?: null);
    }

    public function requestSync(TenantIntegration $integration, string $type): GatewayResult
    {
        $url = $this->url($integration, 'smb_app_data');
        if ($url === null) {
            return GatewayResult::failed('not_configured', 'مفاتيح واتساب ناقصة.');
        }

        try {
            $response = $this->client($integration)->timeout(15)->post($url, ['messaging_product' => 'whatsapp', 'sync_type' => $type]);
        } catch (ConnectionException $e) {
            return GatewayResult::failed('connection', Str::limit($e->getMessage(), 250), true);
        }

        $id = $response->json('request_id');

        return $response->successful() ? GatewayResult::sent(is_string($id) ? $id : 'accepted') : $this->failure($response);
    }

    /**
     * A read-only call with the club's own keys: it proves they work and sends nothing to anyone. With Meta it also
     * says whether the number is shared with the WhatsApp Business app (Coexistence: is_on_biz_app and platform_type
     * CLOUD_API). 360dialog's key is checked by reading its template list; Coexistence is then read on 360dialog.
     *
     * @return array{ok: bool, message: string, number?: array<string, mixed>}
     */
    public function describeNumber(TenantIntegration $integration): array
    {
        if (! $this->configured($integration)) {
            return ['ok' => false, 'message' => $this->vendor($integration) === self::VENDOR_360DIALOG
                ? 'أدخل D360-API-KEY وPhone number ID أولًا.' : 'أدخل Phone number ID وAccess token أولًا.'];
        }

        try {
            if ($this->vendor($integration) === self::VENDOR_360DIALOG) {
                $response = $this->client($integration)->timeout(15)->get($this->root($integration).'/v1/configs/templates', ['limit' => 1]);
                if (! $response->successful()) {
                    return ['ok' => false, 'message' => 'رفض 360dialog المفتاح (رمز '.$response->status().').'];
                }

                return ['ok' => true, 'message' => 'تم التحقق من مفتاح 360dialog.', 'number' => ['coexistence' => null]];
            }

            $response = $this->client($integration)->timeout(15)->get($this->root($integration).'/'.$integration->credential('phone_number_id'), [
                'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,is_on_biz_app,platform_type',
            ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'تعذّر الاتصال بخوادم المزود. تحقق من الاتصال بالإنترنت وحاول مرة أخرى.'];
        }

        if (! $response->successful() || ! is_string($response->json('display_phone_number'))) {
            $code = (string) ($response->json('error.code') ?? $response->status());
            $detail = (string) ($response->json('error.message') ?? 'WhatsApp API error');

            return ['ok' => false, 'message' => 'رفضت Meta الطلب (رمز '.$code.'): '.Str::limit($detail, 160)];
        }

        return ['ok' => true, 'message' => 'تم التحقق من الرقم والرمز لدى Meta.', 'number' => [
            'display_phone' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
            'quality_rating' => $response->json('quality_rating'),
            'platform_type' => $response->json('platform_type'),
            // Coexistence as Meta states it: on the Business app and on the Cloud API at once.
            'coexistence' => $response->json('is_on_biz_app') === null ? null
                : ($response->json('is_on_biz_app') === true && $response->json('platform_type') === 'CLOUD_API'),
        ]];
    }

    /** Verify `X-Hub-Signature-256` on webhook calls (HMAC-SHA256 of the raw body with the app secret). */
    public static function verifySignature(?string $header, string $rawBody, ?string $appSecret): bool
    {
        if (! $appSecret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $appSecret), $header);
    }

    public function vendor(TenantIntegration $integration): string
    {
        return $integration->option('vendor') === self::VENDOR_360DIALOG ? self::VENDOR_360DIALOG : self::VENDOR_META;
    }

    private function configured(TenantIntegration $integration): bool
    {
        return $this->vendor($integration) === self::VENDOR_360DIALOG
            ? $integration->credential('api_key') !== null
            : $integration->credential('phone_number_id') !== null && $integration->credential('access_token') !== null;
    }

    private function root(TenantIntegration $integration): string
    {
        return $this->vendor($integration) === self::VENDOR_360DIALOG
            ? rtrim((string) config('services.whatsapp.d360_url'), '/')
            : rtrim((string) config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version');
    }

    /** The address of an action for the club's number (messages, media, smb_app_data), or null without keys. */
    private function url(TenantIntegration $integration, string $action): ?string
    {
        if (! $this->configured($integration)) {
            return null;
        }

        return $this->vendor($integration) === self::VENDOR_360DIALOG
            ? $this->root($integration).'/'.$action
            : $this->root($integration).'/'.$integration->credential('phone_number_id').'/'.$action;
    }

    private function client(TenantIntegration $integration): PendingRequest
    {
        return $this->vendor($integration) === self::VENDOR_360DIALOG
            ? Http::withHeaders(['D360-API-KEY' => (string) $integration->credential('api_key')])->acceptJson()
            : Http::withToken((string) $integration->credential('access_token'))->acceptJson();
    }

    /** @param array<string, mixed> $payload */
    private function post(TenantIntegration $integration, array $payload): GatewayResult
    {
        $url = $this->url($integration, 'messages');
        if ($url === null) {
            return GatewayResult::failed('not_configured', 'مفاتيح واتساب ناقصة.');
        }

        try {
            $response = $this->client($integration)->timeout(15)->post($url, $payload);
        } catch (ConnectionException $e) {
            return GatewayResult::failed('connection', Str::limit($e->getMessage(), 250), true);
        }

        $id = $response->json('messages.0.id');

        return $response->successful() && is_string($id) ? GatewayResult::sent($id) : $this->failure($response);
    }

    private function failure(Response $response): GatewayResult
    {
        $code = (string) ($response->json('error.code') ?? $response->json('meta.http_code') ?? $response->status());
        $message = (string) ($response->json('error.error_data.details') ?? $response->json('error.message') ?? $response->json('meta.developer_message') ?? 'WhatsApp API error');
        // 429/5xx and Meta's throttling codes are worth retrying; template or number errors are not.
        $retryable = $response->status() === 429 || $response->serverError() || in_array($code, ['4', '80007', '130429', '131016'], true);

        return GatewayResult::failed($code, Str::limit($message, 250), $retryable);
    }
}
