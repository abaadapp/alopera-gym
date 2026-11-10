/**
 * Pure helpers of «إدارة الموقع» (Pages/Website/Builder): ids, moving items, reading a link target, the checks the
 * editor makes before the server makes them again (links, videos, colour contrast), and turning the server's error
 * paths into the section and field they belong to. Nothing here touches the page, so tests/js/site-builder.test.ts
 * covers it directly.
 */
import type { FieldSchema, SectionDoc, SectionTypeSchema, SiteDoc } from '@/types/site';

/* ---------- ids ---------- */

/** A short random part of an id: lowercase letters and digits. */
export function randomPart(length = 6): string {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let out = '';
    for (let i = 0; i < length; i++) out += chars[Math.floor(Math.random() * chars.length)];
    return out;
}

/** A section id: `<type>-<random>`, lowercase [a-z0-9-], at most 40 characters (also the anchor #id on the home page). */
export function newSectionId(type: string, taken: string[] = []): string {
    const base = type.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32) || 'section';
    let id = `${base}-${randomPart()}`;
    while (taken.includes(id)) id = `${base}-${randomPart()}`;
    return id;
}

/** A list item's id (kept stable while the item lives, so React keys and error paths follow it). */
export function newItemId(): string {
    return `i-${randomPart(8)}`;
}

function clone<T>(value: T): T {
    return value === undefined ? value : (JSON.parse(JSON.stringify(value)) as T);
}

/**
 * The value a field starts with when its section is added or an item of a list is added: the schema's default when it
 * has one, else the empty value of its type.
 */
export function emptyValue(field: FieldSchema): unknown {
    switch (field.type) {
        case 'toggle':
            return false;
        case 'number':
            return field.min ?? 0;
        case 'link':
            return { label: '', href: '' };
        case 'image':
            return null;
        case 'list':
            return [];
        case 'select':
            return field.options?.[0]?.value ?? '';
        case 'color':
            return '#000000';
        default:
            return '';
    }
}

/** A new item of a list field: every sub-field at its empty value, and an id. */
export function newListItem(field: FieldSchema): Record<string, unknown> {
    const item: Record<string, unknown> = { id: newItemId() };
    for (const f of field.fields ?? []) item[f.key] = emptyValue(f);
    return item;
}

/** Gives every item of every list field (at any depth) an id when it has none, without changing the others. */
export function ensureItemIds(data: Record<string, unknown>, fields: FieldSchema[]): Record<string, unknown> {
    const out: Record<string, unknown> = { ...data };
    for (const f of fields) {
        if (f.type !== 'list') continue;
        const list = Array.isArray(out[f.key]) ? (out[f.key] as Record<string, unknown>[]) : [];
        out[f.key] = list.map((item) => {
            const withId = item && typeof item === 'object' ? { ...item } : {};
            if (typeof withId.id !== 'string' || withId.id === '') withId.id = newItemId();
            return ensureItemIds(withId, f.fields ?? []);
        });
    }
    return out;
}

/** A new section of a template: visible, its own tone left to the template, its defaults copied. */
export function newSection(schema: SectionTypeSchema, taken: string[] = []): SectionDoc {
    const data: Record<string, unknown> = {};
    for (const f of schema.fields) data[f.key] = schema.defaults[f.key] !== undefined ? clone(schema.defaults[f.key]) : emptyValue(f);
    return { id: newSectionId(schema.type, taken), type: schema.type, visible: true, tone: 'auto', data: ensureItemIds(data, schema.fields) };
}

/** A copy of a section beside it: a new id, and new ids for its list items. */
export function duplicateSection(section: SectionDoc, schema: SectionTypeSchema | undefined, taken: string[] = []): SectionDoc {
    const data = clone(section.data);
    const renew = (value: Record<string, unknown>, fields: FieldSchema[]): Record<string, unknown> => {
        for (const f of fields) {
            if (f.type !== 'list' || !Array.isArray(value[f.key])) continue;
            value[f.key] = (value[f.key] as Record<string, unknown>[]).map((item) => renew({ ...item, id: newItemId() }, f.fields ?? []));
        }
        return value;
    };
    return { ...section, id: newSectionId(section.type, taken), data: schema ? renew(data, schema.fields) : data };
}

