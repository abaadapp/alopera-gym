import { Download, FileSpreadsheet, Receipt, ShoppingBag, Undo2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodFilter from '@/Components/PeriodFilter';
import { BarTrend, Button, Card, CardHeader, EmptyState, HBars, PageHeader, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money, number } from '@/lib/format';

type Line = { label: string; count: number; total: string };

interface Props {
    period: { from: string; to: string };
    days: { day: string; count: number; total: string }[];
    products: { name: string; quantity: number; total: string }[];
    methods: Line[];
    staff: { name: string; count: number; total: string }[];
    sources: Line[];
    voided: { count: number; total: string };
}

export default function SalesReports({ period, days, products, methods, staff, sources, voided }: Props) {
    const currency = useCurrency();
    const m = (v: string | number) => money(v, currency);
    const total = days.reduce((t, d) => t + Math.round(Number(d.total) * 1000), 0) / 1000;
    const count = days.reduce((t, d) => t + d.count, 0);

    const table = (title: string, rows: { label: string; count: number; total: string }[], first: string) => (
        <Card padded={false}>
            <div className="p-5 pb-2"><CardHeader title={title} className="mb-0" /></div>
            {rows.length ? (
                <table className="table-base">
                    <thead><tr><th>{first}</th><th className="text-end">العمليات</th><th className="text-end">المبلغ</th></tr></thead>
                    <tbody>{rows.map((r) => <tr key={r.label}><td>{r.label}</td><td className="text-end num">{number(r.count)}</td><td className="num text-end font-medium">{m(r.total)}</td></tr>)}</tbody>
                </table>
            ) : (
                <p className="px-5 pb-5 text-sm text-muted">لا بيانات في هذه الفترة.</p>
            )}
        </Card>
    );

    return (
        <ClubLayout title="تقارير المبيعات">
            <PageHeader
                title="تقارير المبيعات"
                subtitle={`المبيعات المكتملة من ${date(period.from)} إلى ${date(period.to)}`}
                actions={
                    <>
                        <Button variant="outline" href={route('club.sales.reports.export', { ...period, format: 'xlsx' })} external icon={<FileSpreadsheet />}>Excel</Button>
                        <Button variant="outline" href={route('club.sales.reports.export', period)} external icon={<Download />}>CSV</Button>
                    </>
                }
            />

            <PeriodFilter routeName="club.sales.reports" from={period.from} to={period.to} />

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard label="إجمالي المبيعات" value={m(total)} icon={<ShoppingBag />} tone="brand" />
                <StatCard label="عدد العمليات" value={number(count)} icon={<Receipt />} tone="gray" />
                <StatCard label="الملغاة والمستردة" value={m(voided.total)} hint={`${number(voided.count)} عملية لا تدخل في الإجمالي`} icon={<Undo2 />} tone={voided.count ? 'amber' : 'gray'} />
            </div>

            <Card className="mb-6">
                <CardHeader title="المبيعات اليومية" />
                {days.length ? <BarTrend data={days.map((d) => ({ label: d.day.slice(5), value: Number(d.total) }))} valueLabel={(v) => m(v)} /> : <EmptyState title="لا مبيعات في هذه الفترة" />}
            </Card>

            <div className="mb-6 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="حسب المنتج" />
                    {products.length ? <HBars data={products.slice(0, 12).map((p) => ({ label: `${p.name} × ${number(p.quantity)}`, value: Number(p.total) }))} format={(v) => m(v)} /> : <p className="text-sm text-muted">لا بيانات في هذه الفترة.</p>}
                </Card>
                {table('حسب المصدر', sources, 'المصدر')}
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                {table('حسب طريقة الدفع', methods, 'الطريقة')}
                {table('حسب الموظف', staff.map((s) => ({ label: s.name, count: s.count, total: s.total })), 'الموظف')}
            </div>
        </ClubLayout>
    );
}
