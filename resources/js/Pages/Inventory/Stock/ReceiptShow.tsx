import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, Printer, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, Notice, PageHeader } from '@/Components/ui';
import { Pill, RECEIPT_LABELS, ReasonDialog } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';

interface Receipt {
    id: number; number: string; status: string; total: string; notes: string | null; reason: string | null; delivery_reference: string | null; received_on: string;
    supplier: string | null; order: { id: number; number: string } | null; branch: string | null; by: string | null; approver: string | null; approved_at: string | null;
    lines: { product: string | null; sku: string | null; ordered_quantity: string; quantity: string; unit_factor: string; unit_cost: string; line_total: string; note: string | null; stock_quantity: number }[];
}

const qty = (v: number | string) => number(v, Number(v) % 1 ? 3 : 0);

/** A goods receipt note: what was still due, what arrived (and in stock units), the value; approved or rejected once. */
export default function ReceiptShow({ receipt, can }: { receipt: Receipt; can: { approve: boolean } }) {
    const currency = useCurrency();
    const [rejecting, setRejecting] = useState(false);

    return (
        <ClubLayout title={`إشعار الاستلام ${receipt.number}`}>
            <PageHeader
                back={<Link href={route('club.inventory.receipts')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> إشعارات الاستلام</Link>}
                title={<span className="num">إشعار الاستلام {receipt.number}</span>}
                subtitle={`${receipt.supplier} · ${receipt.branch} · استلمه ${receipt.by ?? '—'} في ${receipt.received_on}${receipt.approver ? ` · اعتمده ${receipt.approver}` : ''}`}
                actions={<>
                    <Pill tone={RECEIPT_LABELS[receipt.status][1]}>{RECEIPT_LABELS[receipt.status][0]}</Pill>
                    <Button variant="outline" icon={<Printer />} href={route('club.papers.show', { type: 'goods_receipt', id: receipt.id, print: 1 })} external>طباعة السند</Button>
                    {receipt.status === 'pending' && can.approve && <><Button variant="outline" icon={<X />} onClick={() => setRejecting(true)}>رفض</Button><Button icon={<Check />} onClick={() => router.post(route('club.inventory.receipts.decide', { goodsReceipt: receipt.id, decision: 'approve' }), {}, { preserveScroll: true })}>اعتماد وإضافة للمخزون</Button></>}
                </>}
            />
            {receipt.reason && <Notice tone="gray" className="mb-4">سبب الرفض: {receipt.reason}</Notice>}
            <Card padded={false} className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>المنتج</th><th>المتبقي على الأمر</th><th>المستلم</th><th>بوحدة المخزون</th><th>التكلفة</th><th>الإجمالي</th><th>ملاحظة</th></tr></thead>
                        <tbody>
                            {receipt.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="font-medium">{l.product}</td>
                                    <td className="num">{qty(l.ordered_quantity)}</td>
                                    <td className="font-semibold num">{qty(l.quantity)}{Number(l.quantity) < Number(l.ordered_quantity) && <span className="block text-[11px] font-normal text-[#b45309]">أقل بـ {qty(Number(l.ordered_quantity) - Number(l.quantity))}</span>}</td>
                                    <td className="num">{qty(l.stock_quantity)}{Number(l.unit_factor) !== 1 && <span className="text-[11px] text-[#9ca3af]"> (×{qty(l.unit_factor)})</span>}</td>
                                    <td className="num">{money(l.unit_cost, currency)}</td>
                                    <td className="num">{money(l.line_total, currency)}</td>
                                    <td className="text-[13px] text-[#6b7280]">{l.note ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot><tr><td colSpan={5} className="font-semibold">الإجمالي</td><td className="font-semibold num">{money(receipt.total, currency)}</td><td /></tr></tfoot>
                    </table>
                </div>
            </Card>
            <p className="mt-3 text-[12px] text-[#9ca3af]">{receipt.order && <>أمر الشراء <Link href={route('club.purchases.show', receipt.order.id)} className="underline num">{receipt.order.number}</Link>{receipt.delivery_reference && <> · سند تسليم المورد <span className="num">{receipt.delivery_reference}</span></>}. </>}الاستلام يحرك المخزون فقط؛ يُسجَّل الالتزام للمورد عند اعتماد سند المورد.</p>
            <ReasonDialog open={rejecting} title={`رفض ${receipt.number}`} message="لا يتغير المخزون." confirm="رفض" url={route('club.inventory.receipts.decide', { goodsReceipt: receipt.id, decision: 'reject' })} onClose={() => setRejecting(false)} />
        </ClubLayout>
    );
}
