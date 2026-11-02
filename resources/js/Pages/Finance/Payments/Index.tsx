import { Link } from '@inertiajs/react';
import { Check, FileDown, Receipt, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { Badge, Button, ConfirmAction, DataTable, Notice, PageHeader, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { label, paymentMethod, paymentStatus, refundStatus } from '@/lib/labels';
import type { Paginated } from '@/types';
import PrintButton from '@/Components/Printing/PrintButton';

interface PaymentRow {
    id: number;
    receipt_number: string | null;
    kind: 'payment' | 'refund';
    method: string;
    status: string;
    amount: string;
    reference: string | null;
    invoice_id: number | null;
    invoice: string | null;
    customer: string | null;
    receiver: string | null;
    paid_at: string | null;
    has_proof: boolean;
    account: string | null;
    from_credit: boolean;
    cancel_reason: string | null;
    credit_left: string | null;
}

type Filters = Record<string, string | undefined> & { status?: string; method?: string; q?: string; per_page?: string };

/** The status tabs, each with its badge's colour. */
const STATUS_TABS: { value: string; label: string; dot: string }[] = [
    { value: 'completed', label: 'مكتملة', dot: '#059669' },
    { value: 'pending', label: 'بانتظار التأكيد', dot: '#d97706' },
    { value: 'rejected', label: 'مرفوضة', dot: '#dc2626' },
    { value: 'cancelled', label: 'ملغاة', dot: '#9ca3af' },
    { value: 'credit', label: 'أرصدة دائنة', dot: '#2563eb' },
];

/*
 * الدفعات والإيصالات on the panel's one list table: the search, the status tabs, the method filter and the page are in
 * the address and read by the server; the period (above) is on the payment date — except for the customers' credits,
 * listed whenever they were received.
 */
export default function PaymentsIndex({ payments, pendingCount, creditCount, filters, period }: { payments: Paginated<PaymentRow>; pendingCount: number; creditCount: number; filters: Filters; period: Period }) {
    const can = useCan();
    const currency = useCurrency();
    const params = { ...filters, ...periodParams(period) };

    const Amount = ({ p }: { p: PaymentRow }) => (
        <span className={cn('font-medium num', p.kind === 'refund' && 'text-red-600', p.status === 'cancelled' && 'text-muted line-through')}>
            {p.kind === 'refund' ? '- ' : ''}{money(p.amount, currency)}
            {p.credit_left !== null && Number(p.credit_left) > 0 && <span className="block text-xs font-normal text-[#1d4ed8]">رصيد {money(p.credit_left, currency)}</span>}
        </span>
    );
    const Status = ({ p }: { p: PaymentRow }) => {
        const [text, tone] = label(p.kind === 'refund' ? refundStatus : paymentStatus, p.status);
        return (
            <>
                <Badge tone={tone}>{text}</Badge>
                {p.cancel_reason && <p className="mt-0.5 max-w-48 truncate text-xs text-muted" title={p.cancel_reason}>{p.cancel_reason}</p>}
            </>
        );
    };
    const Method = ({ p }: { p: PaymentRow }) => (
        <>
            {p.kind === 'refund' && <Badge tone="red" className="me-1">رد</Badge>}
            {paymentMethod[p.method] ?? p.method}
            {p.account && <p className="text-xs text-muted">{p.kind === 'refund' ? 'من' : 'إلى'} {p.account}</p>}
            {p.reference && <p className="text-xs text-muted num">{p.reference}</p>}
        </>
    );
    const Actions = ({ p }: { p: PaymentRow }) => (
        <div className="flex justify-end gap-1">
            {p.has_proof && <Button variant="ghost" size="sm" href={route('club.payments.proof', p.id)} external icon={<FileDown className="size-3.5" />} aria-label="الإثبات" />}
            {p.status === 'completed' && <PrintButton variant="ghost" size="sm" url={route('club.payments.receipt', p.id)} title={`receipt-${p.id}`} icon={<Receipt className="size-3.5" />} aria-label="الإيصال" />}
            {p.status === 'pending' && can('payments.confirm') && (p.kind === 'refund' ? (
                <>
                    <ConfirmAction url={route('club.payments.confirm', p.id)} title="تأكيد تنفيذ الرد" message="أكّد بعد تنفيذ الرد فعلًا (من البنك أو لوحة Paymob)." reason="مرجع التنفيذ (رقم العملية)" reasonName="reference" reasonOptional confirmLabel="تأكيد التنفيذ" tone="primary" variant="success" icon={<Check className="size-3.5" />}>تأكيد</ConfirmAction>
                    <ConfirmAction url={route('club.payments.reject', p.id)} title="إلغاء طلب الرد" reason="سبب الإلغاء" confirmLabel="إلغاء الطلب" icon={<X className="size-3.5" />}>إلغاء</ConfirmAction>
                </>
            ) : (
                <>
                    <ConfirmAction url={route('club.payments.confirm', p.id)} title="تأكيد التحويل" message="تأكد من وصول المبلغ في كشف الحساب قبل التأكيد." confirmLabel="تأكيد" tone="primary" variant="success" icon={<Check className="size-3.5" />}>تأكيد</ConfirmAction>
                    <ConfirmAction url={route('club.payments.reject', p.id)} title="رفض التحويل" reason="سبب الرفض" confirmLabel="رفض" icon={<X className="size-3.5" />}>رفض</ConfirmAction>
                </>
            ))}
        </div>
    );
    const Customer = ({ p }: { p: PaymentRow }) => (
        <>
            <p className="font-medium">{p.customer ?? '—'}</p>
            {p.invoice_id && <Link href={route('club.invoices.show', p.invoice_id)} className="text-xs text-[#1d4ed8] num hover:underline">{p.invoice}</Link>}
        </>
    );

    const columns: Column<PaymentRow>[] = [
        { key: 'receipt', header: 'الإيصال', cell: (p) => <span className="num">{p.receipt_number ?? '—'}</span> },
        { key: 'paid_at', header: 'التاريخ', cell: (p) => <span className="whitespace-nowrap text-xs text-muted num">{p.paid_at ?? '—'}</span> },
        { key: 'customer', header: 'العميل / الفاتورة', cell: (p) => <Customer p={p} /> },
        { key: 'method', header: 'الطريقة والحساب', cell: (p) => <Method p={p} /> },
        { key: 'amount', header: 'المبلغ', align: 'end', cell: (p) => <Amount p={p} /> },
        { key: 'status', header: 'الحالة', cell: (p) => <Status p={p} /> },
        { key: 'receiver', header: 'استلم', cell: (p) => <span className="text-xs text-muted">{p.receiver ?? '—'}</span> },
        { key: 'actions', header: '', align: 'end', cell: (p) => <Actions p={p} /> },
    ];

    const tableFilters: TableFilter[] = [
        { param: 'status', label: 'الحالة', tabs: true, options: STATUS_TABS.map((s) => ({ ...s, label: s.value === 'pending' && pendingCount ? `${s.label} (${pendingCount})` : s.value === 'credit' && creditCount ? `${s.label} (${creditCount})` : s.label })) },
        { param: 'method', label: 'طريقة الدفع', options: Object.entries(paymentMethod).map(([value, text]) => ({ value, label: text })) },
        { param: 'per_page', label: 'عدد الصفوف', options: ['30', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="الدفعات والإيصالات">
            <PageHeader title="الدفعات والإيصالات" subtitle="كل المبالغ المستلمة والمردودة، والحساب الذي دخلته أو خرجت منه" />

            {pendingCount > 0 && filters.status !== 'pending' && (
                <Notice tone="amber" href={route('club.payments.index', { status: 'pending' })} className="mb-4">
                    <span className="font-semibold num">{pendingCount}</span> بانتظار التأكيد (تحويل وارد أو رد بانتظار التنفيذ) — اضغط للمراجعة
                </Notice>
            )}
            {creditCount > 0 && filters.status !== 'credit' && (
                <Notice tone="blue" href={route('club.payments.index', { status: 'credit' })} className="mb-4">
                    <span className="font-semibold num">{creditCount}</span> دفعة فيها رصيد دائن للعميل (مبلغ وصل ولم يُطبَّق على فاتورته) — خصّصه لفاتورة أخرى أو ردّه من صفحة الفاتورة
                </Notice>
            )}

            {filters.status !== 'credit' && <PeriodTabs period={period} params={filters} />}

            <DataTable
                rows={payments.data}
                columns={columns}
                rowKey={(p) => p.id}
                params={params}
                pagination={payments as unknown as PageMeta}
                search={{ placeholder: 'رقم الإيصال أو الفاتورة أو اسم العميل' }}
                filters={tableFilters}
                empty="لا توجد دفعات"
                card={(p) => (
                    <div className="space-y-1.5 px-4 py-3 text-[13px]">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0"><Customer p={p} /></div>
                            <Amount p={p} />
                        </div>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted">
                            <span className="num">{p.receipt_number ?? '—'}</span>
                            <span className="num">{p.paid_at}</span>
                            <span><Method p={p} /></span>
                        </div>
                        <div className="flex items-center justify-between gap-2"><div><Status p={p} /></div><Actions p={p} /></div>
                    </div>
                )}
            />
        </ClubLayout>
    );
}
