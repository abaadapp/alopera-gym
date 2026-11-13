import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import panel from '@/i18n/panel.en.json';
import portal from '@/i18n/portal.en.json';
import site from '@/i18n/site.en.json';
import { currentLocale, type Locale } from '@/lib/locale';

/**
 * The website's and portal's fixed words in the page's language. Every phrase is written in Arabic in the code, as
 * before, and wrapped: t('اشترك الآن'). On the Arabic site it is the phrase itself, unchanged; on the English site
 * (/en/...) its English from resources/js/i18n/*.en.json, or the Arabic when a phrase has none yet. Values in a
 * phrase are named: t('متبقي :count مقاعد', { count: 3 }). The club's own words (plans, sections) come translated
 * from the server.
 *
 * The club panel is in Arabic and has no language switch; the one part shown in both languages at once, «صلاحيات
 * الباقة» in the plan form, takes its English from panel.en.json here: tr(text, vars, 'en').
 */
const EN: Record<string, string> = { ...(panel as Record<string, string>), ...(site as Record<string, string>), ...(portal as Record<string, string>) };

type Vars = Record<string, string | number | null | undefined>;

export function tr(text: string, vars?: Vars, locale: Locale = currentLocale()): string {
    let out = locale === 'en' ? (EN[text] ?? text) : text;
    if (vars) out = out.replace(/:([A-Za-z_]+)/g, (m, key: string) => (key in vars ? String(vars[key] ?? '') : m));
    return out;
}

/** Whether an Arabic phrase has its English yet (tests, and the check that no visible phrase was missed). */
export function hasEnglish(text: string): boolean {
    return text in EN;
}

export function useLocale(): Locale {
    const { locale } = usePage().props as { locale?: string };
    return locale === 'en' ? 'en' : 'ar';
}

export function useT(): (text: string, vars?: Vars) => string {
    const locale = useLocale();
    return useCallback((text: string, vars?: Vars) => tr(text, vars, locale), [locale]);
}
