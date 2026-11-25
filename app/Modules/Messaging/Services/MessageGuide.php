<?php

namespace App\Modules\Messaging\Services;

/**
 * Each WhatsApp message the system sends, said plainly for «الإعدادات ← رسائل الواتساب»: its purpose, when it goes
 * out, how (by itself, or from a button too), and to whom — read from where each one is queued (MemberNotifier,
 * InvoiceMessage, the dues and class reminders, GiftWhatsApp, documents). The values a message carries have names the
 * owner reads ([اسم العضوة]), never {{1}}.
 */
final class MessageGuide
{
    /** The groups, in the order the page shows them. */
    public const GROUPS = [
        'membership' => 'الاشتراك والعضوية',
        'payments' => 'الفواتير والدفع',
        'classes' => 'الحصص والحجوزات',
        'gifts' => 'هدايا الاشتراك',
        'staff' => 'رسائل للموظفين',
    ];

    /** @var array<string, array{group: string, purpose: string, when: string, how: string, to: string}> */
    public const MESSAGES = [
        'member_welcome' => ['group' => 'membership', 'purpose' => 'الترحيب بالعضوة الجديدة وإعطاؤها رقم عضويتها.', 'when' => 'فور تسجيل عضوة جديدة في النظام.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_activated' => ['group' => 'membership', 'purpose' => 'تأكيد أن الاشتراك صار فعّالًا، مع الباقة وتاريخَي البداية والنهاية.', 'when' => 'فور دفع اشتراك جديد بالكامل وتفعيله.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_renewed' => ['group' => 'membership', 'purpose' => 'تأكيد تجديد الاشتراك وتاريخ نهايته الجديد.', 'when' => 'فور دفع التجديد بالكامل وتفعيله.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_expiring' => ['group' => 'membership', 'purpose' => 'تذكير العضوة بقرب انتهاء اشتراكها مع رابط التجديد.', 'when' => 'قبل نهاية الاشتراك بالأيام المحددة في «الرسائل التلقائية»، إن لم تجدد.', 'how' => 'تلقائيًا كل يوم في الساعة المحددة', 'to' => 'العضوة'],
        'membership_expires_today' => ['group' => 'membership', 'purpose' => 'تنبيه بأن اليوم آخر يوم في الاشتراك.', 'when' => 'في آخر يوم من الاشتراك، إن لم تجدد.', 'how' => 'تلقائيًا في الساعة المحددة', 'to' => 'العضوة'],
        'membership_expired' => ['group' => 'membership', 'purpose' => 'إبلاغها بانتهاء الاشتراك ودعوتها للتجديد.', 'when' => 'في اليوم التالي لانتهاء الاشتراك.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_followup' => ['group' => 'membership', 'purpose' => 'متابعة من لم تجدد لتشجيعها على العودة (رسالة تسويقية).', 'when' => 'بعد انتهاء الاشتراك بالأيام المحددة، لمن وافقت على الرسائل التسويقية فقط.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_frozen' => ['group' => 'membership', 'purpose' => 'تأكيد تجميد الاشتراك وأن نهايته ستُمدَّد بعدد أيام التجميد.', 'when' => 'عند تجميد الاشتراك من النظام.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_resumed' => ['group' => 'membership', 'purpose' => 'تأكيد استئناف الاشتراك وتاريخ نهايته الجديد.', 'when' => 'عند إنهاء التجميد واستئناف الاشتراك.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'membership_paid_after_end' => ['group' => 'membership', 'purpose' => 'شكرها على دفعة لاشتراك انتهت مدته، وتوضيح أنه لا يصير نشطًا.', 'when' => 'عند دفع فاتورة اشتراك بعد انتهاء فترته.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],

        'invoice_issued' => ['group' => 'payments', 'purpose' => 'إرسال الفاتورة ومبلغها ورابط الدفع.', 'when' => 'عند إصدار فاتورة لعضوة من الاستقبال أو اللوحة (لا تُرسل عند التسجيل من الموقع).', 'how' => 'تلقائيًا، أو بزر «إرسال عبر واتساب» في صفحة الفاتورة', 'to' => 'العضوة'],
        'invoice_issued_membership' => ['group' => 'payments', 'purpose' => 'إرسال فاتورة فيها اشتراك، مع التوضيح أن الاشتراك يتفعّل بعد سدادها كاملة.', 'when' => 'عند إصدار فاتورة فيها اشتراك (تحل محل رسالة الفاتورة العادية).', 'how' => 'تلقائيًا، أو بزر «إرسال عبر واتساب» في صفحة الفاتورة', 'to' => 'العضوة (العربية)'],
        'invoice_issued_membership_en' => ['group' => 'payments', 'purpose' => 'الرسالة نفسها بالإنجليزية لمن تفضّل الإنجليزية.', 'when' => 'مثل النسخة العربية، لمن لغة رسائلها الإنجليزية.', 'how' => 'تلقائيًا، أو بزر «إرسال عبر واتساب»', 'to' => 'العضوة (الإنجليزية)'],
        'payment_received' => ['group' => 'payments', 'purpose' => 'شكرها على الدفع مع رقم الإيصال ورابط الفاتورة.', 'when' => 'فور تسجيل دفعة مؤكدة (الدفع الإلكتروني، أو في الاستقبال، أو بعد تأكيد التحويل).', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'transfer_received' => ['group' => 'payments', 'purpose' => 'إبلاغها أن إشعار التحويل البنكي وصل وسيُؤكَّد بعد المطابقة.', 'when' => 'عند استلام إشعار تحويل بنكي منها قبل تأكيده.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'payment_failed' => ['group' => 'payments', 'purpose' => 'إبلاغها بأن الدفع الإلكتروني لم يكتمل مع رابط للمحاولة مجددًا.', 'when' => 'عند رفض البنك عملية دفع إلكتروني لفاتورة صادرة.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'invoice_due_soon' => ['group' => 'payments', 'purpose' => 'تذكير بفاتورة يقترب موعد استحقاقها.', 'when' => 'قبل موعد الاستحقاق بالأيام المحددة في «الإشعارات»، إن كانت التذكيرات مفعّلة.', 'how' => 'تلقائيًا كل يوم، أو بزر التذكير في صفحة المستحقات', 'to' => 'العضوة'],
        'invoice_overdue' => ['group' => 'payments', 'purpose' => 'تذكير بفاتورة تجاوزت موعد استحقاقها.', 'when' => 'بعد موعد الاستحقاق بالأيام المحددة، إن كانت التذكيرات مفعّلة.', 'how' => 'تلقائيًا كل يوم، أو بزر التذكير في صفحة المستحقات', 'to' => 'العضوة'],

        'booking_confirmed' => ['group' => 'classes', 'purpose' => 'تأكيد حجز الحصة بموعدها.', 'when' => 'فور حجز حصة.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'class_reminder' => ['group' => 'classes', 'purpose' => 'تذكيرها بموعد حصتها المحجوزة.', 'when' => 'قبل الحصة بعدد الساعات المحدد في «الإشعارات» (إن كان مفعّلًا).', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'booking_cancelled' => ['group' => 'classes', 'purpose' => 'إبلاغها بإلغاء حجزها وسببه.', 'when' => 'عند إلغاء الحجز أو إلغاء الحصة.', 'how' => 'تلقائيًا', 'to' => 'العضوة'],
        'class_changed' => ['group' => 'classes', 'purpose' => 'إبلاغها بتغيير موعد الحصة أو مدربتها.', 'when' => 'عند تعديل حصة محجوزة (إن كانت إشعارات تعديل الحصص مفعّلة).', 'how' => 'تلقائيًا', 'to' => 'العضوة'],

        'gift_received' => ['group' => 'gifts', 'purpose' => 'إرسال هدية الاشتراك إلى المستلمة مع رابط استلامها.', 'when' => 'بعد دفع الهدية كاملة، وتأكيد المستلمة رقمها برسالة منها.', 'how' => 'تلقائيًا (إن كانت رسالة الهدية مفعّلة)', 'to' => 'مستلمة الهدية (العربية)'],
        'gift_received_en' => ['group' => 'gifts', 'purpose' => 'الرسالة نفسها بالإنجليزية.', 'when' => 'مثل النسخة العربية، إن اختار المُهدي الإنجليزية.', 'how' => 'تلقائيًا', 'to' => 'مستلمة الهدية (الإنجليزية)'],

        'document_expiry' => ['group' => 'staff', 'purpose' => 'تنبيه الموظف المسؤول بقرب انتهاء مستند.', 'when' => 'قبل انتهاء المستند بالأيام المحددة في «تنبيهات المستندات».', 'how' => 'تلقائيًا كل صباح', 'to' => 'الموظف المسؤول'],
    ];

    /** The values a message can carry: the name the owner reads, and what an example shows. */
    public const VALUES = [
        'member_name' => ['اسم العضوة', 'سارة'],
        'club_name' => ['اسم النادي', 'Alopera Gym'],
        'member_no' => ['رقم العضوية', 'M-00012'],
        'invoice_number' => ['رقم الفاتورة', 'INV-2026-000123'],
        'amount' => ['المبلغ', '25.000 ر.ع'],
        'payment_link' => ['رابط الدفع', 'https://aloperagym.om/pay/abc'],
        'receipt_number' => ['رقم الإيصال', 'RCT-2026-000045'],
        'invoice_link' => ['رابط الفاتورة', 'https://aloperagym.om/invoice/abc'],
        'plan_name' => ['اسم الباقة', 'الشهرية'],
        'starts_on' => ['تاريخ البداية', '2026/12/01'],
        'ends_on' => ['تاريخ النهاية', '2026/12/31'],
        'days_left' => ['الأيام المتبقية', '3'],
        'renew_link' => ['رابط التجديد', 'https://aloperagym.om/portal/renew'],
        'class_name' => ['اسم الحصة', 'يوغا'],
        'date' => ['التاريخ', '2026/12/02'],
        'time' => ['الوقت', '18:00'],
        'reason' => ['السبب', 'إلغاء الحصة'],
        'change' => ['التعديل', 'الموعد الجديد 19:00'],
        'due_date' => ['تاريخ الاستحقاق', '2026/12/05'],
        'days' => ['عدد الأيام', '3'],
        'user_name' => ['اسم الموظف', 'أمل'],
        'document_name' => ['اسم المستند', 'السجل التجاري'],
        'expiry_date' => ['تاريخ الانتهاء', '2026/12/20'],
        'giver_name' => ['اسم المُهدي', 'نورة'],
        'gift_link' => ['رابط الهدية', 'https://aloperagym.om/gift/abc'],
    ];

    /** @return array{0: string, 1: string} */
    public static function value(string $name): array
    {
        return self::VALUES[$name] ?? [$name, $name];
    }
}
