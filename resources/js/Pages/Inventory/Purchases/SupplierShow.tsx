import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Pencil } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, PageHeader, StatCard } from '@/Components/ui';
import { BILL_STATES, ORDER_TONES, Pill } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import { SupplierDialog, type SupplierRow } from './Suppliers';

interface Props {
    supplier: SupplierRow;
    orders: { id: number; number: string; status: string; status_label: string; total: string; date: string; branch: string | null }[];
    bills: { id: number; number: string; reference: string; status: string; total: string; paid_total: string; balance: string; state: string | null; issue_date: string; due_date: string | null }[];
    products: { id: number; name: string; quantity: number; last_cost: string; last_date: string }[];
    totals: { purchased: number; due: number; paid: number };
}

/** A supplier: details, what is bought from them (and at what price last), their orders, bills and dues. */
export default function SupplierShow({ supplier, orders, bills, products, totals }: Props) {
    const currency = useCurrency();
    const [editing, setEditing] = useState(false);

    return (
        <ClubLayout title={supplier.name}>
            <PageHeader back={<Link href={route('club.purchases.suppliers')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> الموردون</Link>} title={supplier.name} subtitle={[supplier.contact_name, supplier.phone && `+${supplier.phone}`, supplier.tax_number && `الرقم الضريبي ${supplier.tax_number}`].filter(Boolean).join(' · ') || undefined} actions={<Button variant="outline" icon={<Pencil />} onClick={() => setEditing(true)}>تعديل</Button>} />
            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="المشتريات المعتمدة" value={money(totals.purchased, currency)} tone="gray" />
                <StatCard label="المدفوع" value={money(totals.paid, currency)} tone="green" />
                <StatCard label="المستحق له" value={money(totals.due, currency)} tone={totals.due > 0 ? 'amber' : 'gray'} />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card padded={false} className="overflow-hidden">
                    <div className="p-5 pb-2"><CardHeader title="أوامر الشراء" className="mb-0" /></div>
                    {orders.length === 0 ? <p className="px-5 pb-5 text-[13px] text-muted">لا أوامر بعد.</p> : (
                        <table className="table-base"><tbody>{orders.map((o) => <tr key={o.id}><td><Link href={route('club.purchases.show', o.id)} className="font-medium hover:underline num">{o.number}</Link><span className="block text-[11px] text-[#9ca3af] num">{o.date}</span></td><td className="text-[13px]">{o.branch}</td><td className="num">{money(o.total, currency)}</td><td><Pill tone={ORDER_TONES[o.status] ?? 'gray'}>{o.status_label}</Pill></td></tr>)}</tbody></table>
                    )}
                </Card>
                <Card padded={false} className="overflow-hidden">
                    <div className="p-5 pb-2"><CardHeader title="السندات والمستحقات" className="mb-0" /></div>
                    {bills.length === 0 ? <p className="px-5 pb-5 text-[13px] text-muted">لا سندات بعد.</p> : (
                        <table className="table-base"><tbody>{bills.map((b) => { const [label, tone] = BILL_STATES[b.status === 'approved' ? b.state ?? 'unpaid' : b.status] ?? ['—', 'gray']; return <tr key={b.id}><td><Link href={route('club.purchases.bills.show', b.id)} className="font-medium hover:underline num">{b.reference}</Link><span className="block text-[11px] text-[#9ca3af] num">{b.issue_date}{b.due_date ? ` · يستحق ${b.due_date}` : ''}</span></td><td className="num">{money(b.total, currency)}</td><td className="num text-[#b45309]">{Number(b.balance) > 0 ? money(b.balance, currency) : ''}</td><td><Pill tone={tone}>{label}</Pill></td></tr>; })}</tbody></table>
                    )}
                </Card>
            </div>
            <Card padded={false} className="mt-4 overflow-hidden">
                <div className="p-5 pb-2"><CardHeader title="المنتجات المشتراة منه" subtitle="من أوامر الشراء، بآخر سعر" className="mb-0" /></div>
                {products.length === 0 ? <p className="px-5 pb-5 text-[13px] text-muted">لم يُشترَ منه شيء بعد.</p> : (
                    <table className="table-base"><thead><tr><th>المنتج</th><th>الكمية بوحدة المخزون</th><th>آخر سعر</th><th>آخر شراء</th></tr></thead><tbody>{products.map((p) => <tr key={p.id}><td className="font-medium">{p.name}</td><td className="num">{number(p.quantity, p.quantity % 1 ? 3 : 0)}</td><td className="num">{money(p.last_cost, currency)}</td><td className="text-[13px] num">{p.last_date}</td></tr>)}</tbody></table>
                )}
            </Card>
            {editing && <SupplierDialog supplier={supplier} onClose={() => setEditing(false)} />}
        </ClubLayout>
    );
}
