<?php

namespace App\Modules\Messaging\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Jobs\FetchWhatsAppMedia;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\StatusBacklog;
use App\Modules\Messaging\Models\WhatsAppContact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Processes a verified WhatsApp webhook body (Cloud API format, from Meta or 360dialog). Each change says what it is:
 *
 * - messages: what customers send (text, files, locations…) and the delivery statuses of what the club sent.
 * - smb_message_echoes: what the owner sends from the WhatsApp Business app on the phone (Coexistence). Shown in the
 *   chat as sent from the app, never as a staff member's; it neither opens the 24-hour window nor triggers anything.
 * - history: the past chats the phone shares once after connecting; imported only when the club allowed it.
 * - smb_app_state_sync: the phone's contacts; kept (as names for chats) only when the club allowed it.
 * - account_update: the number was disconnected from or reconnected to the platform.
 *
 * Every message is keyed by its WhatsApp id, so a repeated delivery changes nothing; WhatsApp's own times put late
 * messages in their place; a status that comes before its message waits for it (StatusBacklog).
 */
final class InboundProcessor
{
    /** Words that withdraw marketing consent. */
    public const STOP_WORDS = ['stop', 'unsubscribe', 'إيقاف', 'ايقاف', 'الغاء', 'إلغاء الاشتراك', 'توقف'];

