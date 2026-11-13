import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AlertTriangle, Check, Layers, Pencil, Plus, Star, Trash2, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardFooter, Checkbox, ConfirmAction, EmptyState, EnglishFields, Field, Input, linesOf, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
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
    name_en?: string | null;
    description_en?: string | null;
    features_en?: string[] | null;
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
    /** «صلاحيات الباقة»: what a sale of it gives now. */
    allows_gym: boolean;
    allows_classes: boolean;
    active_count: number;
    /** Sold and not over: they keep what they were sold whatever is changed now. */
    live_count: number;
}

export default function PlansIndex({ plans, programs }: { plans: Plan[]; programs: Option[] }) {
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
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {plans.map((p) => (
                        <Card key={p.id} fill className={p.is_active ? '' : 'opacity-60'}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <span className="size-3 rounded-full" style={{ background: p.color || 'var(--brand)' }} />
                                    <h3 className="text-[15px] font-semibold text-[#111]">{p.name}</h3>
                                    {p.is_featured && <Star className="size-4 fill-amber-400 text-amber-400" />}
                                </div>
                                <div className="flex gap-1">
                                    <button className="grid size-8 place-items-center rounded-lg text-muted hover:bg-[#f2f2f0] hover:text-[#111]" onClick={() => setEditing(p)} title="تعديل"><Pencil className="size-4" /></button>
                                    <ConfirmAction url={route('club.plans.destroy', p.id)} method="delete" title="حذف الباقة" message="تُزال الباقة من البيع، وتبقى العضويات السابقة كما هي." confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-4" />}>{''}</ConfirmAction>
                                </div>
                            </div>
                            <p className="mt-3 text-[22px] font-bold leading-8 tracking-tight text-[#111]"><span className="num">{money(p.price, currency)}</span></p>
                            <p className="text-sm text-muted">{p.duration_label}{Number(p.signup_fee) > 0 && <> · رسوم تسجيل <span className="num">{money(p.signup_fee, currency)}</span></>}</p>
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {p.allows_gym && <Badge tone="brand">دخول النادي</Badge>}
                                {p.allows_classes && <Badge tone="brand">حجز الحصص</Badge>}
                                {p.allows_classes && !p.allows_gym && <Badge tone="amber">الدخول يوم الحصة فقط</Badge>}
                                {p.allows_classes && <Badge tone="gray">{p.sessions_limit ? `${p.sessions_limit} حصة` : 'حصص غير محدودة'}</Badge>}
                                {p.freeze_allowed ? <Badge tone="violet">تجميد {p.max_freeze_days > 0 ? `حتى ${dayCount(p.max_freeze_days)}` : 'مفتوح'}</Badge> : <Badge tone="gray">بدون تجميد</Badge>}
                                {!p.is_public && <Badge tone="amber">غير ظاهرة بالموقع</Badge>}
                                {!p.is_active && <Badge tone="red">موقوفة</Badge>}
                            </div>
                            {p.features && p.features.length > 0 && (
                                <ul className="mt-3 space-y-1 text-sm text-ink/80">
                                    {p.features.slice(0, 4).map((f, i) => <li key={i} className="flex items-center gap-1.5"><Check className="size-3.5 text-emerald-600" />{f}</li>)}
                                </ul>
                            )}
                            <CardFooter className="text-xs text-muted">
                                <span><span className="num">{p.active_count}</span> عضوية نشطة{p.allows_classes && <> · {p.program_ids.length > 0 ? `${p.program_ids.length} برنامج مشمول` : 'كل البرامج'}</>}</span>
                            </CardFooter>
                        </Card>
                    ))}
                </div>
            )}

            <PlanModal
                key={editing?.id ?? (creating ? 'new' : 'closed')}
                open={creating || editing !== null}
                plan={editing}
                programs={programs}
                onClose={() => { setCreating(false); setEditing(null); }}
            />
        </ClubLayout>
    );
}

