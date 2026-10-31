import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowRight, Briefcase, CalendarClock, Check, Clock, Contact, Copy, FileText, KeyRound, Lock, Pencil, Plus, Printer, ShieldCheck, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, Notice, PageHeader, Select, StatCard, Tabs, Toggle } from '@/Components/ui';
import { FactList, Initials } from '@/Components/Payroll/parts';
import { useCurrency, useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money, number, phone } from '@/lib/format';
import { statusTone, type Can, type EmployeeRow } from './Employees';

type Account = {
    id: number; name: string; email: string; phone: string | null; is_active: boolean; last_login_at: string | null;
    role_id: number | null; role: string | null; role_key: string | null; branch_id: number | null; branch: string | null;
    own_permissions: boolean; custom: string[] | null; is_me: boolean; may_manage: boolean;
};
type Access = {
    roles: { id: number; name: string; key: string; assignable: boolean; permissions: string[] }[];
    branches: { id: number; name: string }[];
    groups: { key: string; label: string; permissions: { key: string; label: string }[] }[];
    grantable: string[];
};
type HistoryRow = { id: number; event: string; actor: string | null; at: string; old: Record<string, unknown> | null; new: Record<string, unknown> | null; properties: Record<string, unknown> | null };

interface Props {
    employee: EmployeeRow & { trainer_id: number | null; notes: string | null; bank_masked: string | null };
    account: Account | null;
    stats: { monthly: string | null; days: number; hours: number; last_net: string | null };
    attendance: { id: number; date: string; in: string; out: string | null; hours: number | null; method: string; notes: string | null }[];
    payslips: { id: number; period: string; number: string; run_status: string; net: string; earnings: string; deductions: string; paid_on: string | null; payout: string | null }[];
    history: HistoryRow[];
    permissions: string[];
    access: Access | null;
    confidential: boolean;
    can: Can;
}

const EVENTS: Record<string, string> = {
    'employee.created': 'أُضيف الملف', 'employee.updated': 'عُدّل الملف', 'user.created': 'فُتح حساب دخول', 'user.updated': 'عُدّل حساب الدخول',
    'user.access_updated': 'تغيّر الدور أو الصلاحيات', 'user.password_reset': 'أُعيد تعيين كلمة المرور', 'user.deactivated': 'أوقف حساب الدخول',
};
const FIELDS: Record<string, string> = {
    name: 'الاسم', phone: 'الهاتف', email: 'البريد', hired_at: 'تاريخ التعيين', status: 'الحالة', job_title_id: 'المسمى', branch_id: 'الفرع', trainer_id: 'ملف المدرب', user_id: 'حساب الدخول', notes: 'الملاحظات',
    basic_salary: 'الراتب الأساسي', allowances: 'البدلات', payment_method: 'طريقة الصرف', bank_name: 'البنك', iban: 'الآيبان', account_number: 'رقم الحساب', role_id: 'الدور', is_active: 'الحساب نشط', custom_permissions: 'صلاحيات مخصصة',
};
const METHODS: Record<string, string> = { manual: 'من اللوحة', device: 'جهاز البصمة', correction: 'تسجيل يدوي' };
const RUN: Record<string, string> = { draft: 'مسودة', approved: 'معتمدة', paid: 'مصروفة' };

