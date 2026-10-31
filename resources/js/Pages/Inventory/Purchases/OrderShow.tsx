import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Check, FileText, PackageCheck, Pencil, Printer, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Checkbox, Field, Input, Modal, Notice, PageHeader } from '@/Components/ui';
import { BILL_STATES, clientKey, ORDER_TONES, Pill, RECEIPT_LABELS, ReasonDialog } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';

interface Item { id: number; product: string | null; sku: string | null; quantity: string; received_quantity: string; pending: number; remaining: number; unit: string | null; unit_factor: string; unit_cost: string; line_total: string }
interface Order {
    id: number; number: string; status: string; status_label: string; subtotal: string; discount: string; shipping: string; tax_rate: string; tax: string; total: string; notes: string | null;
    supplier_reference: string | null; payment_terms_days: number | null; cancel_reason: string | null; supplier: { id: number; name: string; phone: string | null } | null; branch: string | null;
    order_date: string; expected_on: string | null; items: Item[]; received_value: string;
    receipts: { id: number; number: string; status: string; total: string; received_on: string; by: string | null; approver: string | null }[];
    bills: { id: number; number: string; reference: string; status: string; total: string; paid_total: string; balance: string }[];
    timeline: { label: string; by: string | null; at: string }[];
}

const qty = (v: number | string) => number(v, Number(v) % 1 ? 3 : 0);

