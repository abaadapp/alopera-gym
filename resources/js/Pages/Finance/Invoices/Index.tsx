import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Coins, FilePlus2, FileText, Printer, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, Input, PageHeader, Pagination, SearchInput, Select, StatCard, Tabs } from '@/Components/ui';
import { useCan, useCurrency, useShared } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { invoiceStatus, invoiceType, label } from '@/lib/labels';
import type { Paginated } from '@/types';

export interface InvoiceRow {
    id: number;
    number: string;
    type: string;
    status: string;
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

interface Filters {
    status?: string;
    type?: string;
    q?: string;
    from?: string;
    to?: string;
}

const statusTabs = [
    { key: '', label: 'الكل' },
    { key: 'open', label: 'غير مسددة' },
    { key: 'overdue', label: 'متأخرة' },
    { key: 'partially_paid', label: 'مدفوعة جزئيًا' },
    { key: 'paid', label: 'مدفوعة' },
    { key: 'void', label: 'ملغاة' },
];

export default function InvoicesIndex({ invoices, totals, filters }: { invoices: Paginated<InvoiceRow>; totals: { receivable: string; overdue: string; due_soon: string; refund_due: string }; filters: Filters }) {
    const can = useCan();
    const currency = useCurrency();
    const inventory = useShared().modules.sales;

    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        router.get(
            route('club.invoices.index'),
            Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== undefined && v !== null)) as Record<string, string>,
            { preserveState: true, replace: true },
        );
    };

    return (
        <ClubLayout title="الفواتير">
            <PageHeader
                title="الفواتير"
                subtitle={`فواتير الأعضاء والعملاء — العضويات والتدريب والمبيعات والخدمات · ${invoices.total} فاتورة`}
                actions={can('invoices.manage') && (
                    <Button href={route('club.invoices.create')} icon={<FilePlus2 className="size-4" />}>فاتورة يدوية</Button>
                )}
            />

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="إجمالي الذمم" value={money(totals.receivable, currency)} icon={<Wallet className="size-5" />} tone="brand" href={route('club.invoices.index', { status: 'open' })} active={filters.status === 'open'} />
                <StatCard label="المتأخر" value={money(totals.overdue, currency)} icon={<AlertTriangle className="size-5" />} tone="red" href={route('club.invoices.index', { status: 'overdue' })} active={filters.status === 'overdue'} />
                <StatCard label="يستحق خلال ٧ أيام" value={money(totals.due_soon, currency)} icon={<CalendarClock className="size-5" />} tone="amber" />
                <StatCard label="مستحق ردّه للعملاء" value={money(totals.refund_due, currency)} hint="مدفوع أكثر من صافي الفاتورة بعد إشعار دائن" icon={<Coins className="size-5" />} tone="green" />
            </div>

            <Card padded={false}>
                <div className="space-y-3 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Tabs tabs={statusTabs} value={filters.status ?? ''} onChange={(key) => apply({ status: key })} className="border-0" />
                        <SearchInput value={filters.q} placeholder="رقم الفاتورة، الاسم، الهاتف…" extra={{ status: filters.status, type: filters.type, from: filters.from, to: filters.to }} />
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="w-40">
                            <Select value={filters.type ?? ''} onChange={(e) => apply({ type: e.target.value })} placeholder="كل الأنواع">
                                {Object.entries(invoiceType).filter(([k]) => k !== 'pos' || inventory).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </Select>
                        </div>
                        <label className="flex items-center gap-2 text-sm text-muted">
                            من
                            <Input type="date" className="w-40" value={filters.from ?? ''} onChange={(e) => apply({ from: e.target.value })} />
                        </label>
                        <label className="flex items-center gap-2 text-sm text-muted">
                            إلى
                            <Input type="date" className="w-40" value={filters.to ?? ''} onChange={(e) => apply({ to: e.target.value })} />
                        </label>
                    </div>
                </div>

                {invoices.data.length === 0 ? (
                    <EmptyState icon={<FileText className="size-6" />} title="لا توجد فواتير" description="غيّر المرشحات أو أصدر فاتورة جديدة." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr>
                                    <th>الرقم</th>
                                    <th>العميل</th>
                                    <th>النوع</th>
                                    <th>الإصدار</th>
                                    <th>الاستحقاق</th>
                                    <th>الإجمالي</th>
                                    <th>المدفوع</th>
                                    <th>المتبقي</th>
                                    <th>الحالة</th>
                                    <th>أيام التأخير</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.map((inv) => {
                                    const [statusLabel, tone] = label(invoiceStatus, inv.status);
                                    return (
                                        <tr key={inv.id} className="cursor-pointer" onClick={() => router.visit(route('club.invoices.show', inv.id))}>
                                            <td>
                                                <Link href={route('club.invoices.show', inv.id)} className="font-medium hover:text-brand num">{inv.number}</Link>
                                            </td>
                                            <td>
                                                <p className="font-medium">{inv.customer_name}</p>
                                                {inv.customer_phone && <p className="text-xs text-muted num">{inv.customer_phone}</p>}
                                            </td>
                                            <td className="text-muted">{invoiceType[inv.type] ?? inv.type}</td>
                                            <td>{date(inv.issue_date)}</td>
                                            <td className="text-muted">{date(inv.due_date)}</td>
                                            <td className="num">{money(inv.total, currency)}</td>
                                            <td className="num text-emerald-700">{money(inv.paid_total, currency)}</td>
                                            <td className={Number(inv.balance) > 0 && inv.status !== 'void' ? 'font-medium text-red-600 num' : 'text-muted num'}>
                                                {inv.status === 'void' ? '—' : money(inv.balance, currency)}
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <Badge tone={tone}>{statusLabel}</Badge>
                                                    {inv.overdue && <Badge tone="red">متأخرة</Badge>}
                                                </div>
                                            </td>
                                            <td className="num">{inv.days_overdue > 0 ? <span className="font-medium text-red-700">{inv.days_overdue}</span> : <span className="text-[#d4d4d8]">—</span>}</td>
                                            <td onClick={(e) => e.stopPropagation()}>
                                                <a href={route('club.invoices.print', inv.id)} target="_blank" rel="noreferrer" className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" title="طباعة الفاتورة"><Printer className="size-4" /></a>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={invoices} />
            </Card>
        </ClubLayout>
    );
}
