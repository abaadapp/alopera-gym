/** «هدايا الاشتراكات» (#192) in the club panel: a gift's shape (GymPresenter::gift) and the words its screens use. */

export interface GiftRow {
    id: number;
    number: string;
    /** The display status: a paid gift past its date already reads `expired`. */
    status: string;
    status_label: string;
    status_label_en: string;
    source: string;
    giver_name: string;
    giver_phone: string | null;
    giver_email: string | null;
    recipient_name: string;
    /** Optional: when set, only an account with this number redeems it from the link (staff may redeem it for another). */
    recipient_phone: string | null;
    /** Kept for older gifts only: new ones carry no free text from the giver. */
    message: string | null;
    /** The recipient's WhatsApp language, as the giver chose it (the template of that language, and only it). */
    message_language: 'ar' | 'en';
    message_language_label: string;
    /** The giver asked not to be named to the recipient: she reads public_giver_name; staff see giver_name. */
    is_anonymous: boolean;
    public_giver_name: string;
    plan: { id: number; name: string; name_en: string | null; duration_label: string | null };
    price: string;
    invoice: { id: number; number: string; status: string; total: string; paid_total: string; balance: string } | null;
    paid_at: string | null;
    expires_on: string | null;
    redeemed_at: string | null;
    redeemed_via: 'link' | 'staff' | null;
    member: { id: number; full_name: string; member_no: string } | null;
    membership_id: number | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    refunded_at: string | null;
    /** Withdrawn by a credit note: where the money is. `due`: not paid back yet; `pending`: a refund awaits confirmation. */
    refund: { state: 'due' | 'pending' | 'done' | 'none'; label: string; due: string; pending: string; refunded: string; credit_note: string | null } | null;
    expired_at: string | null;
    created_at: string | null;
    /** Confirmed by a message the recipient sent from the number (never by a send WhatsApp accepted). */
    phone_verified_at: string | null;
    /** Where the gift's WhatsApp message is (GiftWhatsApp::stateOf). */
    delivery: { state: GiftDeliveryState; label: string };
}

export type GiftDeliveryState = 'not_due' | 'waiting' | 'sending' | 'sent' | 'delivered' | 'read' | 'failed' | 'uncertain' | 'closed';

/** The gift's page: the number's confirmation and the message's delivery, with what this user may do (GiftController). */
export interface GiftWhatsAppPanel {
    verification: {
        state: 'waiting' | 'expired' | 'verified' | 'unavailable';
        phone: string;
        verified_at: string | null;
        expires_at: string | null;
        /** Only for whoever issues gifts (to pass on). */
        code: string | null;
        text: string | null;
        link: string | null;
        club_number: string | null;
        refusal: string | null;
        refusal_text: string | null;
    };
    delivery: { state: GiftDeliveryState; label: string; at: string | null; reason: string | null; can_send: boolean; attempts: number };
    can_new_code: boolean;
    can_change: boolean;
    can_resend: boolean;
}

/** Each delivery state's badge colour. `sent` is WhatsApp having taken it, not the recipient having it. */
export const GIFT_DELIVERY_TONE: Record<GiftDeliveryState, 'gray' | 'amber' | 'blue' | 'green' | 'red'> = {
    not_due: 'gray', waiting: 'amber', sending: 'blue', sent: 'blue', delivered: 'green', read: 'green', failed: 'red', uncertain: 'amber', closed: 'gray',
};

/** The list's status tabs («الكل» comes first on its own), each with its badge's colour. */
/** The languages the giver picks the recipient's WhatsApp message in (Meta's codes), each named in its own language. */
export const GIFT_LANGUAGES: { value: 'ar' | 'en'; label: string }[] = [
    { value: 'ar', label: 'العربية' },
    { value: 'en', label: 'English' },
];

export const GIFT_FILTERS = [
    { value: 'pending', label: 'بانتظار الدفع', dot: '#d97706' },
    { value: 'paid', label: 'مدفوعة — لم تُستخدم', dot: '#059669' },
    { value: 'redeemed', label: 'مستخدمة', dot: '#2563eb' },
    { value: 'expired', label: 'منتهية الصلاحية', dot: '#9ca3af' },
    { value: 'cancelled', label: 'ملغاة', dot: '#dc2626' },
    { value: 'refunded', label: 'أُلغيت بإشعار دائن', dot: '#7c3aed' },
] as const;

