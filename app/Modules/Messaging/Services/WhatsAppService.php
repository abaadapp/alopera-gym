<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantIntegration;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Gateways\GatewayResult;
use App\Modules\Messaging\Gateways\WhatsAppGateway;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WhatsAppGateway $gateway,
    ) {
    }

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

        try {
            // Savepoint: a duplicate must not abort the caller's transaction (PostgreSQL aborts the whole block on error).
            $message = DB::transaction(fn () => Message::create([
                'member_id' => $member->id,
                'campaign_id' => $campaignId,
                'direction' => 'outbound',
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

        if (! $skip && ! $at) {
            SendWhatsAppMessage::dispatch($message->id, $message->tenant_id)->afterCommit();
        }

        return $message;
    }

    /** Free-text reply from the inbox; only allowed inside the 24h customer-service window. */
    public function reply(Conversation $conversation, string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'member_id' => $conversation->member_id,
            'direction' => 'outbound',
            'type' => 'text',
            'to_phone' => $conversation->phone,
            'body' => $body,
            'status' => $conversation->serviceWindowOpen() ? Message::QUEUED : Message::FAILED,
            'error_code' => $conversation->serviceWindowOpen() ? null : 'window_closed',
            'error_message' => $conversation->serviceWindowOpen() ? null : 'انتهت نافذة الـ24 ساعة؛ استخدمي قالبًا معتمدًا.',
            'sent_by' => Auth::guard('web')->id(),
        ]);

        $conversation->forceFill(['last_message_at' => now(), 'unread_count' => 0])->save();

        if ($message->status === Message::QUEUED) {
            SendWhatsAppMessage::dispatch($message->id, $message->tenant_id)->afterCommit();
        }

        return $message;
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
        } else {
            $result = $this->gateway->sendText($integration, (string) $message->to_phone, (string) $message->body);
        }

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

        return $result;
    }
}
