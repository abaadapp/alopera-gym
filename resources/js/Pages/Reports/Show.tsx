import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, ArrowUpCircle, BarChart3, CalendarCheck, CalendarDays, CalendarRange, CalendarX, Download, Dumbbell, FileMinus, LogIn, Percent, RefreshCw, Scale, ShoppingBag, Snowflake, Ticket, TrendingDown, Undo2, UserCheck, UserPlus, UserRound, Wallet, XCircle, type LucideIcon } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { BarTrend, Badge, Button, Card, CardHeader, Delta, EmptyState, HBars, Input, PageHeader, StatCard, StatGrid } from '@/Components/ui';
import { useCan, useCurrency, useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money, number } from '@/lib/format';
import { invoiceType, paymentMethod } from '@/lib/labels';

type Metric = { current: number | string; previous: number | string; change: number | null };
type ReportKey = 'finance' | 'members' | 'classes' | 'trainers' | 'branches';

interface Report {
    range: { from: string; to: string; previous_from: string; previous_to: string };
    comparison: Record<string, Metric>;
    plans: { plan: string; sold: number; renewals: number; revenue: number | null }[];
    programs: { program: string; color: string | null; sessions: number; bookings: number; attended: number; no_show: number; attendance_rate: number; avg_per_session: number }[];
    revenue_by_method: { key: string; amount: number }[];
    revenue_by_type: { key: string; amount: number }[];
    expenses_by_category: { key: string; amount: number }[];
    products: { product: string; quantity: number; revenue: number; profit: number }[];
    trend: { date: string; amount: number }[];
}

interface TrainerRow { id: number; trainer: string; sessions: number; bookings: number; attended: number; no_show: number; attendance_rate: number; pt_completed: number }
interface BranchRow { id: number; name: string; is_main: boolean; is_active: boolean; active_members: number; comparison: Record<string, Metric> }

interface Props {
    /** Which report (not `key`: React keeps that name for itself). */
    kind: ReportKey;
    title: string;
    report: Report;
    trainers: { rows: TrainerRow[]; comparison: Record<string, Metric> } | null;
    branches: BranchRow[] | null;
    filters: { from: string; to: string; preset: string; compare: string; compare_from: string; compare_to: string };
    canFinancial: boolean;
    canExport: boolean;
}

const presets = [
    { key: 'today', label: 'اليوم' },
    { key: 'week', label: 'هذا الأسبوع' },
    { key: '', label: 'هذا الشهر' },
    { key: 'last_month', label: 'الشهر الماضي' },
    { key: 'quarter', label: 'هذا الربع' },
    { key: 'year', label: 'هذه السنة' },
];

const compareModes = [
    { key: 'previous', label: 'الفترة السابقة (بنفس المدة)' },
    { key: 'month', label: 'نفس الفترة من الشهر السابق' },
    { key: 'year', label: 'نفس الفترة من العام السابق' },
    { key: 'custom', label: 'فترة مخصصة' },
];

/** Each figure's icon and colour, as on every stat tile. */
const KPI_ICONS: Record<string, [LucideIcon, 'brand' | 'green' | 'amber' | 'red' | 'gray' | 'blue' | 'violet']> = {
    revenue: [Wallet, 'green'], expenses: [TrendingDown, 'red'], net: [Scale, 'brand'], collection_rate: [Percent, 'blue'], refunds: [Undo2, 'amber'],
    credit_notes: [FileMinus, 'gray'], product_sales: [ShoppingBag, 'violet'],
    new_memberships: [Ticket, 'brand'], renewals: [RefreshCw, 'green'], upgrades: [ArrowUpCircle, 'blue'], new_members: [UserPlus, 'violet'],
    cancellations: [XCircle, 'red'], freezes: [Snowflake, 'blue'], expirations: [CalendarX, 'gray'], checkins: [LogIn, 'blue'],
    sessions: [CalendarDays, 'brand'], bookings: [CalendarCheck, 'blue'], attendance_rate: [UserCheck, 'green'], occupancy_rate: [Percent, 'amber'],
    trainers: [Dumbbell, 'brand'], pt_completed: [UserRound, 'violet'],
};

/** Metrics where a lower number is the good direction. */
const lowerIsBetter = new Set(['cancellations', 'freezes', 'expirations', 'expenses', 'refunds', 'credit_notes']);

/**
 * One report of the reports page: its period and comparison, its figures against the earlier period, its charts and
 * tables, and its exports. The period and the comparison are in the address, so a report can be shared as a link.
 */
