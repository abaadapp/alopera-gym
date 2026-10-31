import { Link } from '@inertiajs/react';
import { ClipboardList, Clock, PackageCheck, Plus, Printer, RefreshCcw, Truck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, EmptyState, PageHeader, Pagination, StatCard } from '@/Components/ui';
import { Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import { BranchPicker, ORDER_TONES, Pill, type BranchFilterState } from '@/Components/Inventory/parts';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface Row { id: number; number: string; status: string; status_label: string; total: string; items_count: number; supplier: string | null; branch: string | null; order_date: string; expected_on: string | null }

/** أوامر الشراء: from draft to approved (sent to the supplier) to received, in part or in full. */
export default function Orders({ orders, summary, reorder, filters, labels, branch }: { orders: Paginated<Row>; summary: { total: number; open: number; received: number; awaiting: number }; reorder: { id: number; name: string; stock_quantity: string; reorder_level: string; suggested: number }[]; filters: { status: string; q: string }; labels: Record<string, string>; branch: BranchFilterState }) {
    const currency = useCurrency();
    const can = useCan();
    const go = (change: Record<string, string>) => visit('club.purchases.index', { ...filters, branch: branch.locked ? undefined : branch.value }, change);

    return (
        <ClubLayout title="أوامر الشراء">
            <PageHeader title="أوامر الشراء" subtitle="أمر الشراء لا يزيد المخزون ولا يسجل دفعًا؛ يزيد المخزون باعتماد الاستلام، والالتزام باعتماد سند المورد" actions={can('purchases.manage') && <Button icon={<Plus />} href={route('club.purchases.create')}>أمر شراء جديد</Button>} />
            <div className="mb-4 grid auto-rows-fr grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="إجمالي الأوامر" value={number(summary.total)} icon={<ClipboardList />} tone="gray" />
                <StatCard label="قيد التنفيذ" value={number(summary.open)} icon={<Clock />} tone="amber" />
                <StatCard label="مستلمة" value={number(summary.received)} icon={<PackageCheck />} tone="green" href={route('club.purchases.index', { status: 'received' })} />
                <StatCard label="قيمة قيد الاستلام" value={money(summary.awaiting, currency)} icon={<Truck />} tone="blue" />
            </div>
            {reorder.length > 0 && can('purchases.manage') && (
                <Card className="mb-4">
                    <CardHeader title="منتجات وصلت لحد إعادة الطلب" subtitle="اقتراح للأمر القادم" actions={<Button size="sm" variant="outline" icon={<RefreshCcw />} href={route('club.purchases.create', { from: 'reorder' })}>أمر شراء بها</Button>} />
                    <div className="flex flex-wrap gap-2">
                        {reorder.map((p) => <span key={p.id} className="rounded-[8px] bg-[#f3f3f1] px-2.5 py-1.5 text-[12px] text-[#4b4b4b]">{p.name} <span className="text-[#b91c1c] num">{number(p.stock_quantity)}</span> / <span className="num">{number(p.reorder_level)}</span> · اقتراح <span className="num">{p.suggested}</span></span>)}
                    </div>
                </Card>
            )}
            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث برقم الأمر أو المورد أو مرجعه" onSearch={(q) => go({ q })} />
                    <div className="flex flex-wrap items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="الحالة">
                        {[['', 'الكل'], ...Object.entries(labels)].map(([key, label]) => (
                            <button key={key || 'all'} type="button" onClick={() => go({ status: key })} aria-pressed={filters.status === key} className={cn('h-8 rounded-[8px] px-3 text-[13px] font-medium transition', filters.status === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>{key === 'ordered' ? 'معتمد' : label}</button>
                        ))}
                    </div>
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>
                {orders.data.length === 0 ? (
                    <EmptyState icon={<ClipboardList className="size-6" />} title="لا توجد أوامر شراء" description="أنشئ أمر شراء لمورد واعتمده، ثم سجّل استلام البضاعة عند وصولها." action={can('purchases.manage') ? <Button size="sm" icon={<Plus />} href={route('club.purchases.create')}>أمر شراء جديد</Button> : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الأمر</th><th>المورد</th><th>الفرع المستلم</th><th>البنود</th><th>الإجمالي</th><th>الحالة</th><th>التسليم المتوقع</th><th /></tr></thead>
                            <tbody>
                                {orders.data.map((o) => (
                                    <tr key={o.id}>
                                        <td><Link href={route('club.purchases.show', o.id)} className="font-medium text-ink hover:underline num">{o.number}</Link><p className="text-[11px] text-[#9ca3af] num">{o.order_date}</p></td>
                                        <td className="text-[13px]">{o.supplier}</td>
                                        <td className="text-[13px]">{o.branch ?? '—'}</td>
                                        <td className="num">{number(o.items_count)}</td>
                                        <td className="font-medium num">{money(o.total, currency)}</td>
                                        <td><Pill tone={ORDER_TONES[o.status] ?? 'gray'}>{o.status_label}</Pill></td>
                                        <td className="text-[13px] num">{o.expected_on ?? '—'}</td>
                                        <td className="text-end"><Button size="sm" variant="ghost" icon={<Printer />} href={route('club.purchases.print', o.id)} external aria-label={`طباعة ${o.number}`} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={orders} />
            </Card>
        </ClubLayout>
    );
}
