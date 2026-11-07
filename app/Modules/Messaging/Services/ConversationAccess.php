<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and do what in the WhatsApp inbox. Every list, thread, live update, file and action goes through here
 * on the server; the screen only hides what this already refuses. A conversation someone may not see does not exist
 * for them (404), so its number and name are not given away.
 *
 * - whatsapp.view_all: every conversation of the club; whatsapp.view_assigned: the ones assigned to them.
 * - whatsapp.reply: answer, change the status, add internal notes — in a conversation they see.
 * - whatsapp.send_media: send files — in a conversation they see.
 * - whatsapp.assign: assign and transfer; with members.view also link a conversation to a member.
 * - The member's card shows personal data with members.view, the membership with memberships.view, money owed with
 *   invoices.view.
 */
final class ConversationAccess
{
    /** @param  Builder<Conversation>  $query */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('whatsapp.view_all')) {
            return $query;
        }
        if ($user->can('whatsapp.view_assigned')) {
            return $query->where('assigned_to', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function canView(User $user, Conversation $conversation): bool
    {
        return $user->can('whatsapp.view_all')
            || ($user->can('whatsapp.view_assigned') && (int) $conversation->assigned_to === $user->id);
    }

    /** Reach the conversation or stop here with a 404. */
    public function authorize(User $user, Conversation $conversation): void
    {
        abort_unless($this->canView($user, $conversation), 404);
    }

    public function canReply(User $user, Conversation $conversation): bool
    {
        return $user->can('whatsapp.reply') && $this->canView($user, $conversation);
    }

    public function canSendMedia(User $user, Conversation $conversation): bool
    {
        return $user->can('whatsapp.send_media') && $this->canReply($user, $conversation);
    }

    public function canAssign(User $user, Conversation $conversation): bool
    {
        return $user->can('whatsapp.assign') && $this->canView($user, $conversation);
    }

    public function canLinkMember(User $user, Conversation $conversation): bool
    {
        return $this->canAssign($user, $conversation) && $user->can('members.view');
    }

    /** @return array{reply: bool, media: bool, assign: bool, link: bool, member: bool, membership: bool, finance: bool} */
    public function abilities(User $user, Conversation $conversation): array
    {
        return [
            'reply' => $this->canReply($user, $conversation),
            'media' => $this->canSendMedia($user, $conversation),
            'assign' => $this->canAssign($user, $conversation),
            'link' => $this->canLinkMember($user, $conversation),
            'member' => $user->can('members.view'),
            'membership' => $user->can('memberships.view'),
            'finance' => $user->can('invoices.view'),
        ];
    }
}
