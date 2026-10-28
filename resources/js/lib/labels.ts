/** Arabic labels and tones for statuses used across screens. */

type Tone = 'green' | 'amber' | 'red' | 'gray' | 'blue' | 'brand' | 'violet';

export const membershipStatus: Record<string, [string, Tone]> = {
    pending: ['بانتظار الدفع', 'amber'],
    scheduled: ['مجدولة', 'blue'],
    active: ['نشطة', 'green'],
    frozen: ['مجمّدة', 'violet'],
    expired: ['منتهية', 'gray'],
    cancelled: ['ملغاة', 'red'],
};

export const membershipKind: Record<string, string> = { new: 'جديد', renewal: 'تجديد', upgrade: 'تغيير باقة' };

export const invoiceStatus: Record<string, [string, Tone]> = {
    draft: ['مسودة', 'gray'],
    issued: ['غير مدفوعة', 'amber'],
    partially_paid: ['مدفوعة جزئيًا', 'blue'],
    paid: ['مدفوعة', 'green'],
    void: ['ملغاة', 'red'],
};

export const invoiceType: Record<string, string> = { membership: 'عضوية', pt: 'تدريب شخصي', pos: 'نقطة بيع', other: 'أخرى' };

export const paymentStatus: Record<string, [string, Tone]> = {
    pending: ['بانتظار التأكيد', 'amber'],
    completed: ['مكتملة', 'green'],
    failed: ['فاشلة', 'red'],
    rejected: ['مرفوضة', 'red'],
};

export const paymentMethod: Record<string, string> = {
    cash: 'نقدًا',
    bank_transfer: 'تحويل بنكي',
    card: 'بطاقة (جهاز الشبكة)',
    paymob: 'دفع إلكتروني',
    other: 'أخرى',
};

export const bookingStatus: Record<string, [string, Tone]> = {
    booked: ['محجوز', 'blue'],
    attended: ['حضرت', 'green'],
    no_show: ['غابت', 'red'],
    cancelled: ['ملغى', 'gray'],
};

export const sessionStatus: Record<string, [string, Tone]> = {
    scheduled: ['مجدولة', 'blue'],
    completed: ['منتهية', 'gray'],
    cancelled: ['ملغاة', 'red'],
};

export const memberStatus: Record<string, [string, Tone]> = {
    active: ['نشطة', 'green'],
    inactive: ['غير نشطة', 'gray'],
    blocked: ['موقوفة', 'red'],
};

export const messageStatus: Record<string, [string, Tone]> = {
    scheduled: ['مجدولة', 'blue'],
    queued: ['في الانتظار', 'gray'],
    sent: ['أُرسلت', 'blue'],
    delivered: ['وصلت', 'green'],
    read: ['قُرئت', 'green'],
    failed: ['فشلت', 'red'],
    received: ['واردة', 'brand'],
    skipped: ['لم تُرسل', 'amber'],
};

export const tenantStatus: Record<string, [string, Tone]> = {
    trial: ['تجريبي', 'blue'],
    active: ['نشط', 'green'],
    past_due: ['متأخر السداد', 'amber'],
    suspended: ['موقوف', 'red'],
    cancelled: ['ملغى', 'gray'],
};

export const durationUnit: Record<string, string> = { day: 'يوم', week: 'أسبوع', month: 'شهر', year: 'سنة' };

export const checkinReason: Record<string, string> = {
    ok: 'مسموح',
    unknown_credential: 'غير معروفة',
    member_blocked: 'الحساب موقوف',
    member_inactive: 'الحساب غير نشط',
    no_membership: 'لا توجد عضوية',
    membership_expired: 'العضوية منتهية',
    membership_frozen: 'العضوية مجمّدة',
    membership_not_started: 'لم تبدأ بعد',
    pending_payment: 'بانتظار الدفع',
    dues_outstanding: 'مستحقات متأخرة',
    device_not_for_members: 'جهاز غير مخصص',
};

export function label(map: Record<string, [string, Tone]>, key: string | null | undefined): [string, Tone] {
    return (key && map[key]) || [key ?? '—', 'gray'];
}
