<?php

namespace App\Modules\Messaging\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantIntegration;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Processes a verified WhatsApp Cloud API webhook payload: inbound messages
 * (inbox, opt-out keywords, auto-replies) and delivery status updates.
 */
final class InboundProcessor
{
    /** Words that withdraw marketing consent. */
    public const STOP_WORDS = ['stop', 'unsubscribe', 'إيقاف', 'ايقاف', 'الغاء', 'إلغاء الاشتراك', 'توقف'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WhatsAppService $whatsapp,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): int
    {
        $handled = 0;

        foreach (Arr::get($payload, 'entry', []) as $entry) {
            foreach (Arr::get($entry, 'changes', []) as $change) {
                $value = Arr::get($change, 'value', []);
                $phoneNumberId = (string) Arr::get($value, 'metadata.phone_number_id', '');
                if ($phoneNumberId === '') {
                    continue;
                }

                // Find which club owns this WhatsApp number — the only cross-tenant lookup here.
                $integration = $this->tenants->bypass(fn () => TenantIntegration::with('tenant')
                    ->where('provider', TenantIntegration::WHATSAPP)
                    ->where('public_identifier', $phoneNumberId)
                    ->first());

                if (! $integration?->tenant) {
                    continue;
                }

                $this->tenants->run($integration->tenant, function () use ($value, &$handled, $integration) {
                    $integration->markWebhook(); // the signature was checked by the controller
                    $contacts = collect(Arr::get($value, 'contacts', []))->keyBy('wa_id');

                    foreach (Arr::get($value, 'messages', []) as $incoming) {
                        $this->inbound($incoming, Arr::get($contacts->get($incoming['from'] ?? ''), 'profile.name'));
                        $handled++;
                    }

                    foreach (Arr::get($value, 'statuses', []) as $status) {
                        $this->status($status);
                        $handled++;
                    }
                });
            }
        }

        return $handled;
    }

    /** @param array<string, mixed> $incoming */
    private function inbound(array $incoming, ?string $profileName): void
    {
        $providerId = (string) ($incoming['id'] ?? '');
        if ($providerId !== '' && Message::where('provider_message_id', $providerId)->exists()) {
            return; // Meta retries webhooks; store each message once
        }

        $phone = Phone::normalize((string) ($incoming['from'] ?? ''));
        $type = (string) ($incoming['type'] ?? 'text');
        $text = match ($type) {
            'text' => (string) Arr::get($incoming, 'text.body', ''),
            'button' => (string) Arr::get($incoming, 'button.text', ''),
            'interactive' => (string) (Arr::get($incoming, 'interactive.button_reply.title') ?? Arr::get($incoming, 'interactive.list_reply.title', '')),
            default => '['.$type.']',
        };

        DB::transaction(function () use ($phone, $profileName, $providerId, $type, $text) {
            $member = Member::where('phone', $phone)->first();

            $conversation = Conversation::firstOrCreate(['phone' => $phone], [
                'member_id' => $member?->id,
                'contact_name' => $member?->full_name ?? $profileName,
                'status' => 'open',
            ]);

            Message::create([
                'conversation_id' => $conversation->id,
                'member_id' => $member?->id,
                'direction' => 'inbound',
                'type' => $type === 'text' ? 'text' : 'media',
                'to_phone' => $phone,
                'body' => $text,
                'status' => Message::RECEIVED,
                'provider_message_id' => $providerId ?: null,
            ]);

            $conversation->forceFill([
                'member_id' => $conversation->member_id ?? $member?->id,
                'status' => 'open',
                'unread_count' => $conversation->unread_count + 1,
                'last_message_at' => now(),
                'last_inbound_at' => now(),
            ])->save();
            app(StaffAlerts::class)->inbox($conversation);

            $normalized = mb_strtolower(trim($text));

            if ($member && in_array($normalized, self::STOP_WORDS, true)) {
                if ($member->marketing_opt_in) {
                    $member->forceFill(['marketing_opt_in' => false, 'marketing_opt_out_at' => now()])->save();
                    $this->audit->log('member.marketing_opt_out', $member, ['via' => 'whatsapp']);
                }
                $this->whatsapp->reply($conversation, 'تم إيقاف الرسائل التسويقية. ستصلك فقط رسائل الخدمة المتعلقة باشتراكك وحجوزاتك.');

                return;
            }

            $rule = AutoReply::where('is_active', true)->get()->first(fn (AutoReply $r) => $r->matches($text));
            if ($rule) {
                $rule->increment('hits');
                $this->whatsapp->reply($conversation, $rule->reply);
            }
        });
    }

    /** @param array<string, mixed> $status */
    private function status(array $status): void
    {
        $message = Message::where('provider_message_id', (string) ($status['id'] ?? ''))->first();
        if (! $message) {
            return;
        }

        $state = (string) ($status['status'] ?? '');
        $rank = [Message::QUEUED => 0, Message::SENT => 1, Message::DELIVERED => 2, Message::READ => 3];

        // Statuses can arrive out of order; never move backwards (read → delivered).
        if ($state === 'failed') {
            $message->forceFill([
                'status' => Message::FAILED,
                'error_code' => (string) Arr::get($status, 'errors.0.code', 'failed'),
                'error_message' => (string) Arr::get($status, 'errors.0.title', ''),
            ])->save();
        } elseif (isset($rank[$state]) && $rank[$state] > ($rank[$message->status] ?? -1)) {
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
}
