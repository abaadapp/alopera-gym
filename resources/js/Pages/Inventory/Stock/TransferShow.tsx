import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, Check, PackageCheck, Send, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, Modal, Notice, PageHeader, Select, Textarea, BackLink } from '@/Components/ui';
import { Pill, ReasonDialog, Steps, TRANSFER_TONES } from '@/Components/Inventory/parts';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';

interface Line { id: number; product: string | null; sku: string | null; requested_quantity: string; approved_quantity: string | null; sent_quantity: string; received_quantity: string; lost_quantity: string; returned_quantity: string; late_quantity: string; outstanding: number; available: number; note: string | null }
interface Transfer { id: number; number: string; status: string; status_label: string; from: string | null; to: string | null; notes: string | null; reason: string | null; steps: { label: string; by: string | null; at: string | null }[]; lines: Line[] }

const qty = (v: number | string | null) => (v === null ? '—' : number(v, Number(v) % 1 ? 3 : 0));

/** One transfer: its steps, its lines (requested, approved, sent, received, still missing) and the next step. */
export default function TransferShow({ transfer, can }: { transfer: Transfer; can: { approve: boolean; send: boolean; receive: boolean } }) {
    const [qtys, setQtys] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState(false);
    const [closing, setClosing] = useState<'reject' | 'cancel' | null>(null);
    const [resolving, setResolving] = useState<Line | null>(null);
    const step = (name: string, extra: Record<string, unknown> = {}) => router.post(route('club.inventory.transfers.step', { transfer: transfer.id, step: name }), { quantities: qtys, ...extra }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setQtys({}); } });
    const s = transfer.status;
    const editable = (s === 'pending' && can.approve) || (s === 'in_transit' && can.receive);
    const editLabel = s === 'pending' ? 'المعتمد' : 'المستلم';
    const short = transfer.lines.some((l) => s === 'in_transit' && qtys[l.id] !== undefined && qtys[l.id] !== '' && Number(qtys[l.id]) < Number(l.sent_quantity));

    return (
        <ClubLayout title={`النقل ${transfer.number}`}>
            <PageHeader
                back={<BackLink href={route('club.inventory.transfers')}>نقل المخزون</BackLink>}
                title={<span className="inline-flex items-center gap-2"><span className="num">{transfer.number}</span><span className="text-[15px] font-medium text-[#6b7280]">{transfer.from} <ArrowLeft className="inline size-4" /> {transfer.to}</span></span>}
                actions={<Pill tone={TRANSFER_TONES[s] ?? 'gray'}>{transfer.status_label}</Pill>}
            />
            <Card className="mb-4"><Steps steps={transfer.steps} /></Card>
            {transfer.reason && <Notice tone="gray" className="mb-4">السبب: {transfer.reason}</Notice>}
            {s === 'discrepancy' && <Notice tone="red" className="mb-4">وصل أقل مما أُرسل. سوِّ كل كمية ناقصة: فُقدت، أو عادت للفرع المرسل، أو وصلت متأخرة.</Notice>}

            <Card padded={false} className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>المنتج</th><th>المطلوب</th><th>المعتمد</th><th>المرسل</th><th>المستلم</th><th>الناقص</th>{s === 'pending' && <th>المتوفر لدى المرسل</th>}{editable && <th>{editLabel}</th>}{s === 'discrepancy' && <th />}</tr></thead>
                        <tbody>
                            {transfer.lines.map((l) => (
                                <tr key={l.id}>
                                    <td><p className="font-medium">{l.product}</p>{l.note && <p className="text-[11px] text-[#9ca3af]">{l.note}</p>}</td>
                                    <td className="num">{qty(l.requested_quantity)}</td>
                                    <td className="num">{qty(l.approved_quantity)}</td>
                                    <td className="num">{qty(l.sent_quantity)}</td>
                                    <td className="num">{qty(l.received_quantity)}{Number(l.lost_quantity) + Number(l.returned_quantity) + Number(l.late_quantity) > 0 && <span className="block text-[11px] text-[#9ca3af]">فاقد {qty(l.lost_quantity)} · عاد {qty(l.returned_quantity)} · متأخر {qty(l.late_quantity)}</span>}</td>
                                    <td className={cn('num', l.outstanding > 0 && 'font-semibold text-red-700')}>{qty(l.outstanding)}</td>
                                    {s === 'pending' && <td className={cn('num', l.available < Number(l.requested_quantity) && 'text-red-700')}>{qty(l.available)}</td>}
                                    {editable && <td><Input type="number" min="0" step="any" dir="ltr" className="w-24" placeholder={String(Number(s === 'pending' ? l.requested_quantity : l.sent_quantity))} value={qtys[l.id] ?? ''} onChange={(e) => setQtys((q) => ({ ...q, [l.id]: e.target.value }))} aria-label={`${editLabel} من ${l.product}`} /></td>}
                                    {s === 'discrepancy' && <td>{l.outstanding > 0 && <Button size="sm" variant="outline" onClick={() => setResolving(l)}>تسوية</Button>}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {(['pending', 'approved', 'in_transit'] as string[]).includes(s) && (
                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-4">
                        {s === 'in_transit' && short && <p className="me-auto text-[12px] text-red-700">ستُستلم كمية أقل من المرسل؛ يبقى الفرق للتسوية.</p>}
                        {s === 'pending' && can.approve && <><Button variant="ghost" icon={<X />} onClick={() => setClosing('reject')}>رفض</Button><Button icon={<Check />} loading={busy} onClick={() => step('approve')}>اعتماد</Button></>}
                        {(s === 'pending' || s === 'approved') && can.send && <Button variant="outline" onClick={() => setClosing('cancel')}>إلغاء</Button>}
                        {s === 'approved' && can.send && <Button icon={<Send />} loading={busy} onClick={() => step('dispatch')}>تأكيد الإرسال</Button>}
                        {s === 'in_transit' && can.receive && <Button icon={<PackageCheck />} loading={busy} onClick={() => step('receive')}>تأكيد الاستلام</Button>}
                    </div>
                )}
            </Card>
            {transfer.notes && <Card className="mt-4"><CardHeader title="ملاحظات" /><p className="whitespace-pre-line text-sm text-[#4b4b4b]">{transfer.notes}</p></Card>}

            <ReasonDialog open={closing !== null} title={closing === 'reject' ? 'رفض طلب النقل' : 'إلغاء النقل'} message="لم يخرج شيء من المخزون بعد." confirm={closing === 'reject' ? 'رفض' : 'إلغاء النقل'} url={route('club.inventory.transfers.step', { transfer: transfer.id, step: closing ?? 'cancel' })} onClose={() => setClosing(null)} />
            {resolving && <Resolve transfer={transfer} line={resolving} onClose={() => setResolving(null)} />}
        </ClubLayout>
    );
}

function Resolve({ transfer, line, onClose }: { transfer: Transfer; line: Line; onClose: () => void }) {
    const [outcome, setOutcome] = useState('lost');
    const [quantity, setQuantity] = useState(String(line.outstanding));
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submit = () => router.post(route('club.inventory.transfers.step', { transfer: transfer.id, step: 'resolve' }), { line_id: line.id, outcome, quantity, reason }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });

    return (
        <Modal open onClose={onClose} size="sm" title={`تسوية الناقص: ${line.product}`} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button onClick={submit}>تسجيل</Button></>}>
            <div className="space-y-4">
                <Field label="ما حدث للكمية" error={errors.outcome}>
                    <Select value={outcome} onChange={(e) => setOutcome(e.target.value)}>
                        <option value="lost">فُقدت أو تلفت أثناء النقل (تُشطب)</option>
                        <option value="returned">عادت إلى الفرع المرسل</option>
                        <option value="late">وصلت متأخرة إلى الفرع المستقبل</option>
                    </Select>
                </Field>
                <Field label={`الكمية (الناقص ${line.outstanding})`} error={errors.quantity}><Input type="number" min="0" step="any" dir="ltr" value={quantity} onChange={(e) => setQuantity(e.target.value)} /></Field>
                <Field label="السبب" error={errors.reason} required><Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} /></Field>
            </div>
        </Modal>
    );
}
