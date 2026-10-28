<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;

/**
 * The events the system can announce over WhatsApp, the values each one can
 * fill into a template, and Arabic starter text a club can submit to Meta for
 * approval. A template is only sent once its status is `approved`.
 */
final class TemplateCatalog
{
    /** @var array<string, array{label: string, category: string, variables: list<string>, body: string}> */
    public const EVENTS = [
        'member_welcome' => [
            'label' => 'الترحيب بمشتركة جديدة',
            'category' => 'utility',
            'variables' => ['member_name', 'club_name', 'member_no'],
            'body' => 'أهلًا {{1}} 🌸 يسعدنا انضمامك إلى {{2}}. رقم عضويتك: {{3}}.',
        ],
        'invoice_issued' => [
            'label' => 'إرسال الفاتورة ورابط الدفع',
            'category' => 'utility',
            'variables' => ['member_name', 'invoice_number', 'amount', 'payment_link'],
            'body' => 'مرحبًا {{1}}، تم إصدار الفاتورة رقم {{2}} بمبلغ {{3}}. للدفع الإلكتروني الآمن: {{4}}',
        ],
        'payment_received' => [
            'label' => 'تأكيد استلام الدفعة',
            'category' => 'utility',
            'variables' => ['member_name', 'amount', 'receipt_number'],
            'body' => 'شكرًا {{1}}، استلمنا دفعتك بمبلغ {{2}}. رقم الإيصال: {{3}}.',
        ],
        'membership_activated' => [
            'label' => 'تأكيد الاشتراك',
            'category' => 'utility',
            'variables' => ['member_name', 'plan_name', 'starts_on', 'ends_on'],
            'body' => 'تم تفعيل اشتراكك يا {{1}} في باقة {{2}} من {{3}} حتى {{4}}. نراكِ قريبًا 💪',
        ],
        'membership_renewed' => [
            'label' => 'تأكيد التجديد',
            'category' => 'utility',
            'variables' => ['member_name', 'plan_name', 'ends_on'],
            'body' => 'تم تجديد اشتراكك يا {{1}} في باقة {{2}} حتى {{3}}. شكرًا لثقتك بنا 🌷',
        ],
        'membership_expiring' => [
            'label' => 'تذكير قبل انتهاء العضوية',
            'category' => 'utility',
            'variables' => ['member_name', 'plan_name', 'ends_on', 'days_left', 'renew_link'],
            'body' => 'تذكير يا {{1}}: اشتراكك في {{2}} ينتهي بتاريخ {{3}} (بعد {{4}} يوم). للتجديد: {{5}}',
        ],
        'membership_expired' => [
            'label' => 'انتهاء العضوية',
            'category' => 'utility',
            'variables' => ['member_name', 'renew_link'],
            'body' => 'انتهى اشتراكك يا {{1}}. اشتقنا لكِ! جددي اشتراكك من هنا: {{2}}',
        ],
        'membership_frozen' => [
            'label' => 'تأكيد التجميد',
            'category' => 'utility',
            'variables' => ['member_name', 'plan_name'],
            'body' => 'تم تجميد اشتراكك يا {{1}} في {{2}}. سيُمدَّد تاريخ الانتهاء بعدد أيام التجميد.',
        ],
        'membership_resumed' => [
            'label' => 'تأكيد الاستئناف',
            'category' => 'utility',
            'variables' => ['member_name', 'ends_on'],
            'body' => 'تم استئناف اشتراكك يا {{1}}. تاريخ الانتهاء الجديد: {{2}}.',
        ],
        'booking_confirmed' => [
            'label' => 'تأكيد الحجز',
            'category' => 'utility',
            'variables' => ['member_name', 'class_name', 'date', 'time'],
            'body' => 'تم تأكيد حجزك يا {{1}} في حصة {{2}} يوم {{3}} الساعة {{4}}.',
        ],
        'booking_cancelled' => [
            'label' => 'إلغاء/تعديل الحجز',
            'category' => 'utility',
            'variables' => ['member_name', 'class_name', 'date', 'time', 'reason'],
            'body' => 'تم إلغاء حجزك في حصة {{2}} يوم {{3}} الساعة {{4}}. السبب: {{5}}',
        ],
    ];

    /** Values campaigns can use. */
    public const CAMPAIGN_VARIABLES = ['member_name', 'club_name', 'plan_name', 'ends_on'];

    /** Create a draft template for every event the club does not have yet. */
    public static function seedDefaults(bool $approved = false): void
    {
        foreach (self::EVENTS as $key => $event) {
            MessageTemplate::firstOrCreate(['key' => $key], [
                'name' => 'alopera_'.$key,
                'language' => 'ar',
                'category' => $event['category'],
                'body' => $event['body'],
                'variables' => $event['variables'],
                'status' => $approved ? MessageTemplate::APPROVED : 'draft',
                'is_active' => true,
            ]);
        }
    }
}
