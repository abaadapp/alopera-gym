import { Fragment, useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown, Plus, RotateCcw, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodFilter from '@/Components/PeriodFilter';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Pagination, SearchInput, Select } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money } from '@/lib/format';
import type { Paginated } from '@/types';

type Line = { account: string; debit: string; credit: string; memo: string | null };
type Entry = { id: number; number: string; date: string; description: string; total: string; source: string; source_type: string | null; manual: boolean; reversed: boolean; is_reversal: boolean; by: string | null; lines: Line[] };

interface Props {
    entries: Paginated<Entry>;
    accounts: { id: number; code: string; name: string; type: string }[];
    sources: Record<string, string>;
    filters: { from: string; to: string; source: string | null; q: string | null };
    canManage: boolean;
}

export default function FinanceJournal({ entries, accounts, sources, filters, canManage }: Props) {
    const currency = useCurrency();
    const [open, setOpen] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <ClubLayout title="القيود اليومية">
            <PageHeader
                title="القيود اليومية"
                subtitle="كل قيد مزدوج ومتوازن. قيود الفواتير والتحصيل والمصروفات والرواتب يسجّلها النظام وتُصحَّح من مستندها؛ القيود اليدوية تُعكس من هنا ولا تُحذف."
                actions={canManage && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>قيد جديد</Button>}
            />

            <PeriodFilter routeName="club.finance.journal" from={filters.from} to={filters.to} extra={{ source: filters.source, q: filters.q }}>
                <Select className="h-8 w-44 py-1 text-[13px]" value={filters.source ?? ''} aria-label="المصدر"
                    onChange={(e) => router.get(route('club.finance.journal'), Object.fromEntries(Object.entries({ ...filters, source: e.target.value }).filter(([, v]) => v)) as Record<string, string>, { preserveState: true, replace: true })}>
                    <option value="">كل المصادر</option>
                    {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </Select>
            </PeriodFilter>

            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
                    <div className="w-full max-w-xs"><SearchInput value={filters.q} placeholder="رقم القيد أو البيان…" extra={{ from: filters.from, to: filters.to, source: filters.source }} /></div>
                    <span className="text-[13px] text-muted">{entries.total} قيدًا</span>
                </div>
                {entries.data.length ? (
                    <>
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th /><th>التاريخ</th><th>القيد</th><th>البيان</th><th>المصدر</th><th className="text-end">المبلغ</th><th /></tr></thead>
                                <tbody>
                                    {entries.data.map((e) => (
                                        <Fragment key={e.id}>
                                            <tr className="cursor-pointer" onClick={() => setOpen(open === e.id ? null : e.id)}>
                                                <td className="w-8"><ChevronDown className={cn('size-4 text-muted transition', open === e.id && 'rotate-180')} /></td>
                                                <td className="num">{date(e.date)}</td>
                                                <td className="num text-muted">{e.number}</td>
                                                <td className="max-w-96">
                                                    <span className={cn(e.reversed && 'text-muted line-through')}>{e.description}</span>
                                                    {e.reversed && <Badge tone="amber" className="ms-2">معكوس</Badge>}
                                                </td>
                                                <td><Badge tone={e.is_reversal ? 'amber' : e.manual ? 'violet' : 'gray'}>{e.source}</Badge></td>
                                                <td className="text-end font-medium num">{money(e.total, currency)}</td>
                                                <td className="text-end" onClick={(ev) => ev.stopPropagation()}>
                                                    {canManage && e.manual && !e.reversed && (
                                                        <ConfirmAction url={route('club.finance.journal.reverse', e.id)} title="عكس القيد" message={`سيُسجَّل قيد معاكس للقيد ${e.number} بتاريخ اليوم، ويبقى الأصل ظاهرًا مشطوبًا.`} variant="ghost" icon={<RotateCcw className="size-3.5" />}>عكس</ConfirmAction>
                                                    )}
                                                </td>
                                            </tr>
                                            {open === e.id && (
                                                <tr className="bg-[#fbfbfa] hover:bg-[#fbfbfa]">
                                                    <td />
                                                    <td colSpan={6} className="py-3">
                                                        <table className="w-full text-[13px]">
                                                            <thead><tr className="text-muted"><th className="pb-1 text-start font-medium">الحساب</th><th className="pb-1 text-start font-medium">البيان</th><th className="pb-1 text-end font-medium">مدين</th><th className="pb-1 text-end font-medium">دائن</th></tr></thead>
                                                            <tbody>
                                                                {e.lines.map((l, i) => (
                                                                    <tr key={i}>
                                                                        <td className="py-1">{l.account}</td>
                                                                        <td className="py-1 text-muted">{l.memo}</td>
                                                                        <td className="py-1 text-end num">{Number(l.debit) ? money(l.debit, currency) : ''}</td>
                                                                        <td className="py-1 text-end num">{Number(l.credit) ? money(l.credit, currency) : ''}</td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                        {e.by && <p className="mt-2 text-[12px] text-muted">سجّله: {e.by}</p>}
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination data={entries} />
                    </>
                ) : (
                    <EmptyState title="لا قيود في هذه الفترة" />
                )}
            </Card>

            {creating && <EntryForm accounts={accounts} onClose={() => setCreating(false)} />}
        </ClubLayout>
    );
}

type FormLine = { account_id: string; debit: string; credit: string; memo: string };

function EntryForm({ accounts, onClose }: { accounts: Props['accounts']; onClose: () => void }) {
    const currency = useCurrency();
    const blank = (): FormLine => ({ account_id: '', debit: '', credit: '', memo: '' });
    const form = useForm<{ date: string; description: string; lines: FormLine[] }>({ date: new Date().toISOString().slice(0, 10), description: '', lines: [blank(), blank()] });
    const sum = (key: 'debit' | 'credit') => form.data.lines.reduce((t, l) => t + Math.round(Number(l[key] || 0) * 1000), 0) / 1000;
    const diff = Math.round((sum('debit') - sum('credit')) * 1000) / 1000;
    const set = (i: number, patch: Partial<FormLine>) => form.setData('lines', form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.finance.journal.store'), { preserveScroll: true, onSuccess: onClose });
    };
    const errors = form.errors as Record<string, string>;

    return (
        <Modal open onClose={onClose} size="xl" title="قيد يدوي جديد" footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing} disabled={diff !== 0 || sum('debit') === 0}>ترحيل القيد</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                    <Field label="التاريخ" error={form.errors.date}><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} /></Field>
                    <Field label="البيان" error={form.errors.description} required><Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="إيداع رأس مال، تسوية، …" /></Field>
                </div>
                <div className="space-y-2">
                    {form.data.lines.map((l, i) => (
                        <div key={i} className="grid grid-cols-[1fr_6.5rem_6.5rem_2rem] items-start gap-2 sm:grid-cols-[1.4fr_1fr_7rem_7rem_2rem]">
                            <Select value={l.account_id} onChange={(e) => set(i, { account_id: e.target.value })} placeholder="الحساب" aria-label="الحساب">
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                            </Select>
                            <Input className="hidden sm:block" value={l.memo} onChange={(e) => set(i, { memo: e.target.value })} placeholder="بيان السطر" aria-label="بيان السطر" />
                            <Input type="number" step="0.001" min="0" dir="ltr" value={l.debit} onChange={(e) => set(i, { debit: e.target.value, credit: e.target.value ? '' : l.credit })} placeholder="مدين" aria-label="مدين" />
                            <Input type="number" step="0.001" min="0" dir="ltr" value={l.credit} onChange={(e) => set(i, { credit: e.target.value, debit: e.target.value ? '' : l.debit })} placeholder="دائن" aria-label="دائن" />
                            <button type="button" disabled={form.data.lines.length <= 2} onClick={() => form.setData('lines', form.data.lines.filter((_, j) => j !== i))} className="grid size-10 place-items-center rounded-lg text-muted hover:bg-red-50 hover:text-red-600 disabled:opacity-30" aria-label="حذف السطر"><Trash2 className="size-4" /></button>
                        </div>
                    ))}
                </div>
                {(errors.error || errors.lines) && <p className="text-xs font-medium text-red-600">{errors.error ?? errors.lines}</p>}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Button type="button" variant="outline" size="sm" icon={<Plus className="size-3.5" />} onClick={() => form.setData('lines', [...form.data.lines, blank()])}>سطر</Button>
                    <span className={cn('rounded-full px-3 py-1 text-[13px] font-medium', diff === 0 && sum('debit') > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700')}>
                        {diff === 0 ? (sum('debit') > 0 ? `متوازن · ${money(sum('debit'), currency)}` : 'أدخل المبالغ') : `الفرق ${money(Math.abs(diff), currency)}`}
                    </span>
                </div>
            </form>
        </Modal>
    );
}
