import { useState } from 'react';
import { History, SlidersHorizontal, TrendingDown, TrendingUp } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Input, PageHeader, Pagination, StatCard } from '@/Components/ui';
import { FilterMenu, Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import AdjustDialog from '@/Components/Inventory/AdjustDialog';
import { BranchPicker, type BranchFilterState } from '@/Components/Inventory/parts';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    at: string;
    product: string | null;
    sku: string | null;
    branch: string | null;
    type: string;
    type_label: string;
    reason: string | null;
    quantity: string;
    unit_cost: string;
    balance: string;
    document: string | null;
    user: string | null;
    note: string | null;
}

type Filters = { q: string; type: string; reason: string; from: string; to: string };

/**
 * سجل المخزون: every movement — purchases received, sales, website orders, voids, counts, damage and loss, transfers
 * — with its date, who, quantity, cost, the branch's balance after it and the document behind it. Rows are never
 * edited; a mistake is put right with an opposite adjustment.
 */
export default function StockMovements({ rows, summary, filters, branch, types, reasons, products }: { rows: Paginated<Row>; summary: { count: number; losses: number; loss_count: number; gains: number }; filters: Filters; branch: BranchFilterState; types: Record<string, string>; reasons: Record<string, string>; products: { id: number; name: string; cost: string }[] }) {
    const currency = useCurrency();
    const can = useCan();
    const [adjusting, setAdjusting] = useState(false);
    const go = (change: Partial<Filters> & { branch?: string }) => visit('club.inventory.movements', { ...filters, branch: branch.locked ? undefined : branch.value }, change);

    return (
        <ClubLayout title="سجل المخزون">
            <PageHeader title="سجل المخزون" subtitle="كل حركة على المخزون ومستندها؛ لا تُعدّل الحركة المسجلة، ويُصحَّح الخطأ بتسوية عكسية" actions={can('inventory.adjust') && <Button icon={<SlidersHorizontal />} onClick={() => setAdjusting(true)}>تعديل مخزون</Button>} />

            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="الحركات" value={number(summary.count)} icon={<History />} tone="gray" />
                <StatCard label="قيمة التالف والفاقد" value={money(summary.losses, currency)} hint={`${number(summary.loss_count)} تسوية`} icon={<TrendingDown />} tone={summary.losses ? 'red' : 'gray'} href={route('club.inventory.movements', { type: 'adjustment' })} />
                <StatCard label="زيادات الجرد والتصحيح" value={money(summary.gains, currency)} icon={<TrendingUp />} tone="green" />
            </div>

            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث بالمنتج أو رقم المستند" onSearch={(q) => go({ q })} />
                    <FilterMenu
                        filters={[
                            { key: 'type', label: 'نوع الحركة', options: Object.entries(types).map(([value, label]) => ({ value, label })) },
                            { key: 'reason', label: 'سبب التسوية', options: Object.entries(reasons).map(([value, label]) => ({ value, label })) },
                        ]}
                        values={filters}
                        onChange={(key, value) => go({ [key]: value })}
                    />
                    <div className="ms-auto flex flex-wrap items-center gap-2">
                        <Input type="date" className="h-9 w-36 py-1.5 text-[13px]" value={filters.from} onChange={(e) => go({ from: e.target.value })} aria-label="من" />
                        <Input type="date" className="h-9 w-36 py-1.5 text-[13px]" value={filters.to} onChange={(e) => go({ to: e.target.value })} aria-label="إلى" />
                        <BranchPicker state={branch} onChange={(value) => go({ branch: value })} />
                    </div>
                </Toolbar>
                {rows.data.length === 0 ? (
                    <EmptyState icon={<History className="size-6" />} title="لا توجد حركات" description="تُسجَّل الحركات تلقائيًا مع البيع والاستلام والجرد والنقل." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>التاريخ والمستند</th><th>المنتج</th><th>الحركة</th><th>الكمية</th><th>التكلفة</th><th>الرصيد بعدها</th><th>الفرع</th><th>الموظف</th></tr></thead>
                            <tbody>
                                {rows.data.map((m) => (
                                    <tr key={m.id}>
                                        <td><p className="text-[13px] num">{m.at}</p><p className="text-[11px] text-[#9ca3af] num">{m.document ?? '—'}</p></td>
                                        <td><p className="max-w-52 truncate font-medium">{m.product}</p>{m.note && <p className="max-w-52 truncate text-[11px] text-[#9ca3af]">{m.note}</p>}</td>
                                        <td className="text-[13px]">{m.type_label}{m.reason && <span className="ms-1 rounded-full bg-[#f2f2f0] px-2 py-0.5 text-[11px] text-[#4b4b4b]">{m.reason}</span>}</td>
                                        <td className={cn('font-semibold num', Number(m.quantity) >= 0 ? 'text-emerald-700' : 'text-red-700')}>{Number(m.quantity) > 0 ? '+' : ''}{number(m.quantity, Number(m.quantity) % 1 ? 3 : 0)}</td>
                                        <td className="text-[13px] text-[#6b7280] num">{money(m.unit_cost, currency)}</td>
                                        <td className="num">{number(m.balance, Number(m.balance) % 1 ? 3 : 0)}</td>
                                        <td className="text-[13px]">{m.branch ?? '—'}</td>
                                        <td className="text-[13px] text-[#6b7280]">{m.user ?? 'النظام'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={rows} />
            </Card>

            {adjusting && <AdjustDialog products={products} branch={branch} reasons={reasons} onClose={() => setAdjusting(false)} />}
        </ClubLayout>
    );
}
