<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Notifications\StaffNotifier;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** WhatsApp conversations inbox. */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $conversation->forceFill(['unread_count' => 0])->save();
        app(StaffNotifier::class)->resolve('inbox:'.$conversation->id.':*');

        return $this->render($request, $conversation);
    }

    private function render(Request $request, ?Conversation $active): Response
    {
        return Inertia::render('Messaging/Inbox', [
            'conversations' => Conversation::with('member:id,full_name,member_no')
                ->when($request->string('status')->toString() ?: 'open', fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
                ->when($request->string('q')->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('contact_name', 'ilike', "%{$t}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $t).'%')))
                ->orderByDesc('last_message_at')->limit(100)->get()
                ->map(fn (Conversation $c) => $c->only('id', 'phone', 'contact_name', 'status', 'unread_count') + [
                    'member' => $c->member?->only('id', 'full_name', 'member_no'), 'last_message_at' => $c->last_message_at?->format('Y-m-d H:i'),
                ]),
            'active' => $active ? $active->only('id', 'phone', 'contact_name', 'status', 'member_id') + [
                'window_open' => $active->serviceWindowOpen(),
                'messages' => Message::where('conversation_id', $active->id)->orderBy('id')->limit(300)->get()
                    ->map(fn ($m) => $m->only('id', 'direction', 'type', 'body', 'status', 'error_message', 'template_key') + ['at' => $m->created_at->format('Y-m-d H:i')]),
            ] : null,
            'enabled' => app(WhatsAppService::class)->enabled(),
            'filters' => $request->only('status', 'q'),
        ]);
    }

    public function reply(Request $request, Conversation $conversation, WhatsAppService $whatsapp): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $message = $whatsapp->reply($conversation, $data['body']);

        return back()->with($message->status === Message::FAILED ? 'error' : 'success', $message->status === Message::FAILED ? $message->error_message : 'تم الإرسال.');
    }

    public function close(Conversation $conversation): RedirectResponse
    {
        $conversation->forceFill(['status' => $conversation->status === 'open' ? 'closed' : 'open'])->save();

        return back();
    }

    /** Send an approved template to one member (reminder, offer, invoice link…). */
    public function sendTemplate(Request $request, Member $member, WhatsAppService $whatsapp): RedirectResponse
    {
        $data = $request->validate(['template_key' => ['required', 'string', 'max:50']]);
        $template = MessageTemplate::where('key', $data['template_key'])->firstOrFail();
        $membership = $member->currentMembership()->first();

        $message = $whatsapp->queueTemplate($member, $template->key, [
            'member_name' => explode(' ', $member->full_name)[0],
            'club_name' => app(TenantContext::class)->require()->name,
            'member_no' => $member->member_no,
            'plan_name' => (string) ($membership?->plan_snapshot['name'] ?? ''),
            'ends_on' => $membership?->ends_on?->format('Y/m/d') ?? '',
            'starts_on' => $membership?->starts_on?->format('Y/m/d') ?? '',
            'renew_link' => route('portal.renew', ['club' => app(TenantContext::class)->require()->slug]),
        ], null, 'manual:'.$template->key.':'.$member->id.':'.now()->timestamp);

        if (! $message) {
            return back()->with('error', 'واتساب غير مفعّل لهذا النادي.');
        }

        return back()->with($message->status === Message::SKIPPED ? 'error' : 'success',
            $message->status === Message::SKIPPED ? 'لم تُرسل: '.$message->error_code : 'تمت جدولة الرسالة للإرسال.');
    }
}
