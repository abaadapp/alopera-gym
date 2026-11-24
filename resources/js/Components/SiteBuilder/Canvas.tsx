import { useCallback, useEffect, useRef, useState, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, Copy, Eye, EyeOff, Pencil, Plus, Trash2 } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { SiteSection } from '@/Components/Site/Sections';
import { cn } from '@/lib/cn';
import type { RenderedSection, SiteChrome, SitePage } from '@/types/site';

/** A section of the document as the editor knows it, rendered on the page or not. */
export interface CanvasOutlineItem {
    id: string;
    label: string;
    visible: boolean;
    /** Why it is not on the page (hidden, empty, waiting for the system's data). */
    reason?: string | null;
}

/** Editor → canvas. */
export type ToCanvas =
    | { type: 'render'; page: SitePage; chrome: SiteChrome; site: Partial<SiteInfo> }
    | { type: 'outline'; sections: CanvasOutlineItem[]; selected: string | null };

/** Canvas → editor. */
export type FromCanvas =
    | { type: 'ready' }
    | { type: 'select'; id: string; field: string | null }
    | { type: 'chrome'; part: 'header' | 'footer' | 'announcement' }
    | { type: 'op'; op: 'up' | 'down' | 'toggle' | 'duplicate' | 'delete'; id: string }
    | { type: 'add'; after: string | null };

function post(message: FromCanvas) {
    window.parent?.postMessage({ source: 'site-canvas', ...message }, window.location.origin);
}

const same = (a: string, b: string) => a.replace(/\s+/g, ' ').trim() === b.replace(/\s+/g, ' ').trim();

/**
 * Which field of the section a click landed on, read from what the page shows: the clicked text is one of the
 * section's texts (a heading, a paragraph, a button's label, an item of a list), or the clicked picture is one of its
 * images. Nothing is added to the site's own components: the page stays exactly what visitors see.
 */
function fieldAt(target: HTMLElement, wrapper: HTMLElement, data: Record<string, unknown>): string | null {
    const imgSrc = target instanceof HTMLImageElement ? target.currentSrc || target.src : null;
    const matches = (value: unknown, text: string): boolean => {
        if (typeof value === 'string') return value !== '' && same(value, text);
        if (value && typeof value === 'object') {
            const o = value as Record<string, unknown>;
            if (imgSrc && typeof o.url === 'string' && o.url && (imgSrc.includes(o.url) || o.url.includes(imgSrc))) return true;
            if (typeof o.label === 'string' && o.label && same(o.label, text)) return true;
        }
        return false;
    };
    for (let node: HTMLElement | null = target; node && node !== wrapper; node = node.parentElement) {
        const text = node.textContent ?? '';
        for (const [key, value] of Object.entries(data)) {
            if (Array.isArray(value)) {
                for (const item of value) {
                    if (item && typeof item === 'object' && Object.values(item as Record<string, unknown>).some((v) => matches(v, text))) return key;
                }
            } else if (matches(value, text)) {
                return key;
            }
        }
    }
    return null;
}

/**
 * The visual editor's frame (the preview page with ?canvas=1, inside «إدارة الموقع»): the site exactly as visitors see
 * it, drawn again from the editor's edits as they are made. Every part answers a click — a section opens its form
 * beside the page at the field clicked, the menu or the footer theirs — and nothing on it navigates or submits.
 */
