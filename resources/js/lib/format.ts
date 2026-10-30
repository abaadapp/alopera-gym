/** Formatting helpers. Amounts are strings with 3 decimals (OMR has 1000 baisa). */

export function money(value: string | number | null | undefined, currency = 'OMR'): string {
    const n = Number(value ?? 0);
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }).format(Number.isFinite(n) ? n : 0);
    return `${formatted} ${currencyLabel(currency)}`;
}

export function currencyLabel(currency: string): string {
    return ({ OMR: 'ر.ع', SAR: 'ر.س', AED: 'د.إ', KWD: 'د.ك', QAR: 'ر.ق', BHD: 'د.ب' } as Record<string, string>)[currency] ?? currency;
}

export function number(value: number | string | null | undefined, digits = 0): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: digits }).format(Number(value ?? 0));
}

const dateFmt = new Intl.DateTimeFormat('ar-OM-u-nu-latn', { day: 'numeric', month: 'short', year: 'numeric' });
const dayFmt = new Intl.DateTimeFormat('ar-OM-u-nu-latn', { weekday: 'long', day: 'numeric', month: 'long' });

export function date(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value.length <= 10 ? `${value}T00:00:00` : value.replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? value : dateFmt.format(d);
}

export function dayName(value: string): string {
    const d = new Date(value.length <= 10 ? `${value}T00:00:00` : value.replace(' ', 'T'));
    return dayFmt.format(d);
}

export function time(value: string | null | undefined): string {
    if (!value) return '';
    const t = value.length > 5 ? value.slice(11, 16) || value.slice(0, 5) : value;
    const [h, m] = t.split(':').map(Number);
    const suffix = h >= 12 ? 'م' : 'ص';
    const hh = h % 12 === 0 ? 12 : h % 12;
    return `${hh}:${String(m).padStart(2, '0')} ${suffix}`;
}

export const WEEKDAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

export function phone(value: string | null | undefined): string {
    if (!value) return '';
    return value.startsWith('968') && value.length === 11 ? `+968 ${value.slice(3, 7)} ${value.slice(7)}` : `+${value}`;
}

export function whatsappUrl(value: string | null | undefined, text = ''): string {
    return `https://wa.me/${value ?? ''}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
}

/** Day counts with Arabic number agreement: «يوم واحد»، «يومين»، «3 أيام»، «12 يومًا». */
export function dayCount(value: number | null | undefined): string {
    const n = Number(value ?? 0);
    if (n === 1) return 'يوم واحد';
    if (n === 2) return 'يومين';
    if (n >= 3 && n <= 10) return `${n} أيام`;
    return n === 0 ? '0 يوم' : `${n} يومًا`;
}

/** For searching: Arabic-Indic digits as digits, the interchangeable letters folded and Latin in lower case, so «احمد» finds «أحمد». */
/** «إضافة واحدة»، «إضافتان»، «3 إضافات»، «11 إضافة». */
export function addonCount(value: number): string {
    if (value === 1) return 'إضافة واحدة';
    if (value === 2) return 'إضافتان';
    return `${number(value)} ${value >= 3 && value <= 10 ? 'إضافات' : 'إضافة'}`;
}

export function fold(text: string): string {
    return text
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
        .toLowerCase()
        .trim();
}
