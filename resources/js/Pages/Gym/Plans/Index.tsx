import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Check, Layers, Pencil, Plus, Star, Trash2, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money, dayCount } from '@/lib/format';
import { durationUnit } from '@/lib/labels';
import type { Option } from '@/types';

interface Plan {
    id: number;
    name: string;
    type: string;
    type_label: string;
    description: string | null;
    duration_value: number;
    duration_unit: string;
    duration_label: string;
    price: string;
    signup_fee: string;
    sessions_limit: number | null;
    freeze_allowed: boolean;
    max_freeze_days: number;
    max_freezes: number;
    features: string[] | null;
    color: string | null;
    is_public: boolean;
    is_featured: boolean;
    is_active: boolean;
    sort: number;
    program_ids: number[];
    active_count: number;
}

export default function PlansIndex({ plans, programs, types }: { plans: Plan[]; programs: Option[]; types: Record<string, string> }) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<Plan | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <ClubLayout title="الباقات والأسعار">
            <PageHeader
                title="الباقات والأسعار"
                subtitle="أنواع العضويات ومددها وأسعارها وقواعد التجميد والبرامج المشمولة. تعديل الباقة لا يغيّر شروط العضويات المباعة سابقًا."
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>باقة جديدة</Button>}
            />

            {plans.length === 0 ? (
                <Card><EmptyState icon={<Layers className="size-6" />} title="لا توجد باقات" description="أضف أول باقة لبدء بيع العضويات." action={<Button onClick={() => setCreating(true)}>إضافة باقة</Button>} /></Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {plans.map((p) => (
                        <Card key={p.id} className={p.is_active ? '' : 'opacity-60'}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <span className="size-3 rounded-full" style={{ background: p.color || 'var(--brand)' }} />
                                    <h3 className="font-semibold">{p.name}</h3>
                                    {p.is_featured && <Star className="size-4 fill-amber-400 text-amber-400" />}
                                </div>
                                <div className="flex gap-1">
                                    <button className="grid size-8 place-items-center rounded-lg text-muted hover:bg-brand-50 hover:text-brand" onClick={() => setEditing(p)} title="تعديل"><Pencil className="size-4" /></button>
                                    <ConfirmAction url={route('club.plans.destroy', p.id)} method="delete" title="حذف الباقة" message="تُزال الباقة من البيع، وتبقى العضويات السابقة كما هي." confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-4" />}>{''}</ConfirmAction>
                                </div>
                            </div>
                            <p className="mt-3 text-2xl font-semibold text-brand-700"><span className="num">{money(p.price, currency)}</span></p>
                            <p className="text-sm text-muted">{p.duration_label}{Number(p.signup_fee) > 0 && <> · رسوم تسجيل <span className="num">{money(p.signup_fee, currency)}</span></>}</p>
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                <Badge tone="brand">{p.type_label}</Badge>
                                <Badge tone="gray">{p.sessions_limit ? `${p.sessions_limit} حصة` : 'حصص غير محدودة'}</Badge>
                                {p.freeze_allowed ? <Badge tone="violet">تجميد {p.max_freeze_days > 0 ? `حتى ${dayCount(p.max_freeze_days)}` : 'مفتوح'}</Badge> : <Badge tone="gray">بدون تجميد</Badge>}
                                {!p.is_public && <Badge tone="amber">غير ظاهرة بالموقع</Badge>}
                                {!p.is_active && <Badge tone="red">موقوفة</Badge>}
                            </div>
                            {p.features && p.features.length > 0 && (
                                <ul className="mt-3 space-y-1 text-sm text-ink/80">
                                    {p.features.slice(0, 4).map((f, i) => <li key={i} className="flex items-center gap-1.5"><Check className="size-3.5 text-emerald-600" />{f}</li>)}
                                </ul>
                            )}
                            <p className="mt-4 border-t border-line pt-3 text-xs text-muted">
                                <span className="num">{p.active_count}</span> عضوية نشطة · {p.program_ids.length > 0 ? `${p.program_ids.length} برنامج مشمول` : 'كل البرامج'}
                            </p>
                        </Card>
                    ))}
                </div>
            )}

            <PlanModal
                key={editing?.id ?? (creating ? 'new' : 'closed')}
                open={creating || editing !== null}
                plan={editing}
                programs={programs}
                types={types}
                onClose={() => { setCreating(false); setEditing(null); }}
            />
        </ClubLayout>
    );
}

