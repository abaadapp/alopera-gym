import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, Boxes, PackageX, SlidersHorizontal, Truck, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Notice, PageHeader, Pagination, StatCard } from '@/Components/ui';
import { FilterMenu, ProductThumb, StockBadge, Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import AdjustDialog from '@/Components/Inventory/AdjustDialog';
import { BranchPicker, type BranchFilterState } from '@/Components/Inventory/parts';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface Row {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    image_url: string | null;
    quantity: number;
    min: number;
    cost: string;
    value: number;
    status: 'in' | 'low' | 'out';
    branches: { name: string; quantity: number }[];
}

type Filters = { q: string; status: string; category: string };

interface Props {
    rows: Paginated<Row>;
    summary: { total: number; out: number; low: number; value: number; in_transit: number; in_transit_value: number };
    filters: Filters;
    branch: BranchFilterState;
    categories: Option[];
    products: { id: number; name: string; cost: string }[];
    reasons: Record<string, string>;
}

const qty = (v: number) => number(v, v % 1 ? 3 : 0);

/** المخزون: the stocked products with each one's balance, reorder level, unit cost, value and state, per branch or for all. */
export default function StockIndex({ rows, summary, filters, branch, categories, products, reasons }: Props) {
    const currency = useCurrency();
    const can = useCan();
    const [adjusting, setAdjusting] = useState(false);
    const go = (change: Record<string, string>) => visit('club.inventory.index', { ...filters, branch: branch.locked ? undefined : branch.value }, change);
    const all = branch.value === 'all';

    return (
        <ClubLayout title="المخزون">
            <PageHeader
                title="المخزون"
                subtitle={`أرصدة المنتجات المخزنية ${all ? 'في كل الفروع' : 'في الفرع المختار'} وقيمتها — التصدير من قسم التقارير`}
                actions={can('inventory.adjust') && <Button icon={<SlidersHorizontal />} onClick={() => setAdjusting(true)}>تعديل مخزون</Button>}
            />

            <div className="mb-4 grid auto-rows-fr grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="أصناف مخزنية" value={number(summary.total)} icon={<Boxes />} tone="gray" />
                <StatCard label="قيمة المخزون" value={money(summary.value, currency)} icon={<Wallet />} tone="blue" />
                <StatCard label="منخفضة" value={number(summary.low)} icon={<AlertTriangle />} tone={summary.low ? 'amber' : 'gray'} href={route('club.inventory.index', { ...filters, status: 'low', branch: branch.value })} />
                <StatCard label="نافدة" value={number(summary.out)} icon={<PackageX />} tone={summary.out ? 'red' : 'gray'} href={route('club.inventory.index', { ...filters, status: 'out', branch: branch.value })} />
            </div>
            {summary.in_transit > 0 && (
                <Notice tone="blue" icon={<Truck />} href={route('club.inventory.transfers', { status: 'in_transit' })} className="mb-4">
                    <span className="num">{qty(summary.in_transit)}</span> قطعة قيد النقل بين الفروع (<span className="num">{money(summary.in_transit_value, currency)}</span>) — لا تُحسب في أي رف حتى تُستلم.
                </Notice>
            )}

            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث بالاسم أو SKU أو الباركود" onSearch={(q) => go({ q })} />
                    <div className="flex items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="حالة المخزون">
                        {([['', 'الكل'], ['in', 'متوفر'], ['low', 'منخفض'], ['out', 'نفد']] as const).map(([key, label]) => (
                            <button key={key || 'all'} type="button" onClick={() => go({ status: key })} aria-pressed={filters.status === key} className={cn('h-8 rounded-[8px] px-3 text-[13px] font-medium transition', filters.status === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>{label}</button>
                        ))}
                    </div>
                    <FilterMenu filters={[{ key: 'category', label: 'التصنيف', options: categories.map((c) => ({ value: String(c.id), label: c.name })) }]} values={filters} onChange={(key, value) => go({ [key]: value })} />
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>

                {rows.data.length === 0 ? (
                    <EmptyState icon={<Boxes className="size-6" />} title={filters.q || filters.status || filters.category ? 'لا توجد أصناف مطابقة' : 'لا توجد أصناف في المخزون بعد'} description="الأصناف المخزنية هي المنتجات المربوطة بالمخزون في قسم المنتجات." action={can('products.manage') ? <Button size="sm" variant="outline" href={route('club.products.index')}>قسم المنتجات</Button> : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>المنتج</th><th>الكمية</th><th>الحد الأدنى</th><th>الحالة</th><th>تكلفة الوحدة</th><th>قيمة المخزون</th></tr></thead>
                            <tbody>
                                {rows.data.map((r) => (
                                    <tr key={r.id}>
                                        <td>
                                            <div className="flex items-center gap-3">
                                                <ProductThumb url={r.image_url} />
                                                <div className="min-w-0">
                                                    <p className="max-w-60 truncate font-medium text-ink">{r.name}</p>
                                                    <p className="text-[11px] text-[#9ca3af]"><span className="num">{r.sku ?? '—'}</span>{r.category ? ` · ${r.category}` : ''}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span className={cn('font-semibold num', r.status !== 'in' && 'text-[#b91c1c]')}>{qty(r.quantity)}</span>
                                            {all && r.branches.length > 1 && <span className="mt-0.5 block max-w-56 truncate text-[11px] text-[#9ca3af]">{r.branches.map((b) => `${b.name} ${qty(b.quantity)}`).join(' · ')}</span>}
                                        </td>
                                        <td className="text-[13px] text-[#6b7280] num">{qty(r.min)}</td>
                                        <td><StockBadge track out={r.status === 'out'} low={r.status === 'low'} /></td>
                                        <td className="text-[#6b7280] num">{money(r.cost, currency)}</td>
                                        <td className="font-medium num">{money(r.value, currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={rows} />
            </Card>
            <p className="mt-3 text-[12px] text-[#9ca3af]">تُعدَّل المنتجات وأسعارها وحد إعادة الطلب من <Link href={route('club.products.index')} className="underline">قسم المنتجات</Link>، وتُصدَّر الأرصدة من تقرير «المخزون والأرصدة» في قسم التقارير.</p>

            {adjusting && <AdjustDialog products={products} branch={branch} reasons={reasons} onClose={() => setAdjusting(false)} />}
        </ClubLayout>
    );
}
