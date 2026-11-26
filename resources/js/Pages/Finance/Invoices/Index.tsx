import { Link } from '@inertiajs/react';
import { BadgeDollarSign, Coins, HandCoins, Plus, ShoppingCart, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { Button, DataTable, PageHeader, StatCard, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import { useCan, useCurrency, useShared } from '@/hooks/useShared';
import { money } from '@/lib/format';
import { invoiceType } from '@/lib/labels';
import { cn } from '@/lib/cn';
import type { Paginated } from '@/types';

export interface InvoiceRow {
    id: number;
    number: string | null;
    type: string;
    status: string;
    state: string;
    customer_name: string;
    customer_phone: string | null;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    total: string;
    paid_total: string;
    currency: string;
    billable_type: string | null;
    billable_id: number | null;
    balance: string;
    issue_date: string;
    due_date: string | null;
    overdue: boolean;
    days_overdue: number;
    /** What it sold, by its lines: membership | pt | program | products | gift | other. */
    categories?: string[];
    /** The till sale or website order it is the invoice of. */
    sale?: { id: number; source: string; number: string } | null;
}

/** Each kind of sale with its colour (the tabs, and the row's tags). */
export const SALE_KINDS: Record<string, string> = {
    membership: 'bg-[#f5f3ff] text-[#6d28d9]',
    pt: 'bg-[#fff7ed] text-[#c2410c]',
    program: 'bg-[#ecfeff] text-[#0e7490]',
    products: 'bg-[#ecfdf5] text-[#047857]',
    gift: 'bg-[#fdf2f8] text-[#be185d]',
    other: 'bg-[#f4f4f5] text-[#52525b]',
};

export const SALE_SOURCES: Record<string, string> = { desk: 'الاستقبال واللوحة', pos: 'نقطة البيع', website: 'متجر الموقع' };

/** The row's one word: late is red, paid green, voided grey, anything still owed blue. */
export const INVOICE_STATES: Record<string, [string, string, string]> = {
    unpaid: ['غير مدفوعة', 'bg-[#eff6ff] text-[#1d4ed8]', '#2563eb'],
    partial: ['مدفوعة جزئيًا', 'bg-[#eff6ff] text-[#1d4ed8]', '#2563eb'],
    paid: ['مدفوعة', 'bg-[#ecfdf5] text-[#047857]', '#059669'],
    overdue: ['متأخرة', 'bg-[#fef2f2] text-[#b91c1c]', '#dc2626'],
    void: ['ملغاة', 'bg-[#f4f4f5] text-[#71717a]', '#9ca3af'],
    credited: ['ملغاة بإشعار دائن', 'bg-[#f4f4f5] text-[#71717a]', '#9ca3af'],
    draft: ['مسودة', 'bg-[#f4f4f5] text-[#71717a]', '#9ca3af'],
};

export function InvoiceState({ state }: { state: string }) {
    const [text, tone] = INVOICE_STATES[state] ?? [state, INVOICE_STATES.void[1]];
    return <span className={cn('whitespace-nowrap rounded-full px-2 py-1 text-[11px]', tone)}>{text}</span>;
}

interface Props {
    invoices: Paginated<InvoiceRow>;
    totals: { receivable: string; overdue: string; due_soon: string; refund_due: string };
    filters: Record<string, string | undefined>;
    period: Period;
    states: Record<string, string>;
    statuses: Record<string, string>;
    draftCount: number;
    summary: { count: number; sold: string; collected: string } | null;
    categories: Record<string, string>;
}

/**
 * المبيعات (owner's decision, 2026-11-25: one section for every sale): every sale with its invoice — memberships and
 * renewals, personal training, programs, products from the till and the website shop, gifts, other services — chosen by
 * what was sold (the tabs) and where (the desk, the till, the website), with the period's figures and what is still owed.
 * Invoices are issued where the sale happens; «إنشاء فاتورة» is for a manual one. A till sale or a website order keeps its
 * own page (its receipt, the order's steps), linked from its row.
 */
export default function InvoicesIndex({ invoices, totals, filters, period, states, statuses, draftCount, summary, categories }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const sales = useShared().modules.sales;
    const m = (v: string | number) => money(v, currency);

    const columns: Column<InvoiceRow>[] = [
        {
            key: 'number',
            header: 'رقم الفاتورة',
            // A draft has no number yet: it opens in the editor.
            cell: (i) => (
                <>
                    {i.state === 'draft'
                        ? <Link href={route('club.invoices.drafts.edit', i.id)} className="font-medium text-[#1d4ed8]">مسودة</Link>
                        : <Link href={route('club.invoices.show', i.id)} className="font-medium text-[#1d4ed8] num">{i.number}</Link>}
                    <div className="text-[11px] text-[#9ca3af]">{invoiceType[i.type] ?? i.type}</div>
                </>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (i) => i.customer_name },
        {
            key: 'sold',
            header: 'ما بيع',
            cell: (i) => (
                <div className="flex flex-wrap items-center gap-1">
                    {(i.categories ?? ['other']).map((c) => <span key={c} className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap', SALE_KINDS[c] ?? SALE_KINDS.other)}>{categories[c] ?? c}</span>)}
                    {i.sale && (
                        <Link href={route('club.sales.show', i.sale.id)} className="text-[11px] whitespace-nowrap text-[#6b7280] underline-offset-2 hover:underline">
                            {i.sale.source === 'website' ? 'طلب الموقع' : 'نقطة البيع'} <span className="num">{i.sale.number}</span>
                        </Link>
                    )}
                </div>
            ),
        },
        { key: 'issue_date', header: 'تاريخ الإصدار', cell: (i) => <span dir="ltr" className="whitespace-nowrap text-[#6b7280] num">{i.issue_date}</span> },
        { key: 'due_date', header: 'تاريخ الاستحقاق', cell: (i) => <span dir="ltr" className="whitespace-nowrap text-[#6b7280] num">{i.due_date ?? '—'}</span> },
        { key: 'total', header: 'الإجمالي', align: 'end', cell: (i) => <span className="whitespace-nowrap tabular-nums">{m(i.total)}</span> },
        { key: 'paid', header: 'المدفوع', align: 'end', cell: (i) => <span className="whitespace-nowrap tabular-nums text-[#047857]">{m(i.paid_total)}</span> },
        { key: 'balance', header: 'المتبقي', align: 'end', cell: (i) => (['void', 'credited'].includes(i.state) ? <span className="text-[#d4d4d8]">—</span> : <span className="whitespace-nowrap tabular-nums font-semibold">{m(i.balance)}</span>) },
        {
            key: 'state',
            header: 'الحالة',
            // Late wins the pill; a partly paid late invoice still says it was partly paid, under it.
            cell: (i) => (
                <>
                    <InvoiceState state={i.state} />
                    {i.state === 'overdue' && i.status === 'partially_paid' && <div className="mt-1 text-[11px] text-[#9ca3af]">مدفوعة جزئيًا</div>}
                </>
            ),
        },
        { key: 'days_overdue', header: 'أيام التأخير', align: 'end', cell: (i) => (i.days_overdue > 0 ? <span className="tabular-nums font-medium text-[#b91c1c]">{i.days_overdue}</span> : <span className="text-[#d4d4d8]">—</span>) },
    ];

    // All on the server: a filter of the page in view would say «no overdue invoices» with twenty on page three.
    const tableFilters: TableFilter[] = [
        // The drafts tab for whoever writes invoices, while there is one (or it is open).
        { param: 'state', label: 'الحالة', tabs: true, options: Object.entries(states).filter(([value]) => value !== 'draft' || (can('invoices.manage') && (draftCount > 0 || filters.state === 'draft'))).map(([value, label]) => ({ value, label: value === 'draft' ? `${label} (${draftCount})` : label, dot: INVOICE_STATES[value]?.[2] })) },
        {
            param: 'status',
            label: 'حالة المستند',
            options: [
                ...Object.entries(statuses).map(([value, label]) => ({ value, label })),
                // Older links to this page still name what they filter.
                { value: 'open', label: 'غير مسددة', hidden: true },
                { value: 'overdue', label: 'متأخرة', hidden: true },
                { value: 'issued', label: 'غير مدفوعة', hidden: true },
                { value: 'partially_paid', label: 'مدفوعة جزئيًا', hidden: true },
                { value: 'paid', label: 'مدفوعة', hidden: true },
            ],
        },
        { param: 'source', label: 'مكان البيع', options: Object.entries(SALE_SOURCES).filter(([k]) => k === 'desk' || sales).map(([value, label]) => ({ value, label })) },
        { param: 'type', label: 'نوع الفاتورة', options: Object.entries(invoiceType).filter(([k]) => k !== 'pos' || sales).map(([value, label]) => ({ value, label })) },
        // The issue date is the page's period (above the table); the due date is chosen in words.
        { param: 'due', label: 'الاستحقاق', options: [{ value: 'past', label: 'فات موعدها' }, { value: 'week', label: 'تستحق خلال ٧ أيام' }, { value: 'month', label: 'تستحق هذا الشهر' }] },
        { param: 'per_page', label: 'عدد الصفوف', options: ['20', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="المبيعات">
            <PageHeader
                title="المبيعات"
                subtitle="كل بيعة بفاتورتها: الاشتراكات والتدريب الشخصي والبرامج والمنتجات والهدايا، من الاستقبال ونقطة البيع والموقع."
                actions={
                    <>
                        {sales && can('pos.sell') && <Button variant="outline" href={route('club.pos.index')} icon={<ShoppingCart />}>نقطة البيع</Button>}
                        {can('invoices.manage') && <Button href={route('club.invoices.create')} icon={<Plus />}>إنشاء فاتورة</Button>}
                    </>
                }
            />

            {/* What was sold: one tap, the rest of the filters kept. */}
            <div className="no-scrollbar -mx-4 mb-4 flex gap-2 overflow-x-auto px-4 lg:mx-0 lg:px-0" role="tablist" aria-label="تصنيف البيع">
                {[['', 'كل المبيعات'] as const, ...Object.entries(categories)].map(([value, label]) => {
                    const active = (filters.category ?? '') === value;
                    return (
                        <Link
                            key={value || 'all'}
                            href={route('club.invoices.index', { ...filters, ...periodParams(period), category: value || undefined, page: undefined })}
                            preserveScroll
                            role="tab"
                            aria-selected={active}
                            className={cn('shrink-0 rounded-full border px-4 py-2 text-[13px] font-medium whitespace-nowrap transition', active ? 'border-ink bg-ink text-white' : 'border-line bg-white text-[#4b4b4b] hover:border-ink')}
                        >
                            {label}
                        </Link>
                    );
                })}
            </div>

            <PeriodTabs period={period} params={filters} />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {summary && <StatCard label="المبيعات في الفترة" value={m(summary.sold)} hint={`${summary.count} فاتورة`} icon={<BadgeDollarSign />} tone="violet" />}
                {summary && <StatCard label="المحصّل منها" value={m(summary.collected)} icon={<HandCoins />} tone="green" />}
                <StatCard label="المتبقي على العملاء" value={m(totals.receivable)} hint={Number(totals.overdue) > 0 ? `منها متأخر ${m(totals.overdue)}` : undefined} icon={<Wallet />} tone="amber" href={route('club.invoices.index', { status: 'open' })} active={filters.status === 'open'} />
                <StatCard label="مستحق ردّه للعملاء" value={m(totals.refund_due)} icon={<Coins />} tone="red" />
            </div>

            <DataTable
                rows={invoices.data}
                columns={columns}
                rowKey={(i) => i.id}
                params={{ ...filters, ...periodParams(period) }}
                pagination={invoices as PageMeta}
                search={{ placeholder: 'رقم الفاتورة أو اسم العميل أو هاتفه' }}
                filters={tableFilters}
                empty="لا فواتير بعد"
            />
        </ClubLayout>
    );
}
