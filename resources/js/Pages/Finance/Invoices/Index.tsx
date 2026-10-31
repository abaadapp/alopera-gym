import { Link } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Coins, Plus, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, DataTable, PageHeader, StatCard, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import { useCan, useCurrency, useShared } from '@/hooks/useShared';
import { money } from '@/lib/format';
import { invoiceType } from '@/lib/labels';
import { cn } from '@/lib/cn';
import type { Paginated } from '@/types';

export interface InvoiceRow {
    id: number;
    number: string;
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
}

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
    states: Record<string, string>;
    statuses: Record<string, string>;
}

/**
 * فواتير العملاء: what members and walk-in customers were invoiced for — memberships and renewals, personal training,
 * product sales, other services and manual invoices — and what is still owed on them. Invoices are issued where the
 * sale happens; this page lists them, and «إنشاء فاتورة» is for a manual one.
 */
export default function InvoicesIndex({ invoices, totals, filters, states, statuses }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const sales = useShared().modules.sales;
    const m = (v: string | number) => money(v, currency);

    const columns: Column<InvoiceRow>[] = [
        {
            key: 'number',
            header: 'رقم الفاتورة',
            cell: (i) => (
                <>
                    <Link href={route('club.invoices.show', i.id)} className="font-medium text-[#1d4ed8] num">{i.number}</Link>
                    <div className="text-[11px] text-[#9ca3af]">{invoiceType[i.type] ?? i.type}</div>
                </>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (i) => i.customer_name },
        { key: 'issue_date', header: 'تاريخ الإصدار', cell: (i) => <span dir="ltr" className="text-[#6b7280] num">{i.issue_date}</span> },
        { key: 'due_date', header: 'تاريخ الاستحقاق', cell: (i) => <span dir="ltr" className="text-[#6b7280] num">{i.due_date ?? '—'}</span> },
        { key: 'total', header: 'الإجمالي', align: 'end', cell: (i) => <span className="tabular-nums">{m(i.total)}</span> },
        { key: 'paid', header: 'المدفوع', align: 'end', cell: (i) => <span className="tabular-nums text-[#047857]">{m(i.paid_total)}</span> },
        { key: 'balance', header: 'المتبقي', align: 'end', cell: (i) => (['void', 'credited'].includes(i.state) ? <span className="text-[#d4d4d8]">—</span> : <span className="tabular-nums font-semibold">{m(i.balance)}</span>) },
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
        { param: 'state', label: 'الحالة', tabs: true, options: Object.entries(states).map(([value, label]) => ({ value, label, dot: INVOICE_STATES[value]?.[2] })) },
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
        { param: 'type', label: 'نوع الفاتورة', options: Object.entries(invoiceType).filter(([k]) => k !== 'pos' || sales).map(([value, label]) => ({ value, label })) },
        { param: 'from', label: 'صدرت من', type: 'date' },
        { param: 'to', label: 'صدرت إلى', type: 'date' },
        { param: 'due_from', label: 'تستحق من', type: 'date' },
        { param: 'due_to', label: 'تستحق إلى', type: 'date' },
        { param: 'per_page', label: 'عدد الصفوف', options: ['20', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="فواتير العملاء">
            <PageHeader
                title="فواتير العملاء"
                subtitle="فواتير العضويات والتجديد والتدريب الشخصي والمبيعات والخدمات، وما بقي منها."
                actions={can('invoices.manage') && <Button href={route('club.invoices.create')} icon={<Plus />}>إنشاء فاتورة</Button>}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="إجمالي الذمم" value={m(totals.receivable)} icon={<Wallet />} tone="violet" href={route('club.invoices.index', { status: 'open' })} active={filters.status === 'open'} />
                <StatCard label="المتأخر" value={m(totals.overdue)} icon={<AlertTriangle />} tone="red" href={route('club.invoices.index', { state: 'overdue' })} active={filters.state === 'overdue'} />
                <StatCard label="يستحق خلال ٧ أيام" value={m(totals.due_soon)} icon={<CalendarClock />} tone="amber" />
                <StatCard label="مستحق ردّه للعملاء" value={m(totals.refund_due)} icon={<Coins />} tone="green" />
            </div>

            <DataTable
                rows={invoices.data}
                columns={columns}
                rowKey={(i) => i.id}
                params={filters}
                pagination={invoices as PageMeta}
                search={{ placeholder: 'رقم الفاتورة أو اسم العميل أو هاتفه' }}
                filters={tableFilters}
                empty="لا فواتير بعد"
            />
        </ClubLayout>
    );
}
