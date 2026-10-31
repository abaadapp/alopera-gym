import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { FileText, Receipt, ShoppingCart, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, EmptyState, PageHeader, Select, StatCard } from '@/Components/ui';
import { Toolbar, visit } from '@/Components/Products/parts';
import { BranchPicker, ORDER_TONES, Pill, type BranchFilterState } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';

interface Row { key: string; source: 'order' | 'bill'; id: number; date: string; reference: string; supplier: string | null; branch: string | null; status: string; status_label: string; total: string; received: string | null; paid: number; due: number; billed: boolean }

const monthName = (m: string) => new Intl.DateTimeFormat('ar', { month: 'long', year: 'numeric' }).format(new Date(`${m}-01T00:00:00`));

/**
 * قائمة المشتريات: what was bought in a month — purchase orders, and supplier bills that have no order (a bill on an
 * order is the same purchase, so it is not listed twice) — with what arrived and what is paid or still owed.
 */
export default function Register({ rows, summary, filters, months, suppliers, branch }: { rows: Row[]; summary: { total: number; count: number; due: number }; filters: { month: string; supplier: string }; months: string[]; suppliers: { id: number; name: string }[]; branch: BranchFilterState }) {
    const currency = useCurrency();
    const [q, setQ] = useState('');
    const [source, setSource] = useState('');
    const go = (change: Record<string, string>) => visit('club.purchases.register', { ...filters, branch: branch.locked ? undefined : branch.value }, change);
    const shown = useMemo(() => rows.filter((r) => (!source || r.source === source) && (!q || r.reference.toLowerCase().includes(q.toLowerCase()) || (r.supplier ?? '').includes(q))), [rows, source, q]);

    return (
        <ClubLayout title="قائمة المشتريات">
            <PageHeader title="قائمة المشتريات" subtitle="ما اشتراه النادي: أوامر الشراء، وسندات الموردين التي بلا أمر شراء — دون احتساب الشراء مرتين" />
            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label={filters.month === 'all' ? 'المشتريات' : `مشتريات ${monthName(filters.month)}`} value={money(summary.total, currency)} icon={<ShoppingCart />} tone="gray" />
                <StatCard label="عدد المستندات" value={number(summary.count)} icon={<FileText />} tone="gray" />
                <StatCard label="مستحق للموردين" value={money(summary.due, currency)} hint="كل السندات المعتمدة غير المسددة" icon={<Wallet />} tone={summary.due > 0 ? 'amber' : 'green'} href={route('club.purchases.bills', { state: 'unpaid' })} />
            </div>
            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <label className="flex w-full items-center gap-2 sm:w-auto sm:min-w-[14rem]">
                        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث بالمرجع أو المورد" aria-label="بحث" className="h-9 w-full border-0 bg-transparent p-0 text-sm placeholder:text-[#9ca3af] focus:outline-none focus:ring-0" />
                    </label>
                    <Select className="h-9 w-40 py-1.5 text-[13px]" value={filters.month} onChange={(e) => go({ month: e.target.value })} aria-label="الشهر">
                        {months.map((m) => <option key={m} value={m}>{monthName(m)}</option>)}
                        <option value="all">كل الفترات</option>
                    </Select>
                    <Select className="h-9 w-40 py-1.5 text-[13px]" value={filters.supplier} onChange={(e) => go({ supplier: e.target.value })} aria-label="المورد">
                        <option value="">كل الموردين</option>
                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                    <Select className="h-9 w-36 py-1.5 text-[13px]" value={source} onChange={(e) => setSource(e.target.value)} aria-label="المصدر">
                        <option value="">كل المصادر</option><option value="order">أوامر الشراء</option><option value="bill">سندات بلا أمر</option>
                    </Select>
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>
                {shown.length === 0 ? (
                    <EmptyState icon={<Receipt className="size-6" />} title="لا مشتريات في هذه الفترة" description="تدخل المشتريات بطريقتين: أمر شراء يُستلم على دفعات، أو سند مورد لشراء مباشر بلا أمر." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>التاريخ</th><th>المرجع</th><th>المورد</th><th>الفرع</th><th>المصدر</th><th>الحالة</th><th>الإجمالي</th><th>المستلم</th><th>المدفوع / المستحق</th></tr></thead>
                            <tbody>
                                {shown.map((r) => (
                                    <tr key={r.key}>
                                        <td className="text-[13px] num">{r.date}</td>
                                        <td><Link href={r.source === 'order' ? route('club.purchases.show', r.id) : route('club.purchases.bills.show', r.id)} className="font-medium text-ink hover:underline num">{r.reference}</Link></td>
                                        <td className="text-[13px]">{r.supplier}</td>
                                        <td className="text-[13px]">{r.branch ?? '—'}</td>
                                        <td><Badge tone={r.source === 'order' ? 'blue' : 'gray'}>{r.source === 'order' ? 'أمر شراء' : 'سند مورد'}</Badge></td>
                                        <td>{r.source === 'order' ? <Pill tone={ORDER_TONES[r.status] ?? 'gray'}>{r.status_label}</Pill> : <span className="text-[13px]">{r.status_label}</span>}</td>
                                        <td className="font-medium num">{money(r.total, currency)}</td>
                                        <td className="text-[13px] num">{r.received === null ? '—' : <>{money(r.received, currency)}<span className="block text-[11px] text-[#9ca3af]">المتبقي {money(Math.max(0, Number(r.total) - Number(r.received)), currency)}</span></>}</td>
                                        <td className="text-[13px] num">{r.billed ? <>{money(r.paid, currency)}<span className={cn('block text-[11px]', r.due > 0 ? 'text-[#b45309]' : 'text-[#9ca3af]')}>مستحق {money(r.due, currency)}</span></> : <span className="text-[#9ca3af]">لا سند مورد بعد</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot><tr><td colSpan={6} className="font-semibold">الإجمالي</td><td className="font-semibold num">{money(shown.reduce((t, r) => t + Number(r.total), 0), currency)}</td><td colSpan={2} /></tr></tfoot>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
