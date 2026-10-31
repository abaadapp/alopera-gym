import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CircleCheck, ClipboardCheck, ClipboardList, FilePlus2, Phone, Plus, Truck, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, DataTable, Field, Input, Modal, PageHeader, RowActions, StatCard, Textarea, Toggle, type Column, type TableFilter, type TableSort } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

export interface SupplierRow { id: number; name: string; contact_name: string | null; phone: string | null; email: string | null; address: string | null; tax_number: string | null; notes: string | null; is_active: boolean; payment_terms_days: number | null; purchase_orders_count?: number; due?: number | null; can_delete?: boolean }

interface Props {
    suppliers: Paginated<SupplierRow>;
    summary: { total: number; with_orders: number; active: number; due: number | null };
    filters: Record<string, string | undefined>;
}

/** الموردون: who the club buys from, how to reach them, their orders and what is owed to them. */
export default function Suppliers({ suppliers, summary, filters }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const money_ = summary.due !== null;
    const [editing, setEditing] = useState<SupplierRow | 'new' | null>(null);

    const columns: Column<SupplierRow>[] = [
        {
            key: 'name',
            header: 'المورّد',
            cell: (s) => (
                <span>
                    <Link href={route('club.purchases.suppliers.show', s.id)} className="text-ink hover:text-ink hover:underline">{s.name}</Link>
                    {!s.is_active && <Badge tone="amber" className="ms-2">موقوف</Badge>}
                    {s.tax_number && <span className="block text-[12px] text-[#9ca3af]">الرقم الضريبي <span className="num">{s.tax_number}</span></span>}
                </span>
            ),
        },
        { key: 'phone', header: 'الهاتف', cell: (s) => <span dir="ltr" className="text-[#6b7280] num">{s.phone ? `+${s.phone}` : '—'}</span> },
        { key: 'contact', header: 'الشخص المسؤول', cell: (s) => s.contact_name || '—' },
        { key: 'email', header: 'البريد', cell: (s) => <span dir="ltr" className="text-[#6b7280]">{s.email || '—'}</span> },
        { key: 'orders', header: 'أوامر الشراء', align: 'end', cell: (s) => <Badge tone="blue">{`${number(s.purchase_orders_count ?? 0)} أمر`}</Badge> },
        ...(money_ ? [{ key: 'due', header: 'المستحق', align: 'end' as const, cell: (s: SupplierRow) => ((s.due ?? 0) > 0 ? <span className="tabular-nums font-medium text-[#b45309]">{money(s.due ?? 0, currency)}</span> : <span className="text-[#d4d4d8]">—</span>) }] : []),
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (s) => (
                <RowActions
                    label={s.name}
                    show={route('club.purchases.suppliers.show', s.id)}
                    edit={can('suppliers.manage') ? () => setEditing(s) : undefined}
                    extra={[
                        ...(s.phone ? [{ label: 'اتصال', icon: <Phone />, href: `tel:+${s.phone}`, external: true }] : []),
                        ...(can('purchases.manage') && s.is_active ? [{ label: 'أمر شراء جديد', icon: <FilePlus2 />, href: route('club.purchases.create', { supplier: s.id }) }] : []),
                    ]}
                    destroy={can('suppliers.manage') && s.can_delete ? { url: route('club.purchases.suppliers.destroy', s.id), message: `حذف «${s.name}»؟ لا أوامر شراء ولا سندات له.` } : undefined}
                />
            ),
        },
    ];

    const tableFilters: TableFilter[] = [
        { param: 'status', label: 'الحالة', options: [{ value: 'active', label: 'نشط' }, { value: 'inactive', label: 'موقوف' }, ...(money_ ? [{ value: 'due', label: 'عليه مستحق' }] : [])] },
        { param: 'per_page', label: 'عدد الصفوف', options: ['20', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];
    const sorts: TableSort[] = [{ key: 'name', label: 'المورّد' }, { key: 'orders', label: 'أوامر الشراء' }, ...(money_ ? [{ key: 'due', label: 'المستحق' }] : [])];

    return (
        <ClubLayout title="الموردون">
            <PageHeader
                title="الموردون"
                subtitle="من يورّد للنادي: بيانات التواصل والرقم الضريبي ومدة السداد، وأوامرهم وما لهم."
                actions={<>
                    {can('purchases.view') && <Button variant="outline" href={route('club.purchases.index')} icon={<ClipboardList />}>أوامر الشراء</Button>}
                    {can('suppliers.create') && <Button icon={<Plus />} onClick={() => setEditing('new')}>مورّد جديد</Button>}
                </>}
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="إجمالي الموردين" value={number(summary.total)} icon={<Truck />} tone="violet" />
                <StatCard label="موردون لديهم أوامر" value={number(summary.with_orders)} icon={<ClipboardCheck />} tone="green" />
                {money_
                    ? <StatCard label="المستحق للموردين" value={money(summary.due ?? 0, currency)} icon={<Wallet />} tone="amber" href={route('club.purchases.bills', { state: 'unpaid' })} />
                    : <StatCard label="الموردون النشطون" value={number(summary.active)} icon={<CircleCheck />} tone="blue" />}
            </div>

            <Card padded={false} className="overflow-hidden">
                <DataTable
                    rows={suppliers.data}
                    columns={columns}
                    rowKey={(s) => s.id}
                    params={filters}
                    pagination={suppliers}
                    search={{ placeholder: 'ابحث باسم المورّد أو الهاتف…' }}
                    filters={tableFilters}
                    sorts={sorts}
                    empty={can('suppliers.create') ? 'أضِف أول مورّد لبدء إنشاء أوامر الشراء.' : 'لا موردين بعد.'}
                />
            </Card>

            {editing && <SupplierDialog supplier={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
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
        <Modal open onClose={onClose} title={supplier ? 'تعديل بيانات المورّد' : 'إضافة مورّد جديد'} footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button type="submit" form="supplier-form" loading={form.processing}>{supplier ? 'حفظ' : 'إضافة'}</Button></>}>
            <form id="supplier-form" onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="اسم المورّد" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={160} autoFocus={!supplier} required /></Field>
                    <Field label="الشخص المسؤول" error={form.errors.contact_name}><Input value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} maxLength={120} /></Field>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="الهاتف" error={form.errors.phone}><Input dir="ltr" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                    <Field label="البريد الإلكتروني" error={form.errors.email}><Input dir="ltr" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="الرقم الضريبي" error={form.errors.tax_number}><Input dir="ltr" value={form.data.tax_number} onChange={(e) => form.setData('tax_number', e.target.value)} maxLength={60} /></Field>
                    <Field label="مدة السداد (يوم)" error={form.errors.payment_terms_days} hint="تحدد تاريخ استحقاق سنداته تلقائيًا"><Input type="number" min="0" max="365" dir="ltr" value={form.data.payment_terms_days} onChange={(e) => form.setData('payment_terms_days', e.target.value)} /></Field>
                </div>
                <Field label="العنوان" error={form.errors.address}><Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} maxLength={255} /></Field>
                <Field label="ملاحظات" error={form.errors.notes}><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={1000} /></Field>
                <Toggle label="مورّد نشط" hint="الموقوف لا يظهر في أوامر الشراء الجديدة، ويبقى سجله." checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
            </form>
        </Modal>
    );
}
