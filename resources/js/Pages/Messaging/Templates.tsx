import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Bell, Pencil, Plus, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, EmptyState, Field, Input, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';

interface Template {
    id: number;
    key: string;
    name: string;
    language: string;
    category: string;
    body: string;
    variables: string[] | null;
    status: string;
    is_active: boolean;
    label: string;
    is_system: boolean;
    allowed_variables: string[];
}

interface Props {
    templates: Template[];
}

const VARIABLE_LABELS: Record<string, string> = {
    member_name: 'اسم العضو',
    club_name: 'اسم النادي',
    member_no: 'رقم العضوية',
    invoice_number: 'رقم الفاتورة',
    amount: 'المبلغ',
    payment_link: 'رابط الدفع',
    receipt_number: 'رقم الإيصال',
    invoice_link: 'رابط الفاتورة',
    plan_name: 'الباقة',
    starts_on: 'تاريخ البداية',
    ends_on: 'تاريخ الانتهاء',
    days_left: 'الأيام المتبقية',
    renew_link: 'رابط التجديد',
    class_name: 'اسم الحصة',
    date: 'التاريخ',
    time: 'الوقت',
    reason: 'السبب',
    change: 'التعديل',
};

const CAMPAIGN_VARIABLES = ['member_name', 'club_name', 'plan_name', 'ends_on'];

const STATUS: Record<string, [string, 'gray' | 'amber' | 'green' | 'red']> = {
    draft: ['مسودة', 'gray'],
    pending: ['قيد المراجعة لدى Meta', 'amber'],
    approved: ['معتمد', 'green'],
    rejected: ['مرفوض', 'red'],
};

type TemplateForm = {
    name: string;
    language: string;
    category: string;
    body: string;
    variables: string[];
    status: string;
    is_active: boolean;
};

function preview(body: string, variables: string[]) {
    return variables.reduce((text, v, i) => text.split(`{{${i + 1}}}`).join(`[${VARIABLE_LABELS[v] ?? v}]`), body);
}

export default function Templates({ templates }: Props) {
    const [editing, setEditing] = useState<Template | null>(null);
    const [creating, setCreating] = useState(false);

    const system = templates.filter((t) => t.is_system);
    const custom = templates.filter((t) => !t.is_system);

    const toggleActive = (t: Template) =>
        router.put(
            route('club.templates.update', t.id),
            { name: t.name, language: t.language, category: t.category, body: t.body, variables: t.variables ?? [], status: t.status, is_active: !t.is_active },
            { preserveScroll: true },
        );

    return (
        <ClubLayout title="قوالب واتساب">
            <PageHeader
                title="قوالب واتساب"
                subtitle="اربط كل حدث بالقالب المعتمد في WhatsApp Manager. الردود الآلية وقواعد الإرسال في «الأتمتة»."
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>قالب تسويقي جديد</Button>}
            />

            <div className="mb-6">
                <Card>
                    <div className="flex items-start gap-3">
                        <div className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[#f2f2f0] text-ink"><Bell className="size-5" /></div>
                        <div className="text-sm leading-7 text-ink/80">
                            <p className="font-semibold text-ink">كيف تعمل القوالب؟</p>
                            يرسل النظام رسائل الخدمة تلقائيًا (ترحيب، فواتير، تأكيد، تذكير، حجوزات) فقط عندما يكون القالب <b>معتمدًا</b> و<b>مفعّلًا</b> هنا. أنشئ القالب في WhatsApp Manager بنفس الاسم واللغة،
                            وانسخ النص بالمتغيرات <span className="num">{'{{1}}'}</span>، <span className="num">{'{{2}}'}</span>… ثم غيّر حالته إلى «معتمد» بعد موافقة Meta.
                        </div>
                    </div>
                </Card>
            </div>

            <Card padded={false} className="mb-6">
                <div className="p-5 pb-0">
                    <CardHeader title="رسائل الخدمة التلقائية" subtitle={`${system.filter((t) => t.status === 'approved' && t.is_active).length} من ${system.length} قالبًا جاهزًا للإرسال`} />
                </div>
                <TemplateTable templates={system} onEdit={setEditing} onToggle={toggleActive} />
            </Card>

            <Card padded={false} className="mb-6">
                <div className="p-5 pb-0">
                    <CardHeader title="قوالب الحملات والعروض" subtitle="تُستخدم في الحملات التسويقية وتُرسل فقط لمن وافق على الرسائل التسويقية" />
                </div>
                {custom.length === 0 ? (
                    <EmptyState title="لا توجد قوالب تسويقية بعد" action={<Button size="sm" onClick={() => setCreating(true)}>إضافة قالب</Button>} />
                ) : (
                    <TemplateTable templates={custom} onEdit={setEditing} onToggle={toggleActive} />
                )}
            </Card>


            {(editing || creating) && (
                <TemplateModal
                    template={editing}
                    onClose={() => {
                        setEditing(null);
                        setCreating(false);
                    }}
                />
            )}
        </ClubLayout>
    );
}