export default function ReportShow(props: Props) {
    const { kind: key, title, report, filters, canFinancial, canExport } = props;
    const currency = useCurrency();
    const can = useCan();
    const sales = useShared().modules.sales;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [compareFrom, setCompareFrom] = useState(filters.compare_from);
    const [compareTo, setCompareTo] = useState(filters.compare_to);
    const [customCompare, setCustomCompare] = useState(filters.compare === 'custom');
    const m = (k: string) => report.comparison[k] ?? { current: 0, previous: 0, change: null };

    // Changing the period keeps the comparison choice, and changing the comparison keeps the period.
    const compareParams = (): Record<string, string> =>
        filters.compare === 'custom' ? { compare: 'custom', compare_from: filters.compare_from, compare_to: filters.compare_to }
            : filters.compare !== 'previous' ? { compare: filters.compare } : {};
    const periodParams = (): Record<string, string> => (filters.preset ? { preset: filters.preset } : { from: filters.from, to: filters.to });
    const go = (params: Record<string, string>, keep: 'compare' | 'period' = 'compare') =>
        router.get(route('club.reports.show', { report: key }), { ...(keep === 'compare' ? compareParams() : periodParams()), ...params }, { preserveState: true, preserveScroll: true });
    const activePreset = filters.preset || (isThisMonth(filters.from, filters.to) ? '' : 'custom');

    const exportLink = (name: string, format: 'csv' | 'xlsx') => route('club.reports.export', { report: name, from: filters.from, to: filters.to, format, ...compareParams() });
    const exports: [string, string][] = !canExport ? [] : {
        finance: [['revenue', 'الإيرادات'], ['expenses', 'المصروفات'], ...(sales ? [['sales', 'المبيعات'] as [string, string]] : [])],
        members: [['memberships', 'العضويات'], ...(can('members.export') ? [['members', 'الأعضاء'] as [string, string]] : [])],
        classes: [['classes', 'الحصص']],
        trainers: [['trainers', 'المدربون']],
        branches: [['branches', 'الفروع']],
    }[key] as [string, string][];

    const fmtMoney = (v: number | string) => money(v, currency);
    const fmtPct = (v: number | string) => `${number(v, 1)}%`;
    const fmtNum = (v: number | string) => number(v);

    return (
        <ClubLayout title={title}>
            <PageHeader
                back={<Link href={route('club.reports.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight className="size-4" /> التقارير</Link>}
                title={title}
                subtitle={<>من {date(report.range.from)} إلى {date(report.range.to)} · مقارنة بـ {date(report.range.previous_from)} – {date(report.range.previous_to)}</>}
                actions={exports.length > 0 && <Exports exports={exports} link={exportLink} />}
            />

            <Card className="mb-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-wrap gap-2" role="group" aria-label="الفترة">
                        {presets.map((p) => (
                            <button
                                key={p.key || 'month'}
                                type="button"
                                onClick={() => go(p.key ? { preset: p.key } : {})}
                                aria-pressed={activePreset === p.key}
                                className={cn('h-9 rounded-full px-4 text-[13px] font-medium transition-colors', activePreset === p.key ? 'bg-[#111] text-white' : 'border border-line bg-white text-[#6b7280] hover:border-[#d4d4d4] hover:text-[#111]')}
                            >
                                {p.label}
                            </button>
                        ))}
                    </div>
                    <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); go({ from, to }); }}>
                        <CalendarRange className="size-4 text-muted" />
                        <Input type="date" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="من" />
                        <span className="text-muted">—</span>
                        <Input type="date" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} aria-label="إلى" />
                        <Button type="submit" variant="secondary">عرض</Button>
                    </form>
                </div>
                <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <span className="text-[13px] text-muted">قارن مع:</span>
                    {compareModes.map((mode) => {
                        const on = customCompare ? mode.key === 'custom' : filters.compare === mode.key;
                        return (
                            <button
                                key={mode.key}
                                type="button"
                                aria-pressed={on}
                                onClick={() => (mode.key === 'custom' ? setCustomCompare(true) : (setCustomCompare(false), go(mode.key === 'previous' ? {} : { compare: mode.key }, 'period')))}
                                className={cn('h-8 rounded-full px-3 text-[12px] font-medium transition-colors', on ? 'bg-[#111] text-white' : 'border border-line bg-white text-[#6b7280] hover:border-[#d4d4d4] hover:text-[#111]')}
                            >
                                {mode.label}
                            </button>
                        );
                    })}
                    {customCompare && (
                        <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); go({ compare: 'custom', compare_from: compareFrom, compare_to: compareTo }, 'period'); }}>
                            <Input type="date" className="w-40" value={compareFrom} onChange={(e) => setCompareFrom(e.target.value)} aria-label="مقارنة من" />
                            <span className="text-muted">—</span>
                            <Input type="date" className="w-40" value={compareTo} onChange={(e) => setCompareTo(e.target.value)} aria-label="مقارنة إلى" />
                            <Button type="submit" variant="secondary" size="sm">قارن</Button>
                        </form>
                    )}
                </div>
            </Card>

            {key === 'finance' && canFinancial && (
                <>
                    <StatGrid>
                        <Kpi label="صافي التحصيل" metric={m('revenue')} format={fmtMoney} metricKey="revenue" />
                        <Kpi label="المصروفات" metric={m('expenses')} format={fmtMoney} metricKey="expenses" />
                        <Kpi label="صافي الربح التشغيلي" metric={m('net')} format={fmtMoney} metricKey="net" />
                        <Kpi label="نسبة التحصيل" metric={m('collection_rate')} format={fmtPct} metricKey="collection_rate" hint={`من فواتير بقيمة ${fmtMoney(m('invoiced').current)}`} />
                        <Kpi label="المردودات" metric={m('refunds')} format={fmtMoney} metricKey="refunds" />
                        <Kpi label="الإشعارات الدائنة" metric={m('credit_notes')} format={fmtMoney} metricKey="credit_notes" />
                        {sales && <Kpi label="مبيعات المنتجات" metric={m('product_sales')} format={fmtMoney} metricKey="product_sales" />}
                    </StatGrid>
                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        <Card className="lg:col-span-2">
                            <CardHeader title="التحصيل اليومي" />
                            {report.trend.length > 0 ? <BarTrend data={report.trend.map((d) => ({ label: d.date.slice(5), value: d.amount }))} valueLabel={fmtMoney} /> : <EmptyState title="لا بيانات" />}
                        </Card>
                        <Card>
                            <CardHeader title="حسب طريقة الدفع" />
                            {report.revenue_by_method.length ? <HBars data={report.revenue_by_method.map((r) => ({ label: paymentMethod[r.key] ?? r.key, value: r.amount }))} format={fmtMoney} /> : <EmptyState title="لا دفعات" />}
                        </Card>
                        <Card>
                            <CardHeader title="حسب نوع الإيراد" />
                            {report.revenue_by_type.length ? <HBars data={report.revenue_by_type.map((r) => ({ label: invoiceType[r.key] ?? r.key, value: r.amount }))} format={fmtMoney} /> : <EmptyState title="لا دفعات" />}
                        </Card>
                        <Card className={cn(!sales && 'lg:col-span-2')}>
                            <CardHeader title="المصروفات حسب التصنيف" />
                            {report.expenses_by_category.length ? <HBars data={report.expenses_by_category.map((r) => ({ label: r.key, value: r.amount, color: '#dc2626' }))} format={fmtMoney} /> : <EmptyState title="لا مصروفات" />}
                        </Card>
                        {sales && (
                            <TableCard title="المنتجات الأكثر مبيعًا" empty="لا مبيعات" head={['المنتج', 'الكمية', 'الإيراد', 'الربح']}>
                                {report.products.map((p) => (
                                    <tr key={p.product}>
                                        <td className="font-medium">{p.product}</td>
                                        <td className="num">{number(p.quantity, 3)}</td>
                                        <td className="num">{fmtMoney(p.revenue)}</td>
                                        <td className={cn('num', p.profit < 0 && 'text-red-600')}>{fmtMoney(p.profit)}</td>
                                    </tr>
                                ))}
                            </TableCard>
                        )}
                    </div>
                </>
            )}

            {key === 'members' && (
                <>
                    <StatGrid>
                        <Kpi label="اشتراكات جديدة" metric={m('new_memberships')} format={fmtNum} metricKey="new_memberships" />
                        <Kpi label="تجديدات" metric={m('renewals')} format={fmtNum} metricKey="renewals" />
                        <Kpi label="تغيير/ترقية باقة" metric={m('upgrades')} format={fmtNum} metricKey="upgrades" />
                        <Kpi label="أعضاء جدد" metric={m('new_members')} format={fmtNum} metricKey="new_members" />
                        <Kpi label="إلغاءات" metric={m('cancellations')} format={fmtNum} metricKey="cancellations" />
                        <Kpi label="تجميدات" metric={m('freezes')} format={fmtNum} metricKey="freezes" />
                        <Kpi label="عضويات انتهت" metric={m('expirations')} format={fmtNum} metricKey="expirations" />
                        <Kpi label="مرات الدخول" metric={m('checkins')} format={fmtNum} metricKey="checkins" />
                    </StatGrid>
                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        <TableCard className="lg:col-span-2" title="الباقات المباعة" subtitle="العضويات المفعّلة خلال الفترة" empty="لم تُفعّل عضويات في هذه الفترة" head={['الباقة', 'المباع', 'منها تجديد', ...(canFinancial ? ['القيمة'] : [])]}>
                            {report.plans.map((p) => (
                                <tr key={p.plan}>
                                    <td className="font-medium">{p.plan}</td>
                                    <td className="num">{number(p.sold)}</td>
                                    <td className="num">{number(p.renewals)}</td>
                                    {canFinancial && <td className="num">{p.revenue !== null ? fmtMoney(p.revenue) : '—'}</td>}
                                </tr>
                            ))}
                        </TableCard>
                        <Card>
                            <CardHeader title="الباقات حسب المباع" />
                            {report.plans.length ? <HBars data={report.plans.map((p) => ({ label: p.plan, value: p.sold }))} /> : <EmptyState title="لا بيانات" />}
                        </Card>
                    </div>
                </>
            )}

            {key === 'classes' && (
                <>
                    <StatGrid>
                        <Kpi label="الحصص" metric={m('sessions')} format={fmtNum} metricKey="sessions" />
                        <Kpi label="الحجوزات" metric={m('bookings')} format={fmtNum} metricKey="bookings" />
                        <Kpi label="نسبة الحضور" metric={m('attendance_rate')} format={fmtPct} metricKey="attendance_rate" hint="الحاضرون من الحضور + الغياب" />
                        <Kpi label="نسبة الإشغال" metric={m('occupancy_rate')} format={fmtPct} metricKey="occupancy_rate" hint="الحجوزات من السعة المتاحة" />
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
                        <Card>
                            <CardHeader title="الحجوزات حسب البرنامج" />
                            {report.programs.length ? <HBars data={report.programs.map((p) => ({ label: p.program, value: p.bookings, color: p.color ?? undefined }))} /> : <EmptyState title="لا بيانات" />}
                        </Card>
                    </div>
                </>
            )}

            {key === 'trainers' && props.trainers && (
                <>
                    <StatGrid>
                        <Kpi label="مدربون لهم نشاط" metric={props.trainers.comparison.trainers} format={fmtNum} metricKey="trainers" />
                        <Kpi label="الحصص" metric={props.trainers.comparison.sessions} format={fmtNum} metricKey="sessions" />
                        <Kpi label="نسبة الحضور" metric={props.trainers.comparison.attendance_rate} format={fmtPct} metricKey="attendance_rate" hint={`الحجوزات: ${number(props.trainers.comparison.bookings.current)}`} />
                        <Kpi label="جلسات تدريب شخصي منجزة" metric={props.trainers.comparison.pt_completed} format={fmtNum} metricKey="pt_completed" />
                    </StatGrid>
                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        <TableCard className="lg:col-span-2" title="المدربون" subtitle="الحصص الجماعية والتدريب الشخصي في الفترة" empty="لا نشاط للمدربين في هذه الفترة" head={['المدرب', 'الحصص', 'الحجوزات', 'حضر', 'غاب', 'نسبة الحضور', 'تدريب شخصي']}>
                            {props.trainers.rows.map((t) => (
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
                        <Card>
                            <CardHeader title="الحجوزات حسب المدرب" />
                            {props.trainers.rows.some((t) => t.bookings > 0) ? <HBars data={props.trainers.rows.filter((t) => t.bookings > 0).map((t) => ({ label: t.trainer, value: t.bookings }))} /> : <EmptyState title="لا حجوزات" />}
                        </Card>
                    </div>
                </>
            )}

            {key === 'branches' && props.branches && <Branches rows={props.branches} canFinancial={canFinancial} fmtMoney={fmtMoney} />}
        </ClubLayout>
    );
}

/** Each branch side by side: its figures, each with its change against the earlier period. */
function Branches({ rows, canFinancial, fmtMoney }: { rows: BranchRow[]; canFinancial: boolean; fmtMoney: (v: number | string) => string }) {
    const metrics: { key: string; label: string; money?: boolean }[] = [
        { key: 'new_members', label: 'أعضاء جدد' },
        { key: 'memberships_sold', label: 'عضويات مفعّلة' },
        { key: 'checkins', label: 'مرات الدخول' },
        { key: 'sessions', label: 'الحصص' },
        ...(canFinancial ? [{ key: 'revenue', label: 'صافي التحصيل', money: true }, { key: 'expenses', label: 'المصروفات', money: true }] : []),
    ];
    const chartKey = canFinancial ? 'revenue' : 'checkins';
    const fmt = (metric: { money?: boolean }, v: number | string) => (metric.money ? fmtMoney(v) : number(v));

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <TableCard className="lg:col-span-2" title="الفروع" subtitle="السجلات التي بلا فرع تُحسب على الفرع الرئيسي" empty="لا فروع" head={['الفرع', 'العضويات النشطة الآن', ...metrics.map((x) => x.label)]}>
                {rows.map((b) => (
                    <tr key={b.id}>
                        <td className="font-medium">
                            <span className="flex flex-wrap items-center gap-1.5">
                                {b.name}
                                {b.is_main && <Badge>الرئيسي</Badge>}
                                {!b.is_active && <Badge tone="amber">موقوف</Badge>}
                            </span>
                        </td>
                        <td className="num">{number(b.active_members)}</td>
                        {metrics.map((x) => {
                            const metric = b.comparison[x.key];
                            return (
                                <td key={x.key}>
                                    <span className="num block">{fmt(x, metric.current)}</span>
                                    {metric.change !== null && <Delta change={metric.change} inverted={lowerIsBetter.has(x.key)} />}
                                </td>
                            );
                        })}
                    </tr>
                ))}
            </TableCard>
            <Card>
                <CardHeader title={canFinancial ? 'صافي التحصيل حسب الفرع' : 'مرات الدخول حسب الفرع'} />
                {rows.some((b) => Number(b.comparison[chartKey].current) > 0)
                    ? <HBars data={rows.map((b) => ({ label: b.name, value: Number(b.comparison[chartKey].current) }))} format={canFinancial ? fmtMoney : undefined} />
                    : <EmptyState title="لا بيانات" />}
            </Card>
        </div>
    );
}

