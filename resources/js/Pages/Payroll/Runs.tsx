import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Banknote, CheckCircle2, Download, FileText, MinusCircle, Pencil, Plus, Printer, Trash2, Wallet, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money, number } from '@/lib/format';

export type RunSummary = { id: number; number: string; period: string; label: string; status: string; gross: string; deductions: string; net: string; items_count: number | null; paid_count: number | null };
export type Item = {
    id: number; employee_id: number; name: string; job_title: string | null; basic: string; allowances: string; allowance_lines: { name: string; amount: string }[];
    overtime_hours: string; overtime: string; deductions: string; deduction_notes: string | null; earnings: string; net: string; notes: string | null;
    attendance_days: number | null; attendance_hours: string | null; payment_method: string; paid_at: string | null; payout: { number: string; paid_on: string } | null;
};
export type RunDetail = RunSummary & { approved_at: string | null; approver: string | null; month_end: string; items: Item[]; unpaid: string };
type Can = { prepare: boolean; edit: boolean; approve: boolean; pay: boolean; payslips: boolean; reports: boolean };

export const runStatus: Record<string, ['مسودة' | 'معتمدة' | 'مصروفة', 'gray' | 'blue' | 'green']> = { draft: ['مسودة', 'gray'], approved: ['معتمدة', 'blue'], paid: ['مصروفة', 'green'] };

interface Props {
    runs: RunSummary[];
    run: RunDetail | null;
    months: { value: string; label: string }[];
    can: Can;
}

