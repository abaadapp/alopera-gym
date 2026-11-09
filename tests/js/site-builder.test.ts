import { describe, expect, it } from 'vitest';
import type { SectionTypeSchema, SiteDoc } from '@/types/site';
import {
    autoLayoutHint,
    checkHref,
    checkVideo,
    contrastRatio,
    dropIndex,
    duplicateSection,
    ensureItemIds,
    errorIndex,
    hasErrorUnder,
    hrefKind,
    imageUses,
    mapErrors,
    move,
    newSection,
    newSectionId,
    normalizeDoc,
    sectionSummary,
    themeWarnings,
    THEME_PRESETS,
    videoId,
} from '@/lib/site-builder';

const gallery: SectionTypeSchema = {
    type: 'gallery',
    label: 'معرض صور',
    description: '',
    group: 'content',
    tone: 'white',
    fields: [
        { key: 'title', type: 'text', label: 'العنوان', max: 120 },
        { key: 'layout', type: 'select', label: 'طريقة العرض', options: [{ value: 'auto', label: 'تلقائي' }] },
        { key: 'items', type: 'list', label: 'الصور', item: 'صورة', max: 30, fields: [{ key: 'image', type: 'image', label: 'الصورة' }, { key: 'caption', type: 'text', label: 'تعليق' }] },
    ],
    defaults: { title: '', layout: 'auto', items: [] },
};

describe('ids', () => {
    it('makes a lowercase section id from the type that is also a valid anchor', () => {
        const id = newSectionId('image_text');
        expect(id).toMatch(/^image-text-[a-z0-9]{6}$/);
        expect(id.length).toBeLessThanOrEqual(40);
    });

    it('never repeats an id already taken', () => {
        const taken = Array.from({ length: 50 }, () => newSectionId('faq'));
        expect(taken.includes(newSectionId('faq', taken))).toBe(false);
    });

    it('starts a new section from the template defaults, visible and with its own tone', () => {
        const s = newSection(gallery);
        expect(s).toMatchObject({ type: 'gallery', visible: true, tone: 'auto', data: { title: '', layout: 'auto', items: [] } });
        // The defaults are copied, not shared.
        (s.data.items as unknown[]).push({ id: 'x' });
        expect(gallery.defaults.items).toEqual([]);
    });

    it('gives list items an id when they have none and keeps the ones they have', () => {
        const data = ensureItemIds({ items: [{ id: 'keep', caption: 'a' }, { caption: 'b' }] }, gallery.fields);
        const items = data.items as { id: string }[];
        expect(items[0].id).toBe('keep');
        expect(items[1].id).toMatch(/^i-[a-z0-9]{8}$/);
    });

    it('duplicates a section with a new id and new item ids', () => {
        const s = { id: 'gallery-aaaaaa', type: 'gallery', visible: true, tone: 'auto' as const, data: { title: 'صور', items: [{ id: 'i-1', caption: 'c' }] } };
        const copy = duplicateSection(s, gallery);
        expect(copy.id).not.toBe(s.id);
        expect(copy.data.title).toBe('صور');
        expect((copy.data.items as { id: string }[])[0].id).not.toBe('i-1');
        expect((s.data.items as { id: string }[])[0].id).toBe('i-1');
    });
});

