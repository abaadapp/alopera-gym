<?php

namespace App\Modules\Gym\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffAlerts;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantIntegration;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Messaging\Events\InboundTextReceived;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppAutomation;
use App\Modules\Messaging\Services\WhatsAppService;
use App\Modules\Website\Support\GiftLinks;
use Illuminate\Support\Facades\DB;

/**
 * A gift sent to its recipient's WhatsApp (#192), through the club's own connected number (WhatsAppService) only.
 *
 * Confirming the number. Neither Meta's Cloud API nor 360dialog offers an official way to ask whether a number has
 * WhatsApp, and a send WhatsApp accepts proves nothing either. So the number counts as confirmed only when the
 * recipient herself sends the club's number a message from it carrying the gift's code: a signed webhook, from that
 * very number (its wa_id), with a code of this gift that has not expired. The message she sends says she agrees to
 * receive the club's messages on the number — her consent, which Meta asks for before a business writes to someone —
 * and says nothing of a gift or a price. On the website the gift cannot be paid before that (the mandatory path); at
 * the desk it can, and the gift waits for the confirmation to be sent.
 *
 * Sending. Once the gift is paid in full (InvoicePaid → ActivatePaidItems → GiftService::markPaid) and its number
 * confirmed, its message is queued and sent after the transaction commits, never before the payment and never from a
 * gateway's return page. Each attempt has its own dedupe key, so a webhook replayed or the nightly run never sends it
 * twice; a new attempt is staff's (resend, after a failure). Nothing goes while the club has not switched the notice
 * on (off by default) or its template is not approved by Meta.
 *
 * Language. The giver picks the message's language (GiftMembership::message_language, ar|en): the gift goes with that
 * language's template (alopera_gift_received in Arabic or in English, one row each in TEMPLATES), and only with it.
 * When that template is missing, not approved or still the older text with other values, the gift waits with the
 * reason; it never goes in the other language instead. A giver who hid their name (is_anonymous) is named in the
 * message by the anonymous wording of that language; staff and the invoice keep the real name.
 *
 * Delivery. The message's own status, from WhatsApp's status webhooks: `sent` is WhatsApp having taken it, not the
 * recipient having it. A failure leaves the paid gift as it is (no cancellation, no new invoice or payment), tells
 * staff in the bell, and lets them correct the number (a new confirmation, and a new link once one may have gone to
 * the old number) and send it again.
 */
final class GiftWhatsApp
{
    /** The club's switch for gift messages (WhatsAppAutomation), and the Arabic template's key. */
    public const TEMPLATE = 'gift_received';

    /** The template each language's gift goes with: the same Meta name, alopera_gift_received, in ar and en. */
    public const TEMPLATES = ['ar' => 'gift_received', 'en' => 'gift_received_en'];

    public const CODE_HOURS = 24;

    /** No 0/O, 1/I/L: read back from a phone screen without doubt. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Why a number cannot be confirmed now, or a gift's message not sent: as staff and buyers are told. */
    public const REASONS = [
        'not_connected' => 'واتساب النادي غير مربوط بالنظام أو غير مفعّل.',
        'no_club_number' => 'رقم واتساب النادي غير مسجّل في إعدادات واتساب.',
        'automation_off' => 'إرسال الهدايا عبر واتساب غير مفعّل في الرسائل التلقائية.',
        'template_missing' => 'قالب رسالة الهدية (:lang) غير موجود في واتساب ← القوالب.',
        'template_not_approved' => 'قالب رسالة الهدية (:lang) لم يُعتمد بعد: أنشئه في Meta بهذه اللغة، ثم اجعله «معتمد» في واتساب ← القوالب. لا تُرسل الهدية بلغة أخرى.',
        'template_outdated' => 'قالب رسالة الهدية (:lang) من الإصدار السابق بمتغيرات أخرى، فلا يُرسل به. اجعله «مسودة» في واتساب ← القوالب ليأخذ النص الجديد، وأنشئ النص الجديد في Meta، ثم اعتمده.',
        'not_verified' => 'بانتظار تأكيد رقم المستلمة برسالة منها.',
        'paid_before_on' => 'دُفعت قبل تشغيل إرسال الهدايا، فلا تُرسل وحدها. تأكد أن الرابط لم يصل المستلمة بطريقة أخرى، ثم أرسلها من «إرسال الآن».',
    ];

