import { useMemo, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Banknote, Clock, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, EmptyState, Field, Input, Modal, PageHeader, Select, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { RunList, type RunDetail, type RunSummary } from './Runs';

type Payout = { id: number; number: string; paid_on: string; account: string | null; total: string; reference: string | null; items: number; by: string | null; period: string | null };
type MoneyAccount = { id: number; name: string; kind: string; balance: string };

interface Props {
    runs: (RunSummary & { unpaid: string })[];
    run: RunDetail | null;
    payouts: Payout[];
    waiting: string;
    accounts: MoneyAccount[];
    defaultAccount: number | null;
    can: { pay: boolean; reports: boolean };
}

export default function PayrollPayouts({ runs, run, payouts, waiting, accounts, defaultAccount, can }: Props) {
    const currency = useCurrency();
    const m = (v: string | number) => money(v, currency);
    const unpaid = useMemo(() => run?.items.filter((i) => !i.paid_at) ?? [], [run]);
    const [selected, setSelected] = useState<number[]>([]);
    const [paying, setPaying] = useState<number[] | null>(null);
    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    return (
        <ClubLayout title="صرف الرواتب">
            <PageHeader title="صرف الرواتب" subtitle="رواتب المسيرات المعتمدة: اصرفي لموظفة أو أكثر من الصندوق أو حساب بنكي، دون انتظار صرف الجميع" />

            <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <StatCard label="بانتظار الصرف في كل المسيرات" value={m(waiting)} icon={<Clock className="size-5" />} tone={Number(waiting) ? 'amber' : 'gray'} />
                <StatCard label={run ? `المتبقي في ${run.label}` : 'المتبقي'} value={m(run?.unpaid ?? '0')} icon={<Wallet className="size-5" />} tone="blue" />
                <StatCard label="دفعات الصرف المسجلة" value={payouts.length} icon={<Banknote className="size-5" />} tone="green" />
            </div>

            {runs.length === 0 ? (
                <Card><EmptyState icon={<Wallet className="size-6" />} title="لا مسيرات معتمدة" description="تظهر هنا المسيرات بعد اعتمادها." /></Card>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
                    <RunList runs={runs} selected={run?.id} routeName="club.payroll.payouts" m={m} />
                    {run && (
                        <div className="min-w-0 space-y-4">
                            <Card padded={false}>
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
                                    <CardHeader title={`رواتب ${run.label}`} subtitle={`${run.items.length - unpaid.length} من ${run.items.length} صُرفت`} className="mb-0" />
                                    {can.pay && unpaid.length > 0 && (
                                        <div className="flex gap-2">
                                            <Button variant="outline" disabled={selected.length === 0} onClick={() => setPaying(selected)}>صرف المحدد ({selected.length})</Button>
                                            <Button onClick={() => setPaying(unpaid.map((i) => i.id))}>صرف الباقي</Button>
                                        </div>
                                    )}
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="table-base">
                                        <thead>
                                            <tr>
                                                <th className="w-10">{can.pay && unpaid.length > 0 && <input type="checkbox" aria-label="تحديد الكل" className="size-4 accent-[var(--brand)]" checked={selected.length === unpaid.length} onChange={(e) => setSelected(e.target.checked ? unpaid.map((i) => i.id) : [])} />}</th>
                                                <th>الموظفة</th><th>طريقة الصرف</th><th className="text-end">الصافي</th><th>الحالة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {run.items.map((i) => (
                                                <tr key={i.id}>
                                                    <td>{can.pay && !i.paid_at && <input type="checkbox" aria-label={`تحديد ${i.name}`} className="size-4 accent-[var(--brand)]" checked={selected.includes(i.id)} onChange={() => toggle(i.id)} />}</td>
                                                    <td><span className="font-medium">{i.name}</span>{i.job_title && <span className="block text-xs text-muted">{i.job_title}</span>}</td>
                                                    <td className="text-muted">{i.payment_method === 'cash' ? 'نقدًا' : 'تحويل بنكي'}</td>
                                                    <td className="text-end font-semibold num">{m(i.net)}</td>
                                                    <td>{i.paid_at ? <Badge tone="green">صُرف {date(i.payout?.paid_on ?? i.paid_at)} · {i.payout?.number}</Badge> : <Badge tone="amber">بانتظار الصرف</Badge>}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>

                            <Card padded={false}>
                                <div className="p-5 pb-2"><CardHeader title="سجل الصرف" className="mb-0" /></div>
                                {payouts.length ? (
                                    <div className="overflow-x-auto">
                                        <table className="table-base">
                                            <thead><tr><th>الرقم</th><th>المسيرة</th><th>التاريخ</th><th>من حساب</th><th>الموظفات</th><th>المرجع</th><th className="text-end">المبلغ</th></tr></thead>
                                            <tbody>
                                                {payouts.map((p) => (
                                                    <tr key={p.id}>
                                                        <td className="num">{p.number}</td>
                                                        <td>{p.period}</td>
                                                        <td className="num">{date(p.paid_on)}</td>
                                                        <td>{p.account}</td>
                                                        <td className="num">{p.items}</td>
                                                        <td className="text-muted">{p.reference ?? '—'}</td>
                                                        <td className="text-end font-medium num">{m(p.total)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <EmptyState title="لا صرف بعد" />
                                )}
                            </Card>
                        </div>
                    )}
                </div>
            )}

            {paying && run && <PayForm run={run} ids={paying} accounts={accounts} defaultAccount={defaultAccount} onClose={() => { setPaying(null); setSelected([]); }} />}
        </ClubLayout>
    );
}

function PayForm({ run, ids, accounts, defaultAccount, onClose }: { run: RunDetail; ids: number[]; accounts: MoneyAccount[]; defaultAccount: number | null; onClose: () => void }) {
    const currency = useCurrency();
    const items = run.items.filter((i) => ids.includes(i.id) && !i.paid_at);
    const total = items.reduce((t, i) => t + Math.round(Number(i.net) * 1000), 0) / 1000;
    const form = useForm({ items: items.map((i) => i.id), account_id: String(defaultAccount ?? accounts[0]?.id ?? ''), paid_on: new Date().toISOString().slice(0, 10), reference: '' });
    const errors = form.errors as Record<string, string>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.payroll.pay', run.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} title={`صرف رواتب ${run.label}`} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>تسجيل الصرف</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-xl bg-[#f2f2f0] p-4">
                    <p className="text-[13px] text-muted">{items.length} موظفة</p>
                    <p className="mt-1 text-xl font-bold num">{money(total, currency)}</p>
                </div>
                <Field label="من حساب" error={errors.account_id} required>
                    <Select value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>
                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.name} — الرصيد {money(a.balance, currency)}</option>)}
                    </Select>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="تاريخ الصرف" error={errors.paid_on}><Input type="date" value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} /></Field>
                    <Field label="المرجع" error={errors.reference}><Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder="رقم التحويل البنكي" /></Field>
                </div>
                {errors.error && <p className="text-xs font-medium text-red-600">{errors.error}</p>}
                <p className="text-[12px] text-muted">يُسجَّل الصرف في الحسابات (تُخفض الرواتب المستحقة ورصيد الحساب). التسجيل هنا لا ينفّذ تحويلًا بنكيًا؛ نفّذي التحويل من البنك.</p>
            </form>
        </Modal>
    );
}