describe('moving', () => {
    it('moves an item and clamps the target', () => {
        expect(move(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
        expect(move(['a', 'b', 'c'], 2, 0)).toEqual(['c', 'a', 'b']);
        expect(move(['a', 'b', 'c'], 1, 9)).toEqual(['a', 'c', 'b']);
        const same = ['a', 'b'];
        expect(move(same, 1, 1)).toBe(same);
    });

    it('reads a drop on a gap as the index the item takes', () => {
        // Dragging the first of three to the gap after the last.
        expect(dropIndex(0, 3)).toBe(2);
        // Dragging the last to the gap before the first.
        expect(dropIndex(2, 0)).toBe(0);
    });
});

describe('links', () => {
    it('accepts what the server accepts', () => {
        for (const ok of ['', '@plans', '@join', '#schedule', '#faq-a1b2c3', 'https://www.instagram.com/alopera', 'http://example.om/x?y=1', 'tel:+96890000000', 'tel:96890000000', 'mailto:hello@aloperagym.om']) {
            expect(checkHref(ok), ok).toBeNull();
        }
    });

    it('refuses dangerous and broken targets', () => {
        for (const bad of ['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,<script>', 'vbscript:x', '//evil.com', 'www.site.com', '@admin', '#Bad Anchor', 'tel:abc', 'mailto:nobody', 'https://', 'https://user:pass@site.com', 'https://bad host.com']) {
            expect(checkHref(bad), bad).not.toBeNull();
        }
    });

    it('tells the kind of a target', () => {
        expect(hrefKind('')).toBe('none');
        expect(hrefKind('@plans')).toBe('page');
        expect(hrefKind('#top')).toBe('anchor');
        expect(hrefKind('https://x.om')).toBe('external');
    });

    it('reads YouTube and Vimeo addresses and refuses others', () => {
        expect(videoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ')).toEqual({ provider: 'youtube', id: 'dQw4w9WgXcQ' });
        expect(videoId('https://youtu.be/dQw4w9WgXcQ?t=3')).toEqual({ provider: 'youtube', id: 'dQw4w9WgXcQ' });
        expect(videoId('https://youtube.com/shorts/dQw4w9WgXcQ')).toEqual({ provider: 'youtube', id: 'dQw4w9WgXcQ' });
        expect(videoId('https://vimeo.com/123456789')).toEqual({ provider: 'vimeo', id: '123456789' });
        expect(checkVideo('')).toBeNull();
        expect(checkVideo('https://example.com/video.mp4')).not.toBeNull();
        expect(checkVideo('javascript:alert(1)')).not.toBeNull();
    });
});

describe('colours', () => {
    it('computes contrast like WCAG', () => {
        expect(contrastRatio('#000000', '#FFFFFF')).toBeCloseTo(21, 0);
        expect(contrastRatio('#FFFFFF', '#FFFFFF')).toBeCloseTo(1, 5);
    });

    it('finds the original design readable and warns on a pale accent', () => {
        expect(themeWarnings(THEME_PRESETS[0].theme)).toEqual([]);
        for (const p of THEME_PRESETS) expect(themeWarnings(p.theme), p.label).toEqual([]);
        expect(themeWarnings({ accent: '#E2C27A', ink: '#16130F', surface: '#F6F3EE' })).toHaveLength(1);
        expect(themeWarnings({ accent: '#8A6700', ink: '#BBBBBB', surface: '#F6F3EE' }).length).toBeGreaterThan(0);
    });
});

describe('errors', () => {
    const doc = { sections: [{ id: 'top', type: 'hero', visible: true, tone: 'auto', data: {} }, { id: 'faq-abc123', type: 'faq', visible: true, tone: 'auto', data: {} }] } as unknown as SiteDoc;

    it('pins a section error to the section that was sent at that position', () => {
        const mapped = mapErrors({ 'doc.sections.1.data.items.0.q': 'السؤال طويل.', 'doc.contact.email': ['البريد غير صحيح.'], version: 'عُدّل الموقع من نافذة أخرى' }, doc);
        expect(mapped).toEqual([
            { path: 'sections.faq-abc123.data.items.0.q', message: 'السؤال طويل.', sectionId: 'faq-abc123' },
            { path: 'contact.email', message: 'البريد غير صحيح.' },
            { path: 'version', message: 'عُدّل الموقع من نافذة أخرى' },
        ]);
        const index = errorIndex(mapped);
        expect(hasErrorUnder(index, 'sections.faq-abc123')).toBe(true);
        expect(hasErrorUnder(index, 'sections.top')).toBe(false);
        expect(hasErrorUnder(index, 'sections.faq-abc123.data.items.0')).toBe(true);
    });
});

describe('reading', () => {
    it('summarises a section by its title, else its first text', () => {
        expect(sectionSummary({ id: 'top', type: 'hero', visible: true, tone: 'auto', data: { title: 'قوّتكِ', title_2: 'تبدأ هنا.' } })).toBe('قوّتكِ تبدأ هنا.');
        expect(sectionSummary({ id: 'x', type: 'intro', visible: true, tone: 'auto', data: { title: '', body: 'نبذة' } })).toBe('نبذة');
        expect(sectionSummary({ id: 'x', type: 'intro', visible: true, tone: 'auto', data: {} })).toBe('');
    });

    it('gives every list item of the document an id once and keeps it after', () => {
        const doc = {
            v: 1,
            header: { links: [{ label: 'الباقات', href: '#pricing' }], cta: { label: '', href: '' }, show_login: true },
            footer: { line: '', links: [], show_hours: true, show_social: true },
            contact: { hours: [{ days: 'السبت', hours: '6-11' }] },
            sections: [{ id: 'g', type: 'gallery', visible: true, tone: 'auto', data: { items: [{ caption: 'x' }] } }],
        } as unknown as SiteDoc;
        const once = normalizeDoc(doc, { gallery });
        expect(once.header.links[0].id).toMatch(/^i-/);
        expect(once.contact.hours[0].id).toMatch(/^i-/);
        expect((once.sections[0].data.items as { id: string }[])[0].id).toMatch(/^i-/);
        expect(normalizeDoc(once, { gallery })).toEqual(once);
    });

    it('finds the images a document uses, in lists too', () => {
        const uses = imageUses(
            [
                { id: 'g', type: 'gallery', visible: true, tone: 'auto', data: { items: [{ id: 'a', image: { id: 7, fit: 'auto' } }, { id: 'b', image: null }] } },
                { id: 'g2', type: 'gallery', visible: false, tone: 'auto', data: { items: [{ id: 'c', image: { id: 7, fit: 'cover' } }] } },
            ],
            { gallery },
        );
        expect(uses).toEqual({ 7: ['معرض صور'] });
    });

    it('explains the automatic layout by orientation and use', () => {
        expect(autoLayoutHint('landscape', 'background')).toContain('أفقية');
        expect(autoLayoutHint('portrait', 'side')).toContain('عمودية');
        expect(autoLayoutHint(null, 'gallery')).toContain('دون قص');
    });
});
