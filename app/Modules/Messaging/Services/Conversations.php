<?php

namespace App\Modules\Messaging\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Phone;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationEvent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\WhatsAppContact;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The life of a conversation: found or started for a number (and matched to the one member with that number), kept
 * up to date as messages arrive in any order, assigned and transferred, noted, linked to a member, closed and opened
 * again. Staff actions are written to the conversation's events and the audit log; an internal note's text stays in
 * the conversation (the audit log says a note was added, not what it says).
 */
final class Conversations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The conversation with a number, started if there is none. A new one is linked to a member only when exactly one
     * member has the number (family members often share a phone: then staff choose).
     */
    public function forPhone(string $phone, ?string $profileName = null, string $status = Conversation::NEW): Conversation
    {
        $phone = Phone::normalize($phone);
        $conversation = Conversation::where('phone', $phone)->first();
        if ($conversation) {
            if ($profileName && $conversation->profile_name !== $profileName) {
                $conversation->forceFill(['profile_name' => Str::limit($profileName, 250, '')])->save();
            }

            return $conversation;
        }

        $member = $this->matchMember($phone);

        return Conversation::create([
            'phone' => $phone,
            'member_id' => $member?->id,
            'contact_name' => WhatsAppContact::where('phone', $phone)->whereNull('removed_at')->value('name'),
            'profile_name' => $profileName ? Str::limit($profileName, 250, '') : null,
            'status' => $status,
        ]);
    }

    /** The one member with this number (Omani numbers with or without 968), or null when none or several have it. */
    public function matchMember(string $phone): ?Member
    {
        $found = Member::whereIn('phone', self::variants($phone))->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    /** How many members have this number (the inbox says when several share it). */
    public function membersWithPhone(string $phone): int
    {
        return Member::whereIn('phone', self::variants($phone))->count();
    }

    /** @return list<string> */
    public static function variants(string $phone): array
    {
        $phone = Phone::normalize($phone);
        $variants = [$phone, '+'.$phone];
        if (str_starts_with($phone, Phone::DEFAULT_COUNTRY_CODE) && strlen($phone) === 11) {
            $variants[] = substr($phone, 3);
        }

        return $variants;
    }

    /**
     * A message joined the conversation. Messages arrive late, twice or out of order (history, retries): the
     * conversation's last message, preview and the 24-hour window only ever move forward in WhatsApp's own time.
     * A live message from the customer counts as unread and opens a closed conversation; one from staff or the phone
     * is the first response if none came before; imported history changes neither.
     */
    public function recorded(Conversation $conversation, Message $message): void
    {
        $at = $message->happenedAt();
        $newest = $conversation->last_message_at === null || $at >= $conversation->last_message_at;
        $updates = [];

        if ($newest) {
            $updates['last_message_at'] = $at;
            $updates['last_preview'] = self::preview($message);
            $updates['last_direction'] = $message->direction;
        }

        if ($message->direction === 'inbound') {
            if ($conversation->first_inbound_at === null || $at < $conversation->first_inbound_at) {
                $updates['first_inbound_at'] = $at;
            }
            if ($message->source !== Message::SOURCE_HISTORY) {
                if ($conversation->last_inbound_at === null || $at > $conversation->last_inbound_at) {
                    $updates['last_inbound_at'] = $at;
                }
                $updates['unread_count'] = $conversation->unread_count + 1;
                if ($conversation->status === Conversation::CLOSED) {
                    $updates['status'] = Conversation::OPEN;
                    $updates['closed_at'] = null;
                }
            }
        } elseif (in_array($message->source, [Message::SOURCE_PLATFORM, Message::SOURCE_BUSINESS_APP], true)) {
            $first = $updates['first_inbound_at'] ?? $conversation->first_inbound_at;
            if ($conversation->first_response_at === null && $first !== null && $at >= $first) {
                $updates['first_response_at'] = $at;
            }
            if ($message->source === Message::SOURCE_PLATFORM && $conversation->status === Conversation::NEW) {
                $updates['status'] = Conversation::OPEN;
            }
        }

        if ($updates !== []) {
            $conversation->forceFill($updates)->save();
        }
    }

    /** Assign to a staff member (or nobody); a change of hands is a transfer. */
    public function assign(Conversation $conversation, ?User $to): void
    {
        $from = $conversation->assigned_to;
        if ($from === $to?->id) {
            return;
        }

        $conversation->forceFill([
            'assigned_to' => $to?->id,
            'assigned_at' => $to ? now() : null,
            'status' => $conversation->status === Conversation::NEW && $to ? Conversation::OPEN : $conversation->status,
        ])->save();

        $data = ['from' => $from, 'to' => $to?->id, 'to_name' => $to?->name];
        $this->event($conversation, ConversationEvent::ASSIGNED, null, $data);
        $this->audit->log($from ? 'whatsapp.conversation_transferred' : 'whatsapp.conversation_assigned', $conversation, $data);
    }

    public function setStatus(Conversation $conversation, string $status): void
    {
        if ($conversation->status === $status || ! isset(Conversation::STATUSES[$status])) {
            return;
        }

        $from = $conversation->status;
        $conversation->forceFill(['status' => $status, 'closed_at' => $status === Conversation::CLOSED ? now() : null])->save();

        $this->event($conversation, ConversationEvent::STATUS, null, ['from' => $from, 'to' => $status]);
        $this->audit->log('whatsapp.conversation_status', $conversation, ['from' => $from, 'to' => $status]);
    }

    public function link(Conversation $conversation, ?Member $member): void
    {
        $from = $conversation->member_id;
        if ($from === $member?->id) {
            return;
        }

        $conversation->forceFill(['member_id' => $member?->id])->save();
        // The chat's messages follow it, so the member's page shows them.
        Message::where('conversation_id', $conversation->id)->update(['member_id' => $member?->id]);

        $data = ['from' => $from, 'to' => $member?->id, 'member_no' => $member?->member_no];
        $this->event($conversation, $member ? ConversationEvent::LINKED : ConversationEvent::UNLINKED, null, $data);
        $this->audit->log($member ? 'whatsapp.conversation_linked' : 'whatsapp.conversation_unlinked', $conversation, $data);
    }

    /** An internal note: seen by staff in the conversation, never sent to the customer. */
    public function note(Conversation $conversation, string $body): ConversationEvent
    {
        $event = $this->event($conversation, ConversationEvent::NOTE, $body);
        $this->audit->log('whatsapp.note_added', $conversation, ['event' => $event->id]);

        return $event;
    }

    /** What the conversation list shows for a message. */
    public static function preview(Message $message): string
    {
        $text = trim((string) $message->body);
        if ($message->type === 'media' && ($text === '' || str_starts_with($text, '['))) {
            $kind = $message->relationLoaded('attachments') ? $message->attachments->first()?->kind : $message->attachments()->value('kind');
            $text = ['image' => '📷 صورة', 'video' => '🎬 فيديو', 'audio' => '🎤 رسالة صوتية', 'document' => '📄 مستند', 'sticker' => 'ملصق'][$kind] ?? ($text ?: 'مرفق');
        }

        return Str::limit($text, 190, '…');
    }

    /** @param  array<string, mixed>|null  $data */
    private function event(Conversation $conversation, string $type, ?string $body = null, ?array $data = null): ConversationEvent
    {
        return $conversation->events()->create([
            'user_id' => Auth::guard('web')->id(),
            'type' => $type,
            'body' => $body,
            'data' => $data,
        ]);
    }
}
