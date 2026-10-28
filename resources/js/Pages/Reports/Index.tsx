import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { CalendarRange, Download } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { BarTrend, Button, Card, CardHeader, Delta, EmptyState, HBars, Input, PageHeader } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money, number } from '@/lib/format';
import { invoiceType, paymentMethod } from '@/lib/labels';

type Metric = { current: number | string; previous: number | string; change: number | null };

interface Report {
    range: { from: string; to: string; previous_from: string; previous_to: string };
    comparison: Record<string, Metric>;
    plans: { plan: string; sold: number; renewals: number; revenue: number | null }[];
    programs: { program: string; color: string | null; sessions: number; bookings: number; attended: number; no_show: number; attendance_rate: number; avg_per_session: number }[];
    trainers: { trainer: string; sessions: number; bookings: number; attended: number }[];
    revenue_by_method: { key: string; amount: number }[];
    revenue_by_type: { key: string; amount: number }[];
    expenses_by_category: { key: string; amount: number }[];
    products: { product: string; quantity: number; revenue: number; profit: number }[];
    trend: { date: string; amount: number }[];
}

interface Props {
    report: Report;
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

/** Metrics where a lower number is the good direction. */
const lowerIsBetter = new Set(['cancellations', 'freezes', 'expirations', 'expenses', 'refunds', 'credit_notes']);

export default function ReportsIndex({ report, filters, canFinancial, canExport }: Props) {
    const currency = useCurrency();
    const can = useCan();
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [compareFrom, setCompareFrom] = useState(filters.compare_from);
    const [compareTo, setCompareTo] = useState(filters.compare_to);
    const [customCompare, setCustomCompare] = useState(filters.compare === 'custom');
    const c = report.comparison;
    const m = (key: string) => c[key] ?? { current: 0, previous: 0, change: null };

    // Changing the period keeps the comparison choice, and changing the comparison keeps the period.
    const compareParams = (): Record<string, string> =>
        filters.compare === 'custom' ? { compare: 'custom', compare_from: filters.compare_from, compare_to: filters.compare_to }
            : filters.compare !== 'previous' ? { compare: filters.compare } : {};
    const periodParams = (): Record<string, string> => (filters.preset ? { preset: filters.preset } : { from: filters.from, to: filters.to });
    const go = (params: Record<string, string>, keep: 'compare' | 'period' | 'none' = 'compare') =>
        router.get(route('club.reports.index'), { ...(keep === 'compare' ? compareParams() : keep === 'period' ? periodParams() : {}), ...params }, { preserveState: true, preserveScroll: true });
    const activePreset = filters.preset || (isThisMonth(filters.from, filters.to) ? '' : 'custom');

    const exportLink = (report: string, format: 'csv' | 'xlsx') => route('club.reports.export', { report, from: filters.from, to: filters.to, format });

    const fmtMoney = (v: number | string) => money(v, currency);
    const fmtPct = (v: number | string) => `${number(v, 1)}%`;
    const fmtNum = (v: number | string) => number(v);

    return (
        <ClubLayout title="التقارير">
            <PageHeader
                title="التقارير"
                subtitle={<>من {date(report.range.from)} إلى {date(report.range.to)} · مقارنة بـ {date(report.range.previous_from)} – {date(report.range.previous_to)}</>}
            />

            <Card className="mb-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-wrap gap-2">
                        {presets.map((p) => (
                            <button
                                key={p.key || 'month'}
                                type="button"
                                onClick={() => go(p.key ? { preset: p.key } : {})}
                                className={cn('rounded-full border px-3.5 py-1.5 text-sm transition', activePreset === p.key ? 'border-brand bg-brand text-white' : 'border-line hover:border-brand-200')}
                            >
                                {p.label}
                            </button>
                        ))}
                    </div>
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ from, to });
                        }}
                    >
                        <CalendarRange className="size-4 text-muted" />
                        <Input type="date" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="من" />
                        <span className="text-muted">—</span>
                        <Input type="date" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} aria-label="إلى" />
                        <Button type="submit" variant="secondary">عرض</Button>
                    </form>
                </div>
                <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <span className="text-sm text-muted">قارن مع:</span>
                    {compareModes.map((mode) => (
                        <button
                            key={mode.key}
                            type="button"
                            onClick={() => (mode.key === 'custom' ? setCustomCompare(true) : (setCustomCompare(false), go(mode.key === 'previous' ? {} : { compare: mode.key }, 'period')))}
                            className={cn('rounded-full border px-3 py-1 text-xs transition', (customCompare ? mode.key === 'custom' : filters.compare === mode.key) ? 'border-brand bg-brand-50 text-brand-700' : 'border-line hover:border-brand-200')}
                        >
                            {mode.label}
                        </button>
                    ))}
                    {customCompare && (
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                go({ compare: 'custom', compare_from: compareFrom, compare_to: compareTo }, 'period');
                            }}
                        >
                            <Input type="date" className="w-40" value={compareFrom} onChange={(e) => setCompareFrom(e.target.value)} aria-label="مقارنة من" />
                            <span className="text-muted">—</span>
                            <Input type="date" className="w-40" value={compareTo} onChange={(e) => setCompareTo(e.target.value)} aria-label="مقارنة إلى" />
                            <Button type="submit" variant="secondary" size="sm">قارن</Button>
                        </form>
                    )}
                </div>
            </Card>

            {canFinancial && (
                <Section title="المالية" exports={canExport ? [['revenue', 'الإيرادات'], ['expenses', 'المصروفات'], ['sales', 'المبيعات']] : []} exportLink={exportLink}>
                    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                        <Kpi label="صافي التحصيل" metric={m('revenue')} format={fmtMoney} metricKey="revenue" />
                        <Kpi label="المصروفات" metric={m('expenses')} format={fmtMoney} metricKey="expenses" />
                        <Kpi label="صافي الربح التشغيلي" metric={m('net')} format={fmtMoney} metricKey="net" />
                        <Kpi label="نسبة التحصيل" metric={m('collection_rate')} format={fmtPct} metricKey="collection_rate" hint={`من فواتير بقيمة ${fmtMoney(m('invoiced').current)}`} />
                        <Kpi label="المردودات" metric={m('refunds')} format={fmtMoney} metricKey="refunds" />
                        <Kpi label="الإشعارات الدائنة" metric={m('credit_notes')} format={fmtMoney} metricKey="credit_notes" />
                        <Kpi label="مبيعات المنتجات" metric={m('product_sales')} format={fmtMoney} metricKey="product_sales" />
                    </div>
                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        <Card className="lg:col-span-2">
                            <CardHeader title="التحصيل اليومي" />
                            {report.trend.length > 0 ? (
                                <BarTrend data={report.trend.map((d) => ({ label: d.date.slice(5), value: d.amount }))} valueLabel={fmtMoney} />
                            ) : (
                                <EmptyState title="لا بيانات" />
                            )}
                        </Card>
                        <Card>
                            <CardHeader title="حسب طريقة الدفع" />
                            {report.revenue_by_method.length ? (
                                <HBars data={report.revenue_by_method.map((r) => ({ label: paymentMethod[r.key] ?? r.key, value: r.amount }))} format={fmtMoney} />
                            ) : (
                                <EmptyState title="لا دفعات" />
                            )}
                        </Card>
                        <Card>
                            <CardHeader title="حسب نوع الإيراد" />
                            {report.revenue_by_type.length ? (
                                <HBars data={report.revenue_by_type.map((r) => ({ label: invoiceType[r.key] ?? r.key, value: r.amount }))} format={fmtMoney} />
                            ) : (
                                <EmptyState title="لا دفعات" />
                            )}
                        </Card>
                        <Card>
                            <CardHeader title="المصروفات حسب التصنيف" />
                            {report.expenses_by_category.length ? (
                                <HBars data={report.expenses_by_category.map((r) => ({ label: r.key, value: r.amount, color: '#dc2626' }))} format={fmtMoney} />
                            ) : (
                                <EmptyState title="لا مصروفات" />
                            )}
                        </Card>
                        <Card padded={false}>
                            <div className="p-5 pb-2"><CardHeader title="المنتجات الأكثر مبيعًا" className="mb-0" /></div>
                            {report.products.length ? (
                                <div className="overflow-x-auto">
                                    <table className="table-base">
                                        <thead><tr><th>المنتج</th><th>الكمية</th><th>الإيراد</th><th>الربح</th></tr></thead>
                                        <tbody>
                                            {report.products.map((p) => (
                                                <tr key={p.product}>
                                                    <td className="font-medium">{p.product}</td>
                                                    <td className="num">{number(p.quantity, 3)}</td>
                                                    <td className="num">{fmtMoney(p.revenue)}</td>
                                                    <td className={cn('num', p.profit < 0 && 'text-red-600')}>{fmtMoney(p.profit)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState title="لا مبيعات" />
                            )}
                        </Card>
                    </div>
                </Section>
            )}

            <Section title="العضويات والمشتركات" exports={canExport ? [['memberships', 'العضويات'], ...(can('members.export') ? [['members', 'المشتركات'] as [string, string]] : [])] : []} exportLink={exportLink}>
                <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                    <Kpi label="اشتراكات جديدة" metric={m('new_memberships')} format={fmtNum} metricKey="new_memberships" />
                    <Kpi label="تجديدات" metric={m('renewals')} format={fmtNum} metricKey="renewals" />
                    <Kpi label="تغيير/ترقية باقة" metric={m('upgrades')} format={fmtNum} metricKey="upgrades" />
                    <Kpi label="مشتركات جديدات" metric={m('new_members')} format={fmtNum} metricKey="new_members" />
                    <Kpi label="إلغاءات" metric={m('cancellations')} format={fmtNum} metricKey="cancellations" />
                    <Kpi label="تجميدات" metric={m('freezes')} format={fmtNum} metricKey="freezes" />
                    <Kpi label="عضويات انتهت" metric={m('expirations')} format={fmtNum} metricKey="expirations" />
                    <Kpi label="مرات الدخول" metric={m('checkins')} format={fmtNum} metricKey="checkins" />
                </div>
                <Card className="mt-4" padded={false}>
                    <div className="p-5 pb-2"><CardHeader title="الباقات المباعة" subtitle="العضويات المفعّلة خلال الفترة" className="mb-0" /></div>
                    {report.plans.length ? (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>الباقة</th><th>المباع</th><th>منها تجديد</th>{canFinancial && <th>القيمة</th>}</tr></thead>
                                <tbody>
                                    {report.plans.map((p) => (
                                        <tr key={p.plan}>
                                            <td className="font-medium">{p.plan}</td>
                                            <td className="num">{number(p.sold)}</td>
                                            <td className="num">{number(p.renewals)}</td>
                                            {canFinancial && <td className="num">{p.revenue !== null ? fmtMoney(p.revenue) : '—'}</td>}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <EmptyState title="لم تُفعّل عضويات في هذه الفترة" />
                    )}
                </Card>
            </Section>

            <Section title="الحصص والحضور" exports={canExport ? [['classes', 'الحصص']] : []} exportLink={exportLink}>
                <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                    <Kpi label="الحصص" metric={m('sessions')} format={fmtNum} metricKey="sessions" />
                    <Kpi label="الحجوزات" metric={m('bookings')} format={fmtNum} metricKey="bookings" />
                    <Kpi label="نسبة الحضور" metric={m('attendance_rate')} format={fmtPct} metricKey="attendance_rate" hint="الحاضرات من الحضور + الغياب" />
                    <Kpi label="نسبة الإشغال" metric={m('occupancy_rate')} format={fmtPct} metricKey="occupancy_rate" hint="الحجوزات من السعة المتاحة" />
                </div>
                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    <Card padded={false} className="lg:col-span-2">
                        <div className="p-5 pb-2"><CardHeader title="البرامج الأكثر طلبًا" className="mb-0" /></div>
                        {report.programs.length ? (
                            <div className="overflow-x-auto">
                                <table className="table-base">
                                    <thead><tr><th>البرنامج</th><th>الحصص</th><th>الحجوزات</th><th>متوسط/حصة</th><th>حضرت</th><th>غابت</th><th>نسبة الحضور</th></tr></thead>
                                    <tbody>
                                        {report.programs.map((p, i) => (
                                            <tr key={p.program}>
                                                <td>
                                                    <span className="flex items-center gap-2 font-medium">
                                                        <span className="text-xs text-muted num">{i + 1}</span>
                                                        <span className="size-2.5 rounded-full" style={{ background: p.color ?? 'var(--brand)' }} />
                                                        {p.program}
                                                    </span>
                                                </td>
                                                <td className="num">{p.sessions}</td>
                                                <td className="font-medium num">{p.bookings}</td>
                                                <td className="num">{number(p.avg_per_session, 1)}</td>
                                                <td className="text-emerald-700 num">{p.attended}</td>
                                                <td className="text-red-600 num">{p.no_show}</td>
                                                <td>
                                                    <div className="flex items-center gap-2">
                                                        <div className="h-1.5 w-16 overflow-hidden rounded-full bg-brand-50"><div className="h-full rounded-full bg-brand" style={{ width: `${p.attendance_rate}%` }} /></div>
                                                        <span className="text-xs num">{number(p.attendance_rate, 1)}%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <EmptyState title="لا حصص في هذه الفترة" />
                        )}
                    </Card>
                    <Card>
                        <CardHeader title="المدربات" subtitle="حسب الحجوزات" />
                        {report.trainers.length ? (
                            <HBars data={report.trainers.map((t) => ({ label: `${t.trainer} (${t.sessions} حصة)`, value: t.bookings }))} />
                        ) : (
                            <EmptyState title="لا بيانات" />
                        )}
                    </Card>
                </div>
            </Section>
        </ClubLayout>
    );
}

function isThisMonth(from: string, to: string): boolean {
    const now = new Date();
    const first = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
    const today = `${first.slice(0, 8)}${String(now.getDate()).padStart(2, '0')}`;
    return from === first && to === today;
}

function Section({ title, children, exports, exportLink }: { title: string; children: ReactNode; exports: [string, string][]; exportLink: (r: string, f: 'csv' | 'xlsx') => string }) {
    return (
        <section className="mb-8">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">{title}</h2>
                {exports.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {exports.map(([key, text]) => (
                            <span key={key} className="inline-flex items-center overflow-hidden rounded-lg border border-line bg-white text-xs">
                                <span className="flex items-center gap-1 px-2 py-1.5 text-muted"><Download className="size-3.5" />{text}</span>
                                <a href={exportLink(key, 'xlsx')} className="border-s border-line px-2 py-1.5 hover:bg-brand-50">Excel</a>
                                <a href={exportLink(key, 'csv')} className="border-s border-line px-2 py-1.5 hover:bg-brand-50">CSV</a>
                            </span>
                        ))}
                    </div>
                )}
            </div>
            {children}
        </section>
    );
}

function Kpi({ label, metric, format, metricKey, hint }: { label: string; metric: Metric; format: (v: number | string) => string; metricKey: string; hint?: string }) {
    const inverted = lowerIsBetter.has(metricKey);
    return (
        <div className="card p-4">
            <p className="text-sm text-muted">{label}</p>
            <p className="mt-1 text-xl font-semibold sm:text-2xl"><span className="num">{format(metric.current)}</span></p>
            <div className="mt-1 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs text-muted">
                <span>المقارنة: <span className="num">{format(metric.previous)}</span></span>
                {inverted ? <InvertedDelta change={metric.change} /> : <Delta change={metric.change} />}
            </div>
            {hint && <p className="mt-1 text-[11px] text-muted">{hint}</p>}
        </div>
    );
}

/** Arrow shows the real direction; colour shows whether that is good (a drop in expenses is green). */
function InvertedDelta({ change }: { change: number | null }) {
    if (change === null || change === undefined) return <span className="text-xs text-muted">—</span>;
    const up = change >= 0;
    return (
        <span className={`text-xs font-medium num ${up ? 'text-red-600' : 'text-emerald-600'}`}>
            {up ? '▲' : '▼'} {Math.abs(change)}%
        </span>
    );
}
