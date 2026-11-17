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
    message: string | null;
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
    expired_at: string | null;
    created_at: string | null;
}

/** The list's status tabs («الكل» comes first on its own), each with its badge's colour. */
export const GIFT_FILTERS = [
    { value: 'pending', label: 'بانتظار الدفع', dot: '#d97706' },
    { value: 'paid', label: 'مدفوعة — لم تُستخدم', dot: '#059669' },
    { value: 'redeemed', label: 'مستخدمة', dot: '#2563eb' },
    { value: 'expired', label: 'منتهية الصلاحية', dot: '#9ca3af' },
    { value: 'cancelled', label: 'ملغاة', dot: '#dc2626' },
    { value: 'refunded', label: 'مستردة ماليًا', dot: '#7c3aed' },
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
    'gift.refunded': 'رُدّ مبلغها',
};

/** A cancellation's reason in words: the server writes a code when the invoice was voided. */
export function giftCancelReason(reason: string | null | undefined): string | null {
    if (!reason) return null;
    return reason === 'invoice_voided' ? 'أُلغيت فاتورتها' : reason;
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