/* ---------- moving ---------- */

/** The list with the item at `from` moved to index `to` (clamped); the same list when nothing moves. */
export function move<T>(list: T[], from: number, to: number): T[] {
    if (from < 0 || from >= list.length) return list;
    const target = Math.max(0, Math.min(list.length - 1, to));
    if (target === from) return list;
    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(target, 0, item);
    return next;
}

/**
 * Where a dragged item lands when dropped on the gap before `gap` (0…length): the index it takes once it has left its
 * own place.
 */
export function dropIndex(from: number, gap: number): number {
    return gap > from ? gap - 1 : gap;
}

/* ---------- links ---------- */

/** The pages of the site a link can open, as the server knows them. */
export const PAGE_TOKENS = ['@home', '@plans', '@programs', '@schedule', '@trainers', '@news', '@contact', '@join', '@shop', '@portal'] as const;

export type HrefKind = 'none' | 'page' | 'anchor' | 'external';

/** What kind of target a stored link is (an unknown one counts as external, so the editor shows it to be fixed). */
export function hrefKind(href: string | null | undefined): HrefKind {
    const value = (href ?? '').trim();
    if (value === '') return 'none';
    if (value.startsWith('@')) return 'page';
    if (value.startsWith('#')) return 'anchor';
    return 'external';
}

const EMAIL = /^[^\s@<>()"',;:]+@[^\s@<>()"',;:]+\.[^\s@<>()"',;:]{2,}$/;

/**
 * Whether a link target is one the server accepts, and if not why, in Arabic: '' (none), a page token, '#<section id>',
 * an http(s) address with a host, tel: with a number, mailto: with an address. Anything else — javascript:, data:,
 * a bare word, «//host» — is refused.
 */
export function checkHref(href: string | null | undefined): string | null {
    const value = (href ?? '').trim();
    if (value === '') return null;
    if (value.startsWith('@')) return (PAGE_TOKENS as readonly string[]).includes(value) ? null : 'هذه الصفحة غير موجودة في الموقع.';
    if (value.startsWith('#')) return /^#[a-z0-9-]{1,40}$/.test(value) ? null : 'اختر قسمًا من الصفحة الرئيسية.';
    const lower = value.toLowerCase();
    if (lower.startsWith('tel:')) return /^tel:\+?[0-9 ]{5,20}$/.test(value) ? null : 'رقم الهاتف غير صحيح. اكتبه هكذا: tel:+96890000000';
    if (lower.startsWith('mailto:')) return EMAIL.test(value.slice(7)) ? null : 'البريد الإلكتروني غير صحيح. اكتبه هكذا: mailto:name@example.com';
    if (lower.startsWith('http://') || lower.startsWith('https://')) {
        if (/\s/.test(value)) return 'الرابط لا يقبل المسافات.';
        try {
            const url = new URL(value);
            if (!url.hostname || !url.hostname.includes('.')) return 'الرابط غير مكتمل. مثال: https://www.instagram.com/alopera';
            if (url.username || url.password) return 'الرابط غير مقبول.';
            return null;
        } catch {
            return 'الرابط غير صحيح. مثال: https://www.instagram.com/alopera';
        }
    }
    return 'رابط غير مقبول. اختر صفحة أو قسمًا، أو اكتب رابطًا يبدأ بـ https:// أو tel: أو mailto:';
}

/** A video address the site can show: YouTube (watch, youtu.be, shorts, embed) or Vimeo. '' is fine (no video). */
export function checkVideo(url: string | null | undefined): string | null {
    const value = (url ?? '').trim();
    if (value === '') return null;
    return videoId(value) ? null : 'الرابط ليس فيديو من YouTube أو Vimeo. انسخ رابط الفيديو من المتصفح أو من زر «مشاركة».';
}

