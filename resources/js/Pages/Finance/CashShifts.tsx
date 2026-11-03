import { useRef, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, Lock, Plus, Printer, Vault } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { clientKey } from '@/Components/Inventory/parts';
import { Badge, Button, Card, CardHeader, DataTable, EmptyState, Field, Input, Modal, Notice, PageHeader, Pagination, Select, Textarea, type Column } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { paymentMethod } from '@/lib/labels';
import type { Paginated } from '@/types';

export type ShiftSummary = {
    methods: Record<string, { received: string; refunded: string; count: number }>;
    cash_received: string; cash_refunded: string; cash_expenses: string; moved_in: string; moved_out: string; expected: string; pending: number; payments: number;
};
type Movement = { id: number; direction: 'in' | 'out'; amount: string; account: string | null; reason: string; by: string | null; at: string };
export type ShiftRow = {
    id: number; number: string; status: 'open' | 'closed'; drawer: string | null; branch: string | null; opening_float: string; opened_at: string; opened_by: string | null;
    expected_cash: string | null; counted_cash: string | null; difference: string | null; close_note: string | null; closed_at: string | null; closed_by: string | null;
};
type OpenShift = ShiftRow & { summary: ShiftSummary; movements: Movement[] };

interface Props {
    open: OpenShift[];
    closed: Paginated<ShiftRow>;
    drawers: { id: number; name: string; last_counted: string | null }[];
    counterAccounts: { id: number; name: string; kind: string }[];
    canManage: boolean;
}

/*
 * ورديات الصندوق. A shift opens on a cash box with the cash counted in it; what is taken at the desk while it is open
 * belongs to it; it closes with the cash counted again, the difference posted to the books. Moving cash to the bank
 * or from the safe is a transfer, never revenue.
 */
