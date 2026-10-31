import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Eye, Printer, ShoppingBag, Undo2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { SALE_STATES, SourceBadge, StateBadge, VoidDialog, type SaleRow } from '@/Components/Sales/parts';
import { Badge, Button, Card, DataTable, PageHeader, RowActions, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface Props {
    sales: Paginated<SaleRow>;
    period: Period;
    filters: Record<string, string | undefined>;
    totals: { count: number; sold: string; cancelled: number; website_count: number; website_sold: string };
    options: { sources: Record<string, string>; states: Record<string, string>; methods: Record<string, string>; branches: Record<string, string> };
    can: { refund: boolean; orders: boolean; export: boolean; pos: boolean; websiteOnly: boolean };
}

/**
 * المبيعات — one page for every sale, from the point of sale and the website alike: the period, a search by number,
 * customer, phone or employee, the source, state, branch and payment filters, a sortable table and the export of exactly
 * what is filtered. Each sale opens on its own page (lines, receipt, a void, a website order's steps). The figures and
 * charts are the sales report's, on the reports page. Whoever only handles website orders sees those.
 */
export default function SalesIndex({ sales, period, filters, totals, options, can: allowed }: Props) {
    const currency = useCurrency();
    const mayPrint = useCan()('papers.print');
    const [voiding, setVoiding] = useState<SaleRow | null>(null);
    const branches = Object.keys(options.branches).length > 0;
    const listParams = { ...filters, ...periodParams(period) };
    const exportParams = Object.fromEntries(Object.entries(listParams).filter(([k, v]) => v && !['sort', 'dir', 'page'].includes(k))) as Record<string, string>;

    const columns: Column<SaleRow>[] = [
        { key: 'number', header: 'الرقم', cell: (s) => <Link href={route('club.sales.show', s.id)} className="font-medium text-ink hover:underline num">{s.number}</Link> },
        {
            key: 'customer',
            header: 'العميل',
            cell: (s) => (
                <div className="max-w-40">
                    <div className="truncate text-ink">{s.buyer}</div>
                    {s.phone && <div className="text-[11px] text-[#9ca3af] num" dir="ltr">+{s.phone}</div>}
                </div>
            ),
        },
        ...(allowed.websiteOnly ? [] : [{ key: 'source', header: 'المصدر', cell: (s: SaleRow) => <SourceBadge source={s.source} label={s.source_label} /> }]),
        // A website order has no seller: its source says where it came from. The branch, in a club with several, under it.
        {
            key: 'employee',
            header: branches ? 'الموظف والفرع' : 'الموظف',
            cell: (s) => (
                <div className="max-w-32">
                    {s.cashier ? <span className="block truncate text-[#4b4b4b]" title={s.cashier}>{s.cashier}</span> : <span className="text-[#9ca3af]">—</span>}
                    {branches && s.branch && <span className="block truncate text-[11px] text-[#9ca3af]" title={s.branch}>{s.branch}</span>}
                </div>
            ),
        },
        {
            key: 'items',
            header: 'المنتجات',
            cell: (s) => (
                <div className="max-w-28">
                    <div className="whitespace-nowrap tabular-nums">{number(s.items_count ?? 0)} منتج</div>
                    {s.items_preview && <div className="truncate text-[11px] text-[#9ca3af]" title={s.items_preview}>{s.items_preview}</div>}
                </div>
            ),
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (s) => (
                <div className="whitespace-nowrap">
                    <span className={s.state === 'voided' || s.state === 'cancelled' ? 'tabular-nums text-[#9ca3af] line-through' : 'tabular-nums font-medium'}>{money(s.total, currency)}</span>
                    {Number(s.discount) > 0 && <div className="text-[11px] text-[#9ca3af]">خصم {money(s.discount, currency)}</div>}
                </div>
            ),
        },
        {
            key: 'payment',
            header: 'الدفع',
            cell: (s) => (
                <>
                    <Badge tone="gray">{s.method_label}</Badge>
                    {!s.paid && s.status === 'pending' && <div className="mt-1 text-[11px] text-[#b45309]">غير مدفوع</div>}
                </>
            ),
        },
        { key: 'date', header: 'التاريخ', cell: (s) => <span className="block whitespace-nowrap text-[#6b7280] num" dir="ltr">{s.at.slice(0, 10)}<span className="block text-[11px] text-[#9ca3af]">{s.at.slice(11)}</span></span> },
        { key: 'state', header: 'الحالة', cell: (s) => <StateBadge sale={s} /> },
        {
            key: 'actions',
            header: <span className="sr-only">إجراءات</span>,
            align: 'end',
            cell: (s) => (
                <div className="flex items-center justify-end gap-1">
                    <Button variant="ghost" size="sm" href={route('club.sales.show', s.id)} icon={<Eye />} aria-label={`عرض ${s.number}`}>عرض</Button>
                    <RowActions
                        label={s.number}
                        extra={[
                            ...(mayPrint ? [{ label: 'طباعة الإيصال', icon: <Printer />, href: route('club.sales.receipt', s.id), external: true }] : []),
                            ...(allowed.refund && s.status === 'completed' ? [{ label: 'إلغاء البيع ورد المبلغ', icon: <Undo2 />, danger: true, onSelect: () => setVoiding(s) }] : []),
                        ]}
                    />
                </div>
            ),
        },
    ];

    const tableFilters: TableFilter[] = [
        {
            param: 'status',
            label: 'الحالة',
            tabs: true,
            options: [
                ...Object.entries(options.states).map(([value, label]) => ({ value, label, dot: SALE_STATES[value as SaleRow['state']]?.dot })),
                // The website orders' old page opened here on every open order.
                { value: 'open', label: 'الطلبات المفتوحة', hidden: true },
            ],
        },
        ...(allowed.websiteOnly ? [] : [{ param: 'source', label: 'المصدر', options: Object.entries(options.sources).map(([value, label]) => ({ value, label })) }]),
        ...(branches ? [{ param: 'branch', label: 'الفرع', options: Object.entries(options.branches).map(([value, label]) => ({ value, label })) }] : []),
        { param: 'method', label: 'طريقة الدفع', options: Object.entries(options.methods).map(([value, label]) => ({ value, label })) },
    ];

    return (
        <ClubLayout title="المبيعات">
            <PageHeader
                title="المبيعات"
                subtitle={allowed.websiteOnly ? 'طلبات الموقع الإلكتروني: التجهيز والتسليم والإلغاء' : 'متابعة عمليات البيع من نقطة البيع والموقع الإلكتروني'}
                actions={<>
                    <ExportMenu href={allowed.export ? route('club.sales.export') : null} params={exportParams} title="عمليات البيع" size="md" />
                    {allowed.pos && <Button href={route('club.pos.index')} icon={<ShoppingBag />}>نقطة البيع</Button>}
                </>}
            />

            <PeriodTabs period={period} params={filters} />

            <Card padded={false} className="overflow-hidden">
                <DataTable
                    className="[&_td]:px-3 [&_th]:px-3"
                    rows={sales.data}
                    columns={columns}
                    rowKey={(s) => s.id}
                    params={listParams}
                    pagination={sales as PageMeta}
                    search={{ placeholder: 'ابحث برقم العملية أو العميل أو الهاتف أو الموظف…' }}
                    filters={tableFilters}
                    sorts={[{ key: 'number', label: 'الرقم' }, { key: 'date', label: 'التاريخ' }, { key: 'total', label: 'الإجمالي' }]}
                    empty={allowed.websiteOnly ? 'لا طلبات من الموقع في هذه الفترة' : 'لا عمليات بيع في هذه الفترة'}
                />
                {/* The sum of what is filtered, not of the page in view; cancelled sales are named, not counted. */}
                <div className="border-t border-line px-4 py-3 text-sm text-[#6b7280]">
                    العمليات: <span className="num">{number(totals.count)}</span> — المبيعات المكتملة: <span className="font-semibold text-ink num">{money(totals.sold, currency)}</span>
                    {totals.cancelled > 0 && <span className="text-[#9ca3af]"> (منها <span className="num">{number(totals.cancelled)}</span> ملغاة لا تُحسب)</span>}
                    {!allowed.websiteOnly && totals.website_count > 0 && (
                        <span className="mt-1 block text-[#6d28d9]">منها من الموقع الإلكتروني: <span className="num">{number(totals.website_count)}</span> — <span className="font-semibold num">{money(totals.website_sold, currency)}</span></span>
                    )}
                </div>
            </Card>

            {voiding && <VoidDialog sale={voiding} onClose={() => setVoiding(null)} />}
        </ClubLayout>
    );
}
