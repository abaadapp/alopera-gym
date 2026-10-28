<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use Inertia\Inertia;
use Inertia\Response;

class WebhookLogController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Platform/Webhooks', [
            'events' => PaymentWebhookEvent::latest('id')->paginate(30)->through(fn ($e) => $e->only('id', 'tenant_id', 'provider', 'transaction_id', 'reference', 'signature_valid', 'outcome', 'ip_address') + [
                'created_at' => $e->created_at->format('Y-m-d H:i:s'),
            ]),
        ]);
    }
}