/** A purchase order: its lines (ordered, received, waiting for approval, still to come), its receipts and bills, and the next step. */
export default function OrderShow({ order, can }: { order: Order; can: { approve: boolean; receive: boolean; manage: boolean } }) {
    const currency = useCurrency();
    const [receiving, setReceiving] = useState(false);
    const [closing, setClosing] = useState<'cancel' | 'close' | null>(null);
    const s = order.status;

    return (
        <ClubLayout title={`أمر الشراء ${order.number}`}>
            <PageHeader
                back={<Link href={route('club.purchases.index')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> أوامر الشراء</Link>}
                title={<span className="num">أمر الشراء {order.number}</span>}
                subtitle={`${order.supplier?.name ?? ''} · يُستلم في ${order.branch ?? '—'} · بتاريخ ${order.order_date}${order.expected_on ? ` · متوقع ${order.expected_on}` : ''}`}
                actions={<>
                    <Pill tone={ORDER_TONES[s] ?? 'gray'}>{order.status_label}</Pill>
                    <Button variant="outline" icon={<Printer />} href={route('club.purchases.print', order.id)} external>معاينة وطباعة</Button>
                    {s === 'draft' && can.manage && <Button variant="outline" icon={<Pencil />} href={route('club.purchases.edit', order.id)}>تعديل</Button>}
                    {s === 'draft' && can.approve && <Button icon={<Check />} onClick={() => router.post(route('club.purchases.approve', order.id), {}, { preserveScroll: true })}>اعتماد وإرسال</Button>}
                    {(s === 'ordered' || s === 'partial') && can.receive && <Button icon={<PackageCheck />} onClick={() => setReceiving(true)}>تسجيل استلام</Button>}
                </>}
            />
            {order.cancel_reason && <Notice tone="gray" className="mb-4">{s === 'cancelled' ? 'سبب الإلغاء' : 'سبب الإغلاق'}: {order.cancel_reason}</Notice>}

            <div className="grid gap-4 lg:grid-cols-[1fr_320px] lg:items-start">
                <Card padded={false} className="overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>المنتج</th><th>الوحدة</th><th>المطلوب</th><th>المستلم</th><th>بانتظار الاعتماد</th><th>المتبقي</th><th>التكلفة</th><th>الإجمالي</th></tr></thead>
                            <tbody>
                                {order.items.map((i) => (
                                    <tr key={i.id}>
                                        <td><p className="font-medium">{i.product}</p><p className="text-[11px] text-[#9ca3af] num">{i.sku ?? '—'}</p></td>
                                        <td className="text-[13px]">{i.unit ?? 'وحدة'}{Number(i.unit_factor) !== 1 && <span className="text-[11px] text-[#9ca3af]"> ×{qty(i.unit_factor)}</span>}</td>
                                        <td className="num">{qty(i.quantity)}</td>
                                        <td className="num">{qty(i.received_quantity)}</td>
                                        <td className={cn('num', i.pending > 0 && 'text-[#b45309]')}>{qty(i.pending)}</td>
                                        <td className={cn('num', i.remaining > 0 && 'font-semibold')}>{qty(i.remaining)}</td>
                                        <td className="num">{money(i.unit_cost, currency)}</td>
                                        <td className="num">{money(i.line_total, currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {['draft', 'ordered', 'partial'].includes(s) && can.approve && (
                        <div className="flex justify-end gap-2 border-t border-line px-5 py-3">
                            {s === 'partial' ? <Button variant="ghost" icon={<X />} onClick={() => setClosing('close')}>إغلاق دون انتظار الباقي</Button> : <Button variant="ghost" icon={<X />} onClick={() => setClosing('cancel')}>إلغاء الأمر</Button>}
                        </div>
                    )}
                </Card>
                <div className="space-y-4">
                    <Card>
                        <CardHeader title="الإجمالي" />
                        <dl className="space-y-1.5 text-sm">
                            <div className="flex justify-between"><dt className="text-muted">البنود</dt><dd className="num">{money(order.subtotal, currency)}</dd></div>
                            {Number(order.discount) > 0 && <div className="flex justify-between"><dt className="text-muted">الخصم</dt><dd className="num">- {money(order.discount, currency)}</dd></div>}
                            {Number(order.shipping) > 0 && <div className="flex justify-between"><dt className="text-muted">الشحن</dt><dd className="num">{money(order.shipping, currency)}</dd></div>}
                            {Number(order.tax) > 0 && <div className="flex justify-between"><dt className="text-muted">الضريبة (<span className="num">{Number(order.tax_rate)}٪</span>)</dt><dd className="num">{money(order.tax, currency)}</dd></div>}
                            <div className="flex justify-between border-t border-line pt-2 font-bold"><dt>الإجمالي</dt><dd className="num">{money(order.total, currency)}</dd></div>
                            <div className="flex justify-between text-[13px]"><dt className="text-muted">ما وصل واعتُمد</dt><dd className="num">{money(order.received_value, currency)}</dd></div>
                        </dl>
                    </Card>
                    <Card>
                        <CardHeader title="سندات الموردين" actions={s !== 'draft' && s !== 'cancelled' && order.bills.length === 0 && can.manage && <Button size="sm" variant="outline" icon={<FileText />} href={route('club.purchases.bills', { order: order.id })}>تسجيل سند</Button>} />
                        {order.bills.length === 0 ? <p className="text-[13px] text-muted">لا سند مورد بعد. الالتزام للمورد يُسجَّل عند اعتماد سنده.</p> : (
                            <ul className="space-y-2 text-[13px]">
                                {order.bills.map((b) => <li key={b.id} className="flex items-center justify-between gap-2"><Link href={route('club.purchases.bills.show', b.id)} className="hover:underline num">{b.reference}</Link><span className="num">{money(b.total, currency)}</span><Pill tone={BILL_STATES[b.status === 'approved' ? (Number(b.balance) > 0 ? (Number(b.paid_total) > 0 ? 'partial' : 'unpaid') : 'paid') : b.status]?.[1] ?? 'gray'}>{b.status === 'approved' ? (Number(b.balance) > 0 ? `متبقٍ ${money(b.balance, currency)}` : 'مدفوع') : BILL_STATES[b.status]?.[0]}</Pill></li>)}
                            </ul>
                        )}
                    </Card>
                    <Card>
                        <CardHeader title="سندات الاستلام" />
                        {order.receipts.length === 0 ? <p className="text-[13px] text-muted">لم يُسجَّل استلام بعد.</p> : (
                            <ul className="space-y-2 text-[13px]">
                                {order.receipts.map((r) => <li key={r.id} className="flex items-center justify-between gap-2"><Link href={route('club.inventory.receipts.show', r.id)} className="hover:underline num">{r.number}</Link><span className="num">{r.received_on}</span><Pill tone={RECEIPT_LABELS[r.status][1]}>{RECEIPT_LABELS[r.status][0]}</Pill></li>)}
                            </ul>
                        )}
                    </Card>
                    <Card>
                        <CardHeader title="السجل" />
                        <ol className="space-y-2 text-[13px]">
                            {order.timeline.map((t, i) => <li key={i}><span className="text-ink">{t.label}</span><span className="block text-[11px] text-[#9ca3af]">{t.by ? `${t.by} · ` : ''}<span className="num">{t.at}</span></span></li>)}
                        </ol>
                    </Card>
                </div>
            </div>

            {receiving && <Receive order={order} canApprove={can.approve} onClose={() => setReceiving(false)} />}
            <ReasonDialog open={closing !== null} title={closing === 'close' ? 'إغلاق أمر الشراء' : 'إلغاء أمر الشراء'} message={closing === 'close' ? 'يبقى ما استُلم، ولا يُنتظر الباقي.' : 'لم يُستلم شيء؛ يُلغى الأمر دون أي أثر.'} confirm={closing === 'close' ? 'إغلاق' : 'إلغاء الأمر'} url={route(closing === 'close' ? 'club.purchases.close' : 'club.purchases.cancel', order.id)} onClose={() => setClosing(null)} />
        </ClubLayout>
    );
}

function Receive({ order, canApprove, onClose }: { order: Order; canApprove: boolean; onClose: () => void }) {
    const key = useMemo(clientKey, []);
    const open = order.items.filter((i) => i.remaining - i.pending > 0);
    const form = useForm({
        lines: Object.fromEntries(open.map((i) => [i.id, { quantity: String(i.remaining - i.pending), note: '' }])) as Record<number, { quantity: string; note: string }>,
        received_on: new Date().toISOString().slice(0, 10),
        delivery_reference: '',
        notes: '',
        approve: canApprove,
        client_key: key,
    });
    const full = open.every((i) => Number(form.data.lines[i.id]?.quantity || 0) >= i.remaining - i.pending) && order.items.every((i) => i.pending === 0);
    const err = form.errors as Record<string, string>;

    return (
        <Modal open onClose={onClose} size="lg" title={`استلام بضاعة — ${order.number}`} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button loading={form.processing} onClick={() => form.post(route('club.purchases.receive', order.id), { preserveScroll: true, onSuccess: onClose })}>{form.data.approve ? 'تسجيل واعتماد' : 'تسجيل سند الاستلام'}</Button></>}>
            <div className="space-y-4">
                <div className="space-y-2">
                    {open.map((i) => (
                        <div key={i.id} className="grid grid-cols-[1fr_7rem] items-start gap-3 rounded-[12px] border border-line p-3">
                            <div>
                                <p className="font-medium">{i.product}</p>
                                <p className="text-[12px] text-[#9ca3af]">المتبقي <span className="num">{qty(i.remaining - i.pending)}</span> {i.unit ?? 'وحدة'}{i.pending > 0 && <> (بانتظار الاعتماد <span className="num">{qty(i.pending)}</span>)</>}</p>
                                <Input className="mt-2" placeholder="ملاحظة (نقص، تلف…)" value={form.data.lines[i.id].note} onChange={(e) => form.setData('lines', { ...form.data.lines, [i.id]: { ...form.data.lines[i.id], note: e.target.value } })} maxLength={200} />
                            </div>
                            <Input type="number" min="0" step="any" dir="ltr" value={form.data.lines[i.id].quantity} onChange={(e) => form.setData('lines', { ...form.data.lines, [i.id]: { ...form.data.lines[i.id], quantity: e.target.value } })} aria-label={`المستلم من ${i.product}`} />
                        </div>
                    ))}
                </div>
                {err.lines && <p className="text-xs text-red-600">{err.lines}</p>}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="تاريخ الاستلام" error={form.errors.received_on} required><Input type="date" value={form.data.received_on} onChange={(e) => form.setData('received_on', e.target.value)} /></Field>
                    <Field label="رقم سند تسليم المورد" error={form.errors.delivery_reference}><Input value={form.data.delivery_reference} onChange={(e) => form.setData('delivery_reference', e.target.value)} maxLength={60} /></Field>
                </div>
                <p className={cn('rounded-[12px] px-4 py-3 text-[13px]', full ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800')}>{full ? 'استلام كامل: يكتمل الأمر عند الاعتماد.' : 'استلام جزئي: يبقى الأمر مفتوحًا للباقي.'}</p>
                {canApprove && <Checkbox label="اعتماد الاستلام الآن وإضافته لمخزون الفرع" checked={form.data.approve} onChange={(e) => form.setData('approve', e.target.checked)} />}
            </div>
        </Modal>
    );
}
