/**
 * The language of the page being shown: Arabic (right to left) everywhere, English (left to right) on the English
 * website and portal (/en/...). Set from the shared `locale` prop on every page (app.tsx), and read by the formatting
 * helpers (dates, times, money) so they write in the page's language. No dictionary here: see lib/i18n.ts.
 */
export type Locale = 'ar' | 'en';

let current: Locale = 'ar';

export function currentLocale(): Locale {
    return current;
}

export function setLocale(locale: unknown): Locale {
    current = locale === 'en' ? 'en' : 'ar';
    if (typeof document !== 'undefined') {
        document.documentElement.lang = current;
        document.documentElement.dir = current === 'ar' ? 'rtl' : 'ltr';
    }
    return current;
}
