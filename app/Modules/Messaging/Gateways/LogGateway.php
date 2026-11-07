<?php

namespace App\Modules\Messaging\Gateways;

use App\Models\TenantIntegration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes messages to the application log instead of sending them: development, tests and demos. Nothing reaches
 * WhatsApp, and the ids it hands back start with «log.» so the panel can say so.
 */
final class LogGateway implements WhatsAppGateway
{
    public function sendTemplate(TenantIntegration $integration, string $to, string $template, string $language, array $params): GatewayResult
    {
        Log::info('[whatsapp:log] template', ['to' => self::mask($to), 'template' => $template, 'tenant' => $integration->tenant_id]);

        return GatewayResult::sent('log.'.Str::uuid());
    }

    public function sendText(TenantIntegration $integration, string $to, string $body): GatewayResult
    {
        Log::info('[whatsapp:log] text', ['to' => self::mask($to), 'length' => mb_strlen($body), 'tenant' => $integration->tenant_id]);

        return GatewayResult::sent('log.'.Str::uuid());
    }

    public function sendMedia(TenantIntegration $integration, string $to, string $kind, string $mediaId, ?string $caption, ?string $filename): GatewayResult
    {
        Log::info('[whatsapp:log] media', ['to' => self::mask($to), 'kind' => $kind, 'tenant' => $integration->tenant_id]);

        return GatewayResult::sent('log.'.Str::uuid());
    }

    public function uploadMedia(TenantIntegration $integration, string $path, string $mime, string $filename): GatewayResult
    {
        return GatewayResult::sent('log.media.'.Str::uuid());
    }

    public function fetchMedia(TenantIntegration $integration, string $mediaId, ?string $url = null): MediaDownload
    {
        return MediaDownload::failed('وضع التجربة: لا اتصال حقيقي بواتساب لتنزيل الملف.');
    }

    public function requestSync(TenantIntegration $integration, string $type): GatewayResult
    {
        return GatewayResult::failed('log_driver', 'وضع التجربة: المزامنة تحتاج اتصالًا حقيقيًا (WHATSAPP_DRIVER=meta).');
    }

    /** Only the last digits of a number reach the log. */
    private static function mask(string $phone): string
    {
        return '…'.substr($phone, -4);
    }
}
