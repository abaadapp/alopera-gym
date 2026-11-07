<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Notifications\StaffNotifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationEvent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\ConversationAccess;
use App\Modules\Messaging\Services\Conversations;
use App\Modules\Messaging\Services\InboxPresenter;
use App\Modules\Messaging\Services\TemplateValues;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The WhatsApp inbox («واتساب ← المحادثات»): the list beside the open conversation, kept current by the screen asking
 * every few seconds (feed) — no page reload. Every action is checked here, on the server, per conversation
 * (ConversationAccess); one the user may not see answers 404.
 */
class InboxController extends Controller
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly InboxPresenter $presenter,
        private readonly Conversations $conversations,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $active = null;
        if ($id = $request->integer('c')) {
            $conversation = Conversation::find($id);
            if ($conversation && $this->access->canView($user, $conversation)) {
                $active = $this->open($conversation, $user);
            }
        }

        return Inertia::render('Messaging/Inbox', [
            'conversations' => $this->presenter->listQuery($request, $user)->limit(InboxPresenter::PAGE)->get()->map(fn ($c) => $this->presenter->row($c)),
            'active' => $active,
            'unread' => (int) $this->access->scope(Conversation::query(), $user)->sum('unread_count'),
            'filters' => $request->only('status', 'q', 'assignee'),
            'assignees' => $user->can('whatsapp.view_all') ? $this->presenter->assignees() : [],
            'scope' => $user->can('whatsapp.view_all') ? 'all' : 'assigned',
            'connection' => $this->connection(),
            'now' => now()->format(DATE_ATOM),
        ]);
    }

    /**
     * What changed since the screen last asked: the list (as filtered), the open conversation's new messages, notes
     * and the statuses of messages already shown, and the unread total.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = $request->date('since') ?? now()->subMinute();
        $thread = null;

        if ($id = $request->integer('c')) {
            $conversation = Conversation::find($id);
            if ($conversation && $this->access->canView($user, $conversation)) {
                $after = $request->integer('after');
                $new = Message::with(['attachments', 'sender:id,name'])->where('conversation_id', $conversation->id)->where('id', '>', $after)->orderBy('id')->limit(100)->get();
                if ($new->contains('direction', 'inbound') && $conversation->unread_count > 0) {
                    $conversation->forceFill(['unread_count' => 0])->save();
                }
                $thread = [
                    'id' => $conversation->id,
                    'row' => $this->presenter->row($conversation),
                    'window_closes_at' => $conversation->windowClosesAt()?->format(DATE_ATOM),
                    'messages' => $new->map(fn ($m) => $this->presenter->message($m))->all(),
                    'events' => ConversationEvent::with('user:id,name')->where('conversation_id', $conversation->id)
                        ->where('id', '>', $request->integer('events_after'))->orderBy('id')->get()->map(fn ($e) => $this->presenter->event($e))->all(),
                    // Messages already on screen whose status or file changed.
                    'updated' => Message::with(['attachments', 'sender:id,name'])->where('conversation_id', $conversation->id)->where('id', '<=', $after)
                        ->where(fn ($q) => $q->where('updated_at', '>=', $since)->orWhereHas('attachments', fn ($a) => $a->where('updated_at', '>=', $since)))
                        ->limit(100)->get()->map(fn ($m) => $this->presenter->message($m))->all(),
                ];
            } else {
                $thread = ['id' => $id, 'gone' => true];
            }
        }

        return response()->json([
            'conversations' => $this->presenter->listQuery($request, $user)->limit(InboxPresenter::PAGE)->get()->map(fn ($c) => $this->presenter->row($c)),
            'thread' => $thread,
            'unread' => (int) $this->access->scope(Conversation::query(), $user)->sum('unread_count'),
            'now' => now()->format(DATE_ATOM),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->access->authorize($request->user(), $conversation);

        return response()->json($request->integer('before')
            ? $this->presenter->thread($conversation, $request->user(), $request->integer('before'))
            : $this->open($conversation, $request->user()));
    }

    public function reply(Request $request, Conversation $conversation, WhatsAppService $whatsapp): JsonResponse
    {
        abort_unless($this->access->canReply($request->user(), $conversation), $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        return $this->sent($whatsapp->reply($conversation, $data['body']));
    }

    public function media(Request $request, Conversation $conversation, WhatsAppService $whatsapp): JsonResponse
    {
        abort_unless($this->access->canSendMedia($request->user(), $conversation), $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $mimes = collect(Attachment::KINDS)->flatMap(fn ($k) => $k['mimes'])->implode(',');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('services.whatsapp.media_max_mb', 16) * 1024), 'mimetypes:'.$mimes],
            'caption' => ['nullable', 'string', 'max:1000'],
        ], ['file.mimetypes' => 'نوع الملف لا يقبله واتساب: صور JPG/PNG، فيديو MP4، صوت، أو مستندات PDF وWord وExcel وPowerPoint ونص.']);

        if (! $conversation->serviceWindowOpen()) {
            // Refused before the file is kept: nothing would carry it.
            return response()->json(['ok' => false, 'error' => 'انتهت نافذة الـ24 ساعة؛ استخدم قالبًا معتمدًا.'], 422);
        }
        $kind = Attachment::kindFor((string) $data['file']->getMimeType());
        $max = Attachment::KINDS[$kind ?? 'document']['max_mb'];
        if ($data['file']->getSize() > $max * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'الحد الأقصى لهذا النوع في واتساب '.$max.' ميغابايت.']);
        }

        return $this->sent($whatsapp->sendMedia($conversation, $data['file'], $data['caption'] ?? null));
    }

    /** An approved template, the way to write first or again once the 24-hour window has closed. */
    public function template(Request $request, Conversation $conversation, WhatsAppService $whatsapp): JsonResponse
    {
        abort_unless($this->access->canReply($request->user(), $conversation), $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $data = $request->validate(['template_key' => ['required', 'string', 'max:50']]);
        $member = $conversation->member;
        if (! $member) {
            throw ValidationException::withMessages(['template_key' => 'اربط المحادثة بمشترك أولًا: القوالب تُرسل لمشترك وافق على رسائل الخدمة.']);
        }
        $template = MessageTemplate::where('key', $data['template_key'])->firstOrFail();

        $message = $whatsapp->queueTemplate($member, $template->key, TemplateValues::forMember($member), null,
            'manual:'.$template->key.':'.$member->id.':'.now()->timestamp);
        if (! $message) {
            throw ValidationException::withMessages(['template_key' => 'واتساب غير مفعّل لهذا النادي.']);
        }

        return $this->sent($message);
    }

    public function note(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->access->canReply($request->user(), $conversation) || $this->access->canAssign($request->user(), $conversation),
            $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $this->conversations->note($conversation, $data['body']);

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, Conversation $conversation, StaffNotifier $notifier): JsonResponse
    {
        abort_unless($this->access->canAssign($request->user(), $conversation), $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);
        $to = null;
        if (! empty($data['user_id'])) {
            $to = User::with('role')->where('tenant_id', $conversation->tenant_id)->where('is_active', true)->find($data['user_id']);
            if (! $to || ! $to->canAny(['whatsapp.view_all', 'whatsapp.view_assigned'])) {
                throw ValidationException::withMessages(['user_id' => 'هذا الموظف لا يملك صلاحية محادثات واتساب.']);
            }
        }

        $this->conversations->assign($conversation, $to);
        if ($to && $to->id !== $request->user()->id) {
            $notifier->notify(['whatsapp.view_all', 'whatsapp.view_assigned'], 'whatsapp-assigned:'.$conversation->id.':'.$to->id.':'.now()->timestamp, 'inbox',
                'حُوّلت إليك محادثة واتساب: '.$conversation->displayName(), route('club.whatsapp.index', ['c' => $conversation->id]), fn (User $u) => $u->id === $to->id);
        }

        return response()->json(['ok' => true]);
    }

    public function status(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->access->canReply($request->user(), $conversation) || $this->access->canAssign($request->user(), $conversation),
            $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Conversation::STATUSES))]]);
        $this->conversations->setStatus($conversation, $data['status']);

        return response()->json(['ok' => true]);
    }

    public function link(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->access->canLinkMember($request->user(), $conversation), $this->access->canView($request->user(), $conversation) ? 403 : 404);
        $data = $request->validate(['member_id' => ['nullable', 'integer']]);
        $member = ! empty($data['member_id']) ? Member::find($data['member_id']) : null;
        if (! empty($data['member_id']) && ! $member) {
            throw ValidationException::withMessages(['member_id' => 'المشترك غير موجود.']);
        }
        $this->conversations->link($conversation, $member);

        return response()->json(['ok' => true]);
    }

    /** Send an approved template to one member from their page (reminder, offer, invoice link…). */
    public function sendTemplate(Request $request, Member $member, WhatsAppService $whatsapp): RedirectResponse
    {
        $data = $request->validate(['template_key' => ['required', 'string', 'max:50']]);
        $template = MessageTemplate::where('key', $data['template_key'])->firstOrFail();
        $message = $whatsapp->queueTemplate($member, $template->key, TemplateValues::forMember($member), null, 'manual:'.$template->key.':'.$member->id.':'.now()->timestamp);

        if (! $message) {
            return back()->with('error', 'واتساب غير مفعّل لهذا النادي.');
        }

        return back()->with($message->status === Message::SKIPPED ? 'error' : 'success',
            $message->status === Message::SKIPPED ? 'لم تُرسل: '.(Message::SKIP_REASONS[$message->error_code] ?? $message->error_code) : 'تمت جدولة الرسالة للإرسال.');
    }

    /** Old addresses of the inbox (and notifications that still point to them) open the new one. */
    public function legacy(Request $request, ?Conversation $conversation = null): RedirectResponse
    {
        return redirect()->route('club.whatsapp.index', array_filter(['c' => $conversation?->id, 'q' => $request->query('q')]));
    }

    /** @return array<string, mixed> */
    private function open(Conversation $conversation, User $user): array
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
        app(StaffNotifier::class)->resolve('inbox:'.$conversation->id.':*');

        return $this->presenter->thread($conversation->load(['member', 'assignee:id,name']), $user);
    }

    private function sent(Message $message): JsonResponse
    {
        return response()->json([
            'ok' => $message->status !== Message::FAILED && $message->status !== Message::SKIPPED,
            'message' => $this->presenter->message($message->load(['attachments', 'sender:id,name'])),
            'error' => $message->status === Message::FAILED || $message->status === Message::SKIPPED
                ? ($message->error_message ?: (Message::SKIP_REASONS[$message->error_code] ?? $message->error_code)) : null,
        ], $message->status === Message::FAILED ? 422 : 200);
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        $whatsapp = app(WhatsAppService::class);
        $integration = $whatsapp->integration();

        return [
            'demo' => config('services.whatsapp.driver') === 'log',
            'enabled' => (bool) $integration?->is_enabled,
            'failing' => (bool) $integration?->failing(),
            'error' => $integration?->failing() ? $integration->last_error : null,
        ];
    }
}
