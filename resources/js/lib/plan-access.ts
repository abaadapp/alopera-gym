/**
 * What a plan gives, as the owner set it («صلاحيات الباقة»), in the words the website, the join page and the portal
 * show before a sale. The server decides the door and the bookings (Membership::opensDoorAnyDay, booksClasses); this
 * only says it. Arabic phrases, wrapped by t() on the page; their English is in resources/js/i18n/site.en.json.
 */
export interface PlanAccessTerms {
    allows_gym?: boolean | null;
    allows_classes?: boolean | null;
    sessions_limit?: number | null;
}

export interface Phrase {
    text: string;
    vars?: Record<string, number>;
}

/** included: what the plan gives (shown with a tick). notes: what it does not give, said plainly (no tick). */
export function planAccess(plan: PlanAccessTerms): { included: Phrase[]; notes: Phrase[] } {
    const gym = plan.allows_gym === true;
    const classes = plan.allows_classes === true;
    const included: Phrase[] = [];
    const notes: Phrase[] = [];

    if (gym) included.push({ text: 'دخول النادي طوال مدة الاشتراك' });
    if (classes) included.push(plan.sessions_limit ? { text: ':count حصة خلال مدة الاشتراك', vars: { count: plan.sessions_limit } } : { text: 'حصص غير محدودة' });
    if (classes && !gym) notes.push({ text: 'الدخول إلى النادي يوم الحصة المحجوزة فقط' });
    if (gym && !classes) notes.push({ text: 'لا تشمل حجز الحصص' });

    return { included, notes };
}

/** A phrase in the page's language: one class is "1 class" in English, not "1 classes". */
export function say(phrase: Phrase, t: (text: string, vars?: Record<string, number>) => string, english: boolean): string {
    if (english && phrase.text === ':count حصة خلال مدة الاشتراك' && phrase.vars?.count === 1) return '1 class during the membership';

    return t(phrase.text, phrase.vars);
}
