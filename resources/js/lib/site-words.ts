/**
 * The English side of the website document («إدارة الموقع» → «النسخة الإنجليزية»): every text the Arabic site shows
 * (a heading, a paragraph, a button's or menu link's label, an item of a list, the contact lines, the search engine
 * text, the inner pages' titles), each with the English the owner writes for it in `doc.en`.
 *
 * The English words follow the server's shape (App\Modules\Website\Content\SiteDocument::translation): sections and
 * list items by id, so moving or deleting them keeps the right words. Images, links, colours and switches are shared
 * by both languages and are edited on the Arabic side. A text the Arabic site leaves empty is not on the English site
 * either. Pure helpers, covered by tests/js/site-words.test.ts.
 */
import type { FieldSchema, PageKey, SectionTypeSchema, SiteDoc } from '@/types/site';
import { sectionSummary } from '@/lib/site-builder';

export type Words = Record<string, unknown>;

export interface WordEntry {
    /** Where the English sits in doc.en. */
    path: string[];
    /** Where the server reports a problem with it, as the editor keeps errors (en.sections.<id>.data.title). */
    errorPath: string;
    label: string;
    arabic: string;
    max: number;
    multiline: boolean;
}

export interface WordGroup {
    key: string;
    title: string;
    entries: WordEntry[];
}