/** The provider and id of a YouTube or Vimeo address, or null. */
export function videoId(url: string): { provider: 'youtube' | 'vimeo'; id: string } | null {
    let parsed: URL;
    try {
        parsed = new URL(url.trim());
    } catch {
        return null;
    }
    if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') return null;
    const host = parsed.hostname.replace(/^www\.|^m\./, '');
    const yt = /^[A-Za-z0-9_-]{11}$/;
    if (host === 'youtu.be') {
        const id = parsed.pathname.slice(1).split('/')[0];
        return yt.test(id) ? { provider: 'youtube', id } : null;
    }
    if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
        const v = parsed.searchParams.get('v');
        if (parsed.pathname === '/watch' && v && yt.test(v)) return { provider: 'youtube', id: v };
        const m = parsed.pathname.match(/^\/(?:shorts|embed|live)\/([A-Za-z0-9_-]{11})/);
        return m ? { provider: 'youtube', id: m[1] } : null;
    }
    if (host === 'vimeo.com' || host === 'player.vimeo.com') {
        const m = parsed.pathname.match(/^\/(?:video\/)?(\d{5,12})(?:\/|$)/);
        return m ? { provider: 'vimeo', id: m[1] } : null;
    }
    return null;
}

/* ---------- colours ---------- */

export function isHexColor(value: string | null | undefined): boolean {
    return /^#[0-9a-fA-F]{6}$/.test(value ?? '');
}

/** WCAG relative luminance of a #rrggbb colour. */
export function luminance(hex: string): number {
    const n = parseInt(hex.slice(1), 16);
    const channel = (c: number) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
}

/** WCAG contrast ratio of two #rrggbb colours (1 to 21). */
export function contrastRatio(a: string, b: string): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

/** The theme's readability problems, in Arabic: text colour and accent against white, at least 4.5:1. */
export function themeWarnings(theme: { accent: string; ink: string; surface: string }): string[] {
    const out: string[] = [];
    if (isHexColor(theme.ink) && contrastRatio(theme.ink, '#FFFFFF') < 4.5) out.push('لون النصوص فاتح على الخلفية البيضاء، وقد يصعب قراءته.');
    if (isHexColor(theme.accent) && contrastRatio(theme.accent, '#FFFFFF') < 4.5) out.push('اللون المميز فاتح على الخلفية البيضاء: العناوين الصغيرة والروابط به قد يصعب قراءتها.');
    if (isHexColor(theme.surface) && isHexColor(theme.ink) && contrastRatio(theme.ink, theme.surface) < 4.5) out.push('لون النصوص قريب من لون الخلفية الفاتحة للأقسام.');
    return out;
}

export const THEME_PRESETS: { label: string; theme: { accent: string; ink: string; surface: string } }[] = [
    { label: 'ذهبي داكن (التصميم الأصلي)', theme: { accent: '#8A6700', ink: '#16130F', surface: '#F6F3EE' } },
    { label: 'عنابي هادئ', theme: { accent: '#8E2A4B', ink: '#1C1418', surface: '#F7F1F3' } },
    { label: 'أخضر زيتوني', theme: { accent: '#4F6B2A', ink: '#141A12', surface: '#F2F4EC' } },
];

/* ---------- errors ---------- */

export interface BuilderError {
    /** The path relative to the document, with a section's index replaced by its id: `sections.<id>.data.items.0.title`. */
    path: string;
    message: string;
    /** The section the error belongs to, if any. */
    sectionId?: string;
}

/**
 * The server's errors (`doc.sections.3.data.title`, `doc.contact.email`, `version`) as the editor shows them: the
 * section's position in the document that was sent is read as that section's id, so the error stays on it even if
 * the sections move afterwards.
 */
export function mapErrors(errors: Record<string, string | string[]>, sent: SiteDoc): BuilderError[] {
    return Object.entries(errors).map(([key, value]) => {
        const message = Array.isArray(value) ? value[0] : value;
        const path = key.startsWith('doc.') ? key.slice(4) : key;
        const m = path.match(/^sections\.(\d+)(\..*)?$/);
        if (m) {
            const section = sent.sections[Number(m[1])];
            if (section) return { path: `sections.${section.id}${m[2] ?? ''}`, message, sectionId: section.id };
        }
        // The English words of a section (doc.en.sections.3.data.title): on that section's English text.
        const en = path.match(/^en\.sections\.(\d+)(\..*)?$/);
        if (en) {
            const section = sent.sections[Number(en[1])];
            if (section) return { path: `en.sections.${section.id}${en[2] ?? ''}`, message };
        }
        return { path, message };
    });
}