function TableCard({ title, subtitle, head, empty, className, children }: { title: string; subtitle?: string; head: string[]; empty: string; className?: string; children: ReactNode[] }) {
    return (
        <Card padded={false} className={className}>
            <div className="p-5 pb-2"><CardHeader title={title} subtitle={subtitle} className="mb-0" /></div>
            {children.length ? (
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr>{head.map((h) => <th key={h}>{h}</th>)}</tr></thead>
                        <tbody>{children}</tbody>
                    </table>
                </div>
            ) : (
                <EmptyState title={empty} />
            )}
        </Card>
    );
}

function Rate({ value }: { value: number }) {
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-[#f2f2f0]"><div className="h-full rounded-full bg-[#111]" style={{ width: `${Math.min(100, value)}%` }} /></div>
            <span className="num text-xs">{number(value, 1)}%</span>
        </div>
    );
}

function Exports({ exports, link }: { exports: [string, string][]; link: (r: string, f: 'csv' | 'xlsx') => string }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {exports.map(([name, text]) => (
                <span key={name} className="inline-flex h-9 items-center overflow-hidden rounded-[10px] border border-line bg-white text-[12px]">
                    <span className="flex items-center gap-1 px-2.5 text-muted"><Download className="size-3.5" />{text}</span>
                    <a href={link(name, 'xlsx')} className="flex h-full items-center border-s border-line px-2.5 text-[#111] hover:bg-[#fafafa]">Excel</a>
                    <a href={link(name, 'csv')} className="flex h-full items-center border-s border-line px-2.5 text-[#111] hover:bg-[#fafafa]">CSV</a>
                </span>
            ))}
        </div>
    );
}

function isThisMonth(from: string, to: string): boolean {
    const now = new Date();
    const first = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
    const today = `${first.slice(0, 8)}${String(now.getDate()).padStart(2, '0')}`;
    return from === first && to === today;
}

function Kpi({ label, metric, format, metricKey, hint }: { label: string; metric: Metric; format: (v: number | string) => string; metricKey: string; hint?: string }) {
    const comparison = `المقارنة: ${format(metric.previous)}`;
    const [Icon, tone] = KPI_ICONS[metricKey] ?? [BarChart3, 'gray'];
    return (
        <StatCard
            icon={<Icon />}
            tone={tone}
            label={label}
            value={format(metric.current)}
            trend={metric.change === null || metric.change === undefined ? null : { change: metric.change, label: comparison, inverted: lowerIsBetter.has(metricKey) }}
            hint={[metric.change === null || metric.change === undefined ? comparison : null, hint].filter(Boolean).join(' · ') || undefined}
        />
    );
}
