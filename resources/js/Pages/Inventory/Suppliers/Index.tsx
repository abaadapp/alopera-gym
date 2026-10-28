import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Building2, Mail, Pencil, Phone, Plus, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Textarea } from '@/Components/ui';

interface Supplier {
    id: number;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    tax_number: string | null;
    notes: string | null;
    is_active: boolean;
    purchase_orders_count: number;
}

export default function SuppliersIndex({ suppliers }: { suppliers: Supplier[] }) {
    const [editing, setEditing] = useState<Supplier | 'new' | null>(null);

    return (
        <ClubLayout title="الموردون">
            <PageHeader title="الموردون" subtitle={`${suppliers.length} مورد`} actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>مورد جديد</Button>} />

            {suppliers.length === 0 ? (
                <Card><EmptyState icon={<Building2 className="size-6" />} title="لا يوجد موردون بعد" action={<Button size="sm" onClick={() => setEditing('new')}>إضافة مورد</Button>} /></Card>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {suppliers.map((s) => (
                        <Card key={s.id}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="truncate font-semibold">{s.name}</p>
                                    {s.contact_name && <p className="text-sm text-muted">{s.contact_name}</p>}
                                </div>
                                {s.is_active ? <Badge tone="green">نشط</Badge> : <Badge>موقوف</Badge>}
                            </div>
                            <div className="mt-3 space-y-1 text-sm">
                                {s.phone && <a href={`tel:${s.phone}`} className="flex items-center gap-2 text-ink/80"><Phone className="size-3.5 text-brand" /><span className="num">{s.phone}</span></a>}
                                {s.email && <a href={`mailto:${s.email}`} className="flex items-center gap-2 text-ink/80"><Mail className="size-3.5 text-brand" />{s.email}</a>}
                                {s.tax_number && <p className="text-xs text-muted">الرقم الضريبي: <span className="num">{s.tax_number}</span></p>}
                            </div>
                            <div className="mt-4 flex items-center justify-between border-t border-line pt-3">
                                <span className="text-xs text-muted"><span className="num">{s.purchase_orders_count}</span> أمر شراء</span>
                                <div className="flex gap-1">
                                    <Button variant="ghost" size="sm" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(s)}>تعديل</Button>
                                    <ConfirmAction url={route('club.suppliers.destroy', s.id)} method="delete" title="حذف المورد" message={`حذف «${s.name}»؟ تبقى أوامر الشراء السابقة.`} confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-3.5 text-red-600" />}>
                                        <span className="sr-only">حذف</span>
                                    </ConfirmAction>
                                </div>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            {editing && <SupplierModal supplier={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function SupplierModal({ supplier, onClose }: { supplier: Supplier | null; onClose: () => void }) {
    const form = useForm({
        name: supplier?.name ?? '',
        contact_name: supplier?.contact_name ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        address: supplier?.address ?? '',
        tax_number: supplier?.tax_number ?? '',
        notes: supplier?.notes ?? '',
        is_active: supplier?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (supplier) form.put(route('club.suppliers.update', supplier.id), options);
        else form.post(route('club.suppliers.store'), options);
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={supplier ? 'تعديل المورد' : 'مورد جديد'}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="supplier-form" loading={form.processing}>حفظ</Button>
                </>
            }
        >
            <form id="supplier-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="اسم المورد" error={form.errors.name} required className="sm:col-span-2">
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                </Field>
                <Field label="مسؤول التواصل" error={form.errors.contact_name}>
                    <Input value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} />
                </Field>
                <Field label="الهاتف" error={form.errors.phone}>
                    <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="البريد" error={form.errors.email}>
                    <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="الرقم الضريبي" error={form.errors.tax_number}>
                    <Input dir="ltr" value={form.data.tax_number} onChange={(e) => form.setData('tax_number', e.target.value)} />
                </Field>
                <Field label="العنوان" error={form.errors.address} className="sm:col-span-2">
                    <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                </Field>
                <Field label="ملاحظات" error={form.errors.notes} className="sm:col-span-2">
                    <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
                <Checkbox label="نشط" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
            </form>
        </Modal>
    );
}
