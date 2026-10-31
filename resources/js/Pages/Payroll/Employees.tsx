import { Link, router } from '@inertiajs/react';
import { Clock, Eye, KeyRound, Pencil, Plus, UserCheck, UserPlus, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, PageHeader, SearchInput, Select, StatCard, Tabs } from '@/Components/ui';
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
    filters: { q?: string; status?: string; job_title_id?: string };
}

export const statusTone: Record<string, 'green' | 'amber' | 'gray'> = { active: 'green', on_leave: 'amber', terminated: 'gray' };

/** الموظفون: the staff list. Salaries appear only for payroll.salaries — the server sends nothing otherwise. */
export default function Employees({ employees, stats, counts, jobTitles, unlinkedTrainers, statuses, confidential, can, filters }: Props) {
    const currency = useCurrency();
    const filter = (patch: Record<string, string>) =>
        router.get(route('club.employees.index'), Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v)) as Record<string, string>, { preserveState: true, preserveScroll: true, replace: true });

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

            <Card padded={false} className="overflow-hidden">
                <div className="px-4 pt-2">
                    <Tabs
                        value={filters.status ?? ''}
                        onChange={(s) => filter({ status: s })}
                        tabs={[{ key: '', label: 'الكل', count: counts.all }, ...Object.entries(statuses).map(([k, l]) => ({ key: k, label: l, count: counts[k] ?? 0 }))]}
                    />
                </div>
                <div className="flex flex-wrap items-center gap-2 border-b border-line p-4">
                    <div className="min-w-[14rem] flex-1"><SearchInput value={filters.q} placeholder="ابحث بالاسم أو الهاتف أو البريد…" extra={{ status: filters.status, job_title_id: filters.job_title_id }} /></div>
                    <Select className="w-auto" value={filters.job_title_id ?? ''} onChange={(e) => filter({ job_title_id: e.target.value })}>
                        <option value="">كل المسميات</option>
                        {jobTitles.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </div>
                {employees.length === 0 ? (
                    <EmptyState icon={<Users className="size-6" />} title="لا يوجد موظفون" description={can.create ? 'أضف أول موظف، أو اربط المدربين ليصبح لهم ملفات وظيفية.' : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الموظف</th><th>المسمى</th><th>الفرع</th><th>الهاتف</th><th>حساب الدخول</th>{confidential && <th>الراتب الشهري</th>}<th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {employees.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            <div className="flex items-center gap-3">
                                                <Initials name={e.name} />
                                                <span className="min-w-0">
                                                    <Link href={route('club.employees.show', e.id)} className="block truncate font-medium hover:underline">{e.name}</Link>
                                                    <span className="block truncate text-[11px] text-[#9ca3af]" dir="ltr">{e.email ?? ''}</span>
                                                    {e.trainer && <span className="block text-[11px] text-muted">مدرب: {e.trainer}</span>}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="text-[13px]">{e.job_title ?? '—'}</td>
                                        <td className="text-[13px]">{e.branch ?? 'الفرع الرئيسي'}</td>
                                        <td className="text-[13px] num" dir="ltr">{e.phone ? phone(e.phone) : '—'}</td>
                                        <td className="text-[13px]">{e.user ? <><span className="block">{e.user.role ?? '—'}</span>{!e.user.is_active && <Badge tone="red">موقوف</Badge>}</> : <span className="text-muted">بلا حساب</span>}</td>
                                        {confidential && <td className="num font-medium">{money(e.monthly, currency)}</td>}
                                        <td><Badge tone={statusTone[e.status]}>{statuses[e.status]}</Badge></td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="ghost" href={route('club.employees.show', e.id)} icon={<Eye className="size-3.5" />}>الملف</Button>
                                                {(can.manage || can.compensation) && <Button size="sm" variant="ghost" href={route('club.employees.edit', e.id)} icon={<Pencil className="size-3.5" />}>تعديل</Button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