/** An object as the server may send it: PHP sends an empty map as [] and a map with keys 0…n as a list. */
function asObject(value: unknown): Record<string, unknown> {
    if (Array.isArray(value)) return Object.fromEntries(value.map((v, i) => [String(i), v]));
    return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

export function getWord(words: unknown, path: string[]): string {
    let node: unknown = words;
    for (const key of path) node = asObject(node)[key];
    return typeof node === 'string' ? node : '';
}

/** doc.en with one text set (an emptied text is removed, so no empty branches pile up). */
export function setWord(words: unknown, path: string[], value: string): Words {
    const [key, ...rest] = path;
    const node = { ...asObject(words) };
    if (rest.length === 0) {
        if (value === '') delete node[key];
        else node[key] = value;
        return node;
    }
    const child = setWord(node[key], rest, value);
    if (Object.keys(child).length === 0) delete node[key];
    else node[key] = child;
    return node;
}

const text = (v: unknown): string => (typeof v === 'string' ? v.trim() : '');

/** The texts of a group of fields that have Arabic words, at any depth. */
function walk(fields: FieldSchema[], values: Record<string, unknown>, path: string[], errorBase: string, prefix: string, out: WordEntry[]): void {
    for (const field of fields) {
        const value = values?.[field.key];
        const label = prefix + field.label;
        if (field.type === 'text' || field.type === 'textarea') {
            if (text(value) !== '') {
                out.push({ path: [...path, field.key], errorPath: `${errorBase}.${field.key}`, label, arabic: value as string, max: field.max ?? (field.type === 'text' ? 200 : 2000), multiline: field.type === 'textarea' });
            }
        } else if (field.type === 'link') {
            const l = asObject(value).label;
            if (text(l) !== '') {
                out.push({ path: [...path, field.key, 'label'], errorPath: `${errorBase}.${field.key}.label`, label: `${label} — نص الزر`, arabic: l as string, max: 40, multiline: false });
            }
        } else if (field.type === 'list' && Array.isArray(value)) {
            value.forEach((item, j) => {
                const id = asObject(item).id;
                if (typeof id !== 'string' || id === '') return;
                walk(field.fields ?? [], asObject(item), [...path, field.key, id], `${errorBase}.${field.key}.${j}`, `${prefix}${field.item ?? field.label} ${j + 1} — `, out);
            });
        }
    }
}

/**
 * Every text of the document to write in English, in the editor's order: the sections (as on the home page), the menu
 * and footer, the contact lines, the announcement and search engine text, then the inner pages.
 */
export function wordGroups(doc: SiteDoc, schemas: Record<string, SectionTypeSchema>, pages: { key: PageKey; label: string; fields: FieldSchema[] }[]): WordGroup[] {
    const groups: WordGroup[] = [];

    for (const section of doc.sections ?? []) {
        const schema = schemas[section.type];
        if (!schema) continue;
        const entries: WordEntry[] = [];
        walk(schema.fields, section.data ?? {}, ['sections', section.id], `en.sections.${section.id}.data`, '', entries);
        const summary = sectionSummary(section);
        if (entries.length) groups.push({ key: `section:${section.id}`, title: summary ? `${schema.label} — ${summary.slice(0, 60)}` : schema.label, entries });
    }

    const menu: WordEntry[] = [];
    (doc.header?.links ?? []).forEach((l, j) => {
        if (text(l.label)) menu.push({ path: ['header', 'links', l.id, 'label'], errorPath: `en.header.links.${j}.label`, label: `رابط القائمة العلوية ${j + 1}`, arabic: l.label, max: 30, multiline: false });
    });
    if (text(doc.header?.cta?.label)) menu.push({ path: ['header', 'cta', 'label'], errorPath: 'en.header.cta.label', label: 'زر القائمة العلوية', arabic: doc.header.cta.label, max: 40, multiline: false });
    if (text(doc.footer?.line)) menu.push({ path: ['footer', 'line'], errorPath: 'en.footer.line', label: 'سطر التذييل', arabic: doc.footer.line, max: 300, multiline: true });
    (doc.footer?.links ?? []).forEach((l, j) => {
        if (text(l.label)) menu.push({ path: ['footer', 'links', l.id, 'label'], errorPath: `en.footer.links.${j}.label`, label: `رابط التذييل ${j + 1}`, arabic: l.label, max: 30, multiline: false });
    });
    if (menu.length) groups.push({ key: 'menu', title: 'القائمة العلوية والتذييل', entries: menu });

    const contact: WordEntry[] = [];
    if (text(doc.contact?.address)) contact.push({ path: ['contact', 'address'], errorPath: 'en.contact.address', label: 'العنوان', arabic: doc.contact.address, max: 255, multiline: false });
    (doc.contact?.hours ?? []).forEach((h, j) => {
        if (text(h.days)) contact.push({ path: ['contact', 'hours', h.id, 'days'], errorPath: `en.contact.hours.${j}.days`, label: `أوقات العمل ${j + 1} — الأيام`, arabic: h.days, max: 60, multiline: false });
        if (text(h.hours)) contact.push({ path: ['contact', 'hours', h.id, 'hours'], errorPath: `en.contact.hours.${j}.hours`, label: `أوقات العمل ${j + 1} — الساعات`, arabic: h.hours, max: 60, multiline: false });
    });
    if (contact.length) groups.push({ key: 'contact', title: 'التواصل وأوقات العمل', entries: contact });

    const seo: WordEntry[] = [];
    if (text(doc.announcement)) seo.push({ path: ['announcement'], errorPath: 'en.announcement', label: 'نص الإعلان', arabic: doc.announcement, max: 200, multiline: false });
    if (text(doc.seo?.title)) seo.push({ path: ['seo', 'title'], errorPath: 'en.seo.title', label: 'عنوان الموقع في نتائج البحث', arabic: doc.seo.title, max: 70, multiline: false });
    if (text(doc.seo?.description)) seo.push({ path: ['seo', 'description'], errorPath: 'en.seo.description', label: 'وصف الموقع في نتائج البحث', arabic: doc.seo.description, max: 300, multiline: true });
    if (seo.length) groups.push({ key: 'seo', title: 'الإعلان ومحركات البحث', entries: seo });

    const inner: WordEntry[] = [];
    for (const page of pages) {
        for (const field of page.fields) {
            const arabic = (doc.pages?.[page.key] as unknown as Record<string, unknown> | undefined)?.[field.key];
            if (text(arabic)) inner.push({ path: ['pages', page.key, field.key], errorPath: `en.pages.${page.key}.${field.key}`, label: `${page.label} — ${field.label}`, arabic: arabic as string, max: field.max ?? 80, multiline: field.type === 'textarea' });
        }
    }
    if (inner.length) groups.push({ key: 'pages', title: 'الصفحات الداخلية', entries: inner });

    return groups;
}
