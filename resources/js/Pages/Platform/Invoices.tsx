import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CreditCard, FileText } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Button, Card, EmptyState, PageHeader, Pagination, Tabs } from '@/Components/ui';
import { date, money } from '@/lib/format';
import type { Paginated } from '@/types';
import { PAY_METHODS, PLATFORM_INVOICE_STATUS, PayInvoiceModal } from './Tenants/Show';

interface Row {
    id: number;
    number: string;
    status: string;
    amount: string;
    currency: string;
    payment_method: string | null;
    payment_reference: string | null;
    tenant: string | null;
    tenant_id: number;
    period: string;
    due_on: string;
    paid_at: string | null;
}

export default function PlatformInvoices({ invoices, filters }: { invoices: Paginated<Row>; filters: { status?: string } }) {
    const [paying, setPaying] = useState<Row | null>(null);

    return (
        <PlatformLayout title="فواتير المنصة">
            <PageHeader title="فواتير المنصة" subtitle="فواتير اشتراكات الأندية في المنصة" />
            <Card padded={false}>
                <div className="p-4">
                    <Tabs
                        className="border-0"
                        value={filters.status ?? ''}
                        onChange={(key) => router.get(route('platform.invoices.index'), { status: key || undefined }, { preserveState: true })}
                        tabs={[{ key: '', label: 'الكل' }, { key: 'unpaid', label: 'غير مدفوعة' }, { key: 'paid', label: 'مدفوعة' }, { key: 'void', label: 'ملغاة' }]}
                    />
                </div>
                {invoices.data.length === 0 ? (
                    <EmptyState icon={<FileText className="size-6" />} title="لا توجد فواتير" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الرقم</th><th>النادي</th><th>الفترة</th><th>المبلغ</th><th>الاستحقاق</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {invoices.data.map((i) => {
                                    const [l, t] = PLATFORM_INVOICE_STATUS[i.status] ?? [i.status, 'gray' as const];
                                    const overdue = i.status === 'unpaid' && i.due_on < new Date().toISOString().slice(0, 10);
                                    return (
                                        <tr key={i.id}>
                                            <td className="num">{i.number}</td>
                                            <td><Link href={route('platform.tenants.show', i.tenant_id)} className="hover:text-brand">{i.tenant ?? '—'}</Link></td>
                                            <td className="text-xs num">{i.period}</td>
                                            <td className="num">{money(i.amount, i.currency)}</td>
                                            <td className={overdue ? 'text-red-600' : ''}>{date(i.due_on)}</td>
                                            <td>
                                                <Badge tone={t}>{l}</Badge>
                                                {i.paid_at && <p className="mt-1 text-[11px] text-muted">{PAY_METHODS[i.payment_method ?? ''] ?? i.payment_method}{i.payment_reference && <> · <span dir="ltr">{i.payment_reference}</span></>}</p>}
                                            </td>
                                            <td className="text-end">{i.status === 'unpaid' && <Button size="sm" variant="secondary" icon={<CreditCard className="size-3.5" />} onClick={() => setPaying(i)}>تسجيل السداد</Button>}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={invoices} />
            </Card>
            {paying && <PayInvoiceModal invoice={paying} onClose={() => setPaying(null)} />}
        </PlatformLayout>
    );
}