/** The errors as a lookup by path. */
export function errorIndex(errors: BuilderError[]): Record<string, string> {
    const out: Record<string, string> = {};
    for (const e of errors) out[e.path] ??= e.message;
    return out;
}

/** Whether any error sits at or under a path (a list item holding a wrong field, a section with any error). */
export function hasErrorUnder(index: Record<string, string>, path: string): boolean {
    return Object.keys(index).some((k) => k === path || k.startsWith(`${path}.`));
}

/* ---------- reading ---------- */

/** A section's own words for the list: its title, else its first non-empty text field. */
export function sectionSummary(section: SectionDoc): string {
    const data = section.data ?? {};
    const pick = (v: unknown) => (typeof v === 'string' ? v.trim() : '');
    const title = [pick(data.title), pick(data.title_2)].filter(Boolean).join(' ');
    if (title) return title;
    for (const key of ['eyebrow', 'subtitle', 'body']) {
        const v = pick(data[key]);
        if (v) return v;
    }
    return '';
}

/**
 * What a content section still needs before visitors see it, or null when it has enough to show. Mirrors the
 * server's SiteRenderer::hasContent for the sections the owner writes (the gym system's sections are judged on
 * the server, from its data), so the editor can say at once why a section is not on the site.
 */
export function missingContent(section: SectionDoc): string | null {
    const d = section.data ?? {};
    const text = (v: unknown) => typeof v === 'string' && v.trim() !== '';
    const image = (v: unknown) => !!v && typeof v === 'object';
    const some = (ok: (item: Record<string, unknown>) => boolean) => (Array.isArray(d.items) ? (d.items as Record<string, unknown>[]) : []).some((i) => !!i && ok(i));
    const link = (v: unknown) => !!v && typeof v === 'object' && text((v as { label?: unknown }).label) && text((v as { href?: unknown }).href);
    switch (section.type) {
        case 'hero': return text(d.title) || text(d.title_2) || image(d.image) ? null : 'اكتب عنوانًا أو أضف صورة.';
        case 'intro': return text(d.title) || text(d.body) ? null : 'اكتب عنوانًا أو نصًا.';
        case 'image_text': return text(d.title) || text(d.body) || image(d.image) ? null : 'اكتب عنوانًا أو نصًا، أو أضف صورة.';
        case 'gallery': return some((i) => image(i.image)) ? null : 'أضف صورة واحدة على الأقل.';
        case 'services': return some((i) => text(i.title)) ? null : 'أضف بطاقة بعنوان واحدة على الأقل.';
        case 'testimonials': return some((i) => text(i.quote)) ? null : 'أضف رأيًا واحدًا على الأقل.';
        case 'stats': return some((i) => text(i.value)) ? null : 'أضف رقمًا واحدًا على الأقل.';
        case 'faq': return some((i) => text(i.q) && text(i.a)) ? null : 'أضف سؤالًا وجوابه.';
        case 'cta': return text(d.title) || link(d.primary) ? null : 'اكتب عنوانًا أو أضف زرًا.';
        case 'video': return checkVideo(typeof d.url === 'string' ? d.url : '') === null && text(d.url) ? null : 'أضف رابط فيديو من YouTube أو Vimeo.';
    }
    return null;
}

/** Bytes as people read them: «850 ك.ب»، «2.4 م.ب». */
export function fileSize(bytes: number): string {
    if (!bytes || bytes < 1024) return `${bytes || 0} بايت`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} ك.ب`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} م.ب`;
}

export const ORIENTATION_LABELS = { landscape: 'أفقية', portrait: 'عمودية', square: 'مربعة' } as const;

