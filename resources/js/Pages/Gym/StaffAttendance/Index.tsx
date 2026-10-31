import { router } from '@inertiajs/react';
import { BarChart3, Clock, Fingerprint, LogIn, UserRoundCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { Badge, Button, Card, EmptyState, Field, Input, PageHeader, Pagination, Select, StatCard } from '@/Components/ui';
import { date, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Row = { id: number; user: string | null; method: string; device: string | null; date: string; in: string; out: string | null; hours: number | null };

const METHODS: Record<string, string> = { manual: 'يدوي من اللوحة', fingerprint: 'بصمة', card: 'بطاقة', pin: 'رمز PIN' };

export default function StaffAttendanceIndex({ rows, stats, staff, filters, period }: { rows: Paginated<Row>; stats: { hours: number; open: number }; staff: Option[]; filters: { user_id: number | null }; period: Period }) {
    const userId = filters.user_id ? String(filters.user_id) : '';
    const pick = (id: string) => router.get(route('club.staff-attendance.index'), { ...periodParams(period), user_id: id || undefined }, { preserveState: true, replace: true });

    return (
        <ClubLayout title="الحضور والانصراف">
            <PageHeader
                title="الحضور والانصراف"
                subtitle="حضور الموظفين من أجهزة البصمة والتسجيل اليدوي — منفصل عن دخول الأعضاء"
                actions={
                    <>
                        <Button variant="outline" href={route('club.reports.show', { report: 'staff-attendance', ...periodParams(period), ...(userId ? { user_id: userId } : {}) })} icon={<BarChart3 className="size-4" />}>تقرير الحضور</Button>
                        <Button icon={<LogIn className="size-4" />} onClick={() => router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true })}>تسجيل حضوري/انصرافي</Button>
                    </>
                }
            />

            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard label="سجلات الفترة" value={number(rows.total)} icon={<UserRoundCheck className="size-5" />} />
                <StatCard label="ساعات العمل في الفترة" value={number(stats.hours, 1)} icon={<Clock className="size-5" />} tone="blue" />
                <StatCard label="لم يُسجّل انصرافه" value={number(stats.open)} icon={<Fingerprint className="size-5" />} tone="amber" />
            </div>

            <PeriodTabs period={period} params={{ user_id: filters.user_id }} />

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-4 sm:items-end">
                    <Field label="الموظف">
                        <Select value={userId} onChange={(e) => pick(e.target.value)} placeholder="الكل">
                            {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                </div>
            </Card>

            <Card padded={false}>
                {rows.data.length === 0 ? (
                    <EmptyState icon={<UserRoundCheck className="size-6" />} title="لا سجلات في هذه الفترة" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الموظف</th><th>التاريخ</th><th>الحضور</th><th>الانصراف</th><th>الساعات</th><th>الطريقة</th></tr>
                            </thead>
                            <tbody>
                                {rows.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className="font-medium">{r.user ?? '—'}</td>
                                        <td>{date(r.date)}</td>
                                        <td className="num">{r.in}</td>
                                        <td>{r.out ? <span className="num">{r.out}</span> : <Badge tone="amber">لم ينصرف</Badge>}</td>
                                        <td className="num">{r.hours ?? '—'}</td>
                                        <td>
                                            {METHODS[r.method] ?? r.method}
                                            {r.device && <p className="text-xs text-muted">{r.device}</p>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={rows} />
            </Card>
        </ClubLayout>
    );
}
