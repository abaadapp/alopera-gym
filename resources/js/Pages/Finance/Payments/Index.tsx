import { Link, router } from '@inertiajs/react';
import { Check, FileDown, Receipt, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Input, PageHeader, Pagination, Select, Tabs } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { label, paymentMethod, paymentStatus } from '@/lib/labels';
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
}

interface Filters {
    status?: string;
    method?: string;
    from?: string;
    to?: string;
}

export default function PaymentsIndex({ payments, pendingCount, filters }: { payments: Paginated<PaymentRow>; pendingCount: number; filters: Filters }) {
    const can = useCan();
    const currency = useCurrency();

    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        router.get(route('club.payments.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)) as Record<string, string>, { preserveState: true, replace: true });
    };

    return (
        <ClubLayout title="الدفعات والإيصالات">
            <PageHeader title="الدفعات والإيصالات" subtitle="كل المبالغ المستلمة والمردودة" />

            {pendingCount > 0 && filters.status !== 'pending' && (
                <button onClick={() => apply({ status: 'pending' })} className="mb-4 w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-start text-sm text-amber-800">
                    <span className="font-semibold num">{pendingCount}</span> تحويل بنكي بانتظار التأكيد — اضغط للمراجعة
                </button>
            )}

            <Card padded={false}>
                <div className="flex flex-wrap items-end justify-between gap-3 p-4">
                    <Tabs
                        tabs={[
                            { key: '', label: 'الكل' },
                            { key: 'completed', label: 'مكتملة' },
                            { key: 'pending', label: 'بانتظار التأكيد', count: pendingCount || null },
                            { key: 'rejected', label: 'مرفوضة' },
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
                        <Input type="date" className="w-40" value={filters.from ?? ''} onChange={(e) => apply({ from: e.target.value })} aria-label="من" />
                        <Input type="date" className="w-40" value={filters.to ?? ''} onChange={(e) => apply({ to: e.target.value })} aria-label="إلى" />
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
                                    <th>الطريقة</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>استلم</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {payments.data.map((p) => {
                                    const [statusLabel, tone] = label(paymentStatus, p.status);
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
                                                {p.reference && <p className="text-xs text-muted num">{p.reference}</p>}
                                            </td>
                                            <td className={cn('font-medium num', p.kind === 'refund' && 'text-red-600')}>{p.kind === 'refund' ? '- ' : ''}{money(p.amount, currency)}</td>
                                            <td><Badge tone={tone}>{statusLabel}</Badge></td>
                                            <td className="text-xs text-muted">{p.receiver ?? '—'}</td>
                                            <td>
                                                <div className="flex justify-end gap-1">
                                                    {p.has_proof && <Button variant="ghost" size="sm" href={route('club.payments.proof', p.id)} external icon={<FileDown className="size-3.5" />} aria-label="الإثبات" />}
                                                    {p.status === 'completed' && <Button variant="ghost" size="sm" href={route('club.payments.receipt', p.id)} external icon={<Receipt className="size-3.5" />} aria-label="الإيصال" />}
                                                    {p.status === 'pending' && can('payments.confirm') && (
                                                        <>
                                                            <ConfirmAction url={route('club.payments.confirm', p.id)} title="تأكيد التحويل" message="تأكد من وصول المبلغ في كشف الحساب قبل التأكيد." confirmLabel="تأكيد" tone="primary" variant="success" icon={<Check className="size-3.5" />}>تأكيد</ConfirmAction>
                                                            <ConfirmAction url={route('club.payments.reject', p.id)} title="رفض التحويل" reason="سبب الرفض" confirmLabel="رفض" icon={<X className="size-3.5" />}>رفض</ConfirmAction>
                                                        </>
                                                    )}
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
