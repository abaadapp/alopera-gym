<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Gateways\MetaCloudGateway;
use App\Modules\Messaging\Services\InboundProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhook (one endpoint for every club; the club is
 * found by the phone_number_id in the payload).
 */
class WhatsAppWebhookController extends Controller
{
    /** GET handshake when the webhook is registered in the Meta app. */
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');

        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, InboundProcessor $processor): Response
    {
        if (! MetaCloudGateway::verifySignature($request->header('X-Hub-Signature-256'), $request->getContent(), config('services.whatsapp.app_secret'))) {
            Log::warning('WhatsApp webhook rejected: bad signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }

        $processor->handle((array) $request->json()->all());

        return response('OK', 200);
    }
}
