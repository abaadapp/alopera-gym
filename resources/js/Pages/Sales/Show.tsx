import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Ban, CheckCheck, FileText, PackageCheck, Phone, Printer, Timer, Undo2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { CancelOrderDialog, DeliverDialog, SourceBadge, StateBadge, VoidDialog, type SaleRow } from '@/Components/Sales/parts';
import { BackLink, Badge, Button, Card, CardHeader, PageHeader } from '@/Components/ui';
import PrintStatus from '@/Components/Printing/PrintStatus';
import usePrint, { type PrintJob } from '@/hooks/usePrint';
import { useCurrency } from '@/hooks/useShared';
import { claimAutoPrint } from '@/lib/printing/print-log';
import { abandonAutoPrint, commitAutoPrint, insideApp, prepareAutoPrint } from '@/lib/printing/printer-service';
import type { PrinterLike } from '@/lib/printing/types';
import { money, number } from '@/lib/format';

interface Item {
    name: string;
    quantity: string;
    unit_price: string;
    line_total: string;
    addons: { name: string; quantity: string; unit_price: string; line_total: string }[];
}

interface Sale extends SaleRow {
    subtotal: string;
    tax: string;
    email: string | null;
    notes: string | null;
    device: string | null;
    reserved_until: string | null;
    delivered_at: string | null;
    delivered_by: string | null;
    voided_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    member: { id: number; member_no: string } | null;
    items: Item[];
    invoice: { id: number; number: string; status: string; total: string; paid_total: string; balance: string } | null;
    payments: { id: number; receipt_number: string | null; kind: string; method_label: string; status: string; amount: string; reference: string | null; paid_at: string | null; receiver: string | null }[];
}

const STEPS = [
    { key: 'new', label: 'جديد' },
    { key: 'preparing', label: 'قيد التجهيز' },
    { key: 'ready', label: 'جاهز للاستلام' },
    { key: 'delivered', label: 'تم التسليم' },
];

/**
 * A sale's page: its lines and totals, the buyer, how it was paid and its invoice, where and by whom it was sold — and
 * what may be done from here: reprint the receipt, void a completed sale (stock back, money refunded), or move a website
 * order through its steps (preparing, ready, handed over and paid) or cancel it. Each button only for whoever may.
 */
