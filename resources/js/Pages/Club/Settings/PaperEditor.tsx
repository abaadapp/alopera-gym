import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Check, ChevronLeft, Lock, Monitor, RefreshCw, RotateCcw, Save, Smartphone, Undo2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PaperFrame from '@/Components/Papers/PaperFrame';
import { postJson } from '@/lib/http';
import { Button, Card, CardHeader, ConfirmAction, Field, Input, PageHeader, Select, Textarea, Toggle, BackLink } from '@/Components/ui';
import { cn } from '@/lib/cn';

type Values = Record<string, string | boolean>;
type Paper = {
    key: string; label: string; desc: string; section: string; sheet: boolean; strip: boolean;
    fields: { key: string; label: string; hint: string | null }[];
    required: string[];
    fonts: Record<string, string>; langs: Record<string, string>; papers: string[]; strips: string[];
    values: Values; defaults: Values;
};

/**
 * The editor of one paper: the settings beside a preview drawn on the server by the same builder and view that print
 * the real document. Every change redraws it (unsaved); «تراجع» returns to what is saved; saving applies it to every
 * paper printed from then on.
 */
export default function PaperEditor({ paper, others, brand }: { paper: Paper; others: { key: string; label: string }[]; brand: { logo: string | null; color: string; vat_number: string | null; cr_number: string | null } }) {
    const form = useForm<Values>({ ...paper.values });
    const [face, setFace] = useState<'sheet' | 'strip'>('sheet');
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const [html, setHtml] = useState('');
    const [size, setSize] = useState('A4');
    const [drawing, setDrawing] = useState(true);
    const [failed, setFailed] = useState(false);
    const ticket = useRef(0);
    const dirty = JSON.stringify(form.data) !== JSON.stringify(paper.values);
    const atDefaults = JSON.stringify(form.data) === JSON.stringify({ ...paper.values, ...paper.defaults });

    const draw = useCallback(async () => {
        const mine = ++ticket.current;
        setDrawing(true);
        try {
            const res = await postJson(route('club.settings.papers.preview', paper.key), { ...form.data, face });
            const body = await res.json();
            if (mine === ticket.current && res.ok) {
                setHtml(body.html ?? '');
                setSize(body.size ?? 'A4');
                setFailed(false);
            } else if (mine === ticket.current) {
                setFailed(true);
            }
        } catch {
            if (mine === ticket.current) setFailed(true);
        } finally {
            if (mine === ticket.current) setDrawing(false);
        }
    }, [form.data, face, paper.key]);

    useEffect(() => {
        const id = window.setTimeout(draw, 300);
        return () => window.clearTimeout(id);
    }, [draw]);

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.put(route('club.settings.papers.update', paper.key), { preserveScroll: true });
    };
    const set = (key: string, value: string | boolean) => form.setData(key, value);

    return (
        <ClubLayout title={`قالب ${paper.label}`}>
            <PageHeader
                back={<BackLink href={route('club.settings.papers')}>قوالب الأوراق</BackLink>}
                title={paper.label}
                subtitle={paper.desc}
                actions={<>
                    {dirty && <Button variant="ghost" icon={<Undo2 className="size-4" />} onClick={() => form.setData({ ...paper.values })}>تراجع عن التعديلات</Button>}
                    <Button icon={<Save className="size-4" />} onClick={() => submit()} loading={form.processing} disabled={!dirty}>حفظ القالب</Button>
                </>}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[7fr_3fr] lg:items-start">
                <div className="min-w-0 lg:sticky lg:top-6">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-[12px] text-[#9ca3af]">المعاينة بالقالب الذي يُطبع فعلًا{dirty && ' — تعديلات لم تُحفظ بعد'}</p>
                        <div className="flex flex-wrap items-center gap-2">
                            {paper.sheet && paper.strip && (
                                <Segmented value={face} onChange={(v) => setFace(v as 'sheet' | 'strip')} options={[{ value: 'sheet', label: `الورقة ${String(form.data.paper ?? 'A4')}` }, { value: 'strip', label: `الإيصال الحراري ${String(form.data.strip ?? '80mm')}` }]} />
                            )}
                            <Segmented value={device} onChange={(v) => setDevice(v as 'desktop' | 'mobile')} options={[{ value: 'desktop', label: <><Monitor className="size-3.5" /> كمبيوتر</> }, { value: 'mobile', label: <><Smartphone className="size-3.5" /> جوال</> }]} />
                            {drawing && <RefreshCw className="size-3.5 animate-spin text-[#d1d5db]" />}
                        </div>
                    </div>
                    {failed && <p className="mb-2 rounded-xl bg-red-50 px-3 py-2 text-[12px] text-red-700">تعذّر رسم المعاينة. تحقق من القيم ثم حاول مرة أخرى.</p>}
                    {device === 'desktop' ? (
                        <PaperFrame html={html} paper={size} height="min(80svh, 1050px)" />
                    ) : (
                        // A phone-width screen: how the same paper reads when opened from a link on a phone.
                        <div className="rounded-[16px] border border-line bg-[#f1f1f0] p-4">
                            <div className="mx-auto w-[390px] max-w-full overflow-hidden rounded-[28px] border-[6px] border-[#1f2937] bg-white">
                                <PaperFrame html={html} paper={size} height="min(70svh, 760px)" className="rounded-none border-0 p-2" />
                            </div>
                        </div>
                    )}
                </div>

                <form onSubmit={submit} className="min-w-0 space-y-4">
                    <Card>
                        <CardHeader title="هوية الأوراق" subtitle="تُطبَّق على كل أوراق النادي، وتُعدَّل من الإعدادات العامة." />
                        <div className="flex items-center gap-3">
                            <div className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-[12px] border border-dashed border-line bg-[#fafafa]">
                                {brand.logo ? <img src={brand.logo} alt="" className="size-full object-contain" /> : <span className="text-[11px] text-muted">بلا شعار</span>}
                            </div>
                            <dl className="min-w-0 space-y-1 text-[12px]">
                                <div className="flex items-center gap-2"><span className="size-3 rounded-full" style={{ background: brand.color }} /> <span className="text-muted">لون الخط العلوي والختم</span></div>
                                <div><span className="text-muted">الرقم الضريبي: </span><span className="num">{brand.vat_number || '—'}</span></div>
                                <div><span className="text-muted">السجل التجاري: </span><span className="num">{brand.cr_number || '—'}</span></div>
                            </dl>
                        </div>
                        <Link href={route('club.settings.edit')} className="mt-3 inline-flex items-center gap-1 text-[12px] font-medium text-ink underline-offset-2 hover:underline">تعديل الشعار والبيانات <ChevronLeft className="size-3.5" /></Link>
                    </Card>

                    <Card>
                        <CardHeader title="إعدادات الورقة" />
                        <div className="space-y-4">
                            <Field label="سطر تحت اسم النادي" hint="عبارة أو شعار نصي — يُترك فارغًا فلا يظهر" error={form.errors.header}>
                                <Input value={String(form.data.header ?? '')} maxLength={120} onChange={(e) => set('header', e.target.value)} />
                            </Field>
                            <Field label="نص التذييل" hint="أسفل الورقة — كل سطر كما كتبته" error={form.errors.footer}>
                                <Textarea rows={3} maxLength={500} value={String(form.data.footer ?? '')} onChange={(e) => set('footer', e.target.value)} />
                            </Field>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                <Field label="حجم الخط" error={form.errors.font}>
                                    <Select value={String(form.data.font)} onChange={(e) => set('font', e.target.value)}>
                                        {Object.entries(paper.fonts).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                                    </Select>
                                </Field>
                                <Field label="لغة النصوص الثابتة" hint="العناوين والتسميات؛ ما تكتبه يُطبع كما هو" error={form.errors.lang}>
                                    <Select value={String(form.data.lang)} onChange={(e) => set('lang', e.target.value)}>
                                        {Object.entries(paper.langs).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                                    </Select>
                                </Field>
                                {paper.sheet && (
                                    <Field label="مقاس الورق" error={form.errors.paper}>
                                        <Select value={String(form.data.paper)} onChange={(e) => { set('paper', e.target.value); setFace('sheet'); }}>
                                            {paper.papers.map((p) => <option key={p} value={p}>{p}</option>)}
                                        </Select>
                                    </Field>
                                )}
                                {paper.strip && (
                                    <Field label="عرض الشريط الحراري" hint="إن كانت لجهاز نقطة البيع طابعة مضبوطة يُطبع بعرضها" error={form.errors.strip}>
                                        <Select value={String(form.data.strip)} onChange={(e) => { set('strip', e.target.value); setFace('strip'); }}>
                                            {paper.strips.map((p) => <option key={p} value={p}>{p}</option>)}
                                        </Select>
                                    </Field>
                                )}
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="ما يظهر على الورقة" subtitle="كل مفتاح أثره في المعاينة فورًا." />
                        <div className="space-y-1">
                            {paper.fields.map((f) => (
                                <Toggle key={f.key} checked={Boolean(form.data[f.key])} onChange={(v) => set(f.key, v)} label={f.label} hint={f.hint ?? undefined} />
                            ))}
                        </div>
                        <div className="mt-4 border-t border-line pt-3">
                            <p className="mb-2 flex items-center gap-1.5 text-[12px] font-medium text-muted"><Lock className="size-3.5" /> يظهر دائمًا — لا يصح المستند بدونه</p>
                            <ul className="space-y-1">
                                {paper.required.map((r) => <li key={r} className="flex items-center gap-1.5 text-[12px] text-[#4b4b4b]"><Check className="size-3.5 text-emerald-600" /> {r}</li>)}
                            </ul>
                        </div>
                    </Card>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" icon={<Save className="size-4" />} loading={form.processing} disabled={!dirty}>حفظ القالب</Button>
                        {dirty && <Button type="button" variant="ghost" icon={<Undo2 className="size-4" />} onClick={() => form.setData({ ...paper.values })}>تراجع</Button>}
                        {!atDefaults && (
                            <ConfirmAction url={route('club.settings.papers.reset', paper.key)} method="delete" title="استعادة الإعدادات الافتراضية" message={`يعود قالب «${paper.label}» إلى إعداداته الأولى ويُحذف تخصيصه. لا تتغير المستندات نفسها.`} confirmLabel="استعادة" variant="ghost" icon={<RotateCcw className="size-3.5" />}>
                                الإعدادات الافتراضية
                            </ConfirmAction>
                        )}
                    </div>

                    <Card>
                        <CardHeader title="قوالب أخرى" />
                        <ul className="-mx-2 space-y-0.5">
                            {others.map((o) => (
                                <li key={o.key}>
                                    <Link href={route('club.settings.papers.edit', o.key)} className="flex items-center justify-between rounded-[10px] px-2 py-2 text-[13px] text-[#374151] hover:bg-[#fafafa]">
                                        {o.label}
                                        <ChevronLeft className="size-4 text-[#d1d5db]" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </form>
            </div>
        </ClubLayout>
    );
}

function Segmented({ value, onChange, options }: { value: string; onChange: (v: string) => void; options: { value: string; label: React.ReactNode }[] }) {
    return (
        <div className="flex rounded-[10px] border border-line bg-white p-0.5">
            {options.map((o) => (
                <button key={o.value} type="button" aria-pressed={value === o.value} onClick={() => onChange(o.value)}
                    className={cn('inline-flex items-center gap-1 rounded-[8px] px-2.5 py-1 text-[12px]', value === o.value ? 'bg-[#111] text-white' : 'text-[#6b7280] hover:bg-[#fafafa]')}>
                    {o.label}
                </button>
            ))}
        </div>
    );
}