    private const MEDIA = ['image', 'video', 'audio', 'document', 'sticker'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WhatsAppService $whatsapp,
        private readonly Conversations $conversations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{handled: int, tenant_id: int|null, note: string|null}
     */
    public function handle(array $payload, string $vendor = 'meta'): array
    {
        $handled = 0;
        $tenantId = null;
        $note = null;

        foreach (Arr::get($payload, 'entry', []) as $entry) {
            foreach (Arr::get($entry, 'changes', []) as $change) {
                $field = (string) Arr::get($change, 'field', 'messages');
                $value = (array) Arr::get($change, 'value', []);
                $integration = $this->integrationFor($field, $value, (string) Arr::get($entry, 'id', ''));
                if (! $integration?->tenant) {
                    $note = 'لا نادي مربوط بهذا الرقم.';

                    continue;
                }

                $tenantId ??= $integration->tenant_id;
                $this->tenants->run($integration->tenant, function () use ($field, $value, $integration, &$handled, &$note) {
                    $integration->markWebhook(); // the signature or token was checked by the controller
                    $count = match ($field) {
                        'messages' => $this->messages($value),
                        'smb_message_echoes' => $this->echoes($value),
                        'history' => $this->history($value, $integration),
                        'smb_app_state_sync' => $this->contacts($value, $integration),
                        'account_update' => $this->accountUpdate($value, $integration),
                        default => 0,
                    };
                    if ($count === 0 && in_array($field, ['history', 'smb_app_state_sync'], true)) {
                        $note = 'الاستيراد من تطبيق WhatsApp Business غير مسموح في إعدادات النادي.';
                    }
                    $handled += $count;
                });
            }
        }

        return ['handled' => $handled, 'tenant_id' => $tenantId, 'note' => $handled ? null : ($note ?? 'لا شيء للمعالجة.')];
    }

    /**
     * The club a change belongs to: by the number's id in the change (the only lookup across clubs), or for an
     * account update by the WhatsApp Business account id.
     *
     * @param  array<string, mixed>  $value
     */
    private function integrationFor(string $field, array $value, string $entryId): ?TenantIntegration
    {
        $phoneNumberId = (string) Arr::get($value, 'metadata.phone_number_id', '');

        return $this->tenants->bypass(function () use ($field, $phoneNumberId, $entryId, $value) {
            $query = TenantIntegration::with('tenant')->where('provider', TenantIntegration::WHATSAPP);
            if ($phoneNumberId !== '') {
                return $query->where('public_identifier', $phoneNumberId)->first();
            }
            if ($field === 'account_update') {
                $waba = (string) (Arr::get($value, 'waba_info.waba_id') ?: $entryId);

                return $waba !== '' ? $query->where('settings->waba_id', $waba)->first() : null;
            }

            return null;
        });
    }

    /** @param array<string, mixed> $value */
    private function messages(array $value): int
    {
        $count = 0;
        $contacts = collect(Arr::get($value, 'contacts', []))->keyBy('wa_id');

        foreach (Arr::get($value, 'messages', []) as $incoming) {
            $this->inbound($incoming, Arr::get($contacts->get($incoming['from'] ?? ''), 'profile.name'));
            $count++;
        }
        foreach (Arr::get($value, 'statuses', []) as $status) {
            $this->status($status);
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $incoming */
    private function inbound(array $incoming, ?string $profileName): void
    {
        $providerId = (string) ($incoming['id'] ?? '');
        if ($providerId !== '' && Message::where('provider_message_id', $providerId)->exists()) {
            return; // WhatsApp retries webhooks; each message is stored once
        }

        DB::transaction(function () use ($incoming, $profileName, $providerId) {
            $conversation = $this->conversations->forPhone((string) ($incoming['from'] ?? ''), $profileName);
            $message = $this->store($conversation, $incoming, 'inbound', Message::SOURCE_CUSTOMER, Message::RECEIVED, $providerId);
            $this->conversations->recorded($conversation, $message);
            app(StaffAlerts::class)->inbox($conversation->refresh());

            if ($message->type !== 'text' || ($incoming['type'] ?? 'text') !== 'text') {
                return;
            }
            $text = (string) $message->body;
            $member = $conversation->member;
            if ($member && in_array(mb_strtolower(trim($text)), self::STOP_WORDS, true)) {
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

    /** What the owner sent from the WhatsApp Business app on the phone (Coexistence). */
    private function echoes(array $value): int
    {
        $count = 0;
        foreach (Arr::get($value, 'message_echoes', []) as $echo) {
            $providerId = (string) ($echo['id'] ?? '');
            if ($providerId !== '' && Message::where('provider_message_id', $providerId)->exists()) {
                continue;
            }
            DB::transaction(function () use ($echo, $providerId) {
                $conversation = $this->conversations->forPhone((string) ($echo['to'] ?? ''), null, Conversation::OPEN);
                $message = $this->store($conversation, $echo, 'outbound', Message::SOURCE_BUSINESS_APP, Message::SENT, $providerId);
                $this->conversations->recorded($conversation, $message);
            });
            $count++;
        }

        return $count;
    }

    /** The past chats the phone shares once after connecting: only when the club allowed the import. */
    private function history(array $value, TenantIntegration $integration): int
    {
        if (! $integration->option('import_history')) {
            return 0;
        }
        $count = 0;

        foreach (Arr::get($value, 'history', []) as $item) {
            if ($errors = Arr::get($item, 'errors')) {
                // The owner declined to share the history in the app (code 2593109), or it failed.
                $integration->forceFill(['settings' => array_merge($integration->settings ?? [], [
                    'history_state' => 'declined', 'history_note' => mb_substr((string) Arr::get($errors, '0.title', 'رُفضت مشاركة السجل.'), 0, 200),
                ])])->saveQuietly();
                $count++;

                continue;
            }

            foreach (Arr::get($item, 'threads', []) as $thread) {
                $customer = Phone::normalize((string) (Arr::get($thread, 'context.wa_id') ?: Arr::get($thread, 'id', '')));
                if ($customer === '') {
                    continue;
                }
                DB::transaction(function () use ($thread, $customer, &$count) {
                    $conversation = $this->conversations->forPhone($customer, null, Conversation::CLOSED);
                    foreach (Arr::get($thread, 'messages', []) as $past) {
                        $providerId = (string) ($past['id'] ?? '');
                        if ($providerId !== '' && Message::where('provider_message_id', $providerId)->exists()) {
                            continue;
                        }
                        $inbound = Phone::normalize((string) ($past['from'] ?? '')) === $customer;
                        $seen = strtolower((string) Arr::get($past, 'history_context.status', ''));
                        $status = $inbound ? Message::RECEIVED : (in_array($seen, ['read', 'played'], true) ? Message::READ : ($seen === 'delivered' ? Message::DELIVERED : Message::SENT));
                        $message = $this->store($conversation, $past, $inbound ? 'inbound' : 'outbound', Message::SOURCE_HISTORY, $status, $providerId);
                        $this->conversations->recorded($conversation, $message);
                        $count++;
                    }
                });
            }

            $progress = Arr::get($item, 'metadata.progress');
            $integration->forceFill(['settings' => array_merge($integration->settings ?? [], array_filter([
                'history_state' => $progress !== null && (int) $progress >= 100 ? 'done' : 'importing',
                'history_phase' => Arr::get($item, 'metadata.phase'),
                'history_progress' => $progress,
            ], fn ($v) => $v !== null))])->saveQuietly();
        }

        return $count;
    }

    /** The phone's contacts: names for chats, kept only when the club allowed it. Nothing else is created. */
    private function contacts(array $value, TenantIntegration $integration): int
    {
        if (! $integration->option('import_contacts')) {
            return 0;
        }
        $count = 0;
        foreach (Arr::get($value, 'state_sync', []) as $item) {
            if (($item['type'] ?? '') !== 'contact') {
                continue;
            }
            $phone = Phone::normalize((string) Arr::get($item, 'contact.phone_number', ''));
            if ($phone === '') {
                continue;
            }
            $name = mb_substr((string) (Arr::get($item, 'contact.full_name') ?: Arr::get($item, 'contact.first_name', '')), 0, 250) ?: null;
            if (($item['action'] ?? 'add') === 'remove') {
                WhatsAppContact::where('phone', $phone)->update(['removed_at' => now()]);
            } else {
                WhatsAppContact::updateOrCreate(['phone' => $phone], ['name' => $name, 'removed_at' => null]);
                if ($name) {
                    Conversation::where('phone', $phone)->whereNull('contact_name')->update(['contact_name' => $name]);
                }
            }
            $count++;
        }

        return $count;
    }

    /** The number left the platform or came back: shown on the settings page and to the owner at once. */
    private function accountUpdate(array $value, TenantIntegration $integration): int
    {
        $event = (string) Arr::get($value, 'event', '');
        $reason = (string) Arr::get($value, 'disconnection_info.reason', '');

        if (in_array($event, ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED'], true)) {
            $integration->forceFill(['settings' => array_merge($integration->settings ?? [], ['connection_state' => 'disconnected', 'disconnected_reason' => $reason ?: $event])])->saveQuietly();
            $integration->markFailed('انفصل الرقم عن المنصة ('.($reason ?: $event).'). افتح تطبيق WhatsApp Business على الهاتف وأعد الربط من الإعدادات.');
            $this->audit->log('whatsapp.disconnected', $integration, ['event' => $event, 'reason' => $reason]);

            return 1;
        }
        if ($event === 'ACCOUNT_RECONNECTED') {
            $integration->forceFill(['settings' => array_merge($integration->settings ?? [], ['connection_state' => 'connected', 'disconnected_reason' => null])])->saveQuietly();
            $integration->markVerified();
            $this->audit->log('whatsapp.reconnected', $integration, ['event' => $event]);

            return 1;
        }
        if (str_contains($event, 'BAN') || in_array($event, ['DISABLED_UPDATE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION'], true)) {
            $integration->markFailed('أبلغت Meta عن حدث على الحساب: '.$event.'.');

            return 1;
        }

        return $event !== '' ? 1 : 0;
    }

    /** @param array<string, mixed> $status */
    private function status(array $status): void
    {
        $id = (string) ($status['id'] ?? '');
        $state = (string) ($status['status'] ?? '');
        $at = isset($status['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $status['timestamp'], config('app.timezone')) : null;
        $code = Arr::get($status, 'errors.0.code');
        $error = Arr::get($status, 'errors.0.error_data.details') ?? Arr::get($status, 'errors.0.title');

        $message = $id !== '' ? Message::where('provider_message_id', $id)->first() : null;
        if (! $message) {
            if ($id !== '' && $state !== '') {
                StatusBacklog::create(['provider_message_id' => $id, 'status' => $state, 'error_code' => $code !== null ? (string) $code : null,
                    'error_message' => $error !== null ? mb_substr((string) $error, 0, 250) : null, 'wa_timestamp' => $at]);
            }

            return;
        }

        $this->whatsapp->applyStatus($message, $state, $code !== null ? (string) $code : null, $error !== null ? (string) $error : null);
    }

    /**
     * One message (live, echoed or imported) and its file, if any. Media is downloaded after the transaction commits.
     *
     * @param  array<string, mixed>  $data
     */
    private function store(Conversation $conversation, array $data, string $direction, string $source, string $status, string $providerId): Message
    {
        $type = (string) ($data['type'] ?? 'text');
        $media = in_array($type, self::MEDIA, true) ? (array) ($data[$type] ?? []) : null;

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'member_id' => $conversation->member_id,
            'direction' => $direction,
            'source' => $source,
            'type' => $media !== null ? 'media' : 'text',
            'to_phone' => $conversation->phone,
            'body' => $media !== null ? (($data[$type]['caption'] ?? null) ?: null) : $this->text($type, $data),
            'status' => $status,
            'provider_message_id' => $providerId ?: null,
            'wa_timestamp' => isset($data['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $data['timestamp'], config('app.timezone')) : null,
            'sent_at' => $direction === 'outbound' ? now() : null,
        ]);

        if ($media !== null) {
            $attachment = $message->attachments()->create([
                'kind' => $type,
                'mime' => isset($media['mime_type']) ? mb_substr((string) $media['mime_type'], 0, 120) : null,
                'filename' => isset($media['filename']) ? mb_substr((string) $media['filename'], 0, 250) : null,
                'caption' => $media['caption'] ?? null,
                'provider_media_id' => $media['id'] ?? null,
                'sha256' => isset($media['sha256']) ? mb_substr((string) $media['sha256'], 0, 100) : null,
                'status' => isset($media['id']) ? Attachment::PENDING : Attachment::FAILED,
                'error' => isset($media['id']) ? null : 'لم يرسل واتساب معرّف الملف.',
            ]);
            if ($attachment->provider_media_id) {
                FetchWhatsAppMedia::dispatch($attachment->id, $attachment->tenant_id, isset($media['url']) ? (string) $media['url'] : null)->afterCommit();
            }
        }

        return $message;
    }

    /** A readable line for what is not plain text. @param array<string, mixed> $data */
    private function text(string $type, array $data): string
    {
        return match ($type) {
            'text' => (string) Arr::get($data, 'text.body', ''),
            'button' => (string) Arr::get($data, 'button.text', ''),
            'interactive' => (string) (Arr::get($data, 'interactive.button_reply.title') ?? Arr::get($data, 'interactive.list_reply.title', '')),
            'location' => '📍 موقع: '.trim(implode(' — ', array_filter([Arr::get($data, 'location.name'), Arr::get($data, 'location.address')])).' ('.Arr::get($data, 'location.latitude').', '.Arr::get($data, 'location.longitude').')'),
            'contacts' => '👤 جهة اتصال: '.collect(Arr::get($data, 'contacts', []))->map(fn ($c) => trim(Arr::get($c, 'name.formatted_name', '').' '.Arr::get($c, 'phones.0.phone', '')))->implode('، '),
            'reaction' => 'تفاعل '.Arr::get($data, 'reaction.emoji', ''),
            default => '['.$type.'] رسالة لا يعرضها النظام. افتحها في تطبيق WhatsApp Business.',
        };
    }
}