/** How the desk collects a gift; «لاحقًا» leaves its invoice open. */
export const GIFT_PAYMENT: Record<string, { label: string; note?: string }> = {
    later: { label: 'لاحقًا', note: 'تبقى الفاتورة مفتوحة وتُصدر الهدية صالحة بعد السداد' },
    cash: { label: 'نقدًا' },
    card: { label: 'بطاقة' },
    bank_transfer: { label: 'تحويل بنكي', note: 'تصبح الهدية صالحة بعد أن تؤكد المالية التحويل' },
    other: { label: 'أخرى' },
};

/** The choices offered when issuing: «لاحقًا» always, then the methods the server allows this user (none: only later). */
export function giftPaymentChoices(methods: readonly string[]): string[] {
    return ['later', ...methods.filter((m) => m !== 'later' && m in GIFT_PAYMENT)];
}

/** Cash when the user may take it, otherwise later. */
export function defaultGiftPayment(methods: readonly string[]): string {
    return methods.includes('cash') ? 'cash' : 'later';
}

/** The gift's history, as the audit log names it. */
export const GIFT_EVENTS: Record<string, string> = {
    'gift.created': 'أُصدرت',
    'gift.paid': 'اكتمل دفعها',
    'gift.redeemed': 'استُخدمت',
    'gift.cancelled': 'أُلغيت',
    'gift.expired': 'انتهت مهلتها',
    'gift.refunded': 'أُلغيت بإشعار دائن',
    'gift.unpaid': 'أُلغيت دفعتها فعادت بانتظار الدفع',
    'gift.link_rotated': 'أُصدر لها رابط جديد',
    'gift.phone_verified': 'أكدت المستلمة رقم واتساب برسالة منه',
    'gift.phone_verify_refused': 'وصل رمز تأكيد لم يُقبل (من رقم آخر أو بعد انتهائه)',
    'gift.recipient_changed': 'غُيّر رقم واتساب المستلمة',
    'gift.whatsapp_queued': 'جُهّزت رسالة الهدية للإرسال عبر واتساب',
    'gift.whatsapp_resent': 'أُعيد إرسال رسالة الهدية',
    'gift.whatsapp_failed': 'فشل إرسال رسالة الهدية',
    'gift.whatsapp_uncertain': 'نتيجة إرسال رسالة الهدية غير مؤكدة',
};

/** The codes the server writes as a cancellation's reason when the invoice did it (anything else is staff's text). */
const CANCEL_REASONS: Record<string, string> = {
    invoice_voided: 'أُلغيت فاتورتها',
    invoice_credited: 'رُدّت قيمة فاتورتها كاملة قبل اكتمال دفعها',
};

/** A cancellation's reason in words. */
export function giftCancelReason(reason: string | null | undefined): string | null {
    if (!reason) return null;
    return CANCEL_REASONS[reason] ?? reason;
}

/** A phone as the server keeps it (Phone::normalize): digits only, no leading 00, a local Omani number with 968. */
function normalizePhone(value: string): string {
    let digits = value.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0)).replace(/\D+/g, '');
    if (digits.startsWith('00')) digits = digits.slice(2);
    return digits.length === 8 ? `968${digits}` : digits;
}

/** Whether a gift tied to a recipient's number is being redeemed for a member with another one (staff are warned). */
export function giftForAnotherNumber(recipientPhone: string | null | undefined, memberPhone: string | null | undefined): boolean {
    if (!recipientPhone) return false;
    return normalizePhone(memberPhone ?? '') !== normalizePhone(recipientPhone);
}

/** Where a gift was bought. */
export function giftSource(source: string): string {
    return source === 'staff' ? 'الموظف' : source === 'site' || source === 'website' ? 'الموقع' : source;
}

/** The redeem dialog's steps, and the step each server error belongs to (a later step's error keeps its step). */
export const REDEEM_STEPS = ['who', 'date', 'summary'] as const;
export type RedeemStep = (typeof REDEEM_STEPS)[number];

const FIELD_STEP: Record<string, RedeemStep> = { mode: 'who', member_id: 'who', full_name: 'who', phone: 'who', email: 'who', starts_on: 'date' };

/** The earliest step with an error, or null when the errors are the dialog's own (`error`) or none. */
export function redeemErrorStep(errors: Record<string, string | undefined>): RedeemStep | null {
    const steps = Object.keys(errors).filter((k) => errors[k]).map((k) => FIELD_STEP[k]).filter(Boolean);
    return REDEEM_STEPS.find((s) => steps.includes(s)) ?? null;
}

/** The errors no step's field shows (`error`, or a sale's own such as a membership waiting for payment): at the top. */
export function redeemGeneralErrors(errors: Record<string, string | undefined>): string[] {
    return Object.entries(errors).filter(([k, v]) => v && !(k in FIELD_STEP)).map(([, v]) => v as string);
}
