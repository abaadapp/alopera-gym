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

/**
 * The words of «صلاحيات الباقة» in the plan form. The panel is in Arabic; this section also shows each line in English
 * under it (resources/js/i18n/panel.en.json), so the owner reads both whatever language the staff prefer.
 */
export const PLAN_ACCESS_FORM = {
    title: 'صلاحيات الباقة',
    intro: 'اختر ما تسمح به الباقة. لا يُستنتج من اسمها أو سعرها.',
    gym: 'دخول النادي',
    gymHint: 'تفتح البوابة للمشترك في أي يوم خلال مدة اشتراكه.',
    classes: 'حجز الحصص',
    classesHint: 'يحجز المشترك الحصص حسب رصيد الباقة والبرامج المشمولة وسعة الحصة.',
    summary: 'الملخص:',
    summaries: {
        'gym': 'دخول النادي فقط: يدخل المشترك أي يوم خلال مدة اشتراكه، ولا يحجز الحصص.',
        'classes': 'حجز الحصص فقط: يحجز المشترك الحصص، ويدخل النادي يوم حصته المحجوزة فقط.',
        'gym+classes': 'دخول النادي وحجز الحصص: يدخل أي يوم، ويحجز الحصص حسب رصيد الباقة.',
    } as Record<string, string>,
    none: 'اختر صلاحية واحدة على الأقل: دخول النادي أو حجز الحصص.',
    live: 'لهذه الباقة :count اشتراك قائم. تغيير الصلاحيات يسري على المبيعات والتجديدات الجديدة فقط، ويحتفظ كل اشتراك قائم بصلاحياته حتى نهاية فترته.',
};
