import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Clock, Fingerprint, LogIn, Search, UserRoundCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, Field, Input, PageHeader, Pagination, Select, StatCard } from '@/Components/ui';
import { date, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Row = { id: number; user: string | null; method: string; device: string | null; date: string; in: string; out: string | null; hours: number | null };

const METHODS: Record<string, string> = { manual: 'يدوي من اللوحة', fingerprint: 'بصمة', card: 'بطاقة', pin: 'رمز PIN' };

export default function StaffAttendanceIndex({ rows, staff, filters }: { rows: Paginated<Row>; staff: Option[]; filters: { from: string; to: string; user_id: number | null } }) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [userId, setUserId] = useState<string>(filters.user_id ? String(filters.user_id) : '');

    const apply = () => router.get(route('club.staff-attendance.index'), { from, to, user_id: userId || undefined }, { preserveState: true });

    const pageHours = rows.data.reduce((sum, r) => sum + (r.hours ?? 0), 0);
    const open = rows.data.filter((r) => !r.out).length;

    return (
        <ClubLayout title="حضور الموظفات">
            <PageHeader
                title="حضور وانصراف الموظفات"
                subtitle="سجل موحّد من أجهزة البصمة والتسجيل اليدوي"
                actions={<Button icon={<LogIn className="size-4" />} onClick={() => router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true })}>تسجيل حضوري/انصرافي</Button>}
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard label="سجلات الفترة" value={number(rows.total)} icon={<UserRoundCheck className="size-5" />} />
                <StatCard label="ساعات (هذه الصفحة)" value={number(pageHours, 1)} icon={<Clock className="size-5" />} tone="blue" />
                <StatCard label="لم تُسجّل انصرافها" value={number(open)} icon={<Fingerprint className="size-5" />} tone="amber" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-4 sm:items-end">
                    <Field label="من"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label="إلى"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                    <Field label="الموظفة">
                        <Select value={userId} onChange={(e) => setUserId(e.target.value)} placeholder="الكل">
                            {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Button variant="secondary" icon={<Search className="size-4" />} onClick={apply}>عرض</Button>
                </div>
            </Card>

            <Card padded={false}>
                {rows.data.length === 0 ? (
                    <EmptyState icon={<UserRoundCheck className="size-6" />} title="لا سجلات في هذه الفترة" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الموظفة</th><th>التاريخ</th><th>الحضور</th><th>الانصراف</th><th>الساعات</th><th>الطريقة</th></tr>
                            </thead>
                            <tbody>
                                {rows.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className="font-medium">{r.user ?? '—'}</td>
                                        <td>{date(r.date)}</td>
                                        <td className="num">{r.in}</td>
                                        <td>{r.out ? <span className="num">{r.out}</span> : <Badge tone="amber">لم تنصرف</Badge>}</td>
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
