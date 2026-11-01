import { Link, router } from '@inertiajs/react';
import { Check, FileDown, Receipt, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { Badge, Button, Card, ConfirmAction, EmptyState, Input, Notice, PageHeader, Pagination, Select, Tabs } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { label, paymentMethod, paymentStatus, refundStatus } from '@/lib/labels';
import type { Paginated } from '@/types';

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

interface Filters {
    status?: string;
    method?: string;
}

export default function PaymentsIndex({ payments, pendingCount, creditCount, filters, period }: { payments: Paginated<PaymentRow>; pendingCount: number; creditCount: number; filters: Filters; period: Period }) {
    const can = useCan();
    const currency = useCurrency();

    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, ...periodParams(period), ...patch };
        router.get(route('club.payments.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)) as Record<string, string>, { preserveState: true, replace: true });
    };

    return (
        <ClubLayout title="الدفعات والإيصالات">
            <PageHeader title="الدفعات والإيصالات" subtitle="كل المبالغ المستلمة والمردودة" />

            {pendingCount > 0 && filters.status !== 'pending' && (
                <Notice tone="amber" onClick={() => apply({ status: 'pending' })} className="mb-4">
                    <span className="font-semibold num">{pendingCount}</span> بانتظار التأكيد (تحويل وارد أو رد بانتظار التنفيذ) — اضغط للمراجعة
                </Notice>
            )}
            {creditCount > 0 && filters.status !== 'credit' && (
                <Notice tone="blue" onClick={() => apply({ status: 'credit' })} className="mb-4">
                    <span className="font-semibold num">{creditCount}</span> دفعة فيها رصيد دائن للعميل (مبلغ وصل ولم يُطبَّق على فاتورته) — خصّصه لفاتورة أخرى أو ردّه من صفحة الفاتورة
                </Notice>
            )}

            {filters.status !== 'credit' && <PeriodTabs period={period} params={{ ...filters }} />}

            <Card padded={false}>
                <div className="flex flex-wrap items-end justify-between gap-3 p-4">
                    <Tabs
                        tabs={[
                            { key: '', label: 'الكل' },
                            { key: 'completed', label: 'مكتملة' },
                            { key: 'pending', label: 'بانتظار التأكيد', count: pendingCount || null },
                            { key: 'rejected', label: 'مرفوضة' },
                            { key: 'cancelled', label: 'ملغاة' },
                            { key: 'credit', label: 'أرصدة دائنة', count: creditCount || null },
                        ]}
                        value={filters.status ?? ''}
                        onChange={(key) => apply({ status: key })}
                        className="border-0"
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="w-44">
                            <Select value={filters.method ?? ''} onChange={(e) => apply({ method: e.target.value })} placeholder="كل الطرق">
                                {Object.entries(paymentMethod).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </Select>
                        </div>
                    </div>
                </div>

                {payments.data.length === 0 ? (
                    <EmptyState icon={<Receipt className="size-6" />} title="لا توجد دفعات" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr>
                                    <th>الإيصال</th>
                                    <th>التاريخ</th>
                                    <th>العميل / الفاتورة</th>
                                    <th>الطريقة والحساب</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>استلم</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {payments.data.map((p) => {
                                    const [statusLabel, tone] = label(p.kind === 'refund' ? refundStatus : paymentStatus, p.status);
                                    return (
                                        <tr key={p.id}>
                                            <td className="num">{p.receipt_number ?? '—'}</td>
                                            <td className="whitespace-nowrap text-xs text-muted">{p.paid_at ?? '—'}</td>
                                            <td>
                                                <p className="font-medium">{p.customer ?? '—'}</p>
                                                {p.invoice_id && <Link href={route('club.invoices.show', p.invoice_id)} className="text-xs text-brand num hover:underline">{p.invoice}</Link>}
                                            </td>
                                            <td>
                                                {p.kind === 'refund' && <Badge tone="red" className="me-1">رد</Badge>}
                                                {paymentMethod[p.method] ?? p.method}
                                                {p.account && <p className="text-xs text-muted">{p.kind === 'refund' ? 'من' : 'إلى'} {p.account}</p>}
                                                {p.reference && <p className="text-xs text-muted num">{p.reference}</p>}
                                            </td>
                                            <td className={cn('font-medium num', p.kind === 'refund' && 'text-red-600', p.status === 'cancelled' && 'text-muted line-through')}>
                                                {p.kind === 'refund' ? '- ' : ''}{money(p.amount, currency)}
                                                {p.credit_left !== null && Number(p.credit_left) > 0 && <p className="text-xs font-normal text-[#1d4ed8] no-underline">رصيد {money(p.credit_left, currency)}</p>}
                                            </td>
                                            <td>
                                                <Badge tone={tone}>{statusLabel}</Badge>
                                                {p.cancel_reason && <p className="mt-0.5 max-w-48 truncate text-xs text-muted" title={p.cancel_reason}>{p.cancel_reason}</p>}
                                            </td>
                                            <td className="text-xs text-muted">{p.receiver ?? '—'}</td>
                                            <td>
                                                <div className="flex justify-end gap-1">
                                                    {p.has_proof && <Button variant="ghost" size="sm" href={route('club.payments.proof', p.id)} external icon={<FileDown className="size-3.5" />} aria-label="الإثبات" />}
                                                    {p.status === 'completed' && <Button variant="ghost" size="sm" href={route('club.payments.receipt', p.id)} external icon={<Receipt className="size-3.5" />} aria-label="الإيصال" />}
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
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={payments} />
            </Card>
        </ClubLayout>
    );
}
