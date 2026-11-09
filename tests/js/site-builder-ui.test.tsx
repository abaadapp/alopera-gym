import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';

vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {}, post: vi.fn(), put: vi.fn() },
    Link: ({ children, href, ...rest }: { children: ReactNode; href: string }) => <a href={href} {...rest}>{children}</a>,
}));

import { BuilderContext, type BuilderContextValue } from '@/Components/SiteBuilder/context';
import LinkPicker from '@/Components/SiteBuilder/LinkPicker';
import SectionEditor from '@/Components/SiteBuilder/SectionEditor';
import SectionList from '@/Components/SiteBuilder/SectionList';
import type { SectionDoc, SectionTypeSchema } from '@/types/site';

const gallery: SectionTypeSchema = {
    type: 'gallery',
    label: 'معرض صور',
    description: 'صور النادي',
    group: 'content',
    tone: 'white',
    fields: [
        { key: 'title', type: 'text', label: 'العنوان', max: 120 },
        { key: 'items', type: 'list', label: 'الصور', item: 'صورة', max: 30, fields: [{ key: 'image', type: 'image', label: 'الصورة', usage: 'gallery' }, { key: 'caption', type: 'text', label: 'تعليق', max: 120 }] },
    ],
    defaults: { title: '', items: [] },
};
const plans: SectionTypeSchema = { type: 'plans', label: 'الباقات', description: '', group: 'system', source: 'من «باقات العضوية» في النظام.', tone: 'white', fields: [{ key: 'title', type: 'text', label: 'العنوان', max: 120 }], defaults: { title: '' } };

function context(over: Partial<BuilderContextValue> = {}): BuilderContextValue {
    return {
        media: [],
        setMedia: () => {},
        targets: [{ value: '@plans', label: 'صفحة الباقات' }],
        sections: [],
        schemas: { gallery, plans },
        limits: { max_image_kb: 4096, types: ['jpg', 'png', 'webp'] },
        errors: {},
        localUses: {},
        ...over,
    };
}

const sections: SectionDoc[] = [
    { id: 'gallery-a', type: 'gallery', visible: true, tone: 'auto', data: { title: 'صور النادي', items: [] } },
    { id: 'plans-b', type: 'plans', visible: false, tone: 'auto', data: { title: 'الباقات' } },
];

afterEach(cleanup);

describe('the sections list', () => {
    it('moves, hides and marks system sections', () => {
        const onMove = vi.fn();
        const onToggle = vi.fn();
        render(
            <BuilderContext.Provider value={context({ sections })}>
                <SectionList sections={sections} selected="gallery-a" onSelect={() => {}} onMove={onMove} onToggle={onToggle} onDuplicate={() => {}} onDelete={() => {}} onAdd={() => {}} />
            </BuilderContext.Provider>,
        );
        expect(screen.getByText('مخفي')).toBeTruthy();
        expect(screen.getByText('من النظام')).toBeTruthy();
        fireEvent.click(screen.getByLabelText('نقل «معرض صور» للأسفل'));
        expect(onMove).toHaveBeenCalledWith(0, 1);
        expect((screen.getByLabelText('نقل «معرض صور» للأعلى') as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByLabelText('إظهار القسم للزوار'));
        expect(onToggle).toHaveBeenCalledWith('plans-b');
    });
});

function Editor({ start, errors = {} }: { start: SectionDoc; errors?: Record<string, string> }) {
    const [section, setSection] = useState(start);
    return (
        <BuilderContext.Provider value={context({ sections: [section], errors })}>
            <SectionEditor section={section} onChange={setSection} />
            <output data-testid="data">{JSON.stringify(section.data)}</output>
        </BuilderContext.Provider>
    );
}

describe('a section form', () => {
    it('adds a list item with an empty «أضف صورة» box and an id', () => {
        render(<Editor start={sections[0]} />);
        fireEvent.click(screen.getByText('إضافة صورة'));
        expect(screen.getByText('أضف صورة')).toBeTruthy();
        const data = JSON.parse(screen.getByTestId('data').textContent ?? '{}');
        expect(data.items).toHaveLength(1);
        expect(data.items[0]).toMatchObject({ image: null, caption: '' });
        expect(data.items[0].id).toMatch(/^i-/);
    });

    it('shows the server error under its field', () => {
        render(<Editor start={sections[0]} errors={{ 'sections.gallery-a.data.title': 'العنوان أطول من المسموح.' }} />);
        expect(screen.getByText('العنوان أطول من المسموح.')).toBeTruthy();
    });

    it('says where a system section takes its data from', () => {
        render(<Editor start={sections[1]} />);
        expect(screen.getByText(/من «باقات العضوية» في النظام/)).toBeTruthy();
    });
});

describe('a link', () => {
    it('shows an outside address in its field and refuses javascript:', () => {
        render(
            <BuilderContext.Provider value={context()}>
                <LinkPicker value="javascript:alert(1)" onChange={() => {}} />
            </BuilderContext.Provider>,
        );
        expect((screen.getByLabelText('الرابط الخارجي') as HTMLInputElement).value).toBe('javascript:alert(1)');
        expect(screen.getByText(/رابط غير مقبول/)).toBeTruthy();
    });
});
