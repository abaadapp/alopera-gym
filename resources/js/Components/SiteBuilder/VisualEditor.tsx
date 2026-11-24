import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Copy, Eye, EyeOff, Image as ImageIcon, Laptop, ListTree, MousePointerClick, Palette, Phone, Plus, Smartphone, Trash2, X } from 'lucide-react';
import { Button, Card, Modal } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { postJson } from '@/lib/http';
import { missingContent } from '@/lib/site-builder';
import type { SectionDoc, SiteDoc } from '@/types/site';
import type { CanvasOutlineItem, FromCanvas, ToCanvas } from './Canvas';
import { sectionLabel, useBuilder } from './context';
import { ContactPanel, MenuPanel, SeoPanel, ThemePanel } from './DocPanels';
import MediaLibrary from './MediaLibrary';
import SectionEditor from './SectionEditor';

type Patch = (fn: (d: SiteDoc) => SiteDoc) => void;
type Sheet = 'menu' | 'contact' | 'colors' | 'announcement' | 'media' | null;

const DEVICES = [
    { key: 'desktop', label: 'كمبيوتر', width: 1280, icon: Laptop },
    { key: 'phone', label: 'هاتف', width: 390, icon: Smartphone },
] as const;

/**
 * «التعديل المرئي»: the site itself, drawn from the edits as they are made (a frame of the preview page, Canvas), and
 * beside it the form of whatever was clicked on it. The owner never needs to know where a text lives: she clicks it.
 */
