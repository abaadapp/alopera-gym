import { useEffect, useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Check, CreditCard, FileText, Paperclip, Plus, Wallet, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, Checkbox, EmptyState, Field, Input, Modal, Notice, PageHeader, Pagination, Select, StatCard, Textarea } from '@/Components/ui';
import { Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import { BILL_STATES, Pill, ReasonDialog } from '@/Components/Inventory/parts';
import PayBill from '@/Components/Inventory/PayBill';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

export interface BillRow { id: number; number: string; reference: string; status: string; total: string; paid_total: string; balance: string; state: string | null; supplier: string | null; supplier_id: number; order: { id: number; number: string } | null; issue_date: string; due_date: string | null; has_attachment: boolean; attachment_name: string | null }
type OrderOption = { id: number; number: string; supplier_id: number; subtotal: string; discount: string; shipping: string; tax: string; total: string; received: string };
type Filters = { state: string; q: string; supplier: string };

interface Props {
    bills: Paginated<BillRow>;
    summary: { total: number; pending: number; due: number; overdue: number; overdue_count: number };
    filters: Filters;
    suppliers: { id: number; name: string; payment_terms_days: number | null }[];
    orders: OrderOption[];
    accounts: { id: number; name: string; kind: string }[];
    taxRate: number;
    preselect: number | null;
    can: { create: boolean; approve: boolean; pay: boolean };
}

export const billState = (b: Pick<BillRow, 'status' | 'state'>) => BILL_STATES[b.status === 'approved' ? b.state ?? 'unpaid' : b.status] ?? ['—', 'gray'];

/** سندات الموردين: supplier invoices, approved into a debt to the supplier, and paid from a cash box or bank. */
export default function Bills({ bills, summary, filters, suppliers, orders, accounts, taxRate, preselect, can }: Props) {
    const currency = useCurrency();
    const [creating, setCreating] = useState(Boolean(preselect));
    const [paying, setPaying] = useState<BillRow | null>(null);
    const [deciding, setDeciding] = useState<{ bill: BillRow; decision: 'reject' | 'void' } | null>(null);
    const go = (change: Partial<Filters>) => visit('club.purchases.bills', filters, change);

    return (
        <ClubLayout title="سندات الموردين">
            <PageHeader title="سندات الموردين" subtitle="فواتير الموردين: تُعتمد فتصبح التزامًا للمورد في الدفاتر، وتُسدَّد من الصندوق أو البنك" actions={can.create && <Button icon={<Plus />} onClick={() => setCreating(true)}>سند مورد جديد</Button>} />
            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="السندات" value={number(summary.total)} icon={<FileText />} tone="gray" />
                <StatCard label="مستحق للموردين" value={money(summary.due, currency)} icon={<Wallet />} tone={summary.due > 0 ? 'amber' : 'green'} href={route('club.purchases.bills', { state: 'unpaid' })} />
                <StatCard label="متأخر عن استحقاقه" value={money(summary.overdue, currency)} hint={`${number(summary.overdue_count)} سند`} icon={<AlertTriangle />} tone={summary.overdue > 0 ? 'red' : 'gray'} href={route('club.purchases.bills', { state: 'overdue' })} />
            </div>
            {summary.pending > 0 && <Notice tone="amber" className="mb-4" onClick={() => go({ state: 'pending' })}><span className="num">{number(summary.pending)}</span> سند بانتظار الاعتماد — عرض الطابور</Notice>}

            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث برقم فاتورة المورد أو اسمه" onSearch={(q) => go({ q })} />
                    <div className="flex flex-wrap items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="الحالة">
                        {([['', 'الكل'], ['pending', 'بانتظار الاعتماد'], ['unpaid', 'غير مدفوع'], ['partial', 'جزئي'], ['paid', 'مدفوع'], ['overdue', 'متأخر']] as const).map(([key, label]) => (
                            <button key={key || 'all'} type="button" onClick={() => go({ state: key })} aria-pressed={filters.state === key} className={cn('h-8 rounded-[8px] px-3 text-[13px] font-medium transition', filters.state === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>{label}</button>
                        ))}
                    </div>
                    <Select className="ms-auto h-9 w-44 py-1.5 text-[13px]" value={filters.supplier} onChange={(e) => go({ supplier: e.target.value })} aria-label="المورد">
                        <option value="">كل الموردين</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </Toolbar>
                {bills.data.length === 0 ? (
                    <EmptyState icon={<FileText className="size-6" />} title={suppliers.length === 0 ? 'أضف موردًا أولًا' : 'لا توجد سندات'} description="سجّل فاتورة المورد عند وصولها، واربطها بأمر الشراء إن وُجد حتى لا يُحتسب الشراء مرتين." action={suppliers.length === 0 ? <Button size="sm" href={route('club.purchases.suppliers')}>الموردون</Button> : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>فاتورة المورد</th><th>المورد</th><th>التاريخ</th><th>الاستحقاق</th><th>الإجمالي</th><th>المتبقي</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {bills.data.map((b) => {
                                    const [label, tone] = billState(b);
                                    return (
                                        <tr key={b.id}>
                                            <td><Link href={route('club.purchases.bills.show', b.id)} className="font-medium text-ink hover:underline num">{b.reference}</Link><p className="text-[11px] text-[#9ca3af]"><span className="num">{b.number}</span>{b.order && <> · <Link href={route('club.purchases.show', b.order.id)} className="hover:underline num">{b.order.number}</Link></>}{b.has_attachment && <Paperclip className="ms-1 inline size-3" />}</p></td>
                                            <td className="text-[13px]">{b.supplier}</td>
                                            <td className="text-[13px] num">{b.issue_date}</td>
                                            <td className={cn('text-[13px] num', b.state === 'overdue' && 'font-semibold text-red-700')}>{b.due_date ?? '—'}</td>
                                            <td className="font-medium num">{money(b.total, currency)}</td>
                                            <td className={cn('num', Number(b.balance) > 0 ? 'text-[#b45309]' : 'text-[#9ca3af]')}>{Number(b.balance) > 0 ? money(b.balance, currency) : '—'}</td>
                                            <td><Pill tone={tone}>{label}</Pill></td>
                                            <td>
                                                <div className="flex justify-end gap-1">
                                                    {b.status === 'pending' && can.approve && <><Button size="sm" variant="ghost" icon={<X />} aria-label="رفض" onClick={() => setDeciding({ bill: b, decision: 'reject' })} /><Button size="sm" icon={<Check />} onClick={() => router.post(route('club.purchases.bills.decide', { supplierBill: b.id, decision: 'approve' }), {}, { preserveScroll: true })}>اعتماد</Button></>}
                                                    {b.status === 'approved' && Number(b.balance) > 0 && can.pay && <Button size="sm" variant="outline" icon={<CreditCard />} onClick={() => setPaying(b)}>سداد</Button>}
                                                    {b.status === 'approved' && Number(b.paid_total) === 0 && can.approve && <Button size="sm" variant="ghost" onClick={() => setDeciding({ bill: b, decision: 'void' })}>إلغاء</Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={bills} />
            </Card>

            {creating && <NewBill suppliers={suppliers} orders={orders} taxRate={taxRate} preselect={preselect} canApprove={can.approve} onClose={() => setCreating(false)} />}
            {paying && <PayBill bill={paying} accounts={accounts} onClose={() => setPaying(null)} />}
            <ReasonDialog open={deciding !== null} title={deciding?.decision === 'void' ? 'إلغاء سند معتمد' : 'رفض السند'} message={deciding?.decision === 'void' ? 'يُعكس قيده في الدفاتر ويبقى ملغى في السجل.' : undefined} confirm={deciding?.decision === 'void' ? 'إلغاء السند' : 'رفض'} url={deciding ? route('club.purchases.bills.decide', { supplierBill: deciding.bill.id, decision: deciding.decision }) : ''} onClose={() => setDeciding(null)} />
        </ClubLayout>
    );
}

function NewBill({ suppliers, orders, taxRate, preselect, canApprove, onClose }: { suppliers: Props['suppliers']; orders: OrderOption[]; taxRate: number; preselect: number | null; canApprove: boolean; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm({ supplier_id: '', purchase_order_id: '', reference: '', issue_date: new Date().toISOString().slice(0, 10), due_date: '', subtotal: '', discount: '0', tax: '0', notes: '', attachment: null as File | null, approve: false });
    const order = orders.find((o) => String(o.id) === form.data.purchase_order_id);
    const pickOrder = (id: string) => {
        const o = orders.find((x) => String(x.id) === id);
        form.setData((d) => ({ ...d, purchase_order_id: id, ...(o ? { supplier_id: String(o.supplier_id), subtotal: String(Number(o.subtotal) + Number(o.shipping)), discount: String(Number(o.discount)), tax: String(Number(o.tax)) } : {}) }));
    };
    useEffect(() => { if (preselect) pickOrder(String(preselect)); }, []); // eslint-disable-line react-hooks/exhaustive-deps
    const total = Math.round((Number(form.data.subtotal || 0) - Number(form.data.discount || 0) + Number(form.data.tax || 0)) * 1000) / 1000;
    const supplierOrders = orders.filter((o) => !form.data.supplier_id || String(o.supplier_id) === form.data.supplier_id);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, purchase_order_id: d.purchase_order_id || null, due_date: d.due_date || null }));
        form.post(route('club.purchases.bills.store'), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} size="lg" title="سند مورد جديد" footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="new-bill" loading={form.processing}>{form.data.approve ? 'تسجيل واعتماد' : 'تسجيل السند'}</Button></>}>
            <form id="new-bill" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="المورد" error={form.errors.supplier_id} required>
                        <Select value={form.data.supplier_id} onChange={(e) => form.setData((d) => ({ ...d, supplier_id: e.target.value, purchase_order_id: '' }))} placeholder="اختر المورد">{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>
                    </Field>
                    <Field label="أمر الشراء (إن وُجد)" error={form.errors.purchase_order_id} hint="يُربط أمر الشراء بسند واحد فقط">
                        <Select value={form.data.purchase_order_id} onChange={(e) => pickOrder(e.target.value)}><option value="">شراء مباشر بلا أمر</option>{supplierOrders.map((o) => <option key={o.id} value={o.id}>{o.number} — {money(o.total, currency)}</option>)}</Select>
                    </Field>
                    <Field label="رقم فاتورة المورد" error={form.errors.reference} required><Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} maxLength={60} /></Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="تاريخ الإصدار" error={form.errors.issue_date} required><Input type="date" value={form.data.issue_date} onChange={(e) => form.setData('issue_date', e.target.value)} /></Field>
                        <Field label="الاستحقاق" error={form.errors.due_date}><Input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} /></Field>
                    </div>
                </div>
                <div className="grid grid-cols-3 gap-3">
                    <Field label="المبلغ قبل الضريبة" error={form.errors.subtotal} required><Input type="number" min="0" step="0.001" dir="ltr" value={form.data.subtotal} onChange={(e) => form.setData('subtotal', e.target.value)} /></Field>
                    <Field label="الخصم" error={form.errors.discount}><Input type="number" min="0" step="0.001" dir="ltr" value={form.data.discount} onChange={(e) => form.setData('discount', e.target.value)} /></Field>
                    <Field label={`الضريبة${taxRate ? ` (${taxRate}٪)` : ''}`} error={form.errors.tax}><Input type="number" min="0" step="0.001" dir="ltr" value={form.data.tax} onChange={(e) => form.setData('tax', e.target.value)} /></Field>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px]">
                    <span>الإجمالي: <span className="font-semibold num">{money(total, currency)}</span></span>
                    {order && <span className="text-[#6b7280]">أمر الشراء <span className="num">{money(order.total, currency)}</span> · ما وصل واعتُمد <span className="num">{money(order.received, currency)}</span>{total > Number(order.total) && <span className="ms-2 font-semibold text-red-700">أكبر من أمر الشراء</span>}</span>}
                </div>
                <Field label="المرفق (PDF أو صورة)" error={form.errors.attachment}><Input type="file" accept="application/pdf,image/*" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} /></Field>
                <Field label="ملاحظات" error={form.errors.notes}><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={1000} /></Field>
                {canApprove && <Checkbox label="اعتماده الآن وتسجيل الالتزام للمورد" checked={form.data.approve} onChange={(e) => form.setData('approve', e.target.checked)} />}
            </form>
        </Modal>
    );
}
