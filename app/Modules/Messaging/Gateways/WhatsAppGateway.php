<?php

namespace App\Modules\Messaging\Gateways;

use App\Models\TenantIntegration;

/**
 * Transport for WhatsApp messages. The Meta Cloud API driver is the real one;
 * the log driver is for local development and demos.
 */
interface WhatsAppGateway
{
    /** @param list<string> $params body variables in order ({{1}}, {{2}}…) */
    public function sendTemplate(TenantIntegration $integration, string $to, string $template, string $language, array $params): GatewayResult;

    public function sendText(TenantIntegration $integration, string $to, string $body): GatewayResult;
}