function PlanModal({ open, plan, programs, onClose }: { open: boolean; plan: Plan | null; programs: Option[]; onClose: () => void }) {
    const form = useForm({
        name: plan?.name ?? '',
        // A new plan starts with neither: the owner chooses what it gives, nothing is assumed.
        allows_gym: plan?.allows_gym ?? false,
        allows_classes: plan?.allows_classes ?? false,
        description: plan?.description ?? '',
        name_en: plan?.name_en ?? '',
        description_en: plan?.description_en ?? '',
        features_en: plan?.features_en ?? [],
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
    const [featuresEn, setFeaturesEn] = useState(() => (plan?.features_en ?? []).join('\n'));
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
            title={plan ? `تعديل: ${plan.name}` : 'باقة جديدة'} size="wide"
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}
        >
            <form onSubmit={submit} className="grid gap-6 md:grid-cols-2">
                <div className="space-y-4">
                    <Field label="اسم الباقة" error={err.name} required>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="شهري — دخول كامل" />
                    </Field>
                    <PlanAccessFields
                        gym={form.data.allows_gym}
                        classes={form.data.allows_classes}
                        onGym={(v) => form.setData('allows_gym', v)}
                        onClasses={(v) => form.setData('allows_classes', v)}
                        error={err.access ?? err.allows_gym ?? err.allows_classes}
                        changedFor={plan && plan.live_count > 0 && (plan.allows_gym !== form.data.allows_gym || plan.allows_classes !== form.data.allows_classes) ? plan.live_count : 0}
                    />
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
                    {form.data.allows_classes && (
                        <Field label="عدد الحصص الجماعية" error={err.sessions_limit} hint="اتركه فارغًا لحصص غير محدودة خلال المدة">
                            <Input type="number" min={1} value={form.data.sessions_limit} onChange={(e) => form.setData('sessions_limit', e.target.value)} />
                        </Field>
                    )}
                    <Field label="الوصف" error={err.description}>
                        <Textarea rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>
                    <EnglishFields filled={Boolean(form.data.name_en || form.data.description_en || form.data.features_en.length)}>
                        <Field label="الاسم بالإنجليزية" error={err.name_en}>
                            <Input dir="ltr" lang="en" maxLength={120} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />
                        </Field>
                        <Field label="الوصف بالإنجليزية" error={err.description_en}>
                            <Textarea dir="ltr" lang="en" rows={3} maxLength={2000} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />
                        </Field>
                        <Field label="المزايا بالإنجليزية (سطر لكل ميزة)" error={err.features_en ?? Object.entries(err).find(([k]) => k.startsWith('features_en.'))?.[1]}>
                            <Textarea
                                dir="ltr"
                                lang="en"
                                rows={3}
                                value={featuresEn}
                                onChange={(e) => {
                                    setFeaturesEn(e.target.value);
                                    form.setData('features_en', linesOf(e.target.value, 12));
                                }}
                            />
                        </Field>
                    </EnglishFields>
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

                    {form.data.allows_classes && (
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
                    )}

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

const ACCESS_SUMMARY: Record<string, string> = {
    'gym': 'دخول النادي فقط: يدخل المشترك أي يوم خلال مدة اشتراكه، ولا يحجز الحصص.',
    'classes': 'حجز الحصص فقط: يحجز المشترك الحصص، ويدخل النادي يوم حصته المحجوزة فقط.',
    'gym+classes': 'دخول النادي وحجز الحصص: يدخل أي يوم، ويحجز الحصص حسب رصيد الباقة.',
};

/**
 * «صلاحيات الباقة»: what a sale of the plan gives, chosen by the owner (the server works the plan's type out of them
 * and refuses a plan with neither). A change applies to sales and renewals from now on; periods already sold keep
 * what they were sold with.
 */
function PlanAccessFields({ gym, classes, onGym, onClasses, error, changedFor }: {
    gym: boolean;
    classes: boolean;
    onGym: (v: boolean) => void;
    onClasses: (v: boolean) => void;
    error?: string;
    changedFor: number;
}) {
    const key = [gym && 'gym', classes && 'classes'].filter(Boolean).join('+');
    const none = 'اختر صلاحية واحدة على الأقل: دخول النادي أو حجز الحصص.';

    return (
        <div className="rounded-2xl border border-line p-4">
            <p className="text-sm font-semibold text-ink">صلاحيات الباقة <span className="text-red-600">*</span></p>
            <p className="mt-0.5 text-[12px] text-muted">اختر ما تسمح به الباقة. لا يُستنتج من اسمها أو سعرها.</p>
            <div className="mt-2 divide-y divide-line">
                <Toggle checked={gym} onChange={onGym} label="دخول النادي" hint="تفتح البوابة للمشترك في أي يوم خلال مدة اشتراكه." />
                <Toggle checked={classes} onChange={onClasses} label="حجز الحصص" hint="يحجز المشترك الحصص حسب رصيد الباقة والبرامج المشمولة وسعة الحصة." />
            </div>
            <p className={`mt-3 rounded-xl px-3 py-2 text-[13px] ${key ? 'bg-brand-50 text-brand-900' : 'bg-red-50 text-red-700'}`}>
                {key ? <><span className="font-semibold">الملخص:</span> {ACCESS_SUMMARY[key]}</> : none}
            </p>
            {/* The server's refusal of a plan with neither is the line above: said once. */}
            {error && error !== none && <p className="mt-2 text-[13px] text-red-600">{error}</p>}
            {changedFor > 0 && (
                <p className="mt-3 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[13px] text-amber-900">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>لهذه الباقة <span className="num font-semibold">{changedFor}</span> اشتراك قائم. تغيير الصلاحيات يسري على المبيعات والتجديدات الجديدة فقط، ويحتفظ كل اشتراك قائم بصلاحياته حتى نهاية فترته.</span>
                </p>
            )}
        </div>
    );
}
