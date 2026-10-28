import { Link } from '@inertiajs/react';
import { ArrowRight, PackageCheck, Phone } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, CardHeader, ConfirmAction, PageHeader, Stat } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money, number } from '@/lib/format';

interface Props {
    order: {
        id: number;
        number: string;
        status: string;
        total: string;
        notes: string | null;
        supplier: { id: number; name: string; phone: string | null } | null;
        order_date: string;
        expected_on: string | null;
        received_at: string | null;
        items: { id: number; quantity: string; unit_cost: string; line_total: string; product: string | null; sku: string | null }[];
    };
}

const statusMap: Record<string, [string, 'gray' | 'blue' | 'green' | 'red']> = {
    draft: ['مسودة', 'gray'],
    ordered: ['بانتظار الاستلام', 'blue'],
    received: ['مستلم', 'green'],
    cancelled: ['ملغى', 'red'],
};

export default function PurchaseShow({ order }: Props) {
    const currency = useCurrency();
    const [text, tone] = statusMap[order.status] ?? [order.status, 'gray'];

    return (
        <ClubLayout title={`أمر شراء ${order.number}`}>
            <PageHeader
                back={<Link href={route('club.purchases.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> المشتريات</Link>}
                title={<span className="flex items-center gap-3"><span className="num">{order.number}</span><Badge tone={tone}>{text}</Badge></span>}
                subtitle={order.supplier?.name}
                actions={
                    order.status === 'ordered' && (
                        <>
                            <ConfirmAction url={route('club.purchases.cancel', order.id)} title="إلغاء أمر الشراء" message="لن يتأثر المخزون." confirmLabel="إلغاء الأمر" size="md">إلغاء</ConfirmAction>
                            <ConfirmAction
                                url={route('club.purchases.receive', order.id)}
                                title="استلام البضاعة"
                                message="ستُضاف الكميات إلى المخزون بتكلفة الشراء (متوسط مرجّح). لا يمكن التراجع."
                                confirmLabel="تأكيد الاستلام"
                                tone="primary"
                                variant="primary"
                                size="md"
                                icon={<PackageCheck className="size-4" />}
                            >
                                استلام البضاعة
                            </ConfirmAction>
                        </>
                    )
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card padded={false} className="lg:col-span-2">
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>المنتج</th><th>الكمية</th><th>تكلفة الوحدة</th><th>الإجمالي</th></tr>
                            </thead>
                            <tbody>
                                {order.items.map((i) => (
                                    <tr key={i.id}>
                                        <td>
                                            <p className="font-medium">{i.product}</p>
                                            {i.sku && <p className="text-xs text-muted num">{i.sku}</p>}
                                        </td>
                                        <td className="num">{number(i.quantity, 3)}</td>
                                        <td className="num">{money(i.unit_cost, currency)}</td>
                                        <td className="font-medium num">{money(i.line_total, currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={3} className="px-4 py-3 text-end font-semibold">الإجمالي</td>
                                    <td className="px-4 py-3 font-semibold num">{money(order.total, currency)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </Card>
                <Card>
                    <CardHeader title="التفاصيل" />
                    <dl className="grid grid-cols-2 gap-4">
                        <Stat label="تاريخ الطلب" value={date(order.order_date)} />
                        <Stat label="الاستلام المتوقع" value={date(order.expected_on)} />
                        <Stat label="تاريخ الاستلام" value={order.received_at ?? '—'} />
                        <Stat label="المورد" value={order.supplier?.name} />
                    </dl>
                    {order.supplier?.phone && (
                        <a href={`tel:${order.supplier.phone}`} className="mt-4 flex items-center gap-2 text-sm text-brand"><Phone className="size-4" /><span className="num">{order.supplier.phone}</span></a>
                    )}
                    {order.notes && <p className="mt-4 rounded-xl bg-canvas p-3 text-sm text-muted">{order.notes}</p>}
                </Card>
            </div>
        </ClubLayout>
    );
}
