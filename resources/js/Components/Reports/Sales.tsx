import { Badge, BarTrend, Card, CardHeader, Delta, EmptyState, HBars, StatGrid } from '@/Components/ui';
import { number } from '@/lib/format';
import { ChartCard, Kpi, TableCard, lowerIsBetter, useFormats, type Metric } from './parts';

type Line = { label: string; count: number; total: string };
export interface Sales {
    comparison: Record<string, Metric>;
    days: { day: string; count: number; total: string }[];
    products: { name: string; quantity: number; total: string }[];
    methods: Line[];
    sources: Line[];
    staff: { name: string; count: number; total: string }[];
}

/** تقرير المبيعات (selling and voiding stay in the sales section). */
export function SalesBody({ data }: { data: Sales }) {
    const f = useFormats();
    const c = data.comparison;
    const table = (title: string, rows: Line[], first: string) => (
        <TableCard title={title} empty="لا بيانات في هذه الفترة" head={[first, <span key="n" className="block text-end">العمليات</span>, <span key="t" className="block text-end">المبلغ</span>]}>
            {rows.map((r) => <tr key={r.label}><td>{r.label}</td><td className="text-end num">{number(r.count)}</td><td className="num text-end font-medium">{f.money(r.total)}</td></tr>)}
        </TableCard>
    );

    return (
        <>
            <StatGrid>
                <Kpi label="إجمالي المبيعات" metric={c.total} format={f.money} metricKey="total" />
                <Kpi label="عدد العمليات" metric={c.count} format={f.num} metricKey="count" />
                <Kpi label="متوسط العملية" metric={c.average} format={f.money} metricKey="average" />
                <Kpi label="الملغاة والمستردة" metric={c.voided_total} format={f.money} metricKey="voided_total" hint={`${number(c.voided_count?.current ?? 0)} عملية لا تدخل في الإجمالي`} />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard className="lg:col-span-2" title="المبيعات اليومية" empty="لا مبيعات في هذه الفترة" has={data.days.length > 0}>
                    <BarTrend data={data.days.map((d) => ({ label: d.day.slice(5), value: Number(d.total) }))} valueLabel={f.money} />
                </ChartCard>
                <Card>
                    <CardHeader title="حسب المنتج" subtitle="الأعلى مبيعًا" />
                    {data.products.length ? <HBars data={data.products.slice(0, 10).map((p) => ({ label: `${p.name} × ${number(p.quantity)}`, value: Number(p.total) }))} format={f.money} /> : <EmptyState title="لا بيانات" />}
                </Card>
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                {table('حسب طريقة الدفع', data.methods, 'الطريقة')}
                {table('حسب المصدر', data.sources, 'المصدر')}
                {table('حسب الموظف', data.staff.map((s) => ({ label: s.name, count: s.count, total: s.total })), 'الموظف')}
            </div>
            <TableCard className="mt-4" title="المنتجات" subtitle={data.products.length >= 50 ? 'أعلى 50 منتجًا؛ الملف المصدَّر يحتويها كلها' : undefined} empty="لا مبيعات في هذه الفترة" head={['المنتج', 'الكمية', <span key="t" className="block text-end">المبلغ</span>]}>
                {data.products.map((p) => <tr key={p.name}><td className="font-medium">{p.name}</td><td className="num">{number(p.quantity, 3)}</td><td className="text-end num font-medium">{f.money(p.total)}</td></tr>)}
            </TableCard>
        </>
    );
}

interface BranchRow { id: number; name: string; is_main: boolean; is_active: boolean; active_members: number; comparison: Record<string, Metric> }
export interface Branches { branches: BranchRow[] }

/** مقارنة الفروع: each branch side by side, each figure with its change against the earlier period. */
export function BranchesBody({ data, canFinancial }: { data: Branches; canFinancial: boolean }) {
    const f = useFormats();
    const rows = data.branches;
    const metrics: { key: string; label: string; money?: boolean }[] = [
        { key: 'new_members', label: 'أعضاء جدد' },
        { key: 'memberships_sold', label: 'عضويات مفعّلة' },
        { key: 'checkins', label: 'مرات الدخول' },
        { key: 'sessions', label: 'الحصص' },
        ...(canFinancial ? [{ key: 'revenue', label: 'صافي التحصيل', money: true }, { key: 'expenses', label: 'المصروفات', money: true }] : []),
    ];
    const chartKey = canFinancial ? 'revenue' : 'checkins';
    const fmt = (metric: { money?: boolean }, v: number | string) => (metric.money ? f.money(v) : number(v));

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
            <ChartCard title={canFinancial ? 'صافي التحصيل حسب الفرع' : 'مرات الدخول حسب الفرع'} has={rows.some((b) => Number(b.comparison[chartKey].current) > 0)}>
                <HBars data={rows.map((b) => ({ label: b.name, value: Number(b.comparison[chartKey].current) }))} format={canFinancial ? f.money : undefined} />
            </ChartCard>
        </div>
    );
}