export default function VisualEditor({
    doc,
    patch,
    selected,
    onSelect,
    onSection,
    onOp,
    onAdd,
    canvasUrl,
}: {
    doc: SiteDoc;
    patch: Patch;
    selected: string | null;
    onSelect: (id: string | null) => void;
    onSection: (next: SectionDoc) => void;
    onOp: (op: Extract<FromCanvas, { type: 'op' }>['op'], id: string) => void;
    onAdd: (after: string | null) => void;
    canvasUrl: string;
}) {
    const { schemas, waiting } = useBuilder();
    const frame = useRef<HTMLIFrameElement>(null);
    const [ready, setReady] = useState(false);
    const [device, setDevice] = useState<(typeof DEVICES)[number]['key']>('desktop');
    const [sheet, setSheet] = useState<Sheet>(null);
    const [focusField, setFocusField] = useState<{ key: string; at: number } | null>(null);
    const [liveWaiting, setLiveWaiting] = useState<Record<string, string>>(waiting);
    const [confirmDelete, setConfirmDelete] = useState<string | null>(null);
    const panel = useRef<HTMLDivElement>(null);

    const send = useCallback((message: ToCanvas) => {
        frame.current?.contentWindow?.postMessage({ source: 'site-editor', ...message }, window.location.origin);
    }, []);

    // What the canvas says: a click on a section, the menu, the footer; a section's bar; «أضف قسمًا هنا».
    useEffect(() => {
        const receive = (e: MessageEvent) => {
            if (e.origin !== window.location.origin || e.source !== frame.current?.contentWindow) return;
            const m = e.data as FromCanvas & { source?: string };
            if (m?.source !== 'site-canvas') return;
            if (m.type === 'ready') setReady(true);
            else if (m.type === 'select') {
                onSelect(m.id);
                setFocusField(m.field ? { key: m.field, at: Date.now() } : null);
                panel.current?.scrollTo({ top: 0 });
            } else if (m.type === 'chrome') setSheet(m.part === 'header' ? 'menu' : m.part === 'footer' ? 'contact' : 'announcement');
            else if (m.type === 'op') {
                if (m.op === 'delete') setConfirmDelete(m.id);
                else onOp(m.op, m.id);
            } else if (m.type === 'add') onAdd(m.after);
        };
        window.addEventListener('message', receive);
        return () => window.removeEventListener('message', receive);
    }, [onSelect, onOp, onAdd]);

    // The page drawn again from the edits, a moment after the last keystroke. A document that would not be saved
    // (a wrong link, say) keeps the last page drawn: the form beside it says what to fix.
    const seq = useRef(0);
    useEffect(() => {
        if (!ready) return;
        const n = ++seq.current;
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const response = await postJson(route('club.website.render'), { doc }, controller.signal);
                if (!response.ok || n !== seq.current) return;
                const data = await response.json();
                send({ type: 'render', page: data.page, chrome: data.chrome, site: data.site });
                setLiveWaiting(data.waiting ?? {});
            } catch {
                // Offline or cancelled: the page drawn stays.
            }
        }, 350);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [doc, ready, send]);

    // Every section of the document, shown or not, so a hidden or empty one can still be found on the page.
    useEffect(() => {
        if (!ready) return;
        const sections: CanvasOutlineItem[] = doc.sections.map((s) => ({
            id: s.id,
            label: sectionLabel(s, schemas),
            visible: s.visible,
            reason: s.visible ? (liveWaiting[s.id] ? `لا يظهر للزوار الآن: ${liveWaiting[s.id]}` : missingContent(s) ? `لا يظهر للزوار وهو فارغ: ${missingContent(s)}` : null) : null,
        }));
        send({ type: 'outline', sections, selected });
    }, [doc.sections, selected, ready, liveWaiting, schemas, send]);

    // The field clicked on the page, ready to type in.
    useEffect(() => {
        if (!focusField) return;
        const id = window.setTimeout(() => {
            const box = panel.current?.querySelector<HTMLElement>(`[data-field="${CSS.escape(focusField.key)}"]`);
            if (!box) return;
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            box.classList.add('ring-2', 'ring-[#2563eb]', 'ring-offset-4', 'rounded-lg');
            window.setTimeout(() => box.classList.remove('ring-2', 'ring-[#2563eb]', 'ring-offset-4', 'rounded-lg'), 1600);
            box.querySelector<HTMLInputElement | HTMLTextAreaElement>('input:not([type=checkbox]):not([type=file]), textarea')?.focus({ preventScroll: true });
        }, 60);
        return () => window.clearTimeout(id);
    }, [focusField, selected]);

    const index = doc.sections.findIndex((s) => s.id === selected);
    const current = index >= 0 ? doc.sections[index] : null;

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3 lg:flex-row">
            <div className="flex min-h-[70vh] min-w-0 flex-1 flex-col gap-2 lg:min-h-0">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex rounded-[10px] border border-line bg-white p-0.5" role="group" aria-label="حجم الشاشة">
                        {DEVICES.map((d) => (
                            <button
                                key={d.key}
                                type="button"
                                aria-pressed={device === d.key}
                                onClick={() => setDevice(d.key)}
                                className={cn('flex items-center gap-1.5 rounded-[8px] px-3 py-1.5 text-[13px] font-medium transition', device === d.key ? 'bg-ink text-white' : 'text-[#4b4b4b] hover:bg-[#f2f2f0]')}
                            >
                                <d.icon className="size-4" />
                                {d.label}
                            </button>
                        ))}
                    </div>
                    <span className="flex items-center gap-1.5 text-[13px] text-muted"><MousePointerClick className="size-4" />اضغطي على أي جزء في الموقع لتعديله</span>
                </div>
                <Stage width={DEVICES.find((d) => d.key === device)!.width}>
                    <iframe ref={frame} src={canvasUrl} title="الموقع للتعديل" className="block border-0 bg-white" style={{ width: '100%', height: '100%' }} />
                </Stage>
            </div>

            <aside ref={panel} className="min-h-0 w-full shrink-0 overflow-y-auto lg:w-[420px]">
                {current ? (
                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-2 rounded-[12px] border border-line bg-white px-3 py-2">
                            <div className="flex items-center gap-1">
                                <Tool label="تحريك للأعلى" disabled={index === 0} onClick={() => onOp('up', current.id)}><ArrowUp /></Tool>
                                <Tool label="تحريك للأسفل" disabled={index === doc.sections.length - 1} onClick={() => onOp('down', current.id)}><ArrowDown /></Tool>
                                <Tool label={current.visible ? 'إخفاء عن الزوار' : 'إظهار للزوار'} onClick={() => onOp('toggle', current.id)}>{current.visible ? <EyeOff /> : <Eye />}</Tool>
                                <Tool label="نسخة من القسم" onClick={() => onOp('duplicate', current.id)}><Copy /></Tool>
                                <Tool label="حذف القسم" danger onClick={() => setConfirmDelete(current.id)}><Trash2 /></Tool>
                            </div>
                            <button type="button" onClick={() => onSelect(null)} className="flex items-center gap-1 rounded-lg px-2 py-1 text-[13px] text-muted hover:bg-[#f2f2f0] hover:text-ink">
                                <X className="size-4" /> إغلاق
                            </button>
                        </div>
                        <SectionEditor section={current} onChange={onSection} />
                        {current.type === 'contact' && (
                            <Button variant="outline" className="w-full" icon={<Phone />} onClick={() => setSheet('contact')}>الهاتف والعنوان وأوقات العمل وحسابات التواصل</Button>
                        )}
                        {current.type === 'posts' && (
                            <p className="text-[13px] text-muted">العروض والأخبار نفسها تُكتب من <Link href={route('club.website.posts.index')} className="font-medium text-ink underline">الأخبار والعروض</Link>.</p>
                        )}
                    </div>
                ) : (
                    <Card className="space-y-4">
                        <div>
                            <h2 className="text-[17px] font-bold text-ink">كيف تعدّلين موقعك؟</h2>
                            <ol className="mt-3 space-y-2.5 text-[14px] leading-6 text-[#374151]">
                                <li className="flex gap-2"><Step n={1} />اضغطي على أي نص أو صورة أو زر في الموقع على اليسار.</li>
                                <li className="flex gap-2"><Step n={2} />عدّليه هنا، وسترين التغيير في الموقع فورًا.</li>
                                <li className="flex gap-2"><Step n={3} />اضغطي «نشر على الموقع» ليراه الزوار. تُحفظ تعديلاتك تلقائيًا.</li>
                            </ol>
                        </div>
                        <div className="grid grid-cols-2 gap-2 border-t border-line pt-4">
                            <Quick icon={<Plus />} label="إضافة قسم" onClick={() => onAdd(doc.sections[doc.sections.length - 1]?.id ?? null)} />
                            <Quick icon={<ListTree />} label="القائمة العلوية والتذييل" onClick={() => setSheet('menu')} />
                            <Quick icon={<Phone />} label="التواصل وأوقات العمل" onClick={() => setSheet('contact')} />
                            <Quick icon={<Palette />} label="ألوان الموقع" onClick={() => setSheet('colors')} />
                            <Quick icon={<ImageIcon />} label="مكتبة الصور" onClick={() => setSheet('media')} />
                            <Quick icon={<MousePointerClick />} label="شريط الإعلان أعلى الموقع" onClick={() => setSheet('announcement')} />
                        </div>
                    </Card>
                )}
            </aside>

            <Modal open={sheet === 'menu'} onClose={() => setSheet(null)} title="القائمة العلوية والتذييل" description="الروابط في أعلى الموقع وأسفله. يتغير الموقع أمامك فورًا." size="wide">
                <div className="pb-2"><MenuPanel doc={doc} patch={patch} /></div>
            </Modal>
            <Modal open={sheet === 'contact'} onClose={() => setSheet(null)} title="التواصل وأوقات العمل" description="الهاتف والعنوان وأوقات العمل وحسابات التواصل الاجتماعي." size="wide">
                <div className="pb-2"><ContactPanel doc={doc} patch={patch} /></div>
            </Modal>
            <Modal open={sheet === 'colors'} onClose={() => setSheet(null)} title="ألوان الموقع" size="wide">
                <div className="pb-2"><ThemePanel doc={doc} patch={patch} /></div>
            </Modal>
            <Modal open={sheet === 'announcement'} onClose={() => setSheet(null)} title="شريط الإعلان ومحركات البحث" description="سطر قصير يظهر أعلى كل صفحات الموقع، واسم الموقع في نتائج البحث." size="wide">
                <div className="pb-2"><SeoPanel doc={doc} patch={patch} /></div>
            </Modal>
            <Modal open={sheet === 'media'} onClose={() => setSheet(null)} title="مكتبة الصور" size="full">
                <MediaLibrary />
            </Modal>
            <Modal
                open={confirmDelete !== null}
                onClose={() => setConfirmDelete(null)}
                title="حذف هذا القسم؟"
                size="confirm"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setConfirmDelete(null)}>إلغاء</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                if (confirmDelete) onOp('delete', confirmDelete);
                                setConfirmDelete(null);
                            }}
                        >
                            حذف
                        </Button>
                    </>
                }
            >
                <p className="text-sm leading-7 text-[#4b4b4b]">يُحذف القسم من الموقع بعد النشر. إن أردت إخفاءه مؤقتًا فقط فاختاري «إخفاء» بدلًا من الحذف.</p>
            </Modal>
        </div>
    );
}

