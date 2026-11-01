import { Link } from '@inertiajs/react';
import { Clock, Eye, KeyRound, Pencil, Plus, UserCheck, UserPlus, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, ConfirmAction, DataTable, PageHeader, StatCard, type Column } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money, phone } from '@/lib/format';
import { Initials } from '@/Components/Payroll/parts';

export type Allowance = { name: string; amount: string };
export type EmployeeRow = {
    id: number; name: string; phone: string | null; email: string | null; hired_at: string | null; status: string;
    job_title_id: number | null; job_title: string | null; branch_id: number | null; branch: string | null;
    user_id: number | null; user: { name: string; email: string; role: string | null; is_active: boolean } | null; trainer: string | null;
    basic_salary: string | null; allowances: Allowance[] | null; monthly: string | null; payment_method: string | null; bank_name: string | null; iban: string | null; account_number: string | null;
};
export type Can = { create: boolean; manage: boolean; access: boolean; salaries: boolean; compensation: boolean; payslips: boolean; attendance: boolean; attendance_manage: boolean; audit: boolean };

interface Props {
    employees: EmployeeRow[];
    stats: { active: number; on_leave: number; with_account: number; monthly: string | null };
    counts: Record<string, number>;
    jobTitles: { id: number; name: string }[];
    unlinkedTrainers: number;
    statuses: Record<string, string>;
    confidential: boolean;
    can: Can;
    filters: Record<string, string | undefined>;
}

export const statusTone: Record<string, 'green' | 'amber' | 'gray'> = { active: 'green', on_leave: 'amber', terminated: 'gray' };

const STATUS_DOT: Record<string, string> = { active: '#059669', on_leave: '#d97706', terminated: '#9ca3af' };

/** الموظفون: the staff list. Salaries appear only for payroll.salaries — the server sends nothing otherwise. */
export default function Employees({ employees, stats, counts, jobTitles, unlinkedTrainers, statuses, confidential, can, filters }: Props) {
    const currency = useCurrency();

    const Who = ({ e }: { e: EmployeeRow }) => (
        <div className="flex items-center gap-3">
            <Initials name={e.name} />
            <span className="min-w-0">
                <Link href={route('club.employees.show', e.id)} className="block truncate font-medium hover:underline">{e.name}</Link>
                <span className="block truncate text-[11px] text-[#9ca3af]" dir="ltr">{e.email ?? ''}</span>
                {e.trainer && <span className="block text-[11px] text-muted">مدرب: {e.trainer}</span>}
            </span>
        </div>
    );
    const Actions = ({ e }: { e: EmployeeRow }) => (
        <div className="flex justify-end gap-1">
            <Button size="sm" variant="ghost" href={route('club.employees.show', e.id)} icon={<Eye className="size-3.5" />}>الملف</Button>
            {(can.manage || can.compensation) && <Button size="sm" variant="ghost" href={route('club.employees.edit', e.id)} icon={<Pencil className="size-3.5" />}>تعديل</Button>}
        </div>
    );
    const columns: Column<EmployeeRow>[] = [
        { key: 'name', header: 'الموظف', cell: (e) => <Who e={e} /> },
        { key: 'job_title', header: 'المسمى', cell: (e) => <span className="text-[13px]">{e.job_title ?? '—'}</span> },
        { key: 'branch', header: 'الفرع', cell: (e) => <span className="text-[13px]">{e.branch ?? 'الفرع الرئيسي'}</span> },
        { key: 'phone', header: 'الهاتف', cell: (e) => <span className="text-[13px] num" dir="ltr">{e.phone ? phone(e.phone) : '—'}</span> },
        { key: 'account', header: 'حساب الدخول', cell: (e) => <span className="text-[13px]">{e.user ? <><span className="block">{e.user.role ?? '—'}</span>{!e.user.is_active && <Badge tone="red">موقوف</Badge>}</> : <span className="text-muted">بلا حساب</span>}</span> },
        ...(confidential ? [{ key: 'monthly', header: 'الراتب الشهري', align: 'end' as const, cell: (e: EmployeeRow) => <span className="num font-medium">{money(e.monthly, currency)}</span> }] : []),
        { key: 'status', header: 'الحالة', cell: (e) => <Badge tone={statusTone[e.status]}>{statuses[e.status]}</Badge> },
        { key: 'actions', header: '', align: 'end', cell: (e) => <Actions e={e} /> },
    ];

    return (
        <ClubLayout title="الموظفون">
            <PageHeader
                title="الموظفون"
                subtitle="ملفات فريق النادي: الوظيفة والفرع وحساب الدخول والحضور، والراتب لمن لديه الصلاحية"
                actions={<>
                    {can.attendance && <Button variant="outline" href={route('club.staff-attendance.index')} icon={<Clock className="size-4" />}>سجل الحضور</Button>}
                    {can.create && unlinkedTrainers > 0 && (
                        <ConfirmAction url={route('club.employees.import-trainers')} title="ملفات للمدربين" message={`ستُنشأ ملفات وظيفية لـ ${unlinkedTrainers} مدرب مرتبطة بملفاتهم في فريق التدريب، دون تكرار.`} variant="outline" icon={<UserPlus className="size-4" />}>
                            ربط المدربين ({unlinkedTrainers})
                        </ConfirmAction>
                    )}
                    {can.create && <Button href={route('club.employees.create')} icon={<Plus className="size-4" />}>موظف جديد</Button>}
                </>}
            />

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="على رأس العمل" value={stats.active} icon={<UserCheck className="size-5" />} tone="green" />
                <StatCard label="في إجازة" value={stats.on_leave} icon={<Users className="size-5" />} tone="amber" />
                <StatCard label="لهم حساب دخول" value={stats.with_account} icon={<KeyRound className="size-5" />} tone="blue" />
                {confidential ? <StatCard label="الرواتب الشهرية" value={money(stats.monthly, currency)} hint="أساسي + بدلات لمن على رأس العمل" icon={<Wallet className="size-5" />} tone="violet" /> : <StatCard label="كل الموظفين" value={counts.all} icon={<Users className="size-5" />} tone="gray" />}
            </div>

            <DataTable
                rows={employees}
                columns={columns}
                rowKey={(e) => e.id}
                params={filters}
                search={{ placeholder: 'الاسم أو الهاتف أو البريد' }}
                filters={[
                    { param: 'status', label: 'الحالة', tabs: true, options: Object.entries(statuses).map(([value, text]) => ({ value, label: counts[value] ? `${text} (${counts[value]})` : text, dot: STATUS_DOT[value] })) },
                    { param: 'job_title_id', label: 'المسمى الوظيفي', options: jobTitles.map((t) => ({ value: String(t.id), label: t.name })) },
                ]}
                empty={can.create ? 'لا يوجد موظفون بعد. أضف أول موظف، أو اربط المدربين ليصبح لهم ملفات وظيفية.' : 'لا يوجد موظفون'}
                card={(e) => (
                    <div className="space-y-1.5 px-4 py-3 text-[13px]">
                        <div className="flex items-start justify-between gap-3"><Who e={e} /><Badge tone={statusTone[e.status]}>{statuses[e.status]}</Badge></div>
                        <p className="text-muted">{e.job_title ?? '—'} · {e.branch ?? 'الفرع الرئيسي'}{confidential && e.monthly ? ` · ${money(e.monthly, currency)}` : ''}</p>
                        <Actions e={e} />
                    </div>
                )}
            />
        </ClubLayout>
    );
}
