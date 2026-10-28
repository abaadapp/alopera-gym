import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Layers, Pencil, Plus, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Textarea, Toggle } from '@/Components/ui';
import { money, number } from '@/lib/format';

interface Plan {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly: string;
    price_yearly: string;
    currency: string;
    max_members: number | null;
    max_staff: number | null;
    max_branches: number | null;
    features: string[] | null;
    trial_days: number;
    is_active: boolean;
    sort: number;
    subscribers: number;
}

export default function Plans({ plans }: { plans: Plan[] }) {
    const [editing, setEditing] = useState<Plan | 'new' | null>(null);

    return (
        <PlatformLayout title="باقات المنصة">
            <PageHeader title="باقات المنصة" subtitle="ما يدفعه النادي للمنصة — منفصلة عن باقات العضوية داخل النادي" actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>باقة جديدة</Button>} />
            {plans.length === 0 ? (
                <Card><EmptyState icon={<Layers className="size-6" />} title="لا توجد باقات" /></Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {plans.map((p) => (
                        <Card key={p.id} className={`flex flex-col ${p.is_active ? '' : 'opacity-60'}`}>
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-lg font-semibold">{p.name}</p>
                                    <p className="text-xs text-muted" dir="ltr">{p.code}</p>
                                </div>
                                {p.is_active ? <Badge tone="green">متاحة</Badge> : <Badge>متوقفة</Badge>}
                            </div>
                            {p.description && <p className="mt-2 text-sm text-muted">{p.description}</p>}
                            <div className="mt-4 flex items-baseline gap-2">
                                <span className="text-2xl font-semibold num">{money(p.price_monthly, p.currency)}</span>
                                <span className="text-xs text-muted">/ شهر</span>
                            </div>
                            <p className="text-xs text-muted">سنويًا: <span className="num">{money(p.price_yearly, p.currency)}</span> · تجربة <span className="num">{p.trial_days}</span> يوم</p>
                            <ul className="mt-4 space-y-1.5 text-sm">
                                <li>المشتركات: <b className="num">{p.max_members ? number(p.max_members) : 'بلا حد'}</b></li>
                                <li>المستخدمات: <b className="num">{p.max_staff ? number(p.max_staff) : 'بلا حد'}</b></li>
                                <li>الفروع: <b className="num">{p.max_branches ? number(p.max_branches) : 'بلا حد'}</b></li>
                                {(p.features ?? []).map((f) => <li key={f} className="flex items-center gap-1.5 text-ink/80"><Check className="size-3.5 text-emerald-600" />{f}</li>)}
                            </ul>
                            <div className="mt-auto flex items-center justify-between gap-2 pt-4">
                                <span className="text-xs text-muted"><span className="num">{p.subscribers}</span> نادٍ مشترك</span>
                                <div className="flex gap-1">
                                    <Button size="sm" variant="outline" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(p)}>تعديل</Button>
                                    <ConfirmAction url={route('platform.plans.destroy', p.id)} method="delete" title="حذف الباقة" message={p.subscribers > 0 ? 'الباقة مستخدمة؛ ستُوقف بدل حذفها.' : `حذف باقة «${p.name}»؟`} variant="ghost" icon={<Trash2 className="size-3.5" />}>حذف</ConfirmAction>
                                </div>
                            </div>
                        </Card>
                    ))}
                </div>
            )}
            {editing && <PlanModal plan={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </PlatformLayout>
    );
}

function PlanModal({ plan, onClose }: { plan: Plan | null; onClose: () => void }) {
    const [features, setFeatures] = useState((plan?.features ?? []).join('\n'));
    const form = useForm({
        code: plan?.code ?? '',
        name: plan?.name ?? '',
        description: plan?.description ?? '',
        price_monthly: plan?.price_monthly ?? '',
        price_yearly: plan?.price_yearly ?? '',
        max_members: plan?.max_members != null ? String(plan.max_members) : '',
        max_staff: plan?.max_staff != null ? String(plan.max_staff) : '',
        max_branches: plan?.max_branches != null ? String(plan.max_branches) : '',
        trial_days: String(plan?.trial_days ?? 14),
        is_active: plan?.is_active ?? true,
        sort: String(plan?.sort ?? 0),
        features: [] as string[],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, features: features.split('\n').map((f) => f.trim()).filter(Boolean) }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (plan) form.put(route('platform.plans.update', plan.id), options);
        else form.post(route('platform.plans.store'), options);
    };

    return (
        <Modal open onClose={onClose} size="lg" title={plan ? `تعديل: ${plan.name}` : 'باقة منصة جديدة'} footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" error={errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                <Field label="الرمز" error={errors.code} required><Input dir="ltr" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="pro" /></Field>
                <Field label="الوصف" error={errors.description} className="sm:col-span-2"><Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
                <Field label="السعر الشهري (ر.ع)" error={errors.price_monthly} required><Input type="number" step="0.001" min="0" value={form.data.price_monthly} onChange={(e) => form.setData('price_monthly', e.target.value)} /></Field>
                <Field label="السعر السنوي (ر.ع)" error={errors.price_yearly} required><Input type="number" step="0.001" min="0" value={form.data.price_yearly} onChange={(e) => form.setData('price_yearly', e.target.value)} /></Field>
                <Field label="حد المشتركات" error={errors.max_members} hint="فارغ = بلا حد"><Input type="number" min="1" value={form.data.max_members} onChange={(e) => form.setData('max_members', e.target.value)} /></Field>
                <Field label="حد المستخدمات" error={errors.max_staff} hint="فارغ = بلا حد"><Input type="number" min="1" value={form.data.max_staff} onChange={(e) => form.setData('max_staff', e.target.value)} /></Field>
                <Field label="حد الفروع" error={errors.max_branches} hint="فارغ = بلا حد"><Input type="number" min="1" value={form.data.max_branches} onChange={(e) => form.setData('max_branches', e.target.value)} /></Field>
                <Field label="أيام التجربة" error={errors.trial_days}><Input type="number" min="0" max="90" value={form.data.trial_days} onChange={(e) => form.setData('trial_days', e.target.value)} /></Field>
                <Field label="المميزات (سطر لكل ميزة)" error={errors.features} className="sm:col-span-2"><Textarea rows={4} value={features} onChange={(e) => setFeatures(e.target.value)} /></Field>
                <Field label="الترتيب" error={errors.sort}><Input type="number" value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} /></Field>
                <div className="flex items-end"><Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="متاحة للاشتراك" /></div>
            </form>
        </Modal>
    );
}
