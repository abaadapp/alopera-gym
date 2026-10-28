<?php

namespace App\Modules\Messaging\Gateways;

use App\Models\TenantIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WhatsApp Business Platform — Cloud API.
 *
 * Needs, per club: the phone number id and a (system-user) access token from
 * Meta Business Manager. Templates must already be approved in WhatsApp Manager.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
 */
final class MetaCloudGateway implements WhatsAppGateway
{
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

    /** @param array<string, mixed> $payload */
    private function post(TenantIntegration $integration, array $payload): GatewayResult
    {
        $phoneId = $integration->credential('phone_number_id');
        $token = $integration->credential('access_token');

        if (! $phoneId || ! $token) {
            return GatewayResult::failed('not_configured', 'WhatsApp phone number id or access token is missing.');
        }

        $url = rtrim(config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version').'/'.$phoneId.'/messages';

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(15)->post($url, $payload);
        } catch (ConnectionException $e) {
            return GatewayResult::failed('connection', Str::limit($e->getMessage(), 250), true);
        }

        return $this->interpret($response);
    }

    private function interpret(Response $response): GatewayResult
    {
        $id = $response->json('messages.0.id');
        if ($response->successful() && is_string($id)) {
            return GatewayResult::sent($id);
        }

        $code = (string) ($response->json('error.code') ?? $response->status());
        $message = (string) ($response->json('error.error_data.details') ?? $response->json('error.message') ?? 'WhatsApp API error');

        // 429/5xx and Meta's throttling codes are worth retrying; template or number errors are not.
        $retryable = $response->status() === 429 || $response->serverError() || in_array($code, ['4', '80007', '130429', '131016'], true);

        return GatewayResult::failed($code, Str::limit($message, 250), $retryable);
    }

    /** Verify `X-Hub-Signature-256` on webhook calls (HMAC-SHA256 of the raw body with the app secret). */
    public static function verifySignature(?string $header, string $rawBody, ?string $appSecret): bool
    {
        if (! $appSecret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $appSecret), $header);
    }
}