export default function CashShifts({ open, closed, drawers, counterAccounts, canManage }: Props) {
    const currency = useCurrency();
    const [opening, setOpening] = useState(false);
    const [moving, setMoving] = useState<{ shift: OpenShift; direction: 'in' | 'out' } | null>(null);
    const [closing, setClosing] = useState<OpenShift | null>(null);
    const freeDrawers = drawers.filter((d) => !open.some((s) => s.drawer === d.name));

    const columns: Column<ShiftRow>[] = [
        { key: 'number', header: 'الوردية', cell: (s) => <Link href={route('club.cash-shifts.show', s.id)} className="font-medium text-[#1d4ed8] num hover:underline">{s.number}</Link> },
        { key: 'drawer', header: 'الصندوق', cell: (s) => <>{s.drawer}{s.branch && <p className="text-xs text-muted">{s.branch}</p>}</> },
        { key: 'opened', header: 'الفتح', cell: (s) => <span className="text-xs text-muted"><span className="num">{s.opened_at}</span> · {s.opened_by}</span> },
        { key: 'closed', header: 'الإقفال', cell: (s) => <span className="text-xs text-muted"><span className="num">{s.closed_at}</span> · {s.closed_by}</span> },
        { key: 'expected', header: 'المتوقع', align: 'end', cell: (s) => <span className="num">{money(s.expected_cash, currency)}</span> },
        { key: 'counted', header: 'المعدود', align: 'end', cell: (s) => <span className="num">{money(s.counted_cash, currency)}</span> },
        { key: 'difference', header: 'الفرق', align: 'end', cell: (s) => <Difference value={s.difference} note={s.close_note} /> },
    ];

    return (
        <ClubLayout title="ورديات الصندوق">
            <PageHeader
                title="ورديات الصندوق"
                subtitle="تُفتح الوردية بالنقد المعدود في الدرج، وتُقفل بعدّه مجددًا. المتوقع = الافتتاحي + المقبوض نقدًا − المردود والمصروف نقدًا ± نقل النقد. الفرق يُقيَّد في الدفاتر."
                actions={canManage && freeDrawers.length > 0 && <Button icon={<Plus className="size-4" />} onClick={() => setOpening(true)}>فتح وردية</Button>}
            />

            {open.length === 0 ? (
                <Notice tone="gray" icon={<Vault />} className="mb-5">لا توجد وردية مفتوحة. تُسجَّل الدفعات كالمعتاد، لكن لا يُطابَق الدرج إلا داخل وردية.</Notice>
            ) : (
                <div className="mb-5 grid gap-4 xl:grid-cols-2">
                    {open.map((s) => (
                        <Card key={s.id}>
                            <CardHeader
                                title={<span className="flex items-center gap-2"><span className="num">{s.number}</span><Badge tone="green">مفتوحة</Badge></span>}
                                subtitle={<>{s.drawer}{s.branch ? ` · ${s.branch}` : ''} · فتحها {s.opened_by} <span className="num">{s.opened_at}</span></>}
                                actions={<Button variant="ghost" size="sm" href={route('club.cash-shifts.show', s.id)} icon={<Printer className="size-3.5" />}>التقرير</Button>}
                            />
                            <ShiftFigures shift={s} summary={s.summary} />
                            {s.summary.pending > 0 && <Notice tone="amber" className="mt-3">{s.summary.pending} تحويل بانتظار التأكيد في هذه الوردية؛ لا يدخل النقد المتوقع.</Notice>}
                            {s.movements.length > 0 && (
                                <ul className="mt-3 space-y-1 border-t border-line pt-3 text-[13px]">
                                    {s.movements.map((m) => (
                                        <li key={m.id} className="flex flex-wrap justify-between gap-2">
                                            <span>{m.direction === 'out' ? 'إلى' : 'من'} {m.account} — {m.reason}</span>
                                            <span className={cn('num', m.direction === 'out' && 'text-red-600')}>{m.direction === 'out' ? '− ' : '+ '}{money(m.amount, currency)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {canManage && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" icon={<ArrowUpFromLine className="size-3.5" />} onClick={() => setMoving({ shift: s, direction: 'out' })}>نقل إلى البنك</Button>
                                    <Button size="sm" variant="outline" icon={<ArrowDownToLine className="size-3.5" />} onClick={() => setMoving({ shift: s, direction: 'in' })}>إضافة نقد</Button>
                                    <Button size="sm" icon={<Lock className="size-3.5" />} onClick={() => setClosing(s)}>إقفال الوردية</Button>
                                </div>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <Card padded={false}>
                <div className="border-b border-line p-4"><h3 className="font-semibold">الورديات المقفلة</h3></div>
                {closed.data.length ? (
                    <>
                        <DataTable rows={closed.data} columns={columns} rowKey={(s) => s.id} params={{}}
                            card={(s) => (
                                <Link href={route('club.cash-shifts.show', s.id)} className="block space-y-1 p-4">
                                    <div className="flex items-center justify-between"><span className="font-medium num">{s.number}</span><Difference value={s.difference} note={null} /></div>
                                    <p className="text-xs text-muted">{s.drawer} · <span className="num">{s.closed_at}</span> · {s.closed_by}</p>
                                    <p className="text-[13px]">المتوقع <span className="num">{money(s.expected_cash, currency)}</span> · المعدود <span className="num">{money(s.counted_cash, currency)}</span></p>
                                </Link>
                            )} />
                        <Pagination data={closed} />
                    </>
                ) : <EmptyState title="لا ورديات مقفلة بعد" />}
            </Card>

            {opening && <OpenDialog drawers={freeDrawers} onClose={() => setOpening(false)} />}
            {moving && <MoveDialog shift={moving.shift} direction={moving.direction} accounts={counterAccounts} onClose={() => setMoving(null)} />}
            {closing && <CloseDialog shift={closing} onClose={() => setClosing(null)} />}
        </ClubLayout>
    );
}

export function Difference({ value, note }: { value: string | null; note: string | null }) {
    const currency = useCurrency();
    const n = Number(value ?? 0);

    return (
        <span title={note ?? undefined} className={cn('num', n < 0 && 'text-red-600', n > 0 && 'text-[#047857]')}>
            {n === 0 ? 'متطابق' : `${n < 0 ? 'عجز' : 'زيادة'} ${money(Math.abs(n), currency)}`}
        </span>
    );
}

/** What passed through a shift: by method, then the drawer's cash line by line to the expected amount. */
export function ShiftFigures({ shift, summary }: { shift: ShiftRow; summary: ShiftSummary }) {
    const currency = useCurrency();
    const cash: [string, string, boolean?][] = [
        ['النقد الافتتاحي', shift.opening_float],
        ['مقبوض نقدًا', summary.cash_received],
        ['مردود نقدًا', summary.cash_refunded, true],
        ['مصروفات من الدرج', summary.cash_expenses, true],
        ['نقد أُضيف', summary.moved_in],
        ['نقد نُقل من الدرج', summary.moved_out, true],
    ];

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div>
                <p className="mb-1.5 text-[12px] font-semibold text-muted">حسب طريقة الدفع</p>
                {Object.keys(summary.methods).length === 0 ? <p className="text-[13px] text-muted">لا دفعات بعد.</p> : (
                    <dl className="space-y-1 text-[13px]">
                        {Object.entries(summary.methods).map(([method, m]) => (
                            <div key={method} className="flex justify-between gap-2">
                                <dt>{paymentMethod[method] ?? method} <span className="text-muted num">({m.count})</span></dt>
                                <dd className="num">{money(m.received, currency)}{Number(m.refunded) > 0 && <span className="text-red-600"> − {money(m.refunded, currency)}</span>}</dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>
            <div>
                <p className="mb-1.5 text-[12px] font-semibold text-muted">نقد الدرج</p>
                <dl className="space-y-1 text-[13px]">
                    {cash.filter(([, v], i) => i === 0 || Number(v) !== 0).map(([label, value, minus]) => (
                        <div key={label} className="flex justify-between gap-2"><dt>{label}</dt><dd className={cn('num', minus && 'text-red-600')}>{minus ? '− ' : ''}{money(value, currency)}</dd></div>
                    ))}
                    <div className="flex justify-between gap-2 border-t border-line pt-1 font-semibold"><dt>المتوقع في الدرج</dt><dd className="num">{money(summary.expected, currency)}</dd></div>
                </dl>
            </div>
        </div>
    );
}

function OpenDialog({ drawers, onClose }: { drawers: Props['drawers']; onClose: () => void }) {
    const currency = useCurrency();
    const key = useRef(clientKey());
    const form = useForm({ account_id: drawers[0] ? String(drawers[0].id) : '', opening_float: '' });
    const drawer = drawers.find((d) => String(d.id) === form.data.account_id);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((x) => ({ ...x, client_key: key.current }));
        form.post(route('club.cash-shifts.store'), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal open onClose={onClose} title="فتح وردية" size="compact"
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button type="submit" form="shift-open" loading={form.processing}>فتح الوردية</Button></>}>
            <form id="shift-open" onSubmit={submit} className="space-y-4">
                <Field label="الصندوق" error={form.errors.account_id} required>
                    <Select value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>
                        {drawers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </Select>
                </Field>
                <Field label="النقد المعدود في الدرج الآن" error={form.errors.opening_float} required hint={drawer?.last_counted != null ? `عُدّ عند آخر إقفال: ${money(drawer.last_counted, currency)}` : undefined}>
                    <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.opening_float} onChange={(e) => form.setData('opening_float', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

function MoveDialog({ shift, direction, accounts, onClose }: { shift: OpenShift; direction: 'in' | 'out'; accounts: Props['counterAccounts']; onClose: () => void }) {
    const currency = useCurrency();
    const key = useRef(clientKey());
    const choices = accounts.filter((a) => a.name !== shift.drawer);
    const preferred = choices.find((a) => a.kind === (direction === 'out' ? 'bank' : 'cash')) ?? choices[0];
    const form = useForm({ direction, amount: '', counter_account_id: preferred ? String(preferred.id) : '', reason: direction === 'out' ? 'إيداع في البنك' : 'نقد للفكة' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((x) => ({ ...x, client_key: key.current }));
        form.post(route('club.cash-shifts.move', shift.id), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal open onClose={onClose} title={direction === 'out' ? 'نقل نقد من الدرج' : 'إضافة نقد إلى الدرج'} size="compact" description="تحويل بين حسابين، لا إيراد ولا مصروف."
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button type="submit" form="shift-move" loading={form.processing}>تسجيل</Button></>}>
            <form id="shift-move" onSubmit={submit} className="space-y-4">
                {direction === 'out' && <p className="text-[13px] text-muted">المتوقع في الدرج: <span className="num font-medium text-ink">{money(shift.summary.expected, currency)}</span></p>}
                <Field label="المبلغ" error={form.errors.amount} required>
                    <Input type="number" step="0.001" min="0.001" dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                </Field>
                <Field label={direction === 'out' ? 'إلى حساب' : 'من حساب'} error={form.errors.counter_account_id} required>
                    <Select value={form.data.counter_account_id} onChange={(e) => form.setData('counter_account_id', e.target.value)}>
                        {choices.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </Select>
                </Field>
                <Field label="البيان" error={form.errors.reason} required>
                    <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
                {(form.errors as Record<string, string>).error && <Notice tone="red">{(form.errors as Record<string, string>).error}</Notice>}
            </form>
        </Modal>
    );
}

function CloseDialog({ shift, onClose }: { shift: OpenShift; onClose: () => void }) {
    const currency = useCurrency();
    const key = useRef(clientKey());
    const form = useForm({ counted_cash: '', close_note: '' });
    const difference = form.data.counted_cash === '' ? null : Math.round((Number(form.data.counted_cash) - Number(shift.summary.expected)) * 1000) / 1000;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((x) => ({ ...x, client_key: key.current }));
        form.post(route('club.cash-shifts.close', shift.id), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal open onClose={onClose} title={`إقفال الوردية ${shift.number}`} description="اعدد النقد في الدرج وأدخله. تُقفل الوردية مرة واحدة، ويُقيَّد أي فرق في الدفاتر."
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button type="submit" form="shift-close" loading={form.processing} disabled={difference !== null && difference !== 0 && form.data.close_note.trim() === ''} icon={<Lock className="size-4" />}>إقفال الوردية</Button></>}>
            <form id="shift-close" onSubmit={submit} className="space-y-4">
                <ShiftFigures shift={shift} summary={shift.summary} />
                <Field label="النقد المعدود" error={form.errors.counted_cash} required>
                    <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.counted_cash} onChange={(e) => form.setData('counted_cash', e.target.value)} required autoFocus />
                </Field>
                {difference !== null && (
                    <Notice tone={difference === 0 ? 'green' : 'amber'}>
                        {difference === 0 ? 'المعدود يطابق المتوقع.' : <>الفرق: <Difference value={String(difference)} note={null} />. اذكر سببه، ويُقيَّد في «فروقات الصندوق».</>}
                    </Notice>
                )}
                {difference !== null && difference !== 0 && (
                    <Field label="سبب الفرق" error={form.errors.close_note} required>
                        <Textarea rows={2} value={form.data.close_note} onChange={(e) => form.setData('close_note', e.target.value)} />
                    </Field>
                )}
                {form.errors.close_note && difference === 0 && <Notice tone="red">{form.errors.close_note}</Notice>}
                {(form.errors as Record<string, string>).error && <Notice tone="red">{(form.errors as Record<string, string>).error}</Notice>}
            </form>
        </Modal>
    );
}