/** The page at the device's real width, scaled down to the space there is. */
function Stage({ width, children }: { width: number; children: ReactNode }) {
    const box = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });
    useLayoutEffect(() => {
        const measure = () => box.current && setSize({ width: box.current.clientWidth, height: box.current.clientHeight });
        measure();
        const observer = new ResizeObserver(measure);
        if (box.current) observer.observe(box.current);
        return () => observer.disconnect();
    }, []);
    const scale = size.width > 0 ? Math.min(1, (size.width - 16) / width) : 1;
    const shown = Math.max(0, (size.height || 900) - 16);

    return (
        <div ref={box} dir="ltr" className="relative min-h-[60vh] flex-1 overflow-hidden rounded-xl border border-line bg-[#eef0f3]">
            <div className="absolute top-2 left-1/2 -translate-x-1/2 overflow-hidden rounded-lg shadow-[0_4px_24px_rgba(0,0,0,0.12)]" style={{ width: width * scale, height: shown }}>
                <div style={{ width, height: shown / scale, transform: `scale(${scale})`, transformOrigin: '0 0' }}>{children}</div>
            </div>
        </div>
    );
}

function Tool({ label, onClick, disabled, danger, children }: { label: string; onClick: () => void; disabled?: boolean; danger?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className={cn('grid size-9 place-items-center rounded-lg text-[#374151] transition hover:bg-[#f2f2f0] disabled:opacity-30 [&_svg]:size-[18px]', danger && 'hover:bg-red-50 hover:text-red-600')}
        >
            {children}
        </button>
    );
}

function Quick({ icon, label, onClick }: { icon: ReactNode; label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className="flex items-center gap-2 rounded-xl border border-line bg-white p-3 text-start text-[13px] font-medium text-ink transition hover:border-ink [&_svg]:size-[18px] [&_svg]:shrink-0">
            {icon}
            {label}
        </button>
    );
}

function Step({ n }: { n: number }) {
    return <span className="grid size-6 shrink-0 place-items-center rounded-full bg-[#2563eb] text-[12px] font-bold text-white">{n}</span>;
}
