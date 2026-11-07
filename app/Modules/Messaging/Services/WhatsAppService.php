<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Gateways\GatewayResult;
use App\Modules\Messaging\Gateways\WhatsAppGateway;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\StatusBacklog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Queues and sends WhatsApp messages for the current club.
 *
 * Every message is a row first (status `queued` or `scheduled`) and is sent by
 * a queued job after the surrounding transaction commits, so a rolled-back
 * sale never texts the member.
 */
final class WhatsAppService
{
    /** Meta error codes that concern the account or its credentials, not one message. */
    private const CONNECTION_ERRORS = ['190', '10', '100', '200', '368', '131031', '133010'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WhatsAppGateway $gateway,
        private readonly Conversations $conversations,
        private readonly MediaStore $media,
    ) {}

    public function integration(): ?TenantIntegration
    {
        return TenantIntegration::where('provider', TenantIntegration::WHATSAPP)->first();
    }

    public function enabled(): bool
    {
        return (bool) $this->integration()?->is_enabled;
    }

    /**
     * Queue a template message for an event.
     *
     * @param  array<string, string>  $values  available variable values by name
     */
    public function queueTemplate(Member $member, string $key, array $values, ?Model $related = null, ?string $dedupeKey = null, ?\DateTimeInterface $at = null, ?int $campaignId = null, bool $marketing = false): ?Message
    {
        if (! $this->enabled()) {
            return null;
        }

        $template = MessageTemplate::where('key', $key)->first();
        // Campaigns and marketing-category templates need marketing consent; service messages do not.
        $marketing = $marketing || $campaignId !== null || $template?->category === 'marketing';
        $skip = match (true) {
            ! $template => 'template_missing',
            ! $template->isUsable() => 'template_not_approved',
            ! $member->whatsapp_opt_in => 'opted_out',
            $marketing && ! $member->canReceiveMarketing() => 'no_marketing_consent',
            Phone::normalize($member->phone) === '' => 'no_phone',
            default => null,
        };

        $params = $template ? array_map(fn ($name) => (string) ($values[$name] ?? ''), $template->variables ?? []) : [];

        if ($dedupeKey !== null && Message::where('dedupe_key', $dedupeKey)->exists()) {
            return null; // already sent for this event
        }

        // Shown in the member's WhatsApp chat when there is one; an automatic notice never starts a chat by itself.
        $conversation = Phone::normalize($member->phone) !== '' ? Conversation::where('phone', Phone::normalize($member->phone))->first() : null;

        try {
            // Savepoint: a duplicate must not abort the caller's transaction (PostgreSQL aborts the whole block on error).
            $message = DB::transaction(fn () => Message::create([
                'conversation_id' => $conversation?->id,
                'member_id' => $member->id,
                'campaign_id' => $campaignId,
                'direction' => 'outbound',
                'source' => Auth::guard('web')->check() ? Message::SOURCE_PLATFORM : Message::SOURCE_AUTOMATION,
                'type' => 'template',
                'to_phone' => Phone::normalize($member->phone),
                'template_key' => $key,
                'template_name' => $template?->name,
                'template_params' => $params,
                'body' => $template?->render($params),
                'status' => $skip ? Message::SKIPPED : ($at ? Message::SCHEDULED : Message::QUEUED),
                'error_code' => $skip,
                'dedupe_key' => $dedupeKey,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'scheduled_at' => $at,
                'sent_by' => Auth::guard('web')->id(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // already sent for this dedupe key
        }

        if ($conversation && ! $skip) {
            $this->conversations->recorded($conversation, $message);
        }

        if (! $skip && ! $at) {
            SendWhatsAppMessage::dispatch($message->id, $message->tenant_id)->afterCommit();
        }

        return $message;
    }

    /**
     * Queue a template message to a staff member (an internal alert such as a document about to expire). Only
     * an approved template is sent; a staff member without a phone is skipped. Returns null when WhatsApp is
     * off for the club or the alert was already sent (dedupe key).
     *
     * @param  array<string, string>  $values
     */
    public function queueStaffTemplate(User $user, string $key, array $values, ?Model $related, string $dedupeKey): ?Message
    {
        if (! $this->enabled() || Message::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $template = MessageTemplate::where('key', $key)->first();
        $phone = Phone::normalize($user->phone);
        $skip = match (true) {
            ! $template => 'template_missing',
            ! $template->isUsable() => 'template_not_approved',
            $phone === '' => 'no_phone',
            default => null,
        };
        $params = $template ? array_map(fn ($name) => (string) ($values[$name] ?? ''), $template->variables ?? []) : [];

        try {
            $message = DB::transaction(fn () => Message::create([
                'direction' => 'outbound',
                'source' => Message::SOURCE_AUTOMATION,
                'type' => 'template',
                'to_phone' => $phone ?: null,
                'template_key' => $key,
                'template_name' => $template?->name,
                'template_params' => $params,
                'body' => $template?->render($params),
                'status' => $skip ? Message::SKIPPED : Message::QUEUED,
                'error_code' => $skip,
                'dedupe_key' => $dedupeKey,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if (! $skip) {
            SendWhatsAppMessage::dispatch($message->id, $message->tenant_id)->afterCommit();
        }

        return $message;
    }

    /**
     * Free-text reply in a chat; only inside the 24-hour customer-service window (outside it, an approved template).
     * A staff reply marks the chat read; an automatic one (an auto-reply, the opt-out answer) leaves it unread.
     */
    public function reply(Conversation $conversation, string $body): Message
    {
        $staff = Auth::guard('web')->check();
        $open = $conversation->serviceWindowOpen();
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'member_id' => $conversation->member_id,
            'direction' => 'outbound',
            'source' => $staff ? Message::SOURCE_PLATFORM : Message::SOURCE_AUTOMATION,
            'type' => 'text',
            'to_phone' => $conversation->phone,
            'body' => $body,
            'status' => $open ? Message::QUEUED : Message::FAILED,
            'error_code' => $open ? null : 'window_closed',
            'error_message' => $open ? null : 'انتهت نافذة الـ24 ساعة؛ استخدم قالبًا معتمدًا.',
            'sent_by' => Auth::guard('web')->id(),
        ]);

        $this->sent($conversation, $message, $staff);

        return $message;
    }

    /** A file sent from the chat (within the 24-hour window), kept on the private disk and uploaded when sent. */
    public function sendMedia(Conversation $conversation, UploadedFile $file, ?string $caption): Message
    {
        $mime = (string) $file->getMimeType();
        $open = $conversation->serviceWindowOpen();

        $message = DB::transaction(function () use ($conversation, $file, $caption, $mime, $open) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'member_id' => $conversation->member_id,
                'direction' => 'outbound',
                'source' => Message::SOURCE_PLATFORM,
                'type' => 'media',
                'to_phone' => $conversation->phone,
                'body' => $caption !== null && $caption !== '' ? $caption : null,
                'status' => $open ? Message::QUEUED : Message::FAILED,
                'error_code' => $open ? null : 'window_closed',
                'error_message' => $open ? null : 'انتهت نافذة الـ24 ساعة؛ استخدم قالبًا معتمدًا.',
                'sent_by' => Auth::guard('web')->id(),
            ]);
            $message->attachments()->create([
                'kind' => Attachment::kindFor($mime) ?? 'document',
                'mime' => $mime,
                'size' => $file->getSize(),
                'filename' => mb_substr($file->getClientOriginalName(), 0, 250),
                'caption' => $caption,
                'path' => $this->media->storeUpload($file, $conversation->tenant_id),
                'status' => Attachment::STORED,
            ]);

            return $message;
        });

        $this->sent($conversation, $message, true);

        return $message;
    }

    private function sent(Conversation $conversation, Message $message, bool $staff): void
    {
        $this->conversations->recorded($conversation, $message);
        if ($staff) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
        if ($message->status === Message::QUEUED) {
            SendWhatsAppMessage::dispatch($message->id, $message->tenant_id)->afterCommit();
        }
    }

    /**
     * A delivery status from WhatsApp. Statuses arrive out of order: a message never moves back (read → delivered);
     * a failure is kept with its reason.
     */
    public function applyStatus(Message $message, string $state, ?string $code = null, ?string $error = null): void
    {
        if ($state === 'failed') {
            $message->forceFill([
                'status' => Message::FAILED,
                'error_code' => $code ?? 'failed',
                'error_message' => $error !== null ? mb_substr($error, 0, 250) : null,
            ])->save();
        } elseif (isset(Message::PROGRESS[$state]) && Message::PROGRESS[$state] > (Message::PROGRESS[$message->status] ?? -1) && $message->status !== Message::FAILED) {
            $message->forceFill([
                'status' => $state,
                'delivered_at' => in_array($state, [Message::DELIVERED, Message::READ], true) ? ($message->delivered_at ?? now()) : $message->delivered_at,
                'read_at' => $state === Message::READ ? now() : $message->read_at,
            ])->save();
        }

        if ($message->campaign_id) {
            CampaignService::refreshCounts($message->campaign_id);
        }
    }

    /** Called by the queue worker. */
    public function deliver(Message $message): GatewayResult
    {
        $integration = $this->integration();

        if (! $integration || ! $integration->is_enabled) {
            $result = GatewayResult::failed('not_configured', 'واتساب غير مفعّل لهذا النادي.');
        } elseif ($message->type === 'template') {
            $template = MessageTemplate::where('key', $message->template_key)->first();
            $result = $this->gateway->sendTemplate(
                $integration,
                (string) $message->to_phone,
                (string) $message->template_name,
                $template?->language ?? 'ar',
                $message->template_params ?? []
            );
        } elseif ($message->type === 'media') {
            $result = $this->deliverMedia($integration, $message);
        } else {
            $result = $this->gateway->sendText($integration, (string) $message->to_phone, (string) $message->body);
        }

        $this->recordHealth($integration, $result);

        DB::transaction(function () use ($message, $result) {
            $message->forceFill([
                'attempts' => $message->attempts + 1,
                'status' => $result->ok ? Message::SENT : ($result->retryable ? $message->status : Message::FAILED),
                'provider_message_id' => $result->messageId ?? $message->provider_message_id,
                'error_code' => $result->ok ? null : $result->errorCode,
                'error_message' => $result->ok ? null : $result->errorMessage,
                'sent_at' => $result->ok ? now() : $message->sent_at,
            ])->save();

            if ($message->campaign_id) {
                CampaignService::refreshCounts($message->campaign_id);
            }
        });

        // A status that came before WhatsApp's answer to the send waited for this id.
        if ($result->ok && $message->provider_message_id) {
            StatusBacklog::where('provider_message_id', $message->provider_message_id)->orderBy('id')->get()
                ->each(function (StatusBacklog $early) use ($message) {
                    $this->applyStatus($message->refresh(), $early->status, $early->error_code, $early->error_message);
                    $early->delete();
                });
        }

        return $result;
    }

    /** Upload the file once (its WhatsApp id is kept for a retry), then send it. */
    private function deliverMedia(TenantIntegration $integration, Message $message): GatewayResult
    {
        $attachment = $message->attachments()->first();
        if (! $attachment) {
            return GatewayResult::failed('no_file', 'لا ملف مرفق بالرسالة.');
        }
        if (! $attachment->provider_media_id) {
            $path = $this->media->absolutePath($attachment);
            if ($path === null) {
                return GatewayResult::failed('no_file', 'الملف غير موجود على الخادم.');
            }
            $upload = $this->gateway->uploadMedia($integration, $path, (string) $attachment->mime, (string) ($attachment->filename ?: basename($path)));
            if (! $upload->ok) {
                return $upload;
            }
            $attachment->forceFill(['provider_media_id' => $upload->messageId])->save();
        }

        return $this->gateway->sendMedia($integration, (string) $message->to_phone, $attachment->kind, (string) $attachment->provider_media_id, $message->body, $attachment->filename);
    }

    /**
     * What a send says about the connection itself: a message Meta accepted proves it (the development log driver
     * proves nothing); a refused token, a wrong number id or a blocked account is shown on the integrations screen.
     * Errors about one message (a template, a recipient) say nothing about the connection and are left out.
     */
    private function recordHealth(?TenantIntegration $integration, GatewayResult $result): void
    {
        if (! $integration?->exists) {
            return;
        }
        if ($result->ok) {
            if (! str_starts_with((string) $result->messageId, 'log.')) {
                $integration->markVerified();
            }

            return;
        }
        if (in_array($result->errorCode, self::CONNECTION_ERRORS, true)) {
            $integration->markFailed('رفضت Meta الإرسال (رمز '.$result->errorCode.'): '.$result->errorMessage);
        }
    }
}
