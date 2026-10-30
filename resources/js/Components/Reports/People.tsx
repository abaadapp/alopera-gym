import { Link } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, Clock, Hourglass, RefreshCw, XCircle } from 'lucide-react';
import { Badge, BarTrend, HBars, Pagination, StatGrid } from '@/Components/ui';
import { date, number } from '@/lib/format';
import type { Paginated } from '@/types';
import type { PeriodReport } from './Finance';
import { ChartCard, Figure, Kpi, Rate, TableCard, useFormats, type Metric } from './parts';

/** العضويات والأعضاء. */
export function MembersBody({ report, canFinancial }: { report: PeriodReport; canFinancial: boolean }) {
    const f = useFormats();
    const m = (k: string) => report.comparison[k];
    return (
        <>
            <StatGrid>
                <Kpi label="اشتراكات جديدة" metric={m('new_memberships')} format={f.num} metricKey="new_memberships" />
                <Kpi label="تجديدات" metric={m('renewals')} format={f.num} metricKey="renewals" />
                <Kpi label="تغيير/ترقية باقة" metric={m('upgrades')} format={f.num} metricKey="upgrades" />
                <Kpi label="أعضاء جدد" metric={m('new_members')} format={f.num} metricKey="new_members" />
                <Kpi label="إلغاءات" metric={m('cancellations')} format={f.num} metricKey="cancellations" />
                <Kpi label="تجميدات" metric={m('freezes')} format={f.num} metricKey="freezes" />
                <Kpi label="عضويات انتهت" metric={m('expirations')} format={f.num} metricKey="expirations" />
                <Kpi label="مرات الدخول" metric={m('checkins')} format={f.num} metricKey="checkins" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="الباقات المباعة" subtitle="العضويات المفعّلة خلال الفترة" empty="لم تُفعّل عضويات في هذه الفترة" head={['الباقة', 'المباع', 'منها تجديد', ...(canFinancial ? ['القيمة'] : [])]}>
                    {report.plans.map((p) => (
                        <tr key={p.plan}>
                            <td className="font-medium">{p.plan}</td>
                            <td className="num">{number(p.sold)}</td>
                            <td className="num">{number(p.renewals)}</td>
                            {canFinancial && <td className="num">{p.revenue !== null ? f.money(p.revenue) : '—'}</td>}
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="الباقات حسب المباع" has={report.plans.length > 0}>
                    <HBars data={report.plans.map((p) => ({ label: p.plan, value: p.sold }))} />
                </ChartCard>
            </div>
        </>
    );
}

type ExpiringRow = { id: number; member_id: number; member_no: string | null; member: string | null; phone: string | null; plan: string; ends_on: string; days_left: number; renewed: boolean };
export interface Expiring { rows: ExpiringRow[]; stats: { total: number; soon: number; renewed: number }; byPlan: { plan: string; count: number }[]; options: { days: number } }

/** العضويات قريبة الانتهاء (today's state; renewing stays on the member's page). */
export function ExpiringBody({ data }: { data: Expiring }) {
    return (
        <>
            <StatGrid cols={3}>
                <Figure label={`تنتهي خلال ${data.options.days} يومًا`} value={number(data.stats.total)} icon={Hourglass} tone="amber" />
                <Figure label="تنتهي خلال 3 أيام" value={number(data.stats.soon)} icon={CalendarClock} tone="red" />
                <Figure label="لها عضوية تالية" value={number(data.stats.renewed)} icon={RefreshCw} tone="green" hint={`لم تُجدَّد: ${number(data.stats.total - data.stats.renewed)}`} />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="العضويات" subtitle="الأقرب انتهاءً أولًا" empty="لا عضويات تنتهي في هذه المدة" head={['العضو', 'الباقة', 'تنتهي', 'المتبقي', 'التجديد']}>
                    {data.rows.map((r) => (
                        <tr key={r.id}>
                            <td>
                                <Link href={route('club.members.show', r.member_id)} className="font-medium hover:text-brand">{r.member ?? '—'}</Link>
                                <span className="block text-xs text-muted num">{r.member_no}{r.phone && ` · ${r.phone}`}</span>
                            </td>
                            <td>{r.plan}</td>
                            <td className="num">{date(r.ends_on)}</td>
                            <td><Badge tone={r.days_left <= 3 ? 'red' : 'amber'}><span className="num">{r.days_left}</span> يوم</Badge></td>
                            <td>{r.renewed ? <Badge tone="green">له عضوية تالية</Badge> : <span className="text-xs text-muted">لم تُجدَّد</span>}</td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="حسب الباقة" has={data.byPlan.length > 0}>
                    <HBars data={data.byPlan.map((p) => ({ label: p.plan, value: p.count }))} />
                </ChartCard>
            </div>
        </>
    );
}

/** الحصص والحضور. */
export function ClassesBody({ report }: { report: PeriodReport }) {
    const f = useFormats();
    const m = (k: string) => report.comparison[k];
    return (
        <>
            <StatGrid>
                <Kpi label="الحصص" metric={m('sessions')} format={f.num} metricKey="sessions" />
                <Kpi label="الحجوزات" metric={m('bookings')} format={f.num} metricKey="bookings" />
                <Kpi label="نسبة الحضور" metric={m('attendance_rate')} format={f.pct} metricKey="attendance_rate" hint="الحاضرون من الحضور + الغياب" />
                <Kpi label="نسبة الإشغال" metric={m('occupancy_rate')} format={f.pct} metricKey="occupancy_rate" hint="الحجوزات من السعة المتاحة" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="البرامج الأكثر طلبًا" empty="لا حصص في هذه الفترة" head={['البرنامج', 'الحصص', 'الحجوزات', 'متوسط/حصة', 'حضر', 'غاب', 'نسبة الحضور']}>
                    {report.programs.map((p, i) => (
                        <tr key={p.program}>
                            <td>
                                <span className="flex items-center gap-2 font-medium">
                                    <span className="num text-xs text-muted">{i + 1}</span>
                                    <span className="size-2.5 rounded-full" style={{ background: p.color ?? '#9ca3af' }} />
                                    {p.program}
                                </span>
                            </td>
                            <td className="num">{p.sessions}</td>
                            <td className="num font-medium">{p.bookings}</td>
                            <td className="num">{number(p.avg_per_session, 1)}</td>
                            <td className="num text-emerald-700">{p.attended}</td>
                            <td className="num text-red-600">{p.no_show}</td>
                            <td><Rate value={p.attendance_rate} /></td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="الحجوزات حسب البرنامج" has={report.programs.length > 0}>
                    <HBars data={report.programs.map((p) => ({ label: p.program, value: p.bookings, color: p.color ?? undefined }))} />
                </ChartCard>
            </div>
        </>
    );
}

type TrainerRow = { id: number; trainer: string; sessions: number; bookings: number; attended: number; no_show: number; attendance_rate: number; pt_completed: number };
export interface Trainers { trainers: { rows: TrainerRow[]; comparison: Record<string, Metric> } }

/** أداء المدربين. */
export function TrainersBody({ data }: { data: Trainers }) {
    const f = useFormats();
    const { rows, comparison } = data.trainers;
    return (
        <>
            <StatGrid>
                <Kpi label="مدربون لهم نشاط" metric={comparison.trainers} format={f.num} metricKey="trainers" />
                <Kpi label="الحصص" metric={comparison.sessions} format={f.num} metricKey="sessions" />
                <Kpi label="نسبة الحضور" metric={comparison.attendance_rate} format={f.pct} metricKey="attendance_rate" hint={`الحجوزات: ${number(comparison.bookings.current)}`} />
                <Kpi label="جلسات تدريب شخصي منجزة" metric={comparison.pt_completed} format={f.num} metricKey="pt_completed" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="المدربون" subtitle="الحصص الجماعية والتدريب الشخصي في الفترة" empty="لا نشاط للمدربين في هذه الفترة" head={['المدرب', 'الحصص', 'الحجوزات', 'حضر', 'غاب', 'نسبة الحضور', 'تدريب شخصي']}>
                    {rows.map((t) => (
                        <tr key={t.id}>
                            <td className="font-medium">{t.trainer}</td>
                            <td className="num">{t.sessions}</td>
                            <td className="num font-medium">{t.bookings}</td>
                            <td className="num text-emerald-700">{t.attended}</td>
                            <td className="num text-red-600">{t.no_show}</td>
                            <td><Rate value={t.attendance_rate} /></td>
                            <td className="num">{t.pt_completed}</td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="الحجوزات حسب المدرب" empty="لا حجوزات" has={rows.some((t) => t.bookings > 0)}>
                    <HBars data={rows.filter((t) => t.bookings > 0).map((t) => ({ label: t.trainer, value: t.bookings }))} />
                </ChartCard>
            </div>
        </>
    );
}

type CheckinRecord = { id: number; method: string; direction: string; result: string; credential_hint: string | null; reason: string | null; member: { id: number; full_name: string; member_no: string } | null; by: string | null; at: string };
export interface Checkins {
    comparison: Record<string, Metric>;
    days: { date: string; allowed: number; denied: number; duplicate: number }[];
    hours: { hour: string; count: number }[];
    methods: { label: string; count: number }[];
    reasons: { label: string; count: number }[];
    records: Paginated<CheckinRecord>;
}

const METHODS: Record<string, string> = { manual: 'يدوي (الاستقبال)', qr: 'رمز QR', card: 'بطاقة', fingerprint: 'بصمة', barcode: 'باركود', pin: 'رمز سري' };

/** دخول الأعضاء. */
export function CheckinsBody({ data }: { data: Checkins }) {
    const f = useFormats();
    const c = data.comparison;
    return (
        <>
            <StatGrid>
                <Kpi label="مرات الدخول المسموحة" metric={c.allowed} format={f.num} metricKey="allowed" />
                <Kpi label="أعضاء مختلفون دخلوا" metric={c.members} format={f.num} metricKey="members" />
                <Kpi label="محاولات مرفوضة" metric={c.denied} format={f.num} metricKey="denied" />
                <Kpi label="مكررة لم تُحتسب" metric={c.duplicate} format={f.num} metricKey="duplicate" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard className="lg:col-span-2" title="الدخول اليومي" has={data.days.some((d) => d.allowed > 0)}>
                    <BarTrend data={data.days.map((d) => ({ label: d.date.slice(5), value: d.allowed }))} valueLabel={(v) => number(v)} />
                </ChartCard>
                <ChartCard title="حسب الساعة" has={data.hours.length > 0}>
                    <HBars data={data.hours.map((h) => ({ label: h.hour, value: h.count }))} />
                </ChartCard>
                <ChartCard title="حسب طريقة الدخول" has={data.methods.length > 0}>
                    <HBars data={data.methods.map((m) => ({ label: m.label, value: m.count }))} />
                </ChartCard>
                <ChartCard className="lg:col-span-2" title="أسباب الرفض" empty="لا محاولات مرفوضة" has={data.reasons.length > 0}>
                    <HBars data={data.reasons.map((r) => ({ label: r.label, value: r.count, color: '#dc2626' }))} />
                </ChartCard>
            </div>
            <TableCard className="mt-4" title="السجل" subtitle="الأحدث أولًا" empty="لا عمليات دخول في هذه الفترة" head={['الوقت', 'العضو', 'الطريقة', 'الجهاز / الموظف', 'النتيجة']} footer={<Pagination data={data.records} />}>
                {data.records.data.map((r) => (
                    <tr key={r.id}>
                        <td className="whitespace-nowrap text-xs num">{r.at}</td>
                        <td>
                            {r.member ? (
                                <Link href={route('club.members.show', r.member.id)} className="font-medium hover:text-brand">{r.member.full_name}<span className="block text-xs font-normal text-muted num">{r.member.member_no}</span></Link>
                            ) : (
                                <span className="text-muted">غير معروف{r.credential_hint && <span className="ms-1 num">({r.credential_hint})</span>}</span>
                            )}
                        </td>
                        <td className="text-sm">{METHODS[r.method] ?? r.method}{r.direction === 'out' && <span className="ms-1 text-xs text-muted">(خروج)</span>}</td>
                        <td className="text-xs text-muted">{r.by ?? '—'}</td>
                        <td>
                            {r.result === 'allowed' ? <Badge tone="green"><CheckCircle2 className="size-3.5" /> مسموح</Badge>
                                : r.result === 'duplicate' ? <Badge tone="amber"><Clock className="size-3.5" /> مكرر</Badge>
                                    : <Badge tone="red"><XCircle className="size-3.5" /> {r.reason ?? 'مرفوض'}</Badge>}
                        </td>
                    </tr>
                ))}
            </TableCard>
        </>
    );
}

type StaffRecord = { id: number; user: string | null; method: string; device: string | null; date: string; in: string; out: string | null; hours: number | null };
export interface StaffAttendance {
    comparison: Record<string, Metric>;
    summary: { user: string; days: number; hours: number; open: number; records: number }[];
    records: Paginated<StaffRecord>;
    staff: { id: number; name: string }[];
    options: { user_id: number | null };
}

/** حضور الموظفين (punching stays in its section). */
export function StaffBody({ data }: { data: StaffAttendance }) {
    const f = useFormats();
    const c = data.comparison;
    return (
        <>
            <StatGrid>
                <Kpi label="موظفون حضروا" metric={c.staff} format={f.num} metricKey="staff" />
                <Kpi label="أيام حضور" metric={c.days} format={f.num} metricKey="days" />
                <Kpi label="ساعات العمل" metric={c.hours} format={f.dec} metricKey="hours" />
                <Kpi label="بلا انصراف" metric={c.open} format={f.num} metricKey="open" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="ملخص الفترة لكل موظف" empty="لا حضور في هذه الفترة" head={['الموظف', 'أيام الحضور', 'ساعات العمل', 'السجلات', 'بلا انصراف']}>
                    {data.summary.map((s, i) => (
                        <tr key={i}>
                            <td className="font-medium">{s.user}</td>
                            <td className="num">{s.days}</td>
                            <td className="num">{number(s.hours, 1)}</td>
                            <td className="num">{s.records}</td>
                            <td>{s.open ? <Badge tone="amber">{s.open}</Badge> : <span className="text-muted">—</span>}</td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="ساعات العمل حسب الموظف" has={data.summary.some((s) => s.hours > 0)}>
                    <HBars data={data.summary.filter((s) => s.hours > 0).map((s) => ({ label: s.user, value: s.hours }))} format={(v) => number(v, 1)} />
                </ChartCard>
            </div>
            <TableCard className="mt-4" title="السجلات" subtitle="الأحدث أولًا" empty="لا سجلات في هذه الفترة" head={['الموظف', 'التاريخ', 'الحضور', 'الانصراف', 'الساعات', 'الطريقة']} footer={<Pagination data={data.records} />}>
                {data.records.data.map((r) => (
                    <tr key={r.id}>
                        <td className="font-medium">{r.user ?? '—'}</td>
                        <td className="num">{date(r.date)}</td>
                        <td className="num">{r.in}</td>
                        <td>{r.out ? <span className="num">{r.out}</span> : <Badge tone="amber">لم ينصرف</Badge>}</td>
                        <td className="num">{r.hours ?? '—'}</td>
                        <td>{r.method}{r.device && <span className="block text-xs text-muted">{r.device}</span>}</td>
                    </tr>
                ))}
            </TableCard>
        </>
    );
}