export default function SaleShow({ sale, can, printOrders = false, printer = null }: { sale: Sale; can: { refund: boolean; orders: boolean; invoices: boolean; print: boolean }; printOrders?: boolean; printer?: PrinterLike | null }) {
    const receipt = usePrint(printer);
    const job: PrintJob = { title: sale.number, url: route('club.sales.receipt', sale.id), subject: { document: 'sale', id: sale.id } };
    const currency = useCurrency();
    const m = (v: string | number) => money(v, currency);
    const [dialog, setDialog] = useState<'void' | 'deliver' | 'cancel' | null>(null);
    const order = sale.source === 'website';
    const open = order && sale.status === 'pending';
    const advance = (status: 'preparing' | 'ready') => {
        // This device prints an order when its preparation starts (its printer's setting): prepared in the click, once
        // per order whatever screen or reload asks again (the server's claim).
        const attempt = status === 'preparing' && can.print ? prepareAutoPrint(printer, printOrders) : null;
        router.post(route('club.sales.orders.status', sale.id), { status }, {
            preserveScroll: true,
            onSuccess: () => {
                if (!attempt || attempt.mode === 'none') return abandonAutoPrint(attempt);
                void claimAutoPrint({ document: 'sale', id: sale.id }).then((log) => {
                    if (log === false) return abandonAutoPrint(attempt);
                    void receipt.run(job, 'manual', (onProgress) => commitAutoPrint(attempt, job, onProgress), insideApp() || attempt.mode === 'page', log);
                });
            },
            onError: () => abandonAutoPrint(attempt),
        });
    };

    return (
        <ClubLayout title={`عملية البيع ${sale.number}`}>
            <PageHeader
                back={<BackLink href={route('club.sales.index')}>المبيعات</BackLink>}
                title={<span className="flex flex-wrap items-center gap-2">{order ? 'طلب' : 'عملية بيع'} <span className="num">{sale.number}</span> <StateBadge sale={sale} /></span>}
                subtitle={<span className="num">{sale.at}</span>}
                actions={<>
                    {can.print && <Button variant="outline" loading={receipt.busy} onClick={() => void receipt.print(job, 'reprint')} icon={<Printer />}>طباعة الإيصال</Button>}
                    {open && can.orders && sale.fulfillment === 'new' && <Button variant="outline" onClick={() => advance('preparing')}>بدء التجهيز</Button>}
                    {open && can.orders && (sale.fulfillment === 'new' || sale.fulfillment === 'preparing') && <Button variant="outline" icon={<PackageCheck />} onClick={() => advance('ready')}>جاهز للاستلام</Button>}
                    {open && can.orders && (!sale.paid || can.refund) && <Button variant="outline" icon={<Ban />} onClick={() => setDialog('cancel')}>إلغاء الطلب</Button>}
                    {open && can.orders && <Button icon={<CheckCheck />} onClick={() => setDialog('deliver')}>تسليم</Button>}
                    {sale.status === 'completed' && can.refund && <Button variant="outline" icon={<Undo2 />} onClick={() => setDialog('void')}>إلغاء البيع</Button>}
                </>}
            />
            {can.print && <PrintStatus state={receipt.state} onRetry={() => void receipt.retry()} onOpen={() => void receipt.open()} className="mb-4" />}

            {order && sale.status !== 'cancelled' && (
                <Card className="mb-4">
                    <ol className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {STEPS.map((step, i) => {
                            const at = STEPS.findIndex((s) => s.key === (sale.status === 'completed' || sale.status === 'voided' ? 'delivered' : (sale.fulfillment ?? 'new')));
                            return (
                                <li key={step.key} className="flex items-center gap-2">
                                    <span className={i <= at ? 'grid size-6 place-items-center rounded-full bg-[#111] text-[11px] font-semibold text-white num' : 'grid size-6 place-items-center rounded-full border border-line text-[11px] text-[#9ca3af] num'}>{i + 1}</span>
                                    <span className={i <= at ? 'text-[13px] font-medium text-ink' : 'text-[13px] text-[#9ca3af]'}>{step.label}</span>
                                </li>
                            );
                        })}
                    </ol>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="min-w-0 space-y-4 lg:col-span-2">
                    <Card padded={false} className="overflow-hidden">
                        <div className="p-5 pb-3"><CardHeader title="المنتجات" subtitle={`${number(sale.items.length)} منتج`} className="mb-0" /></div>
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>المنتج</th><th className="text-end">الكمية</th><th className="text-end">سعر الوحدة</th><th className="text-end">الإجمالي</th></tr></thead>
                                <tbody>
                                    {sale.items.map((item, i) => (
                                        <tr key={i}>
                                            <td>
                                                <div className="font-medium text-ink">{item.name}</div>
                                                {item.addons.map((a, k) => <div key={k} className="text-[12px] text-[#6b7280]">+ {a.name} <span className="num">× {number(a.quantity)}</span> · <span className="num">{m(a.line_total)}</span></div>)}
                                            </td>
                                            <td className="text-end tabular-nums">{number(item.quantity)}</td>
                                            <td className="text-end tabular-nums">{m(item.unit_price)}</td>
                                            <td className="text-end font-medium tabular-nums">{m(item.line_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className="space-y-2 border-t border-line p-5 text-[13px]">
                            <Row label="قيمة المنتجات" value={m(sale.subtotal)} />
                            {Number(sale.discount) > 0 && <Row label="الخصم" value={`− ${m(sale.discount)}`} />}
                            {Number(sale.tax) > 0 && <Row label="الضريبة" value={m(sale.tax)} />}
                            <div className="flex items-center justify-between rounded-[10px] bg-[#f5f3ff] px-3 py-2.5">
                                <dt className="text-[14px] font-bold text-ink">الإجمالي</dt>
                                <dd className="text-[16px] font-bold tabular-nums text-[#6d28d9]">{m(sale.total)}</dd>
                            </div>
                        </dl>
                    </Card>

                    {sale.payments.length > 0 && (
                        <Card padded={false} className="overflow-hidden">
                            <div className="p-5 pb-3"><CardHeader title="الدفعات" className="mb-0" /></div>
                            <div className="overflow-x-auto">
                                <table className="table-base">
                                    <thead><tr><th>الإيصال</th><th>الطريقة</th><th>التاريخ</th><th>استلمها</th><th className="text-end">المبلغ</th></tr></thead>
                                    <tbody>
                                        {sale.payments.map((p) => (
                                            <tr key={p.id}>
                                                <td className="num">{p.receipt_number ?? '—'}{p.kind === 'refund' && <Badge tone="red" className="ms-2">رد مبلغ</Badge>}</td>
                                                <td>{p.method_label}{p.reference && <div className="text-[11px] text-[#9ca3af] num" dir="ltr">{p.reference}</div>}</td>
                                                <td className="text-[#6b7280] num" dir="ltr">{p.paid_at ?? '—'}</td>
                                                <td className="text-[#6b7280]">{p.receiver ?? '—'}</td>
                                                <td className="text-end font-medium tabular-nums">{m(p.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    )}
                </div>

                <div className="min-w-0 space-y-4">
                    <Card>
                        <CardHeader title="العميل" />
                        <p className="text-[15px] font-semibold text-ink">{sale.member ? <Link href={route('club.members.show', sale.member.id)} className="hover:underline">{sale.buyer}</Link> : sale.buyer}</p>
                        {sale.member && <p className="mt-0.5 text-[12px] text-[#9ca3af] num">عضو رقم {sale.member.member_no}</p>}
                        <ul className="mt-3 space-y-1.5 text-[13px] text-[#4b4b4b]">
                            {sale.phone && <li><a href={`tel:+${sale.phone}`} className="inline-flex items-center gap-1.5 hover:text-ink num" dir="ltr"><Phone className="size-3.5 text-[#9ca3af]" />+{sale.phone}</a></li>}
                            {sale.email && <li className="num" dir="ltr">{sale.email}</li>}
                            {sale.notes && <li className="rounded-[10px] bg-[#fafafa] p-3 text-[12px] text-[#6b7280]">«{sale.notes}»</li>}
                        </ul>
                    </Card>

                    <Card>
                        <CardHeader title="الدفع" />
                        <dl className="space-y-2 text-[13px]">
                            <Row label="طريقة الدفع" value={sale.method_label} />
                            <Row label="الحالة" value={sale.paid ? <Badge tone="green">مدفوع</Badge> : <Badge tone="amber">غير مدفوع</Badge>} />
                            {sale.reserved_until && !sale.paid && <Row label="مهلة الدفع" value={<span className="inline-flex items-center gap-1 num"><Timer className="size-3.5 text-[#9ca3af]" />{sale.reserved_until}</span>} />}
                            {sale.invoice && <Row label="الفاتورة" value={can.invoices ? <Link href={route('club.invoices.show', sale.invoice.id)} className="inline-flex items-center gap-1 text-[#1d4ed8] num"><FileText className="size-3.5" />{sale.invoice.number}</Link> : <span className="num">{sale.invoice.number}</span>} />}
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="تفاصيل العملية" />
                        <dl className="space-y-2 text-[13px]">
                            <Row label="المصدر" value={<SourceBadge source={sale.source} label={sale.source_label} />} />
                            {sale.branch && <Row label="الفرع" value={sale.branch} />}
                            {sale.device && <Row label="الجهاز" value={sale.device} />}
                            {sale.cashier && <Row label="الموظف" value={sale.cashier} />}
                            <Row label="التاريخ" value={<span className="num" dir="ltr">{sale.at}</span>} />
                            {sale.delivered_at && <Row label="سُلّم" value={<span><span className="num" dir="ltr">{sale.delivered_at}</span>{sale.delivered_by && ` · ${sale.delivered_by}`}</span>} />}
                            {(sale.voided_at || sale.cancelled_at) && <Row label="أُلغي" value={<span className="num" dir="ltr">{sale.voided_at ?? sale.cancelled_at}</span>} />}
                        </dl>
                        {sale.cancel_reason && <p className="mt-3 rounded-[10px] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]">سبب الإلغاء: {sale.cancel_reason}</p>}
                    </Card>
                </div>
            </div>

            {dialog === 'void' && <VoidDialog sale={sale} onClose={() => setDialog(null)} />}
            {dialog === 'deliver' && <DeliverDialog sale={sale} onClose={() => setDialog(null)} />}
            {dialog === 'cancel' && <CancelOrderDialog sale={sale} onClose={() => setDialog(null)} />}
        </ClubLayout>
    );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className="text-end text-ink">{value}</dd>
        </div>
    );
}
