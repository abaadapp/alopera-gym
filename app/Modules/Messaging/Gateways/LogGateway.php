<?php

namespace App\Modules\Messaging\Gateways;

use App\Models\TenantIntegration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Writes messages to the application log instead of sending them. */
final class LogGateway implements WhatsAppGateway
{
    public function sendTemplate(TenantIntegration $integration, string $to, string $template, string $language, array $params): GatewayResult
    {
        Log::info('[whatsapp:log] template', compact('to', 'template', 'language', 'params') + ['tenant' => $integration->tenant_id]);

        return GatewayResult::sent('log.'.Str::uuid());
    }

    public function sendText(TenantIntegration $integration, string $to, string $body): GatewayResult
    {
        Log::info('[whatsapp:log] text', compact('to', 'body') + ['tenant' => $integration->tenant_id]);

        return GatewayResult::sent('log.'.Str::uuid());
    }
}
