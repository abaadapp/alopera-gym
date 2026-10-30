import { Link, usePage } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { Badge, BarTrend, HBars, StatGrid } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { date, number } from '@/lib/format';
import { ChartCard, Figure, Kpi, TableCard, useFormats, type Metric } from './parts';

const STATUS: Record<string, ['مسودة' | 'معتمدة' | 'مصروفة', 'gray' | 'blue' | 'green']> = { draft: ['مسودة', 'gray'], approved: ['معتمدة', 'blue'], paid: ['مصروفة', 'green'] };

type Run = { id: number; number: string; label: string; period: string; status: string; employees: number; paid_count: number; gross: string; deductions: string; net: string; paid: string; unpaid: string };
type Item = { id: number; name: string; job_title: string | null; attendance_days: number | null; basic: string; allowances: string; overtime: string; deductions: string; net: string; method: string; paid_at: string | null };
export interface Payroll { comparison: Record<string, Metric>; runs: Run[]; run: Run | null; items: Item[]; options: { run: number | null } }

/** The address of this report with one filter changed (the run picked), the rest kept. */
function useWith(): (patch: Record<string, string>) => string {
    const { url } = usePage();
    return (patch) => {
        const query = new URLSearchParams(url.split('?')[1] ?? '');
        Object.entries(patch).forEach(([k, v]) => query.set(k, v));
        return `${url.split('?')[0]}?${query.toString()}`;
    };
}

/** مسيرات الرواتب (preparing, approving and paying stay in the payroll section). */
export function PayrollBody({ data }: { data: Payroll }) {
    const f = useFormats();
    const withRun = useWith();
    const c = data.comparison;
    const months = [...data.runs].reverse();

    return (
        <>
            <StatGrid cols={3}>
                <Kpi label="صافي الرواتب" metric={c.net} format={f.money} metricKey="net" hint={`${number(c.runs?.current ?? 0)} مسيرة`} />
                <Kpi label="المصروف" metric={c.paid} format={f.money} metricKey="paid" />
                <Kpi label="لم يُصرف بعد" metric={c.unpaid} format={f.money} metricKey="unpaid" />
                <Kpi label="إجمالي الاستحقاقات" metric={c.gross} format={f.money} metricKey="gross" />
                <Kpi label="الخصومات" metric={c.deductions} format={f.money} metricKey="deductions" />
                <Kpi label="المسيرات" metric={c.runs} format={f.num} metricKey="runs" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="المسيرات" subtitle="اختر مسيرة لعرض كشف رواتبها" empty="لا مسيرات في هذه الفترة" head={['المسيرة', 'الحالة', 'الموظفون', 'الصافي', 'المصروف', 'المتبقي']}>
                    {data.runs.map((r) => {
                        const [text, tone] = STATUS[r.status] ?? [r.status, 'gray'];
                        const on = data.run?.id === r.id;
                        return (
                            <tr key={r.id} className={cn(on && 'bg-[#fafafa]')}>
                                <td><Link href={withRun({ run: String(r.id) })} preserveScroll preserveState className={cn('font-medium hover:text-brand', on && 'text-brand')}>{r.label}</Link><span className="block text-xs text-muted num">{r.number}</span></td>
                                <td><Badge tone={tone}>{text}</Badge></td>
                                <td className="num">{r.employees}</td>
                                <td className="num font-medium">{f.money(r.net)}</td>
                                <td className="num text-emerald-700">{f.money(r.paid)}</td>
                                <td className={cn('num', Number(r.unpaid) > 0 ? 'text-amber-700' : 'text-muted')}>{f.money(r.unpaid)}</td>
                            </tr>
                        );
                    })}
                </TableCard>
                <ChartCard title="صافي الرواتب حسب الشهر" has={months.length > 0}>
                    <BarTrend data={months.map((r) => ({ label: r.period.slice(5), value: Number(r.net) }))} valueLabel={f.money} />
                </ChartCard>
            </div>
            {data.run && (
                <TableCard className="mt-4" title={`كشف رواتب ${data.run.label}`} subtitle={`${data.run.paid_count} من ${data.run.employees} صُرف لهم`} empty="لا موظفين في هذه المسيرة"
                    head={['الموظف', 'أيام الحضور', 'الأساسي', 'البدلات', 'الإضافي', 'الخصومات', 'الصافي', 'الصرف']}>
                    {data.items.map((i) => (
                        <tr key={i.id}>
                            <td><span className="font-medium">{i.name}</span>{i.job_title && <span className="block text-xs text-muted">{i.job_title}</span>}</td>
                            <td className="num">{i.attendance_days ?? '—'}</td>
                            <td className="num">{f.money(i.basic)}</td>
                            <td className="num">{f.money(i.allowances)}</td>
                            <td className="num">{f.money(i.overtime)}</td>
                            <td className="num text-red-600">{f.money(i.deductions)}</td>
                            <td className="num font-semibold">{f.money(i.net)}</td>
                            <td>{i.paid_at ? <Badge tone="green">صُرف {date(i.paid_at)}</Badge> : <Badge tone="amber">{i.method} · لم يُصرف</Badge>}</td>
                        </tr>
                    ))}
                </TableCard>
            )}
        </>
    );
}

type Payout = { id: number; number: string; paid_on: string; period: string | null; account: string | null; reference: string | null; by: string | null; employees: number; total: string };
export interface Payouts { comparison: Record<string, Metric>; payouts: Payout[]; byAccount: { account: string; count: number; total: string }[]; waiting: string }

/** صرف الرواتب (paying stays in the payroll section). */
export function PayoutsBody({ data }: { data: Payouts }) {
    const f = useFormats();
    const c = data.comparison;
    return (
        <>
            <StatGrid>
                <Kpi label="المصروف في الفترة" metric={c.paid} format={f.money} metricKey="paid" />
                <Kpi label="دفعات الصرف" metric={c.payouts} format={f.num} metricKey="payouts" />
                <Kpi label="موظفون صُرف لهم" metric={c.employees} format={f.num} metricKey="employees" />
                <Figure label="بانتظار الصرف الآن" value={f.money(data.waiting)} icon={Clock} tone={Number(data.waiting) ? 'amber' : 'gray'} hint="في المسيرات المعتمدة" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="دفعات الصرف" empty="لا صرف في هذه الفترة" head={['الرقم', 'التاريخ', 'المسيرة', 'من حساب', 'الموظفون', 'المرجع', <span key="t" className="block text-end">المبلغ</span>]}>
                    {data.payouts.map((p) => (
                        <tr key={p.id}>
                            <td className="num">{p.number}</td>
                            <td className="num">{date(p.paid_on)}</td>
                            <td>{p.period}</td>
                            <td>{p.account}</td>
                            <td className="num">{p.employees}</td>
                            <td className="text-muted">{p.reference ?? '—'}</td>
                            <td className="text-end font-medium num">{f.money(p.total)}</td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="حسب الحساب" has={data.byAccount.length > 0}>
                    <HBars data={data.byAccount.map((a) => ({ label: a.account, value: Number(a.total) }))} format={f.money} />
                </ChartCard>
            </div>
        </>
    );
}
