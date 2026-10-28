import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeftRight, Banknote, CreditCard, Landmark, Pencil, Plus, Scale, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, EmptyState, Field, Input, Modal, PageHeader, Select, StatCard, Toggle } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money } from '@/lib/format';
import { kindIcon } from './Summary';

type Account = { id: number; code: string; name: string; kind: string; balance: string; bank_name: string | null; masked: string | null; is_active: boolean; system: boolean; opening: { amount: string; date: string } | null };

interface Props {
    accounts: Account[];
    totals: { cash: string; banks: string; clearing: string };
    kinds: Record<string, string>;
    canManage: boolean;
}

const today = () => new Date().toISOString().slice(0, 10);

export default function FinanceBanks({ accounts, totals, kinds, canManage }: Props) {
    const currency = useCurrency();
    const m = (v: string) => money(v, currency);
    const [editing, setEditing] = useState<Account | 'new' | null>(null);
    const [opening, setOpening] = useState<Account | null>(null);
    const [transfer, setTransfer] = useState(false);

    return (
        <ClubLayout title="الحسابات البنكية">
            <PageHeader
                title="الحسابات البنكية"
                subtitle="الصندوق والحسابات البنكية، وحسابات تسوية البطاقات وPaymob التي تنتظر فيها المبالغ حتى تصل البنك"
                actions={canManage && (
                    <>
                        <Button variant="outline" icon={<ArrowLeftRight className="size-4" />} onClick={() => setTransfer(true)}>تحويل بين الحسابات</Button>
                        <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>حساب جديد</Button>
                    </>
                )}
            />

            <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
                <StatCard label="الصناديق النقدية" value={m(totals.cash)} icon={<Banknote className="size-5" />} tone="green" />
                <StatCard label="الحسابات البنكية" value={m(totals.banks)} icon={<Landmark className="size-5" />} tone="blue" />
                <StatCard label="قيد التسوية" value={m(totals.clearing)} hint="بطاقات وPaymob لم تصل البنك بعد" icon={<CreditCard className="size-5" />} tone="violet" />
            </div>

            {accounts.length ? (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {accounts.map((a) => {
                        const Icon = kindIcon[a.kind] ?? Wallet;
                        return (
                            <div key={a.id} className={cn('card flex flex-col p-5', !a.is_active && 'opacity-60')}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold text-ink">{a.name}</p>
                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                            <Badge>{kinds[a.kind]}</Badge>
                                            {a.system && <Badge tone="blue">أساسي</Badge>}
                                            {!a.is_active && <Badge tone="amber">موقوف</Badge>}
                                        </div>
                                        {(a.bank_name || a.masked) && <p className="mt-2 text-[12px] text-muted">{a.bank_name} <span dir="ltr" className="font-mono">{a.masked}</span></p>}
                                    </div>
                                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#f2f2f0] text-[#4b4b4b]"><Icon className="size-5" /></span>
                                </div>
                                <div className="mt-4 flex items-end justify-between gap-3">
                                    <p className={cn('text-xl font-bold tracking-tight num', Number(a.balance) < 0 && 'text-red-700')}>{m(a.balance)}</p>
                                    <span className="text-[12px] text-muted num">{a.code}</span>
                                </div>
                                {a.opening && <p className="mt-1 text-[12px] text-muted">رصيد افتتاحي {m(a.opening.amount)} في {date(a.opening.date)}</p>}
                                <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                                    <Button size="sm" variant="ghost" href={route('club.finance.movement', { account_id: a.id })} icon={<Scale className="size-3.5" />}>الحركة</Button>
                                    {canManage && (
                                        <>
                                            <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(a)}>تعديل</Button>
                                            {a.is_active && <Button size="sm" variant="ghost" onClick={() => setOpening(a)}>الرصيد الافتتاحي</Button>}
                                        </>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="card"><EmptyState icon={<Landmark className="size-6" />} title="لا حسابات بعد" /></div>
            )}
            <p className="mt-3 text-[12px] text-muted">
                أرقام الحسابات تُحفظ مشفرة وتظهر مختصرة. حساب يحمل رصيدًا لا يُوقف قبل نقل رصيده. لعرض كل حركة حساب افتحي <Link href={route('club.finance.movement')} className="underline">الحركة المالية</Link>.
            </p>

            {editing && <AccountForm account={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {opening && <OpeningForm account={opening} onClose={() => setOpening(null)} />}
            {transfer && <TransferForm accounts={accounts.filter((a) => a.is_active)} onClose={() => setTransfer(false)} />}
        </ClubLayout>
    );
}

function AccountForm({ account, onClose }: { account: Account | null; onClose: () => void }) {
    const form = useForm({ name: account?.name ?? '', kind: account?.kind ?? 'bank', bank_name: account?.bank_name ?? '', account_number: '', iban: '', opening_balance: '', opening_date: today(), is_active: account?.is_active ?? true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        account ? form.put(route('club.finance.banks.update', account.id), opts) : form.post(route('club.finance.banks.store'), opts);
    };
    const isMoneyBox = form.data.kind === 'cash';

    return (
        <Modal open onClose={onClose} title={account ? `تعديل «${account.name}»` : 'حساب جديد'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="اسم الحساب" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="بنك مسقط — الحساب الجاري" autoFocus /></Field>
                {!account && (
                    <Field label="النوع" error={form.errors.kind}>
                        <Select value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                            <option value="bank">حساب بنكي</option>
                            <option value="cash">صندوق نقدي (مثل صندوق فرع)</option>
                        </Select>
                    </Field>
                )}
                {!isMoneyBox && (account?.kind ?? form.data.kind) !== 'card' && (account?.kind ?? form.data.kind) !== 'gateway' && (
                    <>
                        <Field label="اسم البنك" error={form.errors.bank_name}><Input value={form.data.bank_name} onChange={(e) => form.setData('bank_name', e.target.value)} /></Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="رقم الحساب" error={form.errors.account_number} hint={account?.masked ? `المحفوظ: ${account.masked} — اتركيه فارغًا لإبقائه` : undefined}><Input dir="ltr" value={form.data.account_number} onChange={(e) => form.setData('account_number', e.target.value)} /></Field>
                            <Field label="IBAN" error={form.errors.iban}><Input dir="ltr" value={form.data.iban} onChange={(e) => form.setData('iban', e.target.value)} placeholder="OM…" /></Field>
                        </div>
                    </>
                )}
                {!account && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="الرصيد الافتتاحي" error={form.errors.opening_balance} hint="ما في الحساب يوم بدء استخدام النظام"><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.opening_balance} onChange={(e) => form.setData('opening_balance', e.target.value)} /></Field>
                        <Field label="بتاريخ" error={form.errors.opening_date}><Input type="date" value={form.data.opening_date} onChange={(e) => form.setData('opening_date', e.target.value)} /></Field>
                    </div>
                )}
                {account && !account.system && <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="الحساب مفعّل" hint={form.errors.is_active ?? 'الحساب الموقوف لا يُستخدم في عمليات جديدة'} />}
            </form>
        </Modal>
    );
}

function OpeningForm({ account, onClose }: { account: Account; onClose: () => void }) {
    const form = useForm({ amount: account.opening?.amount ?? '', date: account.opening?.date ?? today() });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.finance.banks.opening', account.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} size="sm" title={`الرصيد الافتتاحي — ${account.name}`} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-[13px] text-muted">يُسجَّل قيدًا مقابل «الأرصدة الافتتاحية». تعديله لاحقًا يعكس القيد السابق ويسجّل الجديد، فيبقى السجل كاملًا.</p>
                <Field label="المبلغ" error={form.errors.amount} required><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} autoFocus /></Field>
                <Field label="بتاريخ" error={form.errors.date}><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} /></Field>
            </form>
        </Modal>
    );
}

function TransferForm({ accounts, onClose }: { accounts: Account[]; onClose: () => void }) {
    const form = useForm({ from_id: '', to_id: '', amount: '', date: today(), note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.finance.transfers.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} title="تحويل بين الحسابات" footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>تسجيل التحويل</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-[13px] text-muted">مثل إيداع نقد الصندوق في البنك، أو وصول تسوية البطاقات وPaymob إلى الحساب البنكي.</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="من حساب" error={form.errors.from_id} required>
                        <Select value={form.data.from_id} onChange={(e) => form.setData('from_id', e.target.value)} placeholder="اختاري">
                            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="إلى حساب" error={form.errors.to_id} required>
                        <Select value={form.data.to_id} onChange={(e) => form.setData('to_id', e.target.value)} placeholder="اختاري">
                            {accounts.filter((a) => String(a.id) !== form.data.from_id).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </Select>
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="المبلغ" error={form.errors.amount} required><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                    <Field label="التاريخ" error={form.errors.date}><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} /></Field>
                </div>
                <Field label="ملاحظة" error={form.errors.note}><Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="رقم الإيداع أو المرجع" /></Field>
            </form>
        </Modal>
    );
}
