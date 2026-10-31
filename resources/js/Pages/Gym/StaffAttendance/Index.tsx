import { router } from '@inertiajs/react';
import { BarChart3, Clock, Fingerprint, LogIn, UserRoundCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { Badge, Button, Card, DataTable, PageHeader, StatCard, type Column, type PageMeta } from '@/Components/ui';
import { date, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Row = { id: number; user: string | null; method: string; device: string | null; date: string; in: string; out: string | null; hours: number | null };

const METHODS: Record<string, string> = { manual: 'يدوي من اللوحة', fingerprint: 'بصمة', card: 'بطاقة', pin: 'رمز PIN' };

export default function StaffAttendanceIndex({ rows, stats, staff, filters, period }: { rows: Paginated<Row>; stats: { hours: number; open: number }; staff: Option[]; filters: { user_id: number | null }; period: Period }) {
    const userId = filters.user_id ? String(filters.user_id) : '';
    const columns: Column<Row>[] = [
        { key: 'user', header: 'الموظف', cell: (r) => <span className="font-medium">{r.user ?? '—'}</span> },
        { key: 'date', header: 'التاريخ', cell: (r) => date(r.date) },
        { key: 'in', header: 'الحضور', cell: (r) => <span className="num">{r.in}</span> },
        { key: 'out', header: 'الانصراف', cell: (r) => (r.out ? <span className="num">{r.out}</span> : <Badge tone="amber">لم ينصرف</Badge>) },
        { key: 'hours', header: 'الساعات', cell: (r) => <span className="num">{r.hours ?? '—'}</span> },
        {
            key: 'method',
            header: 'الطريقة',
            cell: (r) => (
                <>
                    {METHODS[r.method] ?? r.method}
                    {r.device && <p className="text-xs text-muted">{r.device}</p>}
                </>
            ),
        },
    ];

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

            {/* The same period as every screen, under the title and over what it counts; the employee is the list's filter. */}
            <PeriodTabs period={period} params={{ user_id: filters.user_id }} />

            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard label="سجلات الفترة" value={number(rows.total)} icon={<UserRoundCheck className="size-5" />} />
                <StatCard label="ساعات العمل في الفترة" value={number(stats.hours, 1)} icon={<Clock className="size-5" />} tone="blue" />
                <StatCard label="لم يُسجّل انصرافه" value={number(stats.open)} icon={<Fingerprint className="size-5" />} tone="amber" />
            </div>

            <Card padded={false} className="overflow-hidden">
                <DataTable
                    rows={rows.data}
                    columns={columns}
                    rowKey={(r) => r.id}
                    params={{ ...periodParams(period), user_id: filters.user_id }}
                    pagination={rows as PageMeta}
                    filters={[{ param: 'user_id', label: 'الموظف', options: staff.map((s) => ({ value: String(s.id), label: s.name })) }]}
                    empty="لا سجلات في هذه الفترة"
                />
            </Card>
        </ClubLayout>
    );
}
