<?php

namespace App\Modules\Messaging\Gateways;

use App\Models\TenantIntegration;

/**
 * Transport for WhatsApp messages. The Cloud API gateway is the real one (Meta's Cloud API directly, or a Coexistence
 * provider such as 360dialog that speaks the same message format); the log driver is for development, tests and
 * demos and never reaches WhatsApp.
 */
interface WhatsAppGateway
{
    /** @param list<string> $params body variables in order ({{1}}, {{2}}…) */
    public function sendTemplate(TenantIntegration $integration, string $to, string $template, string $language, array $params): GatewayResult;

    public function sendText(TenantIntegration $integration, string $to, string $body): GatewayResult;

    /** Send a file already uploaded to WhatsApp (uploadMedia's id). */
    public function sendMedia(TenantIntegration $integration, string $to, string $kind, string $mediaId, ?string $caption, ?string $filename): GatewayResult;

    /** Upload a file; the result's messageId is the media id to send. */
    public function uploadMedia(TenantIntegration $integration, string $path, string $mime, string $filename): GatewayResult;

    /** Download a file a customer sent (by its media id, or the link the webhook gave). */
    public function fetchMedia(TenantIntegration $integration, string $mediaId, ?string $url = null): MediaDownload;

    /**
     * Ask WhatsApp to send, once, what the WhatsApp Business app holds: its contacts (smb_app_state_sync) or the past
     * chats (history). Only for a number connected with Coexistence, within 24 hours of connecting.
     */
    public function requestSync(TenantIntegration $integration, string $type): GatewayResult;
}
