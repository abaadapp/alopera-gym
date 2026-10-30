import { Link } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CircleAlert, Hourglass, TrendingDown, TrendingUp } from 'lucide-react';
import { Badge, BarTrend, Card, CardHeader, Delta, HBars, Pagination, StatGrid, SummaryCard } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, number } from '@/lib/format';
import { invoiceStatus, invoiceType, label, paymentMethod } from '@/lib/labels';
import type { Paginated } from '@/types';
import { ChartCard, Figure, Kpi, TableCard, useFormats, type Metric } from './parts';

export interface PeriodReport {
    comparison: Record<string, Metric>;
    plans: { plan: string; sold: number; renewals: number; revenue: number | null }[];
    programs: { program: string; color: string | null; sessions: number; bookings: number; attended: number; no_show: number; attendance_rate: number; avg_per_session: number }[];
    revenue_by_method: { key: string; amount: number }[];
    revenue_by_type: { key: string; amount: number }[];
    expenses_by_category: { key: string; amount: number }[];
    products: { product: string; quantity: number; revenue: number; profit: number }[];
    trend: { date: string; amount: number }[];
}

/** الإيرادات والمصروفات. */
export function FinanceBody({ report }: { report: PeriodReport }) {
    const f = useFormats();
    const sales = useShared().modules.sales;
    const m = (k: string) => report.comparison[k];

    return (
        <>
            <StatGrid>
                <Kpi label="صافي التحصيل" metric={m('revenue')} format={f.money} metricKey="revenue" />
                <Kpi label="المصروفات" metric={m('expenses')} format={f.money} metricKey="expenses" />
                <Kpi label="صافي الربح التشغيلي" metric={m('net')} format={f.money} metricKey="net" />
                <Kpi label="نسبة التحصيل" metric={m('collection_rate')} format={f.pct} metricKey="collection_rate" hint={`من فواتير بقيمة ${f.money(m('invoiced')?.current ?? 0)}`} />
                <Kpi label="المردودات" metric={m('refunds')} format={f.money} metricKey="refunds" />
                <Kpi label="الإشعارات الدائنة" metric={m('credit_notes')} format={f.money} metricKey="credit_notes" />
                {sales && <Kpi label="مبيعات المنتجات" metric={m('product_sales')} format={f.money} metricKey="product_sales" />}
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <ChartCard className="lg:col-span-2" title="التحصيل اليومي" has={report.trend.some((d) => d.amount !== 0)}>
                    <BarTrend data={report.trend.map((d) => ({ label: d.date.slice(5), value: d.amount }))} valueLabel={f.money} />
                </ChartCard>
                <ChartCard title="حسب طريقة الدفع" empty="لا دفعات" has={report.revenue_by_method.length > 0}>
                    <HBars data={report.revenue_by_method.map((r) => ({ label: paymentMethod[r.key] ?? r.key, value: r.amount }))} format={f.money} />
                </ChartCard>
                <ChartCard title="حسب نوع الإيراد" empty="لا دفعات" has={report.revenue_by_type.length > 0}>
                    <HBars data={report.revenue_by_type.map((r) => ({ label: invoiceType[r.key] ?? r.key, value: r.amount }))} format={f.money} />
                </ChartCard>
                <ChartCard className={cn(!sales && 'lg:col-span-2')} title="المصروفات حسب التصنيف" empty="لا مصروفات" has={report.expenses_by_category.length > 0}>
                    <HBars data={report.expenses_by_category.map((r) => ({ label: r.key, value: r.amount, color: '#dc2626' }))} format={f.money} />
                </ChartCard>
                {sales && (
                    <TableCard title="المنتجات الأكثر مبيعًا" empty="لا مبيعات" head={['المنتج', 'الكمية', 'الإيراد', 'الربح']}>
                        {report.products.map((p) => (
                            <tr key={p.product}>
                                <td className="font-medium">{p.product}</td>
                                <td className="num">{number(p.quantity, 3)}</td>
                                <td className="num">{f.money(p.revenue)}</td>
                                <td className={cn('num', p.profit < 0 && 'text-red-600')}>{f.money(p.profit)}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}
            </div>
        </>
    );
}

type StatementRow = { code: string; name: string; amount: string; previous: string; contra: boolean };
export interface Statements {
    view: 'income' | 'trial';
    income: { revenue: StatementRow[]; expenses: StatementRow[]; total_revenue: string; total_expenses: string; net: string; previous: { total_revenue: string; total_expenses: string; net: string }; comparison: Record<string, Metric> };
    trial: { rows: { code: string; name: string; type: string; debit: string; credit: string; balance: string; side: string }[]; total_debit: string; total_credit: string };
}

/** قائمة الدخل وميزان المراجعة. */
export function StatementsBody({ data, to }: { data: Statements; to: string }) {
    const f = useFormats();
    const { income, trial } = data;
    const negative = Number(income.net) < 0;
    const balanced = trial.total_debit === trial.total_credit;

    if (data.view === 'trial') {
        return (
            <TableCard
                title="ميزان المراجعة"
                subtitle={`الأرصدة حتى ${date(to)}`}
                actions={<Badge tone={balanced ? 'green' : 'red'}>{balanced ? 'متوازن' : 'غير متوازن'}</Badge>}
                empty="لا قيود حتى هذا التاريخ"
                head={['الرمز', 'الحساب', <span key="d" className="block text-end">مدين</span>, <span key="c" className="block text-end">دائن</span>, <span key="b" className="block text-end">الرصيد</span>]}
            >
                {[
                    ...trial.rows.map((r) => (
                        <tr key={r.code}>
                            <td className="num text-muted">{r.code}</td>
                            <td>{r.name}</td>
                            <td className="text-end num">{f.money(r.debit)}</td>
                            <td className="text-end num">{f.money(r.credit)}</td>
                            <td className={cn('text-end font-medium num', Number(r.balance) < 0 && 'text-red-700')}>{f.money(r.balance)} <span className="text-[11px] text-muted">{r.side === 'debit' ? 'مدين' : 'دائن'}</span></td>
                        </tr>
                    )),
                    ...(trial.rows.length ? [<tr key="total" className="font-semibold"><td /><td>الإجمالي</td><td className="text-end num">{f.money(trial.total_debit)}</td><td className="text-end num">{f.money(trial.total_credit)}</td><td /></tr>] : []),
                ]}
            </TableCard>
        );
    }

    return (
        <>
            <StatGrid cols={3}>
                <Kpi label="الإيرادات" metric={income.comparison.total_revenue} format={f.money} metricKey="total_revenue" />
                <Kpi label="المصروفات" metric={income.comparison.total_expenses} format={f.money} metricKey="total_expenses" />
                <Kpi label="صافي النتيجة" metric={income.comparison.net} format={f.money} metricKey="net" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <StatementSection title="الإيرادات" rows={income.revenue} total={income.total_revenue} previous={income.previous.total_revenue} />
                <StatementSection title="المصروفات" rows={income.expenses} total={income.total_expenses} previous={income.previous.total_expenses} inverted />
                <SummaryCard
                    className="lg:col-span-2"
                    label="صافي النتيجة للفترة"
                    value={f.money(income.net)}
                    negative={negative}
                    icon={negative ? <TrendingDown /> : <TrendingUp />}
                    tone={negative ? 'red' : 'green'}
                    hint={<><Badge tone={negative ? 'red' : 'green'}>{negative ? 'خسارة' : 'ربح'}</Badge> <span className="ms-2">فترة المقارنة: {f.money(income.previous.net)}</span></>}
                />
            </div>
        </>
    );
}

function StatementSection({ title, rows, total, previous, inverted }: { title: string; rows: StatementRow[]; total: string; previous: string; inverted?: boolean }) {
    const f = useFormats();
    const change = (a: string, b: string) => (Number(b) === 0 ? null : Math.round(((Number(a) - Number(b)) / Math.abs(Number(b))) * 1000) / 10);
    return (
        <TableCard title={title} empty="لا حسابات" head={['الرمز', 'الحساب', <span key="a" className="block text-end">الفترة</span>, <span key="p" className="block text-end">المقارنة</span>, 'التغير']}>
            {[
                ...rows.map((r) => (
                    <tr key={r.code}>
                        <td className="num text-muted">{r.code}</td>
                        <td className="max-w-56">{r.name}{r.contra && <span className="ms-1 text-[11px] text-muted">(تُخصم)</span>}</td>
                        <td className="text-end num">{f.money(r.amount)}</td>
                        <td className="text-end num text-muted">{f.money(r.previous)}</td>
                        <td><Delta change={change(r.amount, r.previous)} inverted={inverted} /></td>
                    </tr>
                )),
                <tr key="total" className="font-semibold"><td /><td>الإجمالي</td><td className="text-end num">{f.money(total)}</td><td className="text-end num text-muted">{f.money(previous)}</td><td><Delta change={change(total, previous)} inverted={inverted} /></td></tr>,
            ]}
        </TableCard>
    );
}

type Line = { id: number; date: string; number: string; description: string; source: string; account: string; kind: string; in: string; out: string; memo: string | null; reversed: boolean };
export interface Movement {
    lines: Paginated<Line>;
    comparison: Record<string, Metric>;
    byAccount: { name: string; in: string; out: string; net: string }[];
    opening: string | null;
    closing: string | null;
    accounts: { id: number; name: string; kind: string }[];
    options: { account_id: number | null; direction: string | null };
}

/** الحركة المالية. */
export function MovementBody({ data }: { data: Movement }) {
    const f = useFormats();
    return (
        <>
            <StatGrid>
                <Kpi label="داخل" metric={data.comparison.in} format={f.money} metricKey="in" />
                <Kpi label="خارج" metric={data.comparison.out} format={f.money} metricKey="out" />
                <Kpi label="الصافي" metric={data.comparison.net} format={f.money} metricKey="net" />
                {data.opening !== null && data.closing !== null && <Figure label="رصيد الحساب آخر الفترة" value={f.money(data.closing)} metricKey="net" tone="gray" hint={`أول الفترة ${f.money(data.opening)}`} />}
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="الحركة" subtitle="الأحدث أولًا" empty="لا حركة في هذه الفترة" head={['التاريخ', 'القيد', 'البيان', 'الحساب', 'المصدر', <span key="i" className="block text-end">داخل</span>, <span key="o" className="block text-end">خارج</span>]}
                    footer={<Pagination data={data.lines} />}>
                    {data.lines.data.map((l) => (
                        <tr key={l.id}>
                            <td className="num whitespace-nowrap">{date(l.date)}</td>
                            <td className="num text-muted">{l.number}</td>
                            <td className={cn('max-w-80', l.reversed && 'text-muted line-through')}>{l.description}{l.memo && <span className="block text-xs text-muted">{l.memo}</span>}</td>
                            <td className="text-muted">{l.account}</td>
                            <td><Badge>{l.source}</Badge></td>
                            <td className="text-end font-medium text-emerald-700 num">{Number(l.in) ? f.money(l.in) : '—'}</td>
                            <td className="text-end font-medium text-red-700 num">{Number(l.out) ? f.money(l.out) : '—'}</td>
                        </tr>
                    ))}
                </TableCard>
                <TableCard title="حسب الحساب" empty="لا حركة" head={['الحساب', <span key="n" className="block text-end">الصافي</span>]}>
                    {data.byAccount.map((a) => (
                        <tr key={a.name}>
                            <td className="font-medium">{a.name}<span className="block text-xs text-muted num">داخل {f.money(a.in)} · خارج {f.money(a.out)}</span></td>
                            <td className={cn('text-end font-semibold num', Number(a.net) < 0 && 'text-red-700')}>{f.money(a.net)}</td>
                        </tr>
                    ))}
                </TableCard>
            </div>
        </>
    );
}

type DueRow = { id: number; number: string; type: string; status: string; customer_name: string; customer_phone: string | null; total: string; paid_total: string; balance: string; due_date: string | null; billable_type: string | null; billable_id: number | null; days_overdue: number };
export interface Dues {
    aging: Record<'current' | '1_30' | '31_60' | '60_plus', { count: number; amount: number }>;
    total: number;
    count: number;
    byType: { label: string; count: number; amount: number }[];
    debtors: { name: string; count: number; balance: number; member_id: number | null }[];
    invoices: DueRow[];
    options: { overdue: boolean };
}

/** المستحقات والمتأخرات (the state today; collecting stays in the finance section). */
export function DuesBody({ data }: { data: Dues }) {
    const f = useFormats();
    const a = data.aging;
    const bucket = (k: keyof Dues['aging'], text: string, icon: typeof Hourglass, tone: 'blue' | 'amber' | 'red') => (
        <Figure label={text} value={f.money(a[k].amount)} icon={icon} tone={tone} hint={`${number(a[k].count)} فاتورة`} />
    );

    return (
        <>
            <StatGrid>
                {bucket('current', 'غير مستحقة بعد', CalendarClock, 'blue')}
                {bucket('1_30', 'متأخرة 1–30 يومًا', Hourglass, 'amber')}
                {bucket('31_60', 'متأخرة 31–60 يومًا', AlertTriangle, 'red')}
                {bucket('60_plus', 'أكثر من 60 يومًا', CircleAlert, 'red')}
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="أعمار المستحقات" subtitle={`الإجمالي ${f.money(data.total)} في ${number(data.count)} فاتورة`} />
                    {data.count > 0 ? <HBars data={[['current', 'غير مستحقة بعد'], ['1_30', '1–30 يومًا'], ['31_60', '31–60 يومًا'], ['60_plus', 'أكثر من 60']].map(([k, t]) => ({ label: t, value: a[k as keyof Dues['aging']].amount, color: k === 'current' ? '#2563eb' : k === '1_30' ? '#d97706' : '#dc2626' }))} format={f.money} /> : <p className="text-sm text-muted">لا مستحقات.</p>}
                </Card>
                <TableCard title="أعلى المدينين" empty="لا مدينين" head={['العميل', 'الفواتير', <span key="b" className="block text-end">المتبقي</span>]}>
                    {data.debtors.map((d) => (
                        <tr key={`${d.member_id ?? d.name}`}>
                            <td className="font-medium">{d.member_id ? <Link href={route('club.members.show', d.member_id)} className="hover:text-brand">{d.name}</Link> : d.name}</td>
                            <td className="num">{d.count}</td>
                            <td className="text-end font-semibold text-red-600 num">{f.money(d.balance)}</td>
                        </tr>
                    ))}
                </TableCard>
                <TableCard title="حسب نوع الفاتورة" empty="لا مستحقات" head={['النوع', 'الفواتير', <span key="a" className="block text-end">المتبقي</span>]}>
                    {data.byType.map((t) => (
                        <tr key={t.label}><td>{t.label}</td><td className="num">{t.count}</td><td className="text-end num font-medium">{f.money(t.amount)}</td></tr>
                    ))}
                </TableCard>
            </div>
            <TableCard className="mt-4" title="الفواتير المفتوحة" subtitle={data.invoices.length < data.count ? `أقدم ${number(data.invoices.length)} استحقاقًا من ${number(data.count)}؛ الملف المصدَّر يحتويها كلها` : undefined}
                empty="لا توجد مستحقات" head={['الفاتورة', 'العميل', 'النوع', 'الاستحقاق', 'التأخير', 'الإجمالي', 'المتبقي', 'الحالة']}>
                {data.invoices.map((inv) => {
                    const [statusLabel, tone] = label(invoiceStatus, inv.status);
                    return (
                        <tr key={inv.id}>
                            <td><Link href={route('club.invoices.show', inv.id)} className="font-medium text-brand num hover:underline">{inv.number}</Link></td>
                            <td><span className="font-medium">{inv.customer_name}</span>{inv.customer_phone && <span className="block text-xs text-muted num">{inv.customer_phone}</span>}</td>
                            <td className="text-muted">{invoiceType[inv.type] ?? inv.type}</td>
                            <td className="num">{date(inv.due_date)}</td>
                            <td>{inv.days_overdue > 0 ? <Badge tone={inv.days_overdue > 30 ? 'red' : 'amber'}><span className="num">{inv.days_overdue}</span> يوم</Badge> : <span className="text-xs text-muted">—</span>}</td>
                            <td className="num">{f.money(inv.total)}</td>
                            <td className="font-semibold text-red-600 num">{f.money(inv.balance)}</td>
                            <td><Badge tone={tone}>{statusLabel}</Badge></td>
                        </tr>
                    );
                })}
            </TableCard>
        </>
    );
}
