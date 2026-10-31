import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check, CreditCard, Download, Printer, RotateCcw, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Notice, PageHeader, BackLink } from '@/Components/ui';
import { Pill, ReasonDialog } from '@/Components/Inventory/parts';
import PayBill from '@/Components/Inventory/PayBill';
import { useCurrency, useCan } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { billState, type BillRow } from './Bills';

interface Bill extends BillRow {
    subtotal: string; discount: string; tax: string; notes: string | null; reason: string | null; by: string | null; approver: string | null; approved_at: string | null;
    order: { id: number; number: string; total: string; received: string } | null;
    payments: { id: number; number: string; amount: string; reference: string | null; reverse_reason: string | null; paid_on: string; account: string | null; by: string | null; reversed: boolean }[];
}

/** A supplier bill: its amounts, its order (ordered vs received vs billed), payments and what is still owed. */
export default function BillShow({ bill, accounts, can }: { bill: Bill; accounts: { id: number; name: string; kind: string }[]; can: { approve: boolean; pay: boolean } }) {
    const mayPrint = useCan()('papers.print');
    const currency = useCurrency();
    const [paying, setPaying] = useState(false);
    const [closing, setClosing] = useState<'reject' | 'void' | null>(null);
    const [reversing, setReversing] = useState<number | null>(null);
    const [label, tone] = billState(bill);

    return (
        <ClubLayout title={`سند المورد ${bill.reference}`}>
            <PageHeader
                back={<BackLink href={route('club.purchases.bills')}>سندات الموردين</BackLink>}
                title={<span className="num">{bill.reference}</span>}
                subtitle={`${bill.supplier} · ${bill.number} · صدر ${bill.issue_date}${bill.due_date ? ` · يستحق ${bill.due_date}` : ''}`}
                actions={<>
                    <Pill tone={tone}>{label}</Pill>
                    {mayPrint && <Button variant="outline" icon={<Printer />} href={route('club.papers.show', { type: 'supplier_bill', id: bill.id, print: 1 })} external>طباعة</Button>}
                    {bill.has_attachment && <Button variant="outline" icon={<Download />} href={route('club.purchases.bills.attachment', bill.id)} external>المرفق</Button>}
                    {bill.status === 'pending' && can.approve && <><Button variant="outline" icon={<X />} onClick={() => setClosing('reject')}>رفض</Button><Button icon={<Check />} onClick={() => router.post(route('club.purchases.bills.decide', { supplierBill: bill.id, decision: 'approve' }), {}, { preserveScroll: true })}>اعتماد</Button></>}
                    {bill.status === 'approved' && Number(bill.balance) > 0 && can.pay && <Button icon={<CreditCard />} onClick={() => setPaying(true)}>سداد</Button>}
                    {bill.status === 'approved' && Number(bill.paid_total) === 0 && can.approve && <Button variant="ghost" onClick={() => setClosing('void')}>إلغاء السند</Button>}
                </>}
            />
            {bill.reason && <Notice tone="gray" className="mb-4">السبب: {bill.reason}</Notice>}
            <div className="grid gap-4 lg:grid-cols-[1fr_340px] lg:items-start">
                <Card padded={false} className="overflow-hidden">
                    <div className="p-5 pb-2"><CardHeader title="المدفوعات" className="mb-0" /></div>
                    {bill.payments.length === 0 ? <p className="px-5 pb-5 text-[13px] text-muted">لا مدفوعات بعد.</p> : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>الدفعة</th><th>التاريخ</th><th>من حساب</th><th>المرجع</th><th>المبلغ</th><th /></tr></thead>
                                <tbody>
                                    {bill.payments.map((p) => (
                                        <tr key={p.id} className={cn(p.reversed && 'text-[#9ca3af] line-through')}>
                                            <td className="num">{p.number}<span className="block text-[11px] no-underline">{p.by}</span></td>
                                            <td className="num">{p.paid_on}</td>
                                            <td className="text-[13px]">{p.account}</td>
                                            <td className="text-[13px] num">{p.reference ?? '—'}</td>
                                            <td className="font-medium num">{money(p.amount, currency)}</td>
                                            <td>{!p.reversed && can.pay && <Button size="sm" variant="ghost" icon={<RotateCcw />} onClick={() => setReversing(p.id)}>عكس</Button>}{p.reversed && <span className="text-[11px] no-underline">معكوسة: {p.reverse_reason}</span>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
                <div className="space-y-4">
                    <Card>
                        <CardHeader title="المبالغ" />
                        <dl className="space-y-1.5 text-sm">
                            <div className="flex justify-between"><dt className="text-muted">قبل الضريبة</dt><dd className="num">{money(bill.subtotal, currency)}</dd></div>
                            {Number(bill.discount) > 0 && <div className="flex justify-between"><dt className="text-muted">الخصم</dt><dd className="num">- {money(bill.discount, currency)}</dd></div>}
                            <div className="flex justify-between"><dt className="text-muted">الضريبة</dt><dd className="num">{money(bill.tax, currency)}</dd></div>
                            <div className="flex justify-between border-t border-line pt-2 font-bold"><dt>الإجمالي</dt><dd className="num">{money(bill.total, currency)}</dd></div>
                            <div className="flex justify-between"><dt className="text-muted">المدفوع</dt><dd className="num">{money(bill.paid_total, currency)}</dd></div>
                            <div className="flex justify-between font-semibold"><dt>المتبقي</dt><dd className="num">{money(bill.balance, currency)}</dd></div>
                        </dl>
                    </Card>
                    {bill.order && (
                        <Card>
                            <CardHeader title="المطابقة مع أمر الشراء" />
                            <dl className="space-y-1.5 text-[13px]">
                                <div className="flex justify-between"><dt className="text-muted">أمر الشراء <Link href={route('club.purchases.show', bill.order.id)} className="underline num">{bill.order.number}</Link></dt><dd className="num">{money(bill.order.total, currency)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted">ما وصل واعتُمد</dt><dd className="num">{money(bill.order.received, currency)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted">هذا السند</dt><dd className="num">{money(bill.total, currency)}</dd></div>
                            </dl>
                            {Number(bill.order.received) < Number(bill.total) && <p className="mt-2 text-[12px] text-[#b45309]">لم يصل بعد ما يساوي قيمة السند كلها.</p>}
                        </Card>
                    )}
                    <Card><p className="text-[12px] text-muted">سجّله {bill.by ?? '—'}{bill.approver && <> · اعتمده {bill.approver} في <span className="num">{bill.approved_at}</span></>}. {bill.notes}</p></Card>
                </div>
            </div>
            {paying && <PayBill bill={bill} accounts={accounts} onClose={() => setPaying(false)} />}
            <ReasonDialog open={closing !== null} title={closing === 'void' ? 'إلغاء السند المعتمد' : 'رفض السند'} message={closing === 'void' ? 'يُعكس قيد الالتزام، ويبقى السند ملغى في السجل.' : undefined} confirm={closing === 'void' ? 'إلغاء السند' : 'رفض'} url={route('club.purchases.bills.decide', { supplierBill: bill.id, decision: closing ?? 'reject' })} onClose={() => setClosing(null)} />
            <ReasonDialog open={reversing !== null} title="عكس الدفعة" message="يُعكس قيدها ويعود المبلغ مستحقًا على السند." confirm="عكس الدفعة" url={reversing ? route('club.purchases.payments.reverse', reversing) : ''} onClose={() => setReversing(null)} />
        </ClubLayout>
    );
}