export default function Canvas({ site: initialSite, page: initialPage, chrome: initialChrome }: { site: SiteInfo; page: SitePage; chrome: SiteChrome | null }) {
    const [site, setSite] = useState<SiteInfo>(initialSite);
    const [page, setPage] = useState<SitePage>(initialPage);
    const [chrome, setChrome] = useState<SiteChrome | null>(initialChrome);
    const [outline, setOutline] = useState<CanvasOutlineItem[] | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const receive = (e: MessageEvent) => {
            if (e.origin !== window.location.origin || e.source !== window.parent) return;
            const m = e.data as ToCanvas & { source?: string };
            if (m?.source !== 'site-editor') return;
            if (m.type === 'render') {
                setPage(m.page);
                setChrome(m.chrome);
                setSite((s) => ({ ...s, ...m.site }));
            } else if (m.type === 'outline') {
                setOutline(m.sections);
                setSelected(m.selected);
            }
        };
        window.addEventListener('message', receive);
        post({ type: 'ready' });
        return () => window.removeEventListener('message', receive);
    }, []);

    // The selected section in view when it is chosen from the editor's side.
    useEffect(() => {
        if (!selected) return;
        root.current?.querySelector(`[data-sid="${CSS.escape(selected)}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [selected]);

    const rendered = new Map(page.sections.map((s) => [s.id, s]));
    const order: (CanvasOutlineItem & { section?: RenderedSection })[] = outline
        ? outline.map((o) => ({ ...o, section: rendered.get(o.id) }))
        : page.sections.map((s) => ({ id: s.id, label: s.type, visible: true, section: s }));

    // Nothing on the page goes anywhere: a click chooses what to edit.
    const click = useCallback(
        (e: ReactMouseEvent<HTMLDivElement>) => {
            const target = e.target as HTMLElement;
            if (target.closest('[data-canvas-ui]')) return;
            e.preventDefault();
            e.stopPropagation();
            const wrapper = target.closest<HTMLElement>('[data-sid]');
            if (wrapper) {
                const id = wrapper.dataset.sid!;
                const data = rendered.get(id)?.data ?? {};
                setSelected(id);
                post({ type: 'select', id, field: fieldAt(target, wrapper, data as Record<string, unknown>) });
                return;
            }
            if (target.closest('header')) post({ type: 'chrome', part: 'header' });
            else if (target.closest('footer')) post({ type: 'chrome', part: 'footer' });
            else if (target.closest('[data-announcement]') || target.closest('.site-theme > div:first-child')) post({ type: 'chrome', part: 'announcement' });
        },
        [page],
    );

    return (
        <div ref={root} onClickCapture={click} onSubmitCapture={(e) => e.preventDefault()} className="site-canvas">
            <style>{`
                .site-canvas header, .site-canvas footer { cursor: pointer; transition: box-shadow .15s; }
                .site-canvas header:hover, .site-canvas footer:hover { box-shadow: inset 0 0 0 3px #2563eb; }
                .site-canvas [data-sid] { position: relative; cursor: pointer; }
                .site-canvas [data-sid]:hover > [data-frame] { opacity: 1; }
                .site-canvas [data-sid]:hover > [data-frame] [data-canvas-ui], .site-canvas [data-frame].is-selected [data-canvas-ui] { pointer-events: auto; }
                .site-canvas [data-add]:hover > button, .site-canvas [data-add] > button:focus-visible { opacity: 1; }
                .site-canvas a, .site-canvas button:not([data-canvas-ui] *) { cursor: pointer; }
            `}</style>
            <SiteLayout site={site} chrome={chrome}>
                <AddHere after={null} first />
                {order.map((item, i) => (
                    <div key={item.id}>
                        <div data-sid={item.id}>
                            {item.section ? (
                                <SiteSection
                                    section={item.section}
                                    site={site}
                                    chrome={chrome}
                                    joined={i > 0 && order[i - 1].section?.tone === item.section.tone && item.section.type !== 'hero'}
                                />
                            ) : (
                                <Placeholder item={item} />
                            )}
                            <Frame item={item} selected={selected === item.id} first={i === 0} last={i === order.length - 1} />
                        </div>
                        <AddHere after={item.id} />
                    </div>
                ))}
            </SiteLayout>
        </div>
    );
}

/** The outline and the small bar of a section: its name, and move, hide, copy, delete. */
function Frame({ item, selected, first, last }: { item: CanvasOutlineItem; selected: boolean; first: boolean; last: boolean }) {
    const button = (label: string, icon: ReactNode, op: Extract<FromCanvas, { type: 'op' }>['op'], disabled = false, danger = false) => (
        <button
            type="button"
            title={label}
            aria-label={label}
            disabled={disabled}
            onClick={(e) => {
                e.stopPropagation();
                post({ type: 'op', op, id: item.id });
            }}
            className={cn('grid size-8 place-items-center rounded-full text-white transition hover:bg-white/20 disabled:opacity-30', danger && 'hover:bg-red-500')}
        >
            {icon}
        </button>
    );
    return (
        <div data-frame className={cn('pointer-events-none absolute inset-0 z-30 transition-opacity', selected ? 'is-selected opacity-100' : 'opacity-0')} style={{ boxShadow: `inset 0 0 0 ${selected ? 3 : 2}px #2563eb` }}>
            <div data-canvas-ui dir="rtl" className="pointer-events-none absolute top-2 start-2 flex items-center gap-0.5 rounded-full bg-[#2563eb] py-1 ps-3 pe-1 text-[13px] font-medium text-white shadow-lg" style={{ fontFamily: 'IBM Plex Sans Arabic, system-ui, sans-serif' }}>
                <Pencil className="me-1.5 size-3.5" />
                <span className="me-2 max-w-[180px] truncate">{item.label}</span>
                {button('تحريك للأعلى', <ArrowUp className="size-4" />, 'up', first)}
                {button('تحريك للأسفل', <ArrowDown className="size-4" />, 'down', last)}
                {button(item.visible ? 'إخفاء عن الزوار' : 'إظهار للزوار', item.visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />, 'toggle')}
                {button('نسخة من القسم', <Copy className="size-4" />, 'duplicate')}
                {button('حذف القسم', <Trash2 className="size-4" />, 'delete', false, true)}
            </div>
        </div>
    );
}

/** A section the visitors do not see now (hidden, empty, waiting for data): a quiet band, so it can still be found. */
function Placeholder({ item }: { item: CanvasOutlineItem }) {
    return (
        <div dir="rtl" className="mx-auto my-3 flex max-w-[1360px] items-center gap-3 rounded-2xl border-2 border-dashed border-[#cbd5e1] bg-[#f8fafc] px-5 pt-14 pb-6 text-[#475569]" style={{ fontFamily: 'IBM Plex Sans Arabic, system-ui, sans-serif' }}>
            {item.visible ? <Pencil className="size-5 shrink-0" /> : <EyeOff className="size-5 shrink-0" />}
            <div className="min-w-0">
                <p className="m-0 text-[15px] font-semibold">{item.label}</p>
                <p className="m-0 text-[13px]">{item.visible ? item.reason || 'لا يظهر للزوار لأنه فارغ. اضغطي هنا لإضافة محتواه.' : 'مخفي عن الزوار. اضغطي هنا ثم «إظهار» ليظهر.'}</p>
            </div>
        </div>
    );
}

/** «أضف قسمًا هنا» between two sections: shows on hover, opens the sections to choose from. */
function AddHere({ after, first = false }: { after: string | null; first?: boolean }) {
    return (
        // A band 28px high across the seam of two sections: hovering it shows the button.
        <div data-canvas-ui data-add dir="rtl" className={cn('relative z-40 -my-3.5 flex h-7 items-center justify-center', first && 'mt-0')}>
            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    post({ type: 'add', after });
                }}
                className="flex items-center gap-1.5 rounded-full bg-[#2563eb] px-4 py-1.5 text-[13px] font-medium text-white opacity-0 shadow-lg transition"
                style={{ fontFamily: 'IBM Plex Sans Arabic, system-ui, sans-serif' }}
            >
                <Plus className="size-4" /> أضف قسمًا هنا
            </button>
        </div>
    );
}
