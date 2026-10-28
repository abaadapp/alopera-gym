import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, Package, Pencil, Plus, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money } from '@/lib/format';
import type { Option } from '@/types';

type PtPackage = {
    id: number;
    name: string;
    description: string | null;
    sessions_count: number;
    session_minutes: number;
    price: string;
    validity_days: number;
    trainer_id: number | null;
    is_public: boolean;
    is_active: boolean;
    trainer: string | null;
};

type PackageForm = {
    name: string;
    description: string;
    sessions_count: number | string;
    session_minutes: number | string;
    price: number | string;
    validity_days: number | string;
    trainer_id: number | string | null;
    is_public: boolean;
    is_active: boolean;
};

const blank: PackageForm = { name: '', description: '', sessions_count: 8, session_minutes: 60, price: '', validity_days: 60, trainer_id: null, is_public: true, is_active: true };

export default function PtPackages({ packages, trainers }: { packages: PtPackage[]; trainers: Option[] }) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<PtPackage | null>(null);
    const [open, setOpen] = useState(false);
    const form = useForm<PackageForm>(blank);

    const openForm = (p: PtPackage | null) => {
        setEditing(p);
        form.clearErrors();
        form.setData(p ? {
            name: p.name, description: p.description ?? '', sessions_count: p.sessions_count, session_minutes: p.session_minutes,
            price: p.price, validity_days: p.validity_days, trainer_id: p.trainer_id, is_public: p.is_public, is_active: p.is_active,
        } : blank);
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('club.pt-packages.update', editing.id), options);
        else form.post(route('club.pt-packages.store'), options);
    };

    const perSession = Number(form.data.sessions_count) > 0 && form.data.price !== '' ? Number(form.data.price) / Number(form.data.sessions_count) : null;

    return (
        <ClubLayout title="باقات التدريب الشخصي">
            <PageHeader
                back={<Link href={route('club.pt.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> التدريب الشخصي</Link>}
                title="باقات التدريب الشخصي"
                subtitle="عدد الجلسات والسعر ومدة الصلاحية — تظهر في صفحة الأسعار على الموقع إن كانت عامة"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => openForm(null)}>باقة جديدة</Button>}
            />

            {packages.length === 0 ? (
                <Card><EmptyState icon={<Package className="size-6" />} title="لا توجد باقات" action={<Button onClick={() => openForm(null)}>إضافة باقة</Button>} /></Card>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {packages.map((p) => (
                        <Card key={p.id} className={p.is_active ? '' : 'opacity-60'}>
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-semibold">{p.name}</p>
                                    <p className="text-xs text-muted">{p.trainer ?? 'أي مدربة'}</p>
                                </div>
                                <div className="flex flex-col items-end gap-1">
                                    {p.is_public ? <Badge tone="green">في الموقع</Badge> : <Badge tone="gray">داخلية</Badge>}
                                    {!p.is_active && <Badge tone="gray">موقوفة</Badge>}
                                </div>
                            </div>
                            <p className="mt-4 font-display text-2xl font-semibold text-brand">{money(p.price, currency)}</p>
                            <div className="mt-3 grid grid-cols-3 gap-2 text-center">
                                <div className="rounded-xl bg-canvas p-2"><p className="text-lg font-semibold num">{p.sessions_count}</p><p className="text-[11px] text-muted">جلسة</p></div>
                                <div className="rounded-xl bg-canvas p-2"><p className="text-lg font-semibold num">{p.session_minutes}</p><p className="text-[11px] text-muted">دقيقة</p></div>
                                <div className="rounded-xl bg-canvas p-2"><p className="text-lg font-semibold num">{p.validity_days}</p><p className="text-[11px] text-muted">يوم صلاحية</p></div>
                            </div>
                            {p.description && <p className="mt-3 line-clamp-2 text-sm text-muted">{p.description}</p>}
                            <div className="mt-4 flex gap-2 border-t border-line pt-3">
                                <Button size="sm" variant="outline" icon={<Pencil className="size-3.5" />} onClick={() => openForm(p)}>تعديل</Button>
                                <ConfirmAction url={route('club.pt-packages.destroy', p.id)} method="delete" title="حذف الباقة" message="الاشتراكات المباعة سابقًا لا تتأثر." variant="ghost" icon={<Trash2 className="size-3.5" />} confirmLabel="حذف">حذف</ConfirmAction>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <Modal open={open} onClose={() => setOpen(false)} title={editing ? 'تعديل الباقة' : 'باقة تدريب شخصي جديدة'} footer={
                <>
                    <Button variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
                    <Button type="submit" form="pkg-form" loading={form.processing}>حفظ</Button>
                </>
            }>
                <form id="pkg-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="اسم الباقة" error={form.errors.name} required className="sm:col-span-2">
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus />
                    </Field>
                    <Field label="عدد الجلسات" error={form.errors.sessions_count}>
                        <Input type="number" min={1} value={form.data.sessions_count} onChange={(e) => form.setData('sessions_count', e.target.value)} />
                    </Field>
                    <Field label="مدة الجلسة (دقيقة)" error={form.errors.session_minutes}>
                        <Input type="number" min={15} max={240} value={form.data.session_minutes} onChange={(e) => form.setData('session_minutes', e.target.value)} />
                    </Field>
                    <Field label={`السعر (${currency})`} error={form.errors.price} hint={perSession ? `≈ ${money(perSession, currency)} للجلسة` : undefined}>
                        <Input type="number" step="0.001" min={0} dir="ltr" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} required />
                    </Field>
                    <Field label="الصلاحية (يوم)" error={form.errors.validity_days} hint="تبدأ من تاريخ التفعيل بعد الدفع">
                        <Input type="number" min={1} value={form.data.validity_days} onChange={(e) => form.setData('validity_days', e.target.value)} />
                    </Field>
                    <Field label="المدربة" error={form.errors.trainer_id} className="sm:col-span-2">
                        <Select value={form.data.trainer_id ?? ''} onChange={(e) => form.setData('trainer_id', e.target.value || null)} placeholder="أي مدربة">
                            {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="الوصف" error={form.errors.description} className="sm:col-span-2">
                        <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} />
                    </Field>
                    <div className="space-y-2 sm:col-span-2">
                        <Toggle checked={form.data.is_public} onChange={(v) => form.setData('is_public', v)} label="تظهر في الموقع" />
                        <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="متاحة للبيع" />
                    </div>
                </form>
            </Modal>
        </ClubLayout>
    );
}