/** ملف الموظف: the employee's file — what the viewer may see of it, and the actions they may take. */
export default function EmployeeShow({ employee, account, stats, attendance, payslips, history, permissions, access, confidential, can }: Props) {
    const currency = useCurrency();
    const { flash } = useShared();
    const [tab, setTab] = useState<'attendance' | 'payslips' | 'history' | 'permissions'>(can.attendance ? 'attendance' : can.payslips ? 'payslips' : 'history');
    const [accessOpen, setAccessOpen] = useState(false);
    const [punchOpen, setPunchOpen] = useState(false);
    const [seen, setSeen] = useState(false);
    const issued = !seen ? flash?.password ?? null : null;
    const tabs = [
        can.attendance && { key: 'attendance' as const, label: 'الحضور والانصراف' },
        can.payslips && { key: 'payslips' as const, label: 'كشوف الرواتب', count: payslips.length },
        { key: 'history' as const, label: 'سجل التعديلات' },
        { key: 'permissions' as const, label: 'الصلاحيات' },
    ].filter(Boolean) as { key: typeof tab; label: string; count?: number }[];

    return (
        <ClubLayout title={employee.name}>
            <PageHeader
                back={<Link href={route('club.employees.index')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> الموظفون</Link>}
                title="ملف الموظف"
                subtitle="بيانات الموظف ووظيفته وحضوره ودخوله وصلاحياته"
                actions={(can.manage || can.compensation) && <Button href={route('club.employees.edit', employee.id)} icon={<Pencil className="size-4" />}>تعديل</Button>}
            />

            {issued && (
                <Notice tone="green" className="mb-4">
                    <span className="flex flex-wrap items-center gap-3">
                        <span>كلمة المرور المؤقتة: <code dir="ltr" className="rounded bg-white/70 px-2 py-0.5 font-semibold tracking-wide">{issued}</code> — انسخها الآن وأرسلها للموظف؛ لا تُعرض مرة أخرى.</span>
                        <Button size="sm" variant="outline" icon={<Copy className="size-3.5" />} onClick={async () => { try { await navigator.clipboard.writeText(issued); toast.success('نُسخت'); } catch { toast.error('تعذّر النسخ'); } }}>نسخ</Button>
                        <Button size="sm" variant="ghost" onClick={() => setSeen(true)}>إخفاء</Button>
                    </span>
                </Notice>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-1">
                    <Card className="p-6 text-center">
                        <div className="flex justify-center"><Initials name={employee.name} size="lg" /></div>
                        <h2 className="mt-4 text-[17px] font-bold text-ink">{employee.name}</h2>
                        <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
                            {employee.job_title && <Badge tone="brand">{employee.job_title}</Badge>}
                            <Badge tone={statusTone[employee.status]}>{({ active: 'على رأس العمل', on_leave: 'في إجازة', terminated: 'انتهت الخدمة' } as Record<string, string>)[employee.status]}</Badge>
                            {account ? (account.is_active ? <Badge tone="blue">له حساب دخول</Badge> : <Badge tone="red">حساب الدخول موقوف</Badge>) : <Badge tone="gray">بلا حساب دخول</Badge>}
                        </div>
                        {account && access && (
                            <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                                {account.may_manage ? (
                                    <>
                                        <Button size="sm" variant="outline" icon={<ShieldCheck className="size-3.5" />} onClick={() => setAccessOpen(true)}>الدور والصلاحيات</Button>
                                        <ConfirmAction url={route('club.employees.password', employee.id)} title="إعادة تعيين كلمة المرور" message="تُنشأ كلمة مرور مؤقتة جديدة وتُعرض مرة واحدة. الكلمة الحالية لا تُعرض — لا تُحفظ إلا مُجزّأة." confirmLabel="توليد" tone="primary" variant="outline" size="sm" icon={<KeyRound className="size-3.5" />}>
                                            كلمة مرور مؤقتة
                                        </ConfirmAction>
                                    </>
                                ) : (
                                    <p className="rounded-[10px] bg-[#fffbeb] p-3 text-[12px] leading-relaxed text-[#b45309]">
                                        {account.is_me ? 'هذا حسابك: دورك وصلاحياتك يعدّلها غيرك، وكلمة مرورك من «حسابي».' : 'هذا الحساب يفتح ما لا تفتحه — لا يُعدَّل ولا تُغيَّر كلمة مروره إلا ممّن يملك ذلك.'}
                                    </p>
                                )}
                            </div>
                        )}
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-ink"><Contact className="size-4 text-brand" /> بيانات الاتصال</h3>
                        <FactList facts={[
                            { label: 'البريد الإلكتروني', value: employee.email ?? '—', ltr: true },
                            { label: 'الهاتف', value: employee.phone ? phone(employee.phone) : '—', ltr: true },
                        ]} />
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-ink"><Briefcase className="size-4 text-brand" /> معلومات الوظيفة</h3>
                        <FactList facts={[
                            { label: 'المسمى الوظيفي', value: employee.job_title ?? '—' },
                            { label: 'الفرع', value: employee.branch ?? 'الفرع الرئيسي' },
                            { label: 'تاريخ التعيين', value: date(employee.hired_at) },
                            { label: 'رقم الموظف', value: <span className="num">#{employee.id}</span> },
                            ...(employee.trainer ? [{ label: 'فريق التدريب', value: <Link href={route('club.trainers.index')} className="underline">{employee.trainer}</Link> }] : []),
                        ]} />
                    </Card>

                    {account && (
                        <Card className="p-6">
                            <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-ink"><KeyRound className="size-4 text-brand" /> الدخول</h3>
                            <FactList facts={[
                                { label: 'بريد الدخول', value: account.email, ltr: true },
                                { label: 'الدور', value: <>{account.role ?? '—'}{account.own_permissions && <span className="ms-1 text-[11px] text-muted">(صلاحيات مخصصة)</span>}</> },
                                { label: 'الفروع المصرح بها', value: account.branch ?? 'كل الفروع' },
                                { label: 'آخر دخول', value: account.last_login_at ? <span className="num">{account.last_login_at}</span> : 'لم يسجّل بعد' },
                            ]} />
                        </Card>
                    )}

                    {confidential && (
                        <Card className="p-6">
                            <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-ink"><Lock className="size-4 text-brand" /> الراتب (سري)</h3>
                            <FactList facts={[
                                { label: 'الراتب الأساسي', value: <span className="num">{money(employee.basic_salary, currency)}</span> },
                                ...(employee.allowances ?? []).map((a) => ({ label: `بدل ${a.name}`, value: <span className="num">{money(a.amount, currency)}</span> })),
                                { label: 'الإجمالي الشهري', value: <span className="num font-semibold">{money(employee.monthly, currency)}</span> },
                                { label: 'طريقة الصرف', value: employee.payment_method === 'cash' ? 'نقدًا' : 'تحويل بنكي' },
                                ...(employee.payment_method === 'bank' ? [{ label: 'البنك', value: <>{employee.bank_name ?? '—'} <span className="num">{employee.bank_masked ?? ''}</span></> }] : []),
                            ]} />
                        </Card>
                    )}
                </div>

                <div className="min-w-0 space-y-6 lg:col-span-2">
                    <div className="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2">
                        {confidential && <StatCard label="الراتب الشهري" value={money(stats.monthly, currency)} icon={<Wallet className="size-5" />} tone="violet" />}
                        <StatCard label="أيام الحضور هذا الشهر" value={number(stats.days)} icon={<CalendarClock className="size-5" />} tone="green" />
                        <StatCard label="ساعات العمل هذا الشهر" value={number(stats.hours, 1)} icon={<Clock className="size-5" />} tone="blue" />
                        {confidential ? <StatCard label="آخر صافي راتب" value={stats.last_net ? money(stats.last_net, currency) : '—'} icon={<FileText className="size-5" />} tone="amber" /> : <StatCard label="الفرع" value={employee.branch ?? 'الفرع الرئيسي'} icon={<Briefcase className="size-5" />} tone="amber" />}
                    </div>

                    <Card padded={false} className="overflow-hidden">
                        <div className="px-5"><Tabs value={tab} onChange={setTab} tabs={tabs} /></div>

                        {tab === 'attendance' && (
                            <div className="p-5">
                                <CardHeader title="آخر الحضور والانصراف" subtitle={account ? 'من جهاز البصمة أو اللوحة، والتسجيل اليدوي مع سببه' : undefined} actions={account && can.attendance_manage && <Button size="sm" variant="outline" icon={<Plus className="size-3.5" />} onClick={() => setPunchOpen(true)}>تسجيل يدوي</Button>} />
                                {!account ? (
                                    <EmptyState icon={<Clock className="size-6" />} title="لا حساب دخول" description="الحضور يُسجَّل لحساب الموظف؛ افتح له حسابًا من «تعديل» ليظهر حضوره هنا." />
                                ) : attendance.length === 0 ? (
                                    <EmptyState icon={<Clock className="size-6" />} title="لا حضور مسجّل بعد" />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="table-base">
                                            <thead><tr><th>التاريخ</th><th>الحضور</th><th>الانصراف</th><th>الساعات</th><th>الطريقة</th></tr></thead>
                                            <tbody>
                                                {attendance.map((a) => (
                                                    <tr key={a.id}>
                                                        <td className="num">{a.date}</td>
                                                        <td className="num">{a.in}</td>
                                                        <td className="num">{a.out ?? <Badge tone="amber">لم ينصرف</Badge>}</td>
                                                        <td className="num">{a.hours ?? '—'}</td>
                                                        <td className="text-[13px]">{METHODS[a.method] ?? a.method}{a.notes && <span className="block text-[11px] text-muted">{a.notes}</span>}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        )}

                        {tab === 'payslips' && (
                            <div className="p-5">
                                <CardHeader title="كشوف الرواتب" subtitle="من مسيرات الرواتب؛ كل كشف يُطبع أو يُنزَّل بملف PDF" />
                                {payslips.length === 0 ? <EmptyState icon={<FileText className="size-6" />} title="لا كشوف بعد" /> : (
                                    <div className="overflow-x-auto">
                                        <table className="table-base">
                                            <thead><tr><th>الشهر</th><th>المستحقات</th><th>الخصومات</th><th>الصافي</th><th>الحالة</th><th /></tr></thead>
                                            <tbody>
                                                {payslips.map((p) => (
                                                    <tr key={p.id}>
                                                        <td>{p.period}<span className="block text-[11px] text-muted num">{p.number}</span></td>
                                                        <td className="num">{money(p.earnings, currency)}</td>
                                                        <td className="num">{Number(p.deductions) > 0 ? money(p.deductions, currency) : '—'}</td>
                                                        <td className="num font-medium">{money(p.net, currency)}</td>
                                                        <td>{p.paid_on ? <Badge tone="green">صُرف {p.paid_on}</Badge> : <Badge tone={p.run_status === 'draft' ? 'gray' : 'blue'}>{RUN[p.run_status]}</Badge>}</td>
                                                        <td><div className="flex justify-end gap-1">
                                                            <Button size="sm" variant="ghost" href={route('club.papers.show', { type: 'payslip', id: p.id, print: 1 })} external icon={<Printer className="size-3.5" />}>طباعة</Button>
                                                            <Button size="sm" variant="ghost" href={route('club.papers.pdf', { type: 'payslip', id: p.id })} external>PDF</Button>
                                                        </div></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        )}

                        {tab === 'history' && (
                            <div className="p-5">
                                <CardHeader title="سجل التعديلات" subtitle="من غيّر ماذا ومتى. قيم الراتب والبنك تظهر لمن يملك صلاحية الاطلاع فقط." />
                                {history.length === 0 ? <EmptyState icon={<FileText className="size-6" />} title="لا تعديلات مسجّلة" /> : (
                                    <ol className="space-y-3">
                                        {history.map((h) => <HistoryItem key={h.id} row={h} />)}
                                    </ol>
                                )}
                            </div>
                        )}

                        {tab === 'permissions' && (
                            <div className="p-5">
                                <p className="mb-4 text-sm text-muted">
                                    {!account ? 'لا حساب دخول، فلا صلاحيات.' : account.own_permissions ? 'صلاحيات مخصّصة لهذا الموظف — تُفرض على الخادم.' : `ما يسمح به دور «${account.role ?? '—'}» — يُفرض على الخادم.`}
                                </p>
                                {account && (permissions.length === 0 ? (
                                    <p className="rounded-[12px] border border-dashed border-line p-5 text-center text-[13px] text-[#9ca3af]">لا يفتح هذا الحساب شيئًا.</p>
                                ) : (
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        {permissions.map((p) => (
                                            <div key={p} className="flex items-center justify-between gap-3 rounded-[12px] border border-line px-4 py-2.5">
                                                <span className="text-[13px] text-[#4b4b4b]">{p}</span>
                                                <span className="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-emerald-700"><Check className="size-4" /> مسموح</span>
                                            </div>
                                        ))}
                                    </div>
                                ))}
                                {account?.may_manage && access && <Button className="mt-4" size="sm" variant="outline" icon={<ShieldCheck className="size-3.5" />} onClick={() => setAccessOpen(true)}>تعديل الدور والصلاحيات</Button>}
                            </div>
                        )}
                    </Card>

                    {employee.notes && <Card><CardHeader title="ملاحظات" className="mb-2" /><p className="whitespace-pre-line text-[13px] text-[#4b4b4b]">{employee.notes}</p></Card>}
                </div>
            </div>

            {accessOpen && account && access && <AccessModal employeeId={employee.id} account={account} access={access} onClose={() => setAccessOpen(false)} />}
            {punchOpen && <PunchModal employeeId={employee.id} onClose={() => setPunchOpen(false)} />}
        </ClubLayout>
    );
}

function HistoryItem({ row }: { row: HistoryRow }) {
    const changed = [...new Set([...Object.keys(row.new ?? {}), ...Object.keys(row.old ?? {}), ...((row.properties?.confidential_changed as string[] | undefined) ?? [])])];
    const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));

    return (
        <li className="rounded-[12px] border border-line px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2 text-[13px]">
                <span className="font-medium">{EVENTS[row.event] ?? row.event}</span>
                <span className="text-[12px] text-muted"><span className="num">{row.at}</span>{row.actor && ` · ${row.actor}`}</span>
            </div>
            {changed.length > 0 && (
                <ul className="mt-2 space-y-1 text-[12px] text-[#4b4b4b]">
                    {changed.map((k) => (
                        <li key={k}>
                            <span className="text-muted">{FIELDS[k] ?? k}: </span>
                            {row.new && k in row.new ? <><span className="line-through decoration-[#d4d4d4]">{show(row.old?.[k])}</span> ← <span>{show(row.new[k])}</span></> : <span className="text-muted">تغيّر (القيمة سرية)</span>}
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/** Role, branch reach, own permissions and on/off of the sign-in account. Boxes the viewer does not hold are fixed. */
function AccessModal({ employeeId, account, access, onClose }: { employeeId: number; account: Account; access: Access; onClose: () => void }) {
    const role = (id: string) => access.roles.find((r) => String(r.id) === id);
    const form = useForm({
        role_id: String(account.role_id ?? ''),
        branch_id: account.branch_id ? String(account.branch_id) : '',
        is_active: account.is_active,
        custom: account.own_permissions,
        permissions: account.custom ?? role(String(account.role_id ?? ''))?.permissions ?? [],
    });
    const mine = (p: string) => access.grantable.includes(p);
    const shown = form.data.custom ? form.data.permissions : role(form.data.role_id)?.permissions ?? [];
    const toggle = (p: string) => form.data.custom && mine(p) && form.setData('permissions', form.data.permissions.includes(p) ? form.data.permissions.filter((x) => x !== p) : [...form.data.permissions, p]);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.employees.access', employeeId), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} size="xl" title="الدور والصلاحيات" footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="الدور" error={form.errors.role_id} required>
                        <Select value={form.data.role_id} onChange={(e) => form.setData((d) => ({ ...d, role_id: e.target.value, permissions: d.custom ? d.permissions : role(e.target.value)?.permissions ?? [] }))}>
                            {access.roles.map((r) => <option key={r.id} value={r.id} disabled={!r.assignable && String(r.id) !== String(account.role_id)}>{r.name}{!r.assignable ? ' — يفتح ما لا تملكه' : ''}</option>)}
                        </Select>
                    </Field>
                    <Field label="الفروع المصرح بها" hint="فرع واحد يعمل فيه دائمًا، أو كل الفروع ينتقل بينها" error={form.errors.branch_id}>
                        <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)}>
                            <option value="">كل الفروع</option>
                            {access.branches.map((b) => <option key={b.id} value={b.id}>{b.name} فقط</option>)}
                        </Select>
                    </Field>
                </div>
                <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="الحساب نشط" hint="الحساب الموقوف لا يستطيع الدخول" />
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {[{ on: false, title: 'اتبع صلاحيات الدور', hint: 'تتبدّل صلاحياته كلما تبدّل دوره' }, { on: true, title: 'خصّص لهذا الموظف', hint: 'قائمة له وحده — لا تتبدّل مع الدور' }].map((opt) => (
                        <button key={String(opt.on)} type="button" onClick={() => form.setData((d) => ({ ...d, custom: opt.on, permissions: opt.on ? (account.custom ?? role(d.role_id)?.permissions ?? []) : d.permissions }))}
                            className={cn('rounded-[10px] border p-3 text-start transition-colors', form.data.custom === opt.on ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]')}>
                            <span className="flex items-center gap-2">
                                <span className={cn('flex size-4 shrink-0 items-center justify-center rounded-full border', form.data.custom === opt.on ? 'border-[#111]' : 'border-[#d1d5db]')}>{form.data.custom === opt.on && <span className="size-2 rounded-full bg-[#111]" />}</span>
                                <span className="text-sm font-medium text-ink">{opt.title}</span>
                            </span>
                            <span className="mt-1 block ps-6 text-[12px] text-[#6b7280]">{opt.hint}</span>
                        </button>
                    ))}
                </div>
                {!form.data.custom && <p className="text-[12px] text-muted">ما يفتحه الدور المختار — يُعرض ولا يُعدَّل من هنا.</p>}
                <div className="grid gap-3 md:grid-cols-2">
                    {access.groups.map((g) => (
                        <div key={g.key} className="rounded-2xl border border-line p-4">
                            <p className="mb-2 font-medium">{g.label}</p>
                            <div className="space-y-1.5">
                                {g.permissions.map((p) => (
                                    <label key={p.key} title={form.data.custom && !mine(p.key) ? 'لا تملك هذه الصلاحية — ولا تُمنح ما لا تملك.' : undefined} className={cn('flex items-center gap-2 text-sm text-ink/85', form.data.custom && mine(p.key) ? 'cursor-pointer' : 'cursor-not-allowed opacity-60')}>
                                        <input type="checkbox" className="size-4 accent-[#111]" checked={shown.includes(p.key)} onChange={() => toggle(p.key)} disabled={!form.data.custom || !mine(p.key)} />
                                        {p.label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
                {form.errors.permissions && <p className="text-xs text-red-600">{form.errors.permissions}</p>}
            </form>
        </Modal>
    );
}

function PunchModal({ employeeId, onClose }: { employeeId: number; onClose: () => void }) {
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({ date: today, in: '', out: '', notes: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.employees.attendance', employeeId), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} title="تسجيل حضور يدوي" footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>تسجيل</Button></>}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                <Field label="التاريخ" error={form.errors.date} required><Input type="date" max={today} value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} /></Field>
                <Field label="الحضور" error={form.errors.in} required><Input type="time" value={form.data.in} onChange={(e) => form.setData('in', e.target.value)} /></Field>
                <Field label="الانصراف" error={form.errors.out}><Input type="time" value={form.data.out} onChange={(e) => form.setData('out', e.target.value)} /></Field>
                <Field label="السبب" hint="مثل: نسي البصمة، تعطّل الجهاز" error={form.errors.notes} required className="sm:col-span-3"><Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
            </form>
        </Modal>
    );
}
