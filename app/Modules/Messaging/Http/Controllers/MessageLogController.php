<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\Message;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Delivery tracking for every WhatsApp message (sent, delivered, read, failed, skipped). */
class MessageLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Messaging/Messages', [
            'messages' => Message::with('member:id,full_name')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->when($request->string('direction')->toString(), fn ($q, $d) => $q->where('direction', $d))
                ->when($request->string('template')->toString(), fn ($q, $t) => $q->where('template_key', $t))
                ->latest('id')->paginate(40)->withQueryString()
                ->through(fn (Message $m) => $m->only('id', 'direction', 'type', 'to_phone', 'template_key', 'body', 'status', 'error_code', 'error_message', 'campaign_id') + [
                    'member' => $m->member?->full_name, 'member_id' => $m->member_id,
                    'at' => $m->created_at->format('Y-m-d H:i'), 'sent_at' => $m->sent_at?->format('H:i'),
                    'delivered_at' => $m->delivered_at?->format('H:i'), 'read_at' => $m->read_at?->format('H:i'),
                ]),
            'stats' => Message::where('created_at', '>=', now()->subDays(30))->where('direction', 'outbound')
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'filters' => $request->only('status', 'direction', 'template'),
        ]);
    }
}