    /** The reason as staff read it, naming the template's language where it is about the template. */
    public static function reasonText(string $code, ?string $language = null): string
    {
        return str_replace(':lang', $language === 'en' ? 'الإنجليزية' : 'العربية', self::REASONS[$code] ?? $code);
    }

    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly GiftLinks $links,
    ) {}

    // ---- Confirming the number -----------------------------------------------------------------------------------

    /** The club's own WhatsApp number (international digits), as its settings hold it; null when unknown. */
    public function clubNumber(): ?string
    {
        $integration = $this->whatsapp->integration();
        $number = Phone::normalize((string) $integration?->option('display_phone'));

        return $number !== '' ? $number : null;
    }

    /** Why the recipient cannot confirm her number now (the club's WhatsApp is not connected), or null. */
    public function verificationRefusal(): ?string
    {
        $integration = $this->whatsapp->integration();

        return match (true) {
            ! $integration instanceof TenantIntegration || ! $integration->is_enabled => 'not_connected',
            $this->clubNumber() === null => 'no_club_number',
            default => null,
        };
    }

    /** A new code for the gift's number (the earlier one stops working), valid CODE_HOURS. Nothing is sent. */
    public function newCode(GiftMembership $gift): GiftMembership
    {
        if ($gift->phoneVerified()) {
            return $gift;
        }
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $hash = self::hashCode($code);
        } while (GiftMembership::withoutGlobalScopes()->where('phone_verify_hash', $hash)->exists());

        $gift->forceFill([
            'phone_verify_code' => $code,
            'phone_verify_hash' => $hash,
            'phone_verify_expires_at' => now()->addHours(self::CODE_HOURS),
        ])->save();

        return $gift;
    }

    /**
     * What a confirmation screen shows: the state, and while waiting, the code, the message to send and the wa.me link
     * that opens it to the club's number. Only for whoever may have it (the buyer's own page, staff who issue gifts).
     *
     * @return array<string, mixed>
     */
    public function verification(GiftMembership $gift, bool $english = false): array
    {
        $refusal = $this->verificationRefusal();
        $expired = ! $gift->phoneVerified() && ($gift->phone_verify_expires_at === null || $gift->phone_verify_expires_at->isPast());
        $state = match (true) {
            $gift->phoneVerified() => 'verified',
            $refusal !== null => 'unavailable',
            $expired => 'expired',
            default => 'waiting',
        };
        $code = $state === 'waiting' ? (string) $gift->phone_verify_code : null;
        $text = $code !== null ? $this->confirmationText($code, $english) : null;

        return [
            'state' => $state,
            'phone' => $gift->recipient_phone,
            'verified_at' => $gift->phone_verified_at?->toIso8601String(),
            'expires_at' => $state === 'waiting' ? $gift->phone_verify_expires_at?->toIso8601String() : null,
            'code' => $code !== null ? substr($code, 0, 4).'-'.substr($code, 4) : null,
            'text' => $text,
            'link' => $text !== null ? 'https://wa.me/'.$this->clubNumber().'?text='.rawurlencode($text) : null,
            'club_number' => $this->clubNumber(),
            'refusal' => $refusal,
            'refusal_text' => $refusal !== null ? self::REASONS[$refusal] : null,
        ];
    }

    /** The message the recipient sends: her consent and the code, and nothing of a gift or a price. */
    public function confirmationText(string $code, bool $english = false): string
    {
        $club = $this->tenants->require()->name;
        $code = strlen($code) === 8 ? substr($code, 0, 4).'-'.substr($code, 4) : $code;

        return $english
            ? "I agree to receive messages from {$club} on this number. Confirmation code: {$code}"
            : "أوافق على استلام رسائل {$club} على هذا الرقم. رمز التأكيد: {$code}";
    }

    /**
     * InboundTextReceived: a message carrying a gift's code. Confirms the number only when it comes from the gift's
     * own number, before the code expired, for a gift still waiting or paid. True when the message was a gift code
     * (the inbox's automatic answers leave it alone), null otherwise.
     */
    public function confirmFromWhatsApp(InboundTextReceived $event): ?bool
    {
        $code = self::codeIn((string) $event->message->body);
        $found = $code !== null ? GiftMembership::where('phone_verify_hash', self::hashCode($code))->first() : null;
        if (! $found) {
            return null;
        }
        $from = Phone::normalize((string) $event->conversation->phone);

        return DB::transaction(function () use ($found, $from, $event) {
            $gift = GiftMembership::whereKey($found->id)->lockForUpdate()->first();
            $refusal = match (true) {
                ! $gift || $gift->phoneVerified() => 'already',
                ! in_array($gift->status, [GiftMembership::PENDING, GiftMembership::PAID], true) => 'closed',
                $gift->phone_verify_expires_at === null || $gift->phone_verify_expires_at->isPast() => 'expired',
                $from !== $gift->recipient_phone => 'other_number',
                default => null,
            };
            if ($refusal !== null) {
                $gift && $this->audit->log('gift.phone_verify_refused', $gift, ['number' => $gift->number, 'reason' => $refusal, 'from' => self::mask($from)]);
                $english = $gift?->messageLanguage() === 'en';
                $answer = match ($refusal) {
                    'expired' => $english ? 'This confirmation code has expired. Ask the person who sent it to you for a new one.' : 'انتهت صلاحية رمز التأكيد. اطلب رمزًا جديدًا ممن أرسله إليك.',
                    'other_number' => $english ? 'This code is not for this number, so it was not confirmed.' : 'هذا الرمز ليس لهذا الرقم، فلم يُؤكَّد.',
                    default => null,
                };
                $answer && $this->answer($event, $answer);

                return true;
            }

            $gift->forceFill([
                'phone_verified_at' => now(),
                'phone_verified_message_id' => $event->message->id,
                'phone_verify_code' => null,
                'phone_verify_hash' => null,
                'phone_verify_expires_at' => null,
            ])->save();
            $this->audit->log('gift.phone_verified', $gift, ['number' => $gift->number, 'phone' => self::mask($from), 'message_id' => $event->message->id]);
            $this->answer($event, $gift->messageLanguage() === 'en' ? 'Thank you, your number is confirmed.' : 'شكرًا لك، تم تأكيد رقمك.');

            // Paid already (at the desk): it goes now.
            $this->queue($gift);

            return true;
        });
    }

    // ---- Sending ---------------------------------------------------------------------------------------------------

    /**
     * Why a gift's message cannot go now: the club's connection and switch, then — for a language — that language's
     * template: there, approved, and carrying the three values sent (an older text approved with four is not used).
     */
    public function sendRefusal(?string $language = null): ?string
    {
        if (! $this->whatsapp->enabled()) {
            return 'not_connected';
        }
        if (! WhatsAppAutomation::on($this->tenants->require(), self::TEMPLATE)) {
            return 'automation_off';
        }
        if ($language === null) {
            return null;
        }
        $template = MessageTemplate::where('key', self::TEMPLATES[$language] ?? self::TEMPLATE)->first();

        return match (true) {
            ! $template => 'template_missing',
            ! $template->isUsable() => 'template_not_approved',
            ! TemplateCatalog::isCurrent($template) => 'template_outdated',
            default => null,
        };
    }

    /**
     * Queue the gift's message when it may go: paid in full and usable, its number confirmed, the club ready. Once:
     * a gift that has a message is not sent again here (staff's resend is the new attempt). Null when nothing went.
     */
    public function queue(GiftMembership $gift, bool $again = false): ?Message
    {
        $language = $gift->messageLanguage();
        if (! $gift->isRedeemable() || ! $gift->phoneVerified() || (! $again && $gift->whatsapp_message_id !== null) || $this->sendRefusal($language) !== null) {
            return null;
        }

        $attempt = $gift->whatsapp_attempts + 1;
        $message = $this->whatsapp->queuePhoneTemplate($gift->recipient_phone, self::TEMPLATES[$language], $this->values($gift), $gift,
            'gift:'.$gift->id.':'.$attempt, ['gift_link' => $language === 'en' ? '[gift link]' : '[رابط الهدية]']);
        if (! $message) {
            return null;
        }
        $gift->forceFill(['whatsapp_message_id' => $message->id, 'whatsapp_attempts' => $attempt])->save();
        $this->audit->log($again ? 'gift.whatsapp_resent' : 'gift.whatsapp_queued', $gift, ['number' => $gift->number, 'attempt' => $attempt, 'message_id' => $message->id]);

        return $message;
    }

    /** Staff's new attempt, only when there is nothing on its way: none yet, or the last one failed. */
    public function resend(GiftMembership $gift): Message
    {
        return DB::transaction(function () use ($gift) {
            $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            $last = $gift->whatsapp_message_id ? Message::find($gift->whatsapp_message_id) : null;
            // Failed, or uncertain (it may have arrived: staff check with her first, the panel says so).
            if ($last && ! in_array($last->status, [Message::FAILED, Message::SKIPPED, Message::UNCERTAIN], true)) {
                throw new BusinessRuleException('gift.whatsapp_on_its_way', 'رسالة الهدية '.self::STATUS_WORDS[$last->status].'؛ لا تُعاد إلا إذا فشلت.');
            }
            if (! $gift->isRedeemable()) {
                throw new BusinessRuleException('gift.not_sendable', 'تُرسل الهدية المدفوعة التي لم تُستخدم ولم تنتهِ فقط.');
            }
            if (! $gift->phoneVerified()) {
                throw new BusinessRuleException('gift.not_verified', self::REASONS['not_verified']);
            }
            if ($refusal = $this->sendRefusal($gift->messageLanguage())) {
                throw new BusinessRuleException('gift.whatsapp_'.$refusal, self::reasonText($refusal, $gift->messageLanguage()));
            }

            return $this->queue($gift, again: true) ?? throw new BusinessRuleException('gift.whatsapp_not_queued', 'تعذّر تجهيز رسالة الهدية. حاول مرة أخرى.');
        });
    }

    /**
     * A corrected number (staff, or the buyer before paying): confirmed again from the start. A gift whose link may
     * already have reached the old number (paid, or its message sent) gets a new link, and the old one stops.
     */
    public function changeRecipient(GiftMembership $gift, string $phone): GiftMembership
    {
        $phone = Phone::normalize($phone);
        if (! Phone::isMobile($phone)) {
            throw new BusinessRuleException('gift.recipient_phone', 'أدخل رقم واتساب صحيحًا للمستلمة.', 'recipient_phone');
        }

        return DB::transaction(function () use ($gift, $phone) {
            $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            if (! in_array($gift->status, [GiftMembership::PENDING, GiftMembership::PAID], true)) {
                throw new BusinessRuleException('gift.recipient_closed', 'لا يُغيَّر رقم هدية استُخدمت أو أُلغيت.', 'recipient_phone');
            }
            if ($phone === $gift->recipient_phone) {
                throw new BusinessRuleException('gift.recipient_same', 'هذا هو رقم المستلمة الحالي.', 'recipient_phone');
            }
            $old = $gift->recipient_phone;
            $exposed = $gift->paid_at !== null || $gift->whatsapp_message_id !== null;
            $gift->forceFill([
                'recipient_phone' => $phone,
                'phone_verified_at' => null,
                'phone_verified_message_id' => null,
                'whatsapp_message_id' => null,
            ])->save();
            $this->audit->log('gift.recipient_changed', $gift, ['number' => $gift->number, 'from' => self::mask($old), 'to' => self::mask($phone), 'new_link' => $exposed]);
            if ($exposed) {
                app(GiftService::class)->rotateLink($gift);
            }

            return $this->newCode($gift->fresh());
        });
    }

    /** The nightly run: paid, confirmed gifts whose message has not gone yet (the switch or the template came later). */
    public function sendPending(): int
    {
        // Never what piled up while the notice was off: switching it on sends no backlog (staff send those one by one).
        $since = WhatsAppAutomation::enabledSince($this->tenants->require(), self::TEMPLATE);
        if ($since === null || $this->sendRefusal() !== null) {
            return 0;
        }
        $sent = 0;
        GiftMembership::where('status', GiftMembership::PAID)->whereNotNull('phone_verified_at')->whereNull('whatsapp_message_id')
            ->where('paid_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', today()->toDateString()))
            ->eachById(function (GiftMembership $gift) use (&$sent) {
                $sent += DB::transaction(function () use ($gift) {
                    $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->first();

                    return $gift && $this->queue($gift) ? 1 : 0;
                });
            });

        return $sent;
    }

    /** Would the hourly run send it by itself: paid since the notice was last switched on (no backlog otherwise). */
    private function sendsOnItsOwn(GiftMembership $gift): bool
    {
        $since = WhatsAppAutomation::enabledSince($this->tenants->require(), self::TEMPLATE);

        return $since !== null && $gift->paid_at !== null && $gift->paid_at->greaterThanOrEqualTo($since);
    }

    /**
     * The three values the template is sent with, in the gift's language: the giver as the recipient may see them
     * (the anonymous wording when hidden), the plan, the link. One line each (WhatsApp refuses a line break in a
     * parameter). No free text from the giver: a message from an older gift stays on its card only.
     */
    public function values(GiftMembership $gift): array
    {
        $line = fn (?string $v) => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        $language = $gift->messageLanguage();
        $plan = $gift->plan_snapshot ?? [];

        return [
            'giver_name' => $line($gift->publicGiverName($language)),
            'plan_name' => $line($language === 'en' ? (($plan['name_en'] ?? null) ?: ($plan['name'] ?? '')) : ($plan['name'] ?? '')),
            'gift_link' => $this->links->card($gift),
        ];
    }

    // ---- Delivery --------------------------------------------------------------------------------------------------

    /** How staff read a message's status (the resend refusal names it). */
    private const STATUS_WORDS = [
        Message::SCHEDULED => 'قيد الإرسال', Message::QUEUED => 'قيد الإرسال', Message::SENT => 'أُرسلت إلى المزود',
        Message::DELIVERED => 'سُلّمت', Message::READ => 'قُرئت', Message::FAILED => 'فشلت', Message::SKIPPED => 'لم تُرسل',
        Message::SENDING => 'قيد الإرسال', Message::UNCERTAIN => 'نتيجة إرسالها غير مؤكدة',
    ];

    /**
     * Where the gift's message is, for the panel: its state, when it was last tried, why it failed or waits, and
     * whether staff may send it (again).
     *
     * @return array{state: string, label: string, at: string|null, reason: string|null, can_send: bool, attempts: int}
     */
    public function delivery(GiftMembership $gift): array
    {
        $message = $gift->whatsapp_message_id ? Message::find($gift->whatsapp_message_id) : null;
        $waitingFor = ! $gift->phoneVerified() ? 'not_verified' : $this->sendRefusal($gift->messageLanguage());
        $state = self::stateOf($gift, $message);
        $reason = match ($state) {
            'waiting' => match (true) {
                $waitingFor !== null => self::reasonText($waitingFor, $gift->messageLanguage()),
                ! $this->sendsOnItsOwn($gift) => self::REASONS['paid_before_on'],
                default => 'تُرسل وحدها في ساعة الرسائل التلقائية ('.sprintf('%02d:00', WhatsAppAutomation::hour($this->tenants->require())).')، أو الآن من «إرسال الآن».',
            },
            'uncertain' => $message->error_message ?: 'قد تكون وصلت: لم يصل من واتساب ما يؤكد إرسالها أو ينفيه. تأكد من المستلمة قبل إعادة الإرسال.',
            'not_due' => $gift->phoneVerified() ? null : self::REASONS['not_verified'],
            'failed' => $message->error_message ?: (Message::SKIP_REASONS[$message->error_code] ?? ($message->error_code ? 'رمز الخطأ '.$message->error_code : null)),
            default => null,
        };

        return [
            'state' => $state,
            'label' => GiftMembership::DELIVERY_LABELS[$state] ?? $state,
            'at' => ($message?->sent_at ?? $message?->created_at)?->toIso8601String(),
            'reason' => $reason,
            'can_send' => in_array($state, ['waiting', 'failed', 'uncertain'], true) && $gift->isRedeemable() && $waitingFor === null,
            'attempts' => (int) $gift->whatsapp_attempts,
        ];
    }

    /** The delivery state from the gift and its message alone (the list shows it without asking anything else). */
    public static function stateOf(GiftMembership $gift, ?Message $message): string
    {
        return match (true) {
            $message === null && $gift->status === GiftMembership::PENDING => 'not_due',
            $message === null && ! $gift->isRedeemable() => 'closed',
            $message === null => 'waiting',
            in_array($message->status, [Message::SCHEDULED, Message::QUEUED, Message::SENDING], true) => 'sending',
            in_array($message->status, [Message::FAILED, Message::SKIPPED], true) => 'failed',
            $message->status === Message::UNCERTAIN => 'uncertain',
            in_array($message->status, [Message::SENT, Message::DELIVERED, Message::READ], true) => $message->status,
            default => 'sending',
        };
    }

    /** A gift's message failed (a send refused, or a `failed` status from WhatsApp): staff hear of it; the gift stands. */
    public function messageFailed(Message $message): void
    {
        $gift = GiftMembership::where('whatsapp_message_id', $message->id)->first();
        if (! $gift) {
            return;
        }
        $uncertain = $message->status === Message::UNCERTAIN;
        $reason = $uncertain ? '' : ($message->error_message ?: (Message::SKIP_REASONS[$message->error_code] ?? (string) $message->error_code));
        $this->audit->log($uncertain ? 'gift.whatsapp_uncertain' : 'gift.whatsapp_failed', $gift, ['number' => $gift->number, 'attempt' => $gift->whatsapp_attempts, 'code' => $message->error_code]);
        rescue(fn () => app(StaffAlerts::class)->giftUndelivered($gift, $reason, $uncertain));
    }

    // ---- Helpers ---------------------------------------------------------------------------------------------------

    /** The code in a message, as it was issued (8 characters), or null. Arabic digits and lower case are read too. */
    public static function codeIn(string $text): ?string
    {
        $text = mb_strtoupper(strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $set = 'A-HJKMNP-Z2-9';

        return preg_match('/(?<![A-Z0-9])(['.$set.']{4})[-\s]?(['.$set.']{4})(?![A-Z0-9])/u', $text, $m) ? $m[1].$m[2] : null;
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', 'gift-phone:'.strtoupper(str_replace('-', '', $code)));
    }

    /** A number as the audit trail keeps it: the last four digits. */
    public static function mask(?string $phone): string
    {
        $phone = (string) $phone;

        return $phone !== '' ? '…'.substr($phone, -4) : '';
    }

    /** A short answer in the recipient's chat, inside the window her message opened; only once the club sends gifts. */
    private function answer(InboundTextReceived $event, string $text): void
    {
        if (WhatsAppAutomation::on($this->tenants->require(), self::TEMPLATE) && $this->whatsapp->enabled()) {
            rescue(fn () => $this->whatsapp->reply($event->conversation, $text));
        }
    }
}
