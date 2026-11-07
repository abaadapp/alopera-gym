<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\Money;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Membership;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationEvent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What the inbox shows: the list of conversations (filtered and searched), one conversation's thread with its notes
 * and events, and the member card — each piece only to whom ConversationAccess allows it.
 */
final class InboxPresenter
{
    public const PAGE = 60;

    public function __construct(private readonly ConversationAccess $access, private readonly Conversations $conversations) {}

    /** @return Builder<Conversation> */
    public function listQuery(Request $request, User $user): Builder
    {
        $status = $request->string('status')->toString();
        $assignee = $request->string('assignee')->toString();
        $term = trim($request->string('q')->toString());
        $digits = preg_replace('/\D/', '', strtr($term, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));

        return $this->access->scope(Conversation::query(), $user)
            ->with(['member:id,full_name,member_no', 'assignee:id,name'])
            ->when(isset(Conversation::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->when($assignee === 'me', fn ($q) => $q->where('assigned_to', $user->id))
            ->when($assignee === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when(ctype_digit($assignee), fn ($q) => $q->where('assigned_to', (int) $assignee))
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term, $digits) {
                $w->where('contact_name', 'ilike', '%'.$term.'%')
                    ->orWhere('profile_name', 'ilike', '%'.$term.'%')
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'ilike', '%'.$term.'%'));
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', '%'.(str_starts_with($digits, '0') ? ltrim($digits, '0') : $digits).'%');
                }
            }))
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    public function row(Conversation $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->displayName(),
            'phone' => Phone::display($c->phone),
            'status' => $c->status,
            'unread' => (int) $c->unread_count,
            'preview' => $c->last_preview,
            'last_direction' => $c->last_direction,
            'last_at' => $c->last_message_at?->toIso8601String(),
            'assignee' => $c->assignee ? ['id' => $c->assignee->id, 'name' => $c->assignee->name] : null,
            'is_member' => $c->member_id !== null,
            'is_demo' => (bool) $c->is_demo,
            'window_open' => $c->serviceWindowOpen(),
        ];
    }

    /**
     * One conversation: its newest messages (older ones by `before`), notes and events, the member card and what the
     * viewer may do.
     *
     * @return array<string, mixed>
     */
    public function thread(Conversation $c, User $user, ?int $before = null): array
    {
        $can = $this->access->abilities($user, $c);
        $messages = Message::with(['attachments', 'sender:id,name'])->where('conversation_id', $c->id)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(120)->get()->reverse()->values();
        $events = ConversationEvent::with('user:id,name')->where('conversation_id', $c->id)
            ->when($messages->isNotEmpty() && $before, fn ($q) => $q->where('created_at', '>=', $messages->first()->created_at))
            ->orderBy('id')->limit(300)->get();

        return [
            'id' => $c->id,
            'row' => $this->row($c),
            'phone_raw' => $c->phone,
            'contact_name' => $c->contact_name,
            'profile_name' => $c->profile_name,
            'window_closes_at' => $c->windowClosesAt()?->format(DATE_ATOM),
            'messages' => $messages->map(fn (Message $m) => $this->message($m))->all(),
            'events' => $events->map(fn (ConversationEvent $e) => $this->event($e))->all(),
            'has_older' => $messages->isNotEmpty() && Message::where('conversation_id', $c->id)->where('id', '<', $messages->first()->id)->exists(),
            'member' => $this->memberCard($c, $can),
            'members_sharing' => $c->member_id ? null : ($can['member'] ? $this->conversations->membersWithPhone($c->phone) : null),
            'can' => $can,
            'templates' => $can['reply'] && $c->member_id ? $this->templates() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function message(Message $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'source' => $m->source ?? ($m->direction === 'inbound' ? Message::SOURCE_CUSTOMER : ($m->sent_by ? Message::SOURCE_PLATFORM : Message::SOURCE_AUTOMATION)),
            'type' => $m->type,
            'body' => $m->body,
            'status' => $m->status,
            'error' => $m->status === Message::FAILED || $m->status === Message::SKIPPED ? ($m->error_message ?: (Message::SKIP_REASONS[$m->error_code] ?? $m->error_code)) : null,
            'template' => $m->template_key ? (TemplateCatalog::EVENTS[$m->template_key]['label'] ?? $m->template_name) : null,
            'sender' => $m->sender?->name,
            'at' => $m->happenedAt()->format(DATE_ATOM),
            'logged' => str_starts_with((string) $m->provider_message_id, 'log.'),
            'attachments' => $m->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'mime' => $a->mime,
                'filename' => $a->filename,
                'size' => $a->size,
                'status' => $a->status,
                'error' => $a->error,
                'url' => $a->status === Attachment::STORED ? route('club.whatsapp.attachments.show', $a) : null,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function event(ConversationEvent $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'body' => $e->type === ConversationEvent::NOTE ? $e->body : null,
            'text' => $this->eventText($e),
            'user' => $e->user?->name,
            'at' => $e->created_at->format(DATE_ATOM),
        ];
    }

    /**
     * The member linked to the chat, shown by what the viewer may see: the name and number with members.view, the
     * membership with memberships.view, what is owed with invoices.view. Without members.view: that it is a member.
     *
     * @param  array<string, bool>  $can
     * @return array<string, mixed>|null
     */
    private function memberCard(Conversation $c, array $can): ?array
    {
        $member = $c->member;
        if (! $member) {
            return null;
        }
        if (! $can['member']) {
            return ['restricted' => true];
        }

        $card = [
            'restricted' => false,
            'id' => $member->id,
            'name' => $member->full_name,
            'member_no' => $member->member_no,
            'phone' => Phone::display($member->phone),
            'status' => $member->status,
            'url' => route('club.members.show', $member),
            'deleted' => $member->trashed(),
        ];

        if ($can['membership']) {
            $current = Membership::where('member_id', $member->id)
                ->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED, Membership::PENDING])
                ->orderByRaw("case status when 'active' then 0 when 'frozen' then 1 when 'pending' then 2 else 3 end")->orderByDesc('ends_on')->first()
                ?? Membership::where('member_id', $member->id)->orderByDesc('ends_on')->first();
            $card['membership'] = $current ? [
                'plan' => (string) ($current->plan_snapshot['name'] ?? ''),
                'kind' => $current->kind,
                'status' => $current->status,
                'starts_on' => $current->starts_on->toDateString(),
                'ends_on' => $current->ends_on->toDateString(),
                'days_left' => $current->daysRemaining(),
            ] : null;
        }

        if ($can['finance']) {
            $open = Invoice::where('billable_type', $member->getMorphClass())->where('billable_id', $member->id)->open()->get();
            $card['owed'] = Money::add('0', ...$open->map(fn (Invoice $i) => $i->balance())->all());
            $card['open_invoices'] = $open->count();
        }

        return $card;
    }

    /** @return list<array{key: string, label: string, body: string}> */
    public function templates(): array
    {
        return MessageTemplate::where('is_active', true)->where('status', MessageTemplate::APPROVED)->orderBy('id')->get()
            ->map(fn (MessageTemplate $t) => ['key' => $t->key, 'label' => TemplateCatalog::EVENTS[$t->key]['label'] ?? $t->name, 'body' => $t->body])
            ->values()->all();
    }

    /** The staff who may be given a chat: active, and able to see chats. @return Collection<int, array{id: int, name: string}> */
    public function assignees(): Collection
    {
        return User::with('role')->where('is_active', true)->whereNotNull('tenant_id')->where('tenant_id', app(TenantContext::class)->id())
            ->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canAny(['whatsapp.view_all', 'whatsapp.view_assigned']))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values();
    }

    private function eventText(ConversationEvent $e): string
    {
        $who = $e->user?->name ?? 'النظام';
        $data = $e->data ?? [];

        return match ($e->type) {
            ConversationEvent::NOTE => 'ملاحظة داخلية من '.$who,
            ConversationEvent::ASSIGNED => ($data['to'] ?? null)
                ? (($data['from'] ?? null) ? 'حوّل '.$who.' المحادثة إلى '.($data['to_name'] ?? '') : 'عيّن '.$who.' المحادثة إلى '.($data['to_name'] ?? ''))
                : 'ألغى '.$who.' تعيين المحادثة',
            ConversationEvent::STATUS => 'غيّر '.$who.' الحالة إلى «'.(Conversation::STATUSES[$data['to'] ?? ''] ?? ($data['to'] ?? '')).'»',
            ConversationEvent::LINKED => 'ربط '.$who.' المحادثة بالمشترك'.(isset($data['member_no']) ? ' رقم '.$data['member_no'] : ''),
            ConversationEvent::UNLINKED => 'ألغى '.$who.' ربط المحادثة بالمشترك',
            default => $e->type,
        };
    }
}