function TemplateTable({ templates, onEdit, onToggle }: { templates: Template[]; onEdit: (t: Template) => void; onToggle: (t: Template) => void }) {
    return (
        <div className="overflow-x-auto">
            <table className="table-base">
                <thead>
                    <tr><th>الحدث</th><th>اسم القالب في Meta</th><th>النص</th><th>الحالة</th><th>مفعّل</th><th /></tr>
                </thead>
                <tbody>
                    {templates.map((t) => {
                        const [st, tone] = STATUS[t.status] ?? [t.status, 'gray'];
                        return (
                            <tr key={t.id}>
                                <td>
                                    <p className="font-medium">{t.label}</p>
                                    <p className="text-[11px] text-muted">{t.category === 'marketing' ? 'تسويقي' : 'خدمة'} · {t.language}</p>
                                </td>
                                <td className="text-xs" dir="ltr">{t.name}</td>
                                <td className="max-w-sm"><p className="line-clamp-2 text-xs text-ink/80">{preview(t.body, t.variables ?? [])}</p></td>
                                <td><Badge tone={tone}>{st}</Badge></td>
                                <td>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={t.is_active}
                                        onClick={() => onToggle(t)}
                                        className={`relative inline-flex h-5 w-9 rounded-full transition ${t.is_active ? 'bg-brand' : 'bg-line'}`}
                                    >
                                        <span className={`absolute top-0.5 size-4 rounded-full bg-white shadow transition-all ${t.is_active ? 'start-[18px]' : 'start-0.5'}`} />
                                    </button>
                                </td>
                                <td className="text-end">
                                    <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => onEdit(t)}>تعديل</Button>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function TemplateModal({ template, onClose }: { template: Template | null; onClose: () => void }) {
    const allowed = template?.allowed_variables ?? CAMPAIGN_VARIABLES;
    const form = useForm<TemplateForm>({
        name: template?.name ?? '',
        language: template?.language ?? 'ar',
        category: template?.category ?? 'marketing',
        body: template?.body ?? '',
        variables: template?.variables ?? ['member_name'],
        status: template?.status ?? 'draft',
        is_active: template?.is_active ?? true,
    });

    const vars = form.data.variables;
    const setVars = (next: string[]) => form.setData('variables', next);
    const move = (i: number, dir: -1 | 1) => {
        const next = [...vars];
        [next[i], next[i + dir]] = [next[i + dir], next[i]];
        setVars(next);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (template) form.put(route('club.templates.update', template.id), options);
        else form.post(route('club.templates.store'), options);
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={template ? `قالب: ${template.label}` : 'قالب تسويقي جديد'}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>إلغاء</Button>
                    <Button onClick={submit} loading={form.processing}>حفظ</Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="اسم القالب في WhatsApp Manager" error={form.errors.name} className="sm:col-span-2" hint="أحرف إنجليزية صغيرة وأرقام و _ فقط" required>
                        <Input dir="ltr" value={form.data.name} onChange={(e) => form.setData('name', e.target.value.toLowerCase())} />
                    </Field>
                    <Field label="اللغة" error={form.errors.language}>
                        <Select value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                            <option value="ar">العربية (ar)</option>
                            <option value="en">English (en)</option>
                            <option value="en_US">English US (en_US)</option>
                        </Select>
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="التصنيف" error={form.errors.category}>
                        <Select value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} disabled={template?.is_system}>
                            <option value="utility">خدمة (Utility)</option>
                            <option value="marketing">تسويقي (Marketing)</option>
                        </Select>
                    </Field>
                    <Field label="حالة الاعتماد" error={form.errors.status}>
                        <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {Object.entries(STATUS).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}
                        </Select>
                    </Field>
                </div>
                <Field label="نص القالب (كما هو معتمد في Meta)" error={form.errors.body} hint="استخدم {{1}} و{{2}}… بنفس ترتيب المتغيرات أدناه" required>
                    <Textarea rows={4} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                </Field>

                <div>
                    <p className="label">ترتيب المتغيرات</p>
                    <div className="space-y-1.5">
                        {vars.map((v, i) => (
                            <div key={`${v}-${i}`} className="flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm">
                                <span className="rounded-md bg-brand-50 px-1.5 text-xs text-brand-700 num">{`{{${i + 1}}}`}</span>
                                <span className="flex-1">{VARIABLE_LABELS[v] ?? v}</span>
                                <button type="button" className="grid size-7 place-items-center rounded-lg hover:bg-brand-50 disabled:opacity-30" disabled={i === 0} onClick={() => move(i, -1)} aria-label="أعلى"><ArrowUp className="size-3.5" /></button>
                                <button type="button" className="grid size-7 place-items-center rounded-lg hover:bg-brand-50 disabled:opacity-30" disabled={i === vars.length - 1} onClick={() => move(i, 1)} aria-label="أسفل"><ArrowDown className="size-3.5" /></button>
                                <button type="button" className="grid size-7 place-items-center rounded-lg text-red-600 hover:bg-red-50" onClick={() => setVars(vars.filter((_, j) => j !== i))} aria-label="حذف"><X className="size-3.5" /></button>
                            </div>
                        ))}
                    </div>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {allowed.filter((v) => !vars.includes(v)).map((v) => (
                            <button key={v} type="button" onClick={() => setVars([...vars, v])} className="rounded-full border border-dashed border-brand-300 px-2.5 py-1 text-xs text-brand-700 hover:bg-brand-50">
                                + {VARIABLE_LABELS[v] ?? v}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="rounded-xl bg-[#fbf6f8] p-3">
                    <p className="mb-1 text-xs text-muted">معاينة</p>
                    <p className="whitespace-pre-wrap text-sm leading-6">{preview(form.data.body, vars) || '—'}</p>
                </div>

                <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="مفعّل" hint="إيقاف القالب يوقف هذا النوع من الرسائل التلقائية للنادي كله" />
            </form>
        </Modal>
    );
}
