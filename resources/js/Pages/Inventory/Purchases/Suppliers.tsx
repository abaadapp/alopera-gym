import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Truck, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, EmptyState, Field, Input, Modal, PageHeader, Pagination, StatCard, Textarea } from '@/Components/ui';
import { DropdownItem, DropdownSeparator } from '@/Components/ui/Dropdown';
import { ConfirmDelete, RowMenu, Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

export interface SupplierRow { id: number; name: string; contact_name: string | null; phone: string | null; email: string | null; address: string | null; tax_number: string | null; notes: string | null; is_active: boolean; payment_terms_days: number | null; purchase_orders_count?: number; due?: number }

/** الموردون: who the club buys from, how to reach them, their orders and what is owed to them. */
export default function Suppliers({ suppliers, summary, filters }: { suppliers: Paginated<SupplierRow>; summary: { total: number; with_orders: number; due: number }; filters: { q: string } }) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<SupplierRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<SupplierRow | null>(null);

    return (
        <ClubLayout title="الموردون">
            <PageHeader title="الموردون" subtitle="من يشتري منهم النادي: بيانات التواصل والرقم الضريبي ومدة السداد، وأوامرهم وما لهم" actions={<Button icon={<Plus />} onClick={() => setEditing('new')}>مورد جديد</Button>} />
            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="الموردون" value={number(summary.total)} icon={<Users />} tone="gray" />
                <StatCard label="لهم أوامر شراء" value={number(summary.with_orders)} icon={<Truck />} tone="blue" />
                <StatCard label="مستحق للموردين" value={money(summary.due, currency)} icon={<Wallet />} tone={summary.due > 0 ? 'amber' : 'green'} href={route('club.purchases.bills', { state: 'unpaid' })} />
            </div>
            <Card padded={false} className="overflow-hidden">
                <Toolbar><ToolbarSearch value={filters.q} placeholder="ابحث بالاسم أو الهاتف أو المسؤول" onSearch={(q) => visit('club.purchases.suppliers', filters, { q })} /></Toolbar>
                {suppliers.data.length === 0 ? (
                    <EmptyState icon={<Users className="size-6" />} title="لا يوجد موردون بعد" description="أضف الموردين لتنشئ لهم أوامر الشراء وتسجل فواتيرهم." action={<Button size="sm" icon={<Plus />} onClick={() => setEditing('new')}>مورد جديد</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>المورد</th><th>الهاتف</th><th>المسؤول</th><th>الرقم الضريبي</th><th>أوامر الشراء</th><th>المستحق</th><th className="w-12" /></tr></thead>
                            <tbody>
                                {suppliers.data.map((s) => (
                                    <tr key={s.id} className={cn(!s.is_active && 'text-[#9ca3af]')}>
                                        <td><Link href={route('club.purchases.suppliers.show', s.id)} className="font-medium text-ink hover:underline">{s.name}</Link>{!s.is_active && <Badge className="ms-2">موقوف</Badge>}{s.email && <p className="text-[11px] text-[#9ca3af]">{s.email}</p>}</td>
                                        <td className="text-[13px] num" dir="ltr">{s.phone ? `+${s.phone}` : '—'}</td>
                                        <td className="text-[13px]">{s.contact_name ?? '—'}</td>
                                        <td className="text-[13px] num">{s.tax_number ?? '—'}</td>
                                        <td><Badge tone="gray">{number(s.purchase_orders_count ?? 0)}</Badge></td>
                                        <td className={cn('num', (s.due ?? 0) > 0 ? 'font-medium text-[#b45309]' : 'text-[#9ca3af]')}>{(s.due ?? 0) > 0 ? money(s.due ?? 0, currency) : '—'}</td>
                                        <td className="text-end">
                                            <RowMenu label={`إجراءات ${s.name}`}>
                                                {(close) => <>
                                                    <DropdownItem href={route('club.purchases.suppliers.show', s.id)}><Truck /> السجل والمستحقات</DropdownItem>
                                                    <DropdownItem onClick={() => { close(); setEditing(s); }}><Pencil /> تعديل</DropdownItem>
                                                    <DropdownSeparator />
                                                    <DropdownItem destructive onClick={() => { close(); setDeleting(s); }}><Trash2 /> حذف</DropdownItem>
                                                </>}
                                            </RowMenu>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={suppliers} />
            </Card>
            {editing && <SupplierDialog supplier={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            <ConfirmDelete target={deleting} url={deleting && route('club.purchases.suppliers.destroy', deleting.id)} title={`حذف «${deleting?.name}»`} message="يُحذف المورد إن لم تكن له أوامر شراء أو سندات؛ وإلا أوقفه ليبقى سجله." onClose={() => setDeleting(null)} />
        </ClubLayout>
    );
}

export function SupplierDialog({ supplier, onClose }: { supplier: SupplierRow | null; onClose: () => void }) {
    const form = useForm({ name: supplier?.name ?? '', contact_name: supplier?.contact_name ?? '', phone: supplier?.phone ?? '', email: supplier?.email ?? '', address: supplier?.address ?? '', tax_number: supplier?.tax_number ?? '', payment_terms_days: supplier?.payment_terms_days != null ? String(supplier.payment_terms_days) : '', notes: supplier?.notes ?? '', is_active: supplier?.is_active ?? true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, payment_terms_days: d.payment_terms_days || null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (supplier) form.put(route('club.purchases.suppliers.update', supplier.id), options);
        else form.post(route('club.purchases.suppliers.store'), options);
    };

    return (
        <Modal open onClose={onClose} size="lg" title={supplier ? `تعديل «${supplier.name}»` : 'مورد جديد'} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="supplier-form" loading={form.processing}>حفظ</Button></>}>
            <form id="supplier-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="اسم المورد" error={form.errors.name} required className="sm:col-span-2"><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={160} autoFocus={!supplier} /></Field>
                <Field label="المسؤول" error={form.errors.contact_name}><Input value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} maxLength={120} /></Field>
                <Field label="الهاتف" error={form.errors.phone}><Input dir="ltr" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                <Field label="البريد الإلكتروني" error={form.errors.email}><Input dir="ltr" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                <Field label="الرقم الضريبي" error={form.errors.tax_number}><Input dir="ltr" value={form.data.tax_number} onChange={(e) => form.setData('tax_number', e.target.value)} maxLength={60} /></Field>
                <Field label="العنوان" error={form.errors.address}><Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} maxLength={255} /></Field>
                <Field label="مدة السداد (يوم)" error={form.errors.payment_terms_days} hint="تحدد تاريخ استحقاق سنداته تلقائيًا"><Input type="number" min="0" max="365" dir="ltr" value={form.data.payment_terms_days} onChange={(e) => form.setData('payment_terms_days', e.target.value)} /></Field>
                <Field label="ملاحظات" error={form.errors.notes} className="sm:col-span-2"><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={1000} /></Field>
                <Checkbox label="مورد نشط" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
            </form>
        </Modal>
    );
}