/** What «تلقائي» does with an image of this orientation where the field uses it, in plain words. */
export function autoLayoutHint(orientation: 'landscape' | 'portrait' | 'square' | null | undefined, usage: FieldSchema['usage']): string {
    if (!orientation) return 'تظهر الصورة كاملة بنسبتها الأصلية دون قص.';
    const shape = ORIENTATION_LABELS[orientation];
    switch (usage) {
        case 'background':
            return orientation === 'landscape'
                ? `الصورة ${shape}: تظهر كاملة بعرض الواجهة والنص فوق أسفلها، وعلى الهاتف يظهر النص تحتها حتى لا تُقص.`
                : `الصورة ${shape}: تظهر كاملة بجانب النص على الكمبيوتر، وتحته على الهاتف، دون قص.`;
        case 'side':
            return orientation === 'landscape'
                ? `الصورة ${shape}: تأخذ مساحة أعرض بجانب النص، وتظهر كاملة فوقه على الهاتف.`
                : `الصورة ${shape}: تظهر كاملة بجانب النص بارتفاع مناسب، وفوقه على الهاتف.`;
        case 'gallery':
            return `الصورة ${shape}: تأخذ مكانها في المعرض بنسبتها الأصلية كاملة دون قص.`;
        case 'card':
            return `الصورة ${shape}: تظهر كاملة أعلى البطاقة بنسبتها الأصلية.`;
        case 'portrait':
            return `الصورة ${shape}: تظهر كاملة داخل إطار الصورة الشخصية دون قص.`;
        default:
            return `الصورة ${shape}: تظهر كاملة بنسبتها الأصلية دون قص.`;
    }
}

/**
 * The media ids a document's sections use, each with the names of the sections using it («معرض صور»), found through
 * the image fields of each template (in lists too).
 */
export function imageUses(sections: SectionDoc[], schemas: Record<string, SectionTypeSchema>): Record<number, string[]> {
    const out: Record<number, string[]> = {};
    const add = (id: number, name: string) => {
        out[id] ??= [];
        if (!out[id].includes(name)) out[id].push(name);
    };
    const walk = (data: Record<string, unknown>, fields: FieldSchema[], name: string) => {
        for (const f of fields) {
            const value = data?.[f.key];
            if (f.type === 'image' && value && typeof value === 'object' && typeof (value as { id?: unknown }).id === 'number') add((value as { id: number }).id, name);
            if (f.type === 'list' && Array.isArray(value)) value.forEach((item) => walk(item as Record<string, unknown>, f.fields ?? [], name));
        }
    };
    for (const s of sections) {
        const schema = schemas[s.type];
        if (schema) walk(s.data, schema.fields, schema.label);
    }
    return out;
}

/**
 * The document as the editor keeps it: every list item (in sections, the menu, the footer, the opening hours) with an
 * id, so it keeps its place in React and its errors while it moves. Items that have one keep it.
 */
export function normalizeDoc(doc: SiteDoc, schemas: Record<string, SectionTypeSchema>): SiteDoc {
    const ids = <T extends object>(list: T[] | undefined): (T & { id: string })[] =>
        (Array.isArray(list) ? list : []).map((item) => {
            const id = (item as { id?: unknown }).id;
            return { ...item, id: typeof id === 'string' && id !== '' ? id : newItemId() };
        });
    const en = (doc as { en?: unknown }).en;
    return {
        ...doc,
        // The English words: always an object (the server sends an empty one as []).
        en: (en && typeof en === 'object' && !Array.isArray(en) ? en : Array.isArray(en) && en.length ? Object.fromEntries(en.map((v, i) => [String(i), v])) : {}) as Record<string, unknown>,
        header: { ...doc.header, links: ids(doc.header?.links) },
        footer: { ...doc.footer, links: ids(doc.footer?.links) },
        contact: { ...doc.contact, hours: ids(doc.contact?.hours) },
        sections: (doc.sections ?? []).map((s) => {
            const schema = schemas[s.type];
            return schema ? { ...s, data: ensureItemIds(s.data ?? {}, schema.fields) } : s;
        }),
    };
}

/** Stable JSON of a document, for telling whether it changed. */
export function docKey(doc: SiteDoc): string {
    return JSON.stringify(doc);
}
