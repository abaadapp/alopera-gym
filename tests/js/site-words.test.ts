import { afterEach, describe, expect, it } from 'vitest';
import { tr } from '@/lib/i18n';
import { setLocale } from '@/lib/locale';
import { date, dayCount, money, time } from '@/lib/format';
import { normalizeDoc } from '@/lib/site-builder';
import { getWord, setWord, wordGroups } from '@/lib/site-words';
import type { SectionTypeSchema, SiteDoc } from '@/types/site';

const hero: SectionTypeSchema = {
    type: 'hero',
    label: 'الواجهة الرئيسية',
    description: '',
    group: 'content',
    tone: 'dark',
    defaults: {},
    fields: [
        { key: 'title', type: 'text', label: 'العنوان', max: 60 },
        { key: 'subtitle', type: 'textarea', label: 'الوصف', max: 400 },
        { key: 'primary', type: 'link', label: 'الزر الرئيسي' },
        { key: 'image', type: 'image', label: 'صورة' },
        { key: 'facts', type: 'list', label: 'نقاط', item: 'نقطة', max: 4, fields: [{ key: 'title', type: 'text', label: 'العنوان', max: 40 }] },
    ],
};

function doc(): SiteDoc {
    return {
        v: 1,
        pages: { plans: { title: 'الباقات والأسعار', subtitle: '' } } as SiteDoc['pages'],
        theme: { accent: '#8A6700', ink: '#16130F', surface: '#F6F3EE' },
        announcement: '',
        header: { links: [{ id: 'l1', label: 'الباقات', href: '#pricing' }, { id: 'l2', label: '', href: '@news' }], cta: { label: 'انضمي الآن', href: '@join' }, show_login: true },
        footer: { line: '', links: [], show_hours: true, show_social: true },
        contact: { phone: '', whatsapp: '', email: '', address: 'مسقط', map_url: '', hours: [], social: { instagram: '', x: '', tiktok: '', snapchat: '', facebook: '', youtube: '' } },
        seo: { title: 'نادي', description: '' },
        sections: [{ id: 'top', type: 'hero', visible: true, tone: 'auto', data: { title: 'قوّتكِ', subtitle: '', primary: { label: 'ابدئي', href: '@join' }, image: { id: 3, fit: 'auto' }, facts: [{ id: 'f1', title: 'مدربات' }] } }],
    };
}

describe('the English words of the site («النسخة الإنجليزية»)', () => {
    it('lists every text with Arabic, by section and item id, and nothing else', () => {
        const groups = wordGroups(doc(), { hero }, [{ key: 'plans', label: 'صفحة الباقات', fields: [{ key: 'title', type: 'text', label: 'عنوان الصفحة', max: 80 }, { key: 'subtitle', type: 'textarea', label: 'السطر', max: 300 }] }]);
        const paths = groups.flatMap((g) => g.entries.map((e) => e.path.join('.')));
        expect(paths).toEqual([
            'sections.top.title',
            'sections.top.primary.label',
            'sections.top.facts.f1.title',
            'header.links.l1.label',
            'header.cta.label',
            'contact.address',
            'seo.title',
            'pages.plans.title',
        ]);
        // Server error paths: the section by id (mapErrors turns its index into it), list items by position.
        const entries = groups.flatMap((g) => g.entries);
        expect(entries.find((e) => e.path.join('.') === 'sections.top.facts.f1.title')?.errorPath).toBe('en.sections.top.data.facts.0.title');
        expect(entries.find((e) => e.path.join('.') === 'header.links.l1.label')?.errorPath).toBe('en.header.links.0.label');
    });

    it('sets and reads words without leaving empty branches, whatever shape the server sent', () => {
        let words = setWord([], ['sections', 'top', 'title'], 'Your strength');
        words = setWord(words, ['sections', 'top', 'primary', 'label'], 'Join');
        expect(getWord(words, ['sections', 'top', 'title'])).toBe('Your strength');
        expect(words).toEqual({ sections: { top: { title: 'Your strength', primary: { label: 'Join' } } } });
        words = setWord(words, ['sections', 'top', 'title'], '');
        words = setWord(words, ['sections', 'top', 'primary', 'label'], '');
        expect(words).toEqual({});
        expect(normalizeDoc({ ...doc(), en: [] as unknown as Record<string, unknown> }, { hero }).en).toEqual({});
    });
});

describe('the page language', () => {
    afterEach(() => setLocale('ar'));

    it('keeps Arabic as it is and gives English where the dictionary has it', () => {
        setLocale('ar');
        expect(tr('القائمة')).toBe('القائمة');
        expect(tr('السلة (:count)', { count: 2 })).toBe('السلة (2)');
        expect(document.documentElement.dir).toBe('rtl');
        setLocale('en');
        expect(tr('القائمة')).toBe('Menu');
        expect(tr('السلة (:count)', { count: 2 })).toBe('Cart (2)');
        expect(tr('نص لا ترجمة له')).toBe('نص لا ترجمة له');
        expect(document.documentElement.dir).toBe('ltr');
        expect(document.documentElement.lang).toBe('en');
    });

    it('writes dates, times, day counts and money in the page language', () => {
        setLocale('ar');
        expect(date('2026-11-10')).toBe('10 نوفمبر 2026');
        expect(time('18:30')).toBe('6:30 م');
        expect(dayCount(3)).toBe('3 أيام');
        expect(money('25')).toBe('25.000 ر.ع');
        setLocale('en');
        expect(date('2026-11-10')).toBe('10 November 2026');
        expect(time('18:30')).toBe('6:30 PM');
        expect(dayCount(1)).toBe('1 day');
        expect(money('25')).toBe('25.000 OMR');
    });
});
