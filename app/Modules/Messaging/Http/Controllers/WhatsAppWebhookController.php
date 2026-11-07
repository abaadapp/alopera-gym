<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Gateways\MetaCloudGateway;
use App\Modules\Messaging\Services\WebhookIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp webhooks, one address per provider for every club (the club is found by the number's id in the body):
 *
 * - Meta's Cloud API: signed with the app secret (X-Hub-Signature-256), checked on the raw body.
 * - 360dialog: no signature; the webhook is registered with a secret header (X-Alopera-Webhook-Token) made for the
 *   club, compared with the club's stored one for every number in the body.
 *
 * A verified body is written down and answered 200 at once; processing happens in a queued job (WebhookIntake).
 * Over HTTPS only in production (APP_URL; the release check refuses http).
 */
class WhatsAppWebhookController extends Controller
{
    public const TOKEN_HEADER = 'X-Alopera-Webhook-Token';

    /** GET handshake when the webhook is registered in the Meta app. */
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');

        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, WebhookIntake $intake): Response
    {
        if (! MetaCloudGateway::verifySignature($request->header('X-Hub-Signature-256'), $request->getContent(), config('services.whatsapp.app_secret'))) {
            Log::warning('WhatsApp webhook rejected: bad signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }

        $intake->accept(MetaCloudGateway::VENDOR_META, $request->getContent(), (array) $request->json()->all());

        return response('OK', 200);
    }

    public function receive360(Request $request, WebhookIntake $intake, TenantContext $tenants): Response
    {
        $payload = (array) $request->json()->all();
        $token = (string) $request->header(self::TOKEN_HEADER, '');
        $numbers = collect(Arr::get($payload, 'entry', []))->flatMap(fn ($e) => collect(Arr::get($e, 'changes', []))->pluck('value.metadata.phone_number_id'))
            ->filter()->unique();

        $valid = $token !== '' && $numbers->isNotEmpty() && $numbers->every(function ($id) use ($tenants, $token) {
            $integration = $tenants->bypass(fn () => TenantIntegration::where('provider', TenantIntegration::WHATSAPP)->where('public_identifier', (string) $id)->first());

            return $integration && $integration->option('vendor') === MetaCloudGateway::VENDOR_360DIALOG
                && ($stored = $integration->credential('webhook_token')) !== null && hash_equals($stored, $token);
        });

        if (! $valid) {
            Log::warning('WhatsApp (360dialog) webhook rejected: bad or missing token', ['ip' => $request->ip()]);

            return response('Invalid token', 401);
        }

        $intake->accept(MetaCloudGateway::VENDOR_360DIALOG, $request->getContent(), $payload);

        return response('OK', 200);
    }
}