export default function PayrollRuns({ runs, run, months, can }: Props) {
    const currency = useCurrency();
    const m = (v: string) => money(v, currency);
    const [opening, setOpening] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);
    const draft = run?.status === 'draft';

    return (
        <ClubLayout title="مسيرات الرواتب">
            <PageHeader
                title="مسيرات الرواتب"
                subtitle="مسيرة لكل شهر: تُجهَّز مسودة، ثم تُعتمد فتُسجَّل الرواتب المستحقة في الحسابات، ثم تُصرف"
                actions={can.prepare && months.length > 0 && <Button icon={<Plus className="size-4" />} onClick={() => setOpening(true)}>مسيرة جديدة</Button>}
            />

            {runs.length === 0 ? (
                <Card><EmptyState icon={<FileText className="size-6" />} title="لا مسيرات بعد" description="افتح مسيرة الشهر؛ يُجلب الموظفون على رأس العمل برواتبهم وبدلاتهم من ملفاتهم." action={can.prepare && <Button variant="outline" onClick={() => setOpening(true)}>مسيرة جديدة</Button>} /></Card>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
                    <RunList runs={runs} selected={run?.id} routeName="club.payroll.index" m={m} />

                    {run && (
                        <div className="min-w-0 space-y-4">
                            <Card>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-lg font-semibold">رواتب {run.label}</h2>
                                            <Badge tone={runStatus[run.status][1]}>{runStatus[run.status][0]}</Badge>
                                        </div>
                                        <p className="mt-0.5 text-[13px] text-muted num">{run.number}{run.approved_at && ` · اعتمدها ${run.approver ?? ''} في ${date(run.approved_at)}`}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {can.payslips && <Button variant="outline" size="sm" href={route('club.payroll.print', run.id)} external icon={<Printer className="size-3.5" />}>طباعة الكشف</Button>}
                                        {can.payslips && <Button variant="outline" size="sm" href={route('club.papers.pdf', { type: 'payroll_sheet', id: run.id, download: 1 })} external icon={<Download className="size-3.5" />}>PDF</Button>}
                                        {can.reports && <Button variant="outline" size="sm" href={route('club.payroll.export', { run: run.id, format: 'xlsx' })} external icon={<Download className="size-3.5" />}>Excel</Button>}
                                        {draft && can.edit && (
                                            <ConfirmAction url={route('club.payroll.destroy', run.id)} method="delete" title="حذف المسودة" message={`ستُحذف مسودة ${run.label} وسطورها.`} variant="ghost" size="sm" icon={<Trash2 className="size-3.5" />}>حذف</ConfirmAction>
                                        )}
                                        {draft && can.approve && (
                                            <ConfirmAction url={route('club.payroll.approve', run.id)} title={`اعتماد رواتب ${run.label}`} message={`سيُسجَّل ${m(run.net)} رواتب مستحقة في الحسابات، ولن تُعدَّل سطور المسيرة بعد الاعتماد.`} variant="primary" size="sm" icon={<CheckCircle2 className="size-3.5" />}>اعتماد</ConfirmAction>
                                        )}
                                        {run.status === 'approved' && can.pay && <Button size="sm" href={route('club.payroll.payouts', { run: run.id })} icon={<Wallet className="size-3.5" />}>إلى الصرف</Button>}
                                    </div>
                                </div>
                            </Card>

                            <div className="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <StatCard label="المستحقات" value={m(run.gross)} hint="أساسي + بدلات + إضافي" icon={<Banknote />} tone="blue" />
                                <StatCard label="الخصومات" value={m(run.deductions)} icon={<MinusCircle />} tone="amber" />
                                <StatCard label="الصافي" value={m(run.net)} hint={`${run.items.length} موظف`} icon={<Wallet />} tone="green" />
                            </div>

                            <Card padded={false}>
                                <div className="overflow-x-auto">
                                    <table className="table-base">
                                        <thead><tr><th>الموظف</th><th>الحضور</th><th className="text-end">الأساسي</th><th className="text-end">البدلات</th><th className="text-end">الإضافي</th><th className="text-end">الخصومات</th><th className="text-end">الصافي</th><th>الصرف</th><th /></tr></thead>
                                        <tbody>
                                            {run.items.map((i) => (
                                                <tr key={i.id}>
                                                    <td><span className="font-medium">{i.name}</span>{i.job_title && <span className="block text-xs text-muted">{i.job_title}</span>}</td>
                                                    <td className="text-[13px] text-muted">{i.attendance_days !== null ? `${i.attendance_days} يوم · ${number(i.attendance_hours, 1)} س` : '—'}</td>
                                                    <td className="text-end num">{m(i.basic)}</td>
                                                    <td className="text-end num">{Number(i.allowances) ? m(i.allowances) : '—'}</td>
                                                    <td className="text-end num">{Number(i.overtime) ? m(i.overtime) : '—'}</td>
                                                    <td className="text-end text-amber-700 num">{Number(i.deductions) ? m(i.deductions) : '—'}{i.deduction_notes && <span className="block text-[11px] text-muted">{i.deduction_notes}</span>}</td>
                                                    <td className="text-end font-semibold num">{m(i.net)}</td>
                                                    <td className="text-[13px]">{i.paid_at ? <Badge tone="green">صُرف {date(i.paid_at)}</Badge> : <span className="text-muted">{run.status === 'draft' ? '—' : 'بانتظار الصرف'}</span>}</td>
                                                    <td className="text-end">
                                                        <span className="inline-flex gap-1">
                                                            {can.payslips && <a href={route('club.payroll.print', { run: run.id, item: i.id })} target="_blank" rel="noreferrer" className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" title="كشف راتب الموظف"><FileText className="size-4" /></a>}
                                                            {draft && can.edit && (
                                                                <>
                                                                    <button type="button" onClick={() => setEditing(i)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" aria-label={`تعديل ${i.name}`}><Pencil className="size-4" /></button>
                                                                    <ConfirmAction url={route('club.payroll.items.destroy', i.id)} method="delete" title="إزالة من المسيرة" message={`سيُزال ${i.name} من مسيرة هذا الشهر.`} variant="ghost" size="sm" icon={<X className="size-4" />}>{''}</ConfirmAction>
                                                                </>
                                                            )}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                            <p className="text-[12px] text-muted">الصافي = الأساسي + البدلات + الإضافي − الخصومات، بالريال العماني حتى 3 منازل. سطور المسيرة نسخة من الملفات وقت فتحها: تعديل راتب لاحقًا لا يغيّرها.</p>
                        </div>
                    )}
                </div>
            )}

            {opening && <OpenRun months={months} onClose={() => setOpening(false)} />}
            {editing && <EditItem item={editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

export function RunList({ runs, selected, routeName, m }: { runs: (RunSummary & { unpaid?: string })[]; selected?: number; routeName: string; m: (v: string) => string }) {
    return (
        <Card padded={false} className="lg:sticky lg:top-20">
            <ul className="divide-y divide-line">
                {runs.map((r) => (
                    <li key={r.id}>
                        <Link href={route(routeName, { run: r.id })} preserveScroll className={cn('flex items-center justify-between gap-3 px-4 py-3 transition', selected === r.id ? 'bg-[#f2f2f0]' : 'hover:bg-[#fafafa]')}>
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold">{r.label}</span>
                                <span className="block text-xs text-muted num">{r.items_count ?? 0} موظف · {m(r.unpaid ?? r.net)}{r.unpaid !== undefined && ' متبقٍ'}</span>
                            </span>
                            <Badge tone={runStatus[r.status][1]}>{runStatus[r.status][0]}</Badge>
                        </Link>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function OpenRun({ months, onClose }: { months: { value: string; label: string }[]; onClose: () => void }) {
    const form = useForm({ month: months[0]?.value ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.payroll.store'), { onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} size="sm" title="مسيرة جديدة" footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>فتح المسيرة</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="الشهر" error={form.errors.month}><Select value={form.data.month} onChange={(e) => form.setData('month', e.target.value)}>{months.map((mo) => <option key={mo.value} value={mo.value}>{mo.label}</option>)}</Select></Field>
                <p className="text-[13px] text-muted">تُنسخ الرواتب والبدلات من ملفات الموظفين على رأس العمل، مع أيام الحضور وساعاته في الشهر.</p>
            </form>
        </Modal>
    );
}

function EditItem({ item, onClose }: { item: Item; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm({ overtime_hours: item.overtime_hours, overtime: item.overtime, allowances: item.allowances, deductions: item.deductions, deduction_notes: item.deduction_notes ?? '', notes: item.notes ?? '' });
    const net = Math.round((Number(item.basic) + Number(form.data.allowances || 0) + Number(form.data.overtime || 0) - Number(form.data.deductions || 0)) * 1000) / 1000;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.payroll.items.update', item.id), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={`سطر ${item.name}`} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-[13px] text-muted">الأساسي {money(item.basic, currency)}{item.attendance_days !== null && ` · حضر ${item.attendance_days} يومًا (${number(item.attendance_hours, 1)} ساعة)`}</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="البدلات" error={form.errors.allowances} hint={item.allowance_lines.map((a) => a.name).join('، ') || undefined}><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.allowances} onChange={(e) => form.setData('allowances', e.target.value)} /></Field>
                    <Field label="ساعات إضافية" error={form.errors.overtime_hours}><Input type="number" step="0.25" min="0" dir="ltr" value={form.data.overtime_hours} onChange={(e) => form.setData('overtime_hours', e.target.value)} /></Field>
                    <Field label="مبلغ الإضافي" error={form.errors.overtime}><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.overtime} onChange={(e) => form.setData('overtime', e.target.value)} /></Field>
                    <Field label="الخصومات" error={form.errors.deductions}><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.deductions} onChange={(e) => form.setData('deductions', e.target.value)} /></Field>
                </div>
                <Field label="سبب الخصم" error={form.errors.deduction_notes}><Input value={form.data.deduction_notes} onChange={(e) => form.setData('deduction_notes', e.target.value)} placeholder="غياب، تأخير، سلفة…" /></Field>
                <Field label="ملاحظة" error={form.errors.notes}><Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                <div className={cn('rounded-xl p-3 text-sm font-semibold', net < 0 ? 'bg-red-50 text-red-700' : 'bg-[#f2f2f0]')}>الصافي: <span className="num">{money(net, currency)}</span></div>
            </form>
        </Modal>
    );
}
