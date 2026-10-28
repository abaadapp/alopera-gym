import { Link, router } from '@inertiajs/react';
import { ReceiptText, ShoppingBag, Undo2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Input, PageHeader, Pagination } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface SaleRow {
    id: number;
    number: string;
    status: string;
    total: string;
    discount: string;
    payment_method: string;
    invoice_id: number | null;
    cashier: string | null;
    at: string;
    items: { name: string; quantity: string; line_total: string }[];
}

export default function Sales({ sales, filters }: { sales: Paginated<SaleRow>; filters: { date?: string } }) {
    const can = useCan();
    const currency = useCurrency();
    const pageTotal = sales.data.filter((s) => s.status === 'completed').reduce((sum, s) => sum + Number(s.total), 0);

    return (
        <ClubLayout title="عمليات البيع">
            <PageHeader
                title="عمليات البيع"
                subtitle={`مبيعات هذه الصفحة: ${money(pageTotal, currency)}`}
                actions={
                    <>
                        <Input type="date" className="w-44" value={filters.date ?? ''} onChange={(e) => router.get(route('club.pos.sales'), e.target.value ? { date: e.target.value } : {}, { preserveState: true, replace: true })} aria-label="التاريخ" />
                        {can('pos.sell') && <Button href={route('club.pos.index')} icon={<ShoppingBag className="size-4" />}>نقطة البيع</Button>}
                    </>
                }
            />

            <Card padded={false}>
                {sales.data.length === 0 ? (
                    <EmptyState icon={<ReceiptText className="size-6" />} title="لا توجد عمليات بيع" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الرقم</th><th>الوقت</th><th>المنتجات</th><th>الدفع</th><th>الإجمالي</th><th>الكاشير</th><th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {sales.data.map((s) => (
                                    <tr key={s.id} className={s.status === 'voided' ? 'opacity-60' : undefined}>
                                        <td>
                                            {s.invoice_id ? <Link href={route('club.invoices.show', s.invoice_id)} className="font-medium text-brand num">{s.number}</Link> : <span className="num">{s.number}</span>}
                                        </td>
                                        <td className="whitespace-nowrap text-xs text-muted">{s.at}</td>
                                        <td className="max-w-xs">
                                            <p className="truncate text-sm">{s.items.map((i) => `${i.name} × ${number(i.quantity, 3)}`).join('، ')}</p>
                                        </td>
                                        <td>{s.payment_method === 'card' ? 'بطاقة' : 'نقدًا'}</td>
                                        <td className="font-medium num">
                                            {money(s.total, currency)}
                                            {Number(s.discount) > 0 && <p className="text-xs font-normal text-muted">خصم {money(s.discount, currency)}</p>}
                                        </td>
                                        <td className="text-xs text-muted">{s.cashier ?? '—'}</td>
                                        <td>{s.status === 'voided' ? <Badge tone="red">ملغاة</Badge> : <Badge tone="green">مكتملة</Badge>}</td>
                                        <td>
                                            {s.status === 'completed' && can('invoices.void') && (
                                                <ConfirmAction
                                                    url={route('club.pos.void', s.id)}
                                                    title={`إلغاء عملية البيع ${s.number}`}
                                                    message="ستعود المنتجات للمخزون ويُسجَّل رد للمبلغ."
                                                    reason="سبب الإلغاء"
                                                    confirmLabel="إلغاء البيع"
                                                    variant="ghost"
                                                    icon={<Undo2 className="size-3.5" />}
                                                >
                                                    إلغاء
                                                </ConfirmAction>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={sales} />
            </Card>
        </ClubLayout>
    );
}
