import { afterEach, describe, expect, it } from 'vitest';
import { hasEnglish, tr } from '@/lib/i18n';
import { setLocale } from '@/lib/locale';
import { PLAN_ACCESS_FORM, planAccess, say } from '@/lib/plan-access';

const words = (p: Parameters<typeof planAccess>[0]) => {
    const access = planAccess(p);
    return { included: access.included.map((x) => x.text), notes: access.notes.map((x) => x.text) };
};

describe('what a plan gives, before it is bought («صلاحيات الباقة»)', () => {
    afterEach(() => setLocale('ar'));

    it('ticks only what the owner allowed, and says plainly what is not included', () => {
        expect(words({ allows_gym: true, allows_classes: false, sessions_limit: 8 })).toEqual({
            included: ['دخول النادي طوال مدة الاشتراك'],
            notes: ['لا تشمل حجز الحصص'],
        });
        expect(words({ allows_gym: false, allows_classes: true, sessions_limit: 8 })).toEqual({
            included: [':count حصة خلال مدة الاشتراك'],
            notes: ['الدخول إلى النادي يوم الحصة المحجوزة فقط'],
        });
        expect(words({ allows_gym: true, allows_classes: true, sessions_limit: null })).toEqual({
            included: ['دخول النادي طوال مدة الاشتراك', 'حصص غير محدودة'],
            notes: [],
        });
    });

    it('claims nothing when it does not know what the plan gives', () => {
        expect(words({})).toEqual({ included: [], notes: [] });
    });

    it('has the English of every phrase, and counts classes in both languages', () => {
        for (const p of [{ allows_gym: true }, { allows_classes: true, sessions_limit: 3 }, { allows_classes: true }, { allows_gym: true, allows_classes: true }]) {
            const access = planAccess(p);
            for (const x of [...access.included, ...access.notes]) expect(hasEnglish(x.text), x.text).toBe(true);
        }
        const t = (text: string, vars?: Record<string, number>) => tr(text, vars);
        const [eight] = planAccess({ allows_classes: true, sessions_limit: 8 }).included;
        const [one] = planAccess({ allows_classes: true, sessions_limit: 1 }).included;
        expect(say(eight, t, false)).toBe('8 حصة خلال مدة الاشتراك');
        setLocale('en');
        expect(say(eight, t, true)).toBe('8 classes during the membership');
        expect(say(one, t, true)).toBe('1 class during the membership');
        expect(tr('الدخول إلى النادي يوم الحصة المحجوزة فقط')).toBe('Club entry only on the day of a booked class');
        expect(tr('باقتك الحالية لا تشمل حجز الحصص.')).toBe('Your current membership does not include class bookings.');
        expect(tr('غير مشمولة')).toBe('Not included');
    });

    it('shows the plan form section in Arabic and in English, line by line', () => {
        const { summaries, ...lines } = PLAN_ACCESS_FORM;
        for (const text of [...Object.values(lines), ...Object.values(summaries)]) {
            expect(hasEnglish(text), text).toBe(true);
            expect(tr(text, undefined, 'en'), text).not.toMatch(/[\u0600-\u06FF]/);
            expect(tr(text, undefined, 'ar')).toBe(text);
        }
        expect(Object.keys(summaries).sort()).toEqual(['classes', 'gym', 'gym+classes']);
        expect(tr(PLAN_ACCESS_FORM.live, { count: 18 }, 'en')).toBe(
            'This plan has 18 current memberships. A change applies to new sales and renewals only: each current membership keeps its permissions until its period ends.',
        );
        expect(tr(PLAN_ACCESS_FORM.live, { count: 18 }, 'ar')).toContain('لهذه الباقة 18 اشتراك قائم.');
        expect(tr(PLAN_ACCESS_FORM.summaries.classes, undefined, 'en')).toBe('Classes only: the member books classes and comes in only on the day of a booked class.');
        expect(tr(PLAN_ACCESS_FORM.none, undefined, 'en')).toBe('Choose at least one: gym access or classes.');
    });
});
