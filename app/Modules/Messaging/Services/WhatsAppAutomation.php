<?php

namespace App\Modules\Messaging\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The automatic WhatsApp notices tied to the gym's records, each switched on or off by the club, and when they go
 * out. Every notice still needs its template approved in WhatsApp and the member's consent to service messages; the
 * after-expiry follow-up is a marketing message and needs marketing consent as well. Each one is sent once (its
 * dedupe key), however often the daily job or the event runs.
 *
 * Stored in the club's settings: whatsapp_automation.off (the notices switched off), whatsapp_automation.enabled (the
 * notices off by default that were switched on), whatsapp_automation.hour (the hour the daily reminders go out); the
 * days before and after expiry are the club's existing reminder settings.
 */
final class WhatsAppAutomation
{
    /** @var array<string, array{label: string, detail: string, templates: list<string>, timing?: string}> */
    public const EVENTS = [
        'member_welcome' => ['label' => 'رسالة ترحيب', 'detail' => 'عند تسجيل مشترك جديد.', 'templates' => ['member_welcome']],
        'membership_activated' => ['label' => 'تأكيد الاشتراك الجديد', 'detail' => 'عند تفعيل اشتراك جديد بعد دفعه.', 'templates' => ['membership_activated']],
        'membership_expiring' => ['label' => 'تذكير قبل انتهاء الاشتراك', 'detail' => 'قبل نهاية الاشتراك بالأيام المحددة، إن لم يجدد العضو.', 'templates' => ['membership_expiring'], 'timing' => 'expiry_reminder_days'],
        'membership_expires_today' => ['label' => 'تنبيه يوم انتهاء الاشتراك', 'detail' => 'في آخر يوم من الاشتراك، إن لم يجدد العضو.', 'templates' => ['membership_expires_today']],
        'membership_expired' => ['label' => 'إشعار بعد انتهاء الاشتراك', 'detail' => 'في اليوم التالي لانتهاء الاشتراك.', 'templates' => ['membership_expired']],
        'membership_followup' => ['label' => 'متابعة بعد الانتهاء (تسويقية)', 'detail' => 'بعد الانتهاء بالأيام المحددة لمن لم يجدد ووافق على الرسائل التسويقية.', 'templates' => ['membership_followup'], 'timing' => 'expired_followup_days'],
        'membership_renewed' => ['label' => 'تأكيد تجديد العضوية', 'detail' => 'عند تفعيل التجديد بعد دفعه.', 'templates' => ['membership_renewed']],
        'payment_received' => ['label' => 'تأكيد الدفع', 'detail' => 'عند تسجيل دفعة مؤكدة لها إيصال (التحويل البنكي بعد تأكيده فقط).', 'templates' => ['payment_received', 'membership_paid_after_end']],
        'gift_received' => ['label' => 'إرسال هدية الاشتراك للمستلمة', 'detail' => 'بعد اكتمال دفع الهدية، إلى رقم المستلمة بعد أن تؤكده برسالة منها.', 'templates' => ['gift_received']],
    ];

    /**
     * Notices that stay off until the club switches them on (whatsapp_automation.enabled), not on until switched off:
     * the gift goes to someone who is not a member, so nothing is sent before the owner decides it (#192).
     */
    public const OFF_BY_DEFAULT = ['gift_received'];

    public const DEFAULT_HOUR = 10;

    public static function on(Tenant $tenant, string $event): bool
    {
        if (in_array($event, self::OFF_BY_DEFAULT, true)) {
            return in_array($event, (array) $tenant->setting('whatsapp_automation.enabled', []), true);
        }

        return ! in_array($event, (array) $tenant->setting('whatsapp_automation.off', []), true);
    }

    /**
     * When an off-by-default notice was last switched on (null: off, or on without the panel recording it). What
     * waited while it was off is not sent by the switch: the gift notice sends nothing paid before this.
     */
    public static function enabledSince(Tenant $tenant, string $event): ?CarbonImmutable
    {
        $at = $tenant->setting('whatsapp_automation.enabled_since.'.$event);

        return self::on($tenant, $event) && is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : null;
    }

    /** The hour of the day (club time) the daily reminders go out. */
    public static function hour(Tenant $tenant): int
    {
        return max(0, min(23, (int) $tenant->setting('whatsapp_automation.hour', self::DEFAULT_HOUR)));
    }
}
