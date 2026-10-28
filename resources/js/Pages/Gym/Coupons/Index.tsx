import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { BadgePercent, Pencil, Plus, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import type { Option } from '@/types';

interface Coupon {
    id: number;
    code: string;
    description: string | null;
    type: 'percent' | 'fixed';
    value: string;
    min_amount: string;
    max_uses: number | null;
    used_count: number;
    per_member_limit: number | null;
    plan_ids: number[] | null;
    is_active: boolean;
    valid_from: string | null;
    valid_until: string | null;
}

function couponState(c: Coupon): [string, 'green' | 'gray' | 'red' | 'amber' | 'blue'] {
    const today = new Date().toISOString().slice(0, 10);
    if (!c.is_active) return ['موقوف', 'gray'];
    if (c.valid_until && c.valid_until < today) return ['منتهي', 'red'];
    if (c.valid_from && c.valid_from > today) return ['لم يبدأ', 'blue'];
    if (c.max_uses !== null && c.used_count >= c.max_uses) return ['مستنفد', 'amber'];
    return ['فعّال', 'green'];
}

export default function CouponsIndex({ coupons, plans }: { coupons: Coupon[]; plans: Option[] }) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<Coupon | null>(null);
    const [creating, setCreating] = useState(false);
    const planName = (id: number) => plans.find((p) => p.id === id)?.name ?? `#${id}`;

    return (
        <ClubLayout title="الخصومات والعروض">
            <PageHeader
                title="الخصومات والعروض"
                subtitle="أكواد خصم تُستخدم عند بيع العضويات في الاستقبال أو التسجيل والتجديد أونلاين"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>كود جديد</Button>}
            />

            <Card padded={false}>
                {coupons.length === 0 ? (
                    <EmptyState icon={<BadgePercent className="size-6" />} title="لا توجد أكواد خصم" action={<Button onClick={() => setCreating(true)}>إنشاء كود</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الكود</th><th>الخصم</th><th>الصلاحية</th><th>الاستخدام</th><th>الباقات</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {coupons.map((c) => {
                                    const [l, tone] = couponState(c);
                                    return (
                                        <tr key={c.id}>
                                            <td>
                                                <span className="rounded-lg bg-brand-50 px-2 py-1 font-mono text-sm font-semibold text-brand-700" dir="ltr">{c.code}</span>
                                                {c.description && <span className="mt-1 block text-xs text-muted">{c.description}</span>}
                                            </td>
                                            <td className="num font-medium">{c.type === 'percent' ? `${Number(c.value)}%` : money(c.value, currency)}</td>
                                            <td className="text-xs">{c.valid_from || c.valid_until ? `${date(c.valid_from)} ← ${date(c.valid_until)}` : 'مفتوحة'}</td>
                                            <td className="text-xs num">{c.used_count}{c.max_uses !== null ? ` / ${c.max_uses}` : ''}{c.per_member_limit ? <span className="block text-muted">{c.per_member_limit} لكل مشتركة</span> : null}</td>
                                            <td className="text-xs">{c.plan_ids && c.plan_ids.length > 0 ? c.plan_ids.map(planName).join('، ') : 'كل الباقات'}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                            <td className="text-end whitespace-nowrap">
                                                <button className="inline-grid size-8 place-items-center rounded-lg text-muted hover:bg-brand-50 hover:text-brand" onClick={() => setEditing(c)} title="تعديل"><Pencil className="size-4" /></button>
                                                <ConfirmAction url={route('club.coupons.destroy', c.id)} method="delete" title="حذف الكود" message={c.used_count > 0 ? 'الكود مستخدم؛ سيتم إيقافه بدل حذفه.' : 'حذف الكود نهائيًا؟'} confirmLabel="تأكيد" variant="ghost" icon={<Trash2 className="size-4" />}>{''}</ConfirmAction>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <CouponModal key={editing?.id ?? (creating ? 'new' : 'closed')} open={creating || editing !== null} coupon={editing} plans={plans} onClose={() => { setCreating(false); setEditing(null); }} />
        </ClubLayout>
    );
}

function CouponModal({ open, coupon, plans, onClose }: { open: boolean; coupon: Coupon | null; plans: Option[]; onClose: () => void }) {
    const form = useForm({
        code: coupon?.code ?? '',
        description: coupon?.description ?? '',
        type: coupon?.type ?? 'percent',
        value: coupon?.value ?? '',
        min_amount: coupon?.min_amount ?? '0',
        max_uses: coupon?.max_uses ? String(coupon.max_uses) : '',
        // New codes default to once per member; an existing unlimited code must stay unlimited when edited.
        per_member_limit: coupon ? (coupon.per_member_limit ? String(coupon.per_member_limit) : '') : '1',
        plan_ids: coupon?.plan_ids ?? ([] as number[]),
        valid_from: coupon?.valid_from ?? '',
        valid_until: coupon?.valid_until ?? '',
        is_active: coupon?.is_active ?? true,
    });
    const err = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (coupon) form.put(route('club.coupons.update', coupon.id), options);
        else form.post(route('club.coupons.store'), options);
    };

    const togglePlan = (id: number) => form.setData('plan_ids', form.data.plan_ids.includes(id) ? form.data.plan_ids.filter((x) => x !== id) : [...form.data.plan_ids, id]);

    return (
        <Modal open={open} onClose={onClose} title={coupon ? `تعديل ${coupon.code}` : 'كود خصم جديد'} size="lg" footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="الكود" error={err.code} required hint="أحرف إنجليزية وأرقام">
                    <Input dir="ltr" value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase().replace(/\s/g, ''))} placeholder="RAMADAN25" />
                </Field>
                <Field label="الوصف" error={err.description}>
                    <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="عرض رمضان" />
                </Field>
                <Field label="نوع الخصم" error={err.type}>
                    <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value as 'percent' | 'fixed')}>
                        <option value="percent">نسبة مئوية</option>
                        <option value="fixed">مبلغ ثابت</option>
                    </Select>
                </Field>
                <Field label={form.data.type === 'percent' ? 'النسبة %' : 'المبلغ'} error={err.value} required>
                    <Input type="number" step="0.001" min={0} max={form.data.type === 'percent' ? 100 : undefined} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
                </Field>
                <Field label="يبدأ في" error={err.valid_from}>
                    <Input type="date" value={form.data.valid_from} onChange={(e) => form.setData('valid_from', e.target.value)} />
                </Field>
                <Field label="ينتهي في" error={err.valid_until}>
                    <Input type="date" value={form.data.valid_until} onChange={(e) => form.setData('valid_until', e.target.value)} />
                </Field>
                <Field label="أقصى عدد استخدامات" error={err.max_uses} hint="فارغ = بلا حد">
                    <Input type="number" min={1} value={form.data.max_uses} onChange={(e) => form.setData('max_uses', e.target.value)} />
                </Field>
                <Field label="مرات لكل مشتركة" error={err.per_member_limit} hint="فارغ = بلا حد">
                    <Input type="number" min={1} value={form.data.per_member_limit} onChange={(e) => form.setData('per_member_limit', e.target.value)} />
                </Field>
                <Field label="أقل مبلغ للاستخدام" error={err.min_amount}>
                    <Input type="number" step="0.001" min={0} value={form.data.min_amount} onChange={(e) => form.setData('min_amount', e.target.value)} />
                </Field>
                <div className="flex items-end pb-2">
                    <Checkbox label="فعّال" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                </div>
                <Field label="الباقات المشمولة" hint="بدون اختيار = كل الباقات" className="sm:col-span-2">
                    <div className="flex flex-wrap gap-2">
                        {plans.map((p) => (
                            <button key={p.id} type="button" onClick={() => togglePlan(p.id)} className={`rounded-full border px-3 py-1 text-xs ${form.data.plan_ids.includes(p.id) ? 'border-brand bg-brand text-white' : 'border-line hover:bg-brand-50'}`}>
                                {p.name}
                            </button>
                        ))}
                    </div>
                </Field>
            </form>
        </Modal>
    );
}
