import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, Notice, PageHeader, Select, BackLink } from '@/Components/ui';
import { Pill, ReasonDialog, SUPPLY_LABELS } from '@/Components/Inventory/parts';
import { number } from '@/lib/format';

interface Line { id: number; product: string | null; sku: string | null; quantity: string; approved_quantity: string | null; fulfil_by: string | null; source: string | null; balances: Record<number, number> }
interface Request {
    id: number; number: string; status: string; progress: string; notes: string | null; reason: string | null; branch_id: number; branch: string | null;
    by: string | null; reviewer: string | null; at: string; reviewed_at: string | null; needed_by: string | null; lines: Line[];
    transfers: { id: number; number: string; from: string | null; status: string }[]; order: { id: number; number: string; status: string } | null;
}

const qty = (v: number | string | null) => (v === null ? '—' : number(v, Number(v) % 1 ? 3 : 0));

/** A supply request: what the branch asked for, every branch's balance of it, and — for the reviewer — where each line comes from. */
export default function SupplyShow({ request, branches, suppliers, canReview }: { request: Request; branches: { id: number; name: string }[]; suppliers: { id: number; name: string }[]; canReview: boolean }) {
    const pending = request.status === 'pending';
    const others = branches.filter((b) => b.id !== request.branch_id);
    const [decisions, setDecisions] = useState<Record<number, { quantity: string; source: string }>>(() => Object.fromEntries(request.lines.map((l) => {
        const best = others.slice().sort((a, b) => (l.balances[b.id] ?? 0) - (l.balances[a.id] ?? 0))[0];
        return [l.id, { quantity: String(Number(l.quantity)), source: best && (l.balances[best.id] ?? 0) >= Number(l.quantity) ? String(best.id) : 'purchase' }];
    })));
    const [supplier, setSupplier] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [rejecting, setRejecting] = useState<'reject' | 'cancel' | null>(null);
    const buying = Object.values(decisions).some((d) => d.source === 'purchase' && Number(d.quantity) > 0);
    const approve = () => router.post(route('club.inventory.supply.review', { supplyRequest: request.id, decision: 'approve' }), { decisions, supplier_id: supplier || null }, { preserveScroll: true, onError: setErrors });

    return (
        <ClubLayout title={`طلب التزويد ${request.number}`}>
            <PageHeader
                back={<BackLink href={route('club.inventory.supply')}>طلبات التزويد</BackLink>}
                title={<span className="num">طلب التزويد {request.number}</span>}
                subtitle={`${request.branch} · طلبه ${request.by ?? '—'} في ${request.at}${request.needed_by ? ` · مطلوب قبل ${request.needed_by}` : ''}${request.reviewer ? ` · راجعه ${request.reviewer}` : ''}`}
                actions={<Pill tone={SUPPLY_LABELS[request.progress]?.[1] ?? 'gray'}>{SUPPLY_LABELS[request.progress]?.[0]}</Pill>}
            />
            {request.reason && <Notice tone="gray" className="mb-4">السبب: {request.reason}</Notice>}
            {Object.keys(errors).length > 0 && <Notice tone="red" className="mb-4">{Object.values(errors)[0]}</Notice>}
            <Card padded={false} className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>المنتج</th><th>المطلوب</th>{branches.map((b) => <th key={b.id}>{b.name}</th>)}{pending && canReview ? <><th>المعتمد</th><th>المصدر</th></> : <><th>المعتمد</th><th>المصدر</th></>}</tr></thead>
                        <tbody>
                            {request.lines.map((l) => (
                                <tr key={l.id}>
                                    <td className="font-medium">{l.product}</td>
                                    <td className="num">{qty(l.quantity)}</td>
                                    {branches.map((b) => <td key={b.id} className="text-[13px] text-[#6b7280] num">{qty(l.balances[b.id] ?? 0)}</td>)}
                                    {pending && canReview ? (
                                        <>
                                            <td><Input type="number" min="0" step="any" dir="ltr" className="w-24" value={decisions[l.id].quantity} onChange={(e) => setDecisions((d) => ({ ...d, [l.id]: { ...d[l.id], quantity: e.target.value } }))} aria-label={`المعتمد من ${l.product}`} /></td>
                                            <td>
                                                <Select className="w-44" value={decisions[l.id].source} onChange={(e) => setDecisions((d) => ({ ...d, [l.id]: { ...d[l.id], source: e.target.value } }))} aria-label={`مصدر ${l.product}`}>
                                                    {others.map((b) => <option key={b.id} value={b.id}>نقل من {b.name}</option>)}
                                                    <option value="purchase">شراء من مورد</option>
                                                </Select>
                                            </td>
                                        </>
                                    ) : (
                                        <><td className="num">{qty(l.approved_quantity)}</td><td className="text-[13px]">{l.fulfil_by === 'purchase' ? 'شراء' : l.source ? `نقل من ${l.source}` : '—'}</td></>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {pending && canReview && (
                    <div className="flex flex-wrap items-end justify-end gap-3 border-t border-line px-5 py-4">
                        {buying && (
                            <Field label="المورد لما سيُشترى" className="me-auto w-60" error={errors.supplier_id}>
                                <Select value={supplier} onChange={(e) => setSupplier(e.target.value)} placeholder="اختر المورد">{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>
                            </Field>
                        )}
                        <Button variant="ghost" icon={<X />} onClick={() => setRejecting('reject')}>رفض</Button>
                        <Button icon={<Check />} onClick={approve}>اعتماد وتحويل</Button>
                    </div>
                )}
                {pending && !canReview && (
                    <div className="flex justify-end border-t border-line px-5 py-4"><Button variant="outline" onClick={() => setRejecting('cancel')}>إلغاء الطلب</Button></div>
                )}
            </Card>
            {(request.transfers.length > 0 || request.order) && (
                <Card className="mt-4">
                    <CardHeader title="عمليات التزويد" />
                    <ul className="divide-y divide-line text-sm">
                        {request.transfers.map((t) => <li key={t.id} className="flex justify-between py-2"><Link href={route('club.inventory.transfers.show', t.id)} className="hover:underline">نقل <span className="num">{t.number}</span> من {t.from}</Link><span className="text-[#6b7280]">{t.status}</span></li>)}
                        {request.order && <li className="flex justify-between py-2"><Link href={route('club.purchases.show', request.order.id)} className="hover:underline">أمر شراء <span className="num">{request.order.number}</span></Link></li>}
                    </ul>
                </Card>
            )}
            <ReasonDialog open={rejecting !== null} title={rejecting === 'reject' ? 'رفض الطلب' : 'إلغاء الطلب'} confirm={rejecting === 'reject' ? 'رفض' : 'إلغاء الطلب'} url={route('club.inventory.supply.review', { supplyRequest: request.id, decision: rejecting ?? 'reject' })} onClose={() => setRejecting(null)} />
        </ClubLayout>
    );
}