function PlanModal({ open, plan, programs, types, onClose }: { open: boolean; plan: Plan | null; programs: Option[]; types: Record<string, string>; onClose: () => void }) {
    const form = useForm({
        name: plan?.name ?? '',
        type: plan?.type ?? 'gym',
        description: plan?.description ?? '',
        duration_value: String(plan?.duration_value ?? 1),
        duration_unit: plan?.duration_unit ?? 'month',
        price: plan?.price ?? '',
        signup_fee: plan?.signup_fee ?? '0',
        sessions_limit: plan?.sessions_limit ? String(plan.sessions_limit) : '',
        freeze_allowed: plan?.freeze_allowed ?? true,
        max_freeze_days: String(plan?.max_freeze_days ?? 0),
        max_freezes: String(plan?.max_freezes ?? 0),
        features: plan?.features ?? [],
        color: plan?.color ?? '#db2777',
        is_public: plan?.is_public ?? true,
        is_featured: plan?.is_featured ?? false,
        is_active: plan?.is_active ?? true,
        sort: String(plan?.sort ?? 0),
        program_ids: plan?.program_ids ?? [],
    });
    const [feature, setFeature] = useState('');
    const err = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (plan) form.put(route('club.plans.update', plan.id), options);
        else form.post(route('club.plans.store'), options);
    };

    const addFeature = () => {
        const f = feature.trim();
        if (!f) return;
        form.setData('features', [...form.data.features, f]);
        setFeature('');
    };

    const toggleProgram = (id: number) =>
        form.setData('program_ids', form.data.program_ids.includes(id) ? form.data.program_ids.filter((x) => x !== id) : [...form.data.program_ids, id]);

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={plan ? `تعديل: ${plan.name}` : 'باقة جديدة'}
            size="xl"
            footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}
        >
            <form onSubmit={submit} className="grid gap-6 md:grid-cols-2">
                <div className="space-y-4">
                    <Field label="اسم الباقة" error={err.name} required>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="شهري — دخول كامل" />
                    </Field>
                    <Field label="نوع العضوية" error={err.type}>
                        <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            {Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="المدة" error={err.duration_value} required>
                            <Input type="number" min={1} value={form.data.duration_value} onChange={(e) => form.setData('duration_value', e.target.value)} />
                        </Field>
                        <Field label="الوحدة" error={err.duration_unit}>
                            <Select value={form.data.duration_unit} onChange={(e) => form.setData('duration_unit', e.target.value)}>
                                {Object.entries(durationUnit).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </Select>
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="السعر" error={err.price} required>
                            <Input type="number" step="0.001" min={0} value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />
                        </Field>
                        <Field label="رسوم التسجيل" error={err.signup_fee} hint="تُحتسب على أول عضوية فقط">
                            <Input type="number" step="0.001" min={0} value={form.data.signup_fee} onChange={(e) => form.setData('signup_fee', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="عدد الحصص الجماعية" error={err.sessions_limit} hint="اتركه فارغًا لحصص غير محدودة خلال المدة">
                        <Input type="number" min={1} value={form.data.sessions_limit} onChange={(e) => form.setData('sessions_limit', e.target.value)} />
                    </Field>
                    <Field label="الوصف" error={err.description}>
                        <Textarea rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>
                </div>

                <div className="space-y-4">
                    <div className="rounded-2xl border border-line p-4">
                        <Toggle checked={form.data.freeze_allowed} onChange={(v) => form.setData('freeze_allowed', v)} label="السماح بالتجميد" hint="يُمدَّد تاريخ الانتهاء بعدد أيام التجميد" />
                        {form.data.freeze_allowed && (
                            <div className="mt-3 grid grid-cols-2 gap-3">
                                <Field label="أقصى أيام" error={err.max_freeze_days} hint="0 = بلا حد">
                                    <Input type="number" min={0} value={form.data.max_freeze_days} onChange={(e) => form.setData('max_freeze_days', e.target.value)} />
                                </Field>
                                <Field label="أقصى مرات" error={err.max_freezes} hint="0 = بلا حد">
                                    <Input type="number" min={0} value={form.data.max_freezes} onChange={(e) => form.setData('max_freezes', e.target.value)} />
                                </Field>
                            </div>
                        )}
                    </div>

                    <Field label="البرامج المشمولة" hint="بدون اختيار = كل البرامج متاحة للحجز">
                        <div className="flex flex-wrap gap-2">
                            {programs.map((p) => (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => toggleProgram(p.id)}
                                    className={`rounded-full border px-3 py-1 text-xs ${form.data.program_ids.includes(p.id) ? 'border-brand bg-brand text-white' : 'border-line hover:bg-brand-50'}`}
                                >
                                    {p.name}
                                </button>
                            ))}
                            {programs.length === 0 && <span className="text-xs text-muted">لا توجد برامج بعد.</span>}
                        </div>
                    </Field>

                    <Field label="مزايا تظهر في الموقع" error={err.features}>
                        <div className="flex gap-2">
                            <Input value={feature} onChange={(e) => setFeature(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addFeature(); } }} placeholder="دخول غير محدود للصالة" />
                            <Button type="button" variant="secondary" onClick={addFeature}>إضافة</Button>
                        </div>
                        <ul className="mt-2 space-y-1">
                            {form.data.features.map((f, i) => (
                                <li key={i} className="flex items-center justify-between rounded-lg bg-canvas px-3 py-1.5 text-sm">
                                    {f}
                                    <button type="button" onClick={() => form.setData('features', form.data.features.filter((_, j) => j !== i))} className="text-muted hover:text-red-600"><X className="size-3.5" /></button>
                                </li>
                            ))}
                        </ul>
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="اللون" error={err.color}>
                            <Input type="color" className="h-10 p-1" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} />
                        </Field>
                        <Field label="الترتيب" error={err.sort}>
                            <Input type="number" value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} />
                        </Field>
                    </div>

                    <div className="flex flex-wrap gap-4">
                        <Checkbox label="نشطة للبيع" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        <Checkbox label="تظهر في الموقع" checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} />
                        <Checkbox label="مميزة" checked={form.data.is_featured} onChange={(e) => form.setData('is_featured', e.target.checked)} />
                    </div>
                </div>
            </form>
        </Modal>
    );
}
