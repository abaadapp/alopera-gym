import { Link } from '@inertiajs/react';
import { Globe, Package, ReceiptText, ShoppingBag, TrendingUp, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodFilter from '@/Components/PeriodFilter';
import { Badge, Card, CardHeader, EmptyState, HBars, PageHeader, StatCard } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money, number } from '@/lib/format';
import { SourceBadge, saleStatusTone, type SaleRow } from '@/Pages/Inventory/Sales';

interface Props {
    period: { from: string; to: string };
    stats: { total: string; count: number; average: string; today: string; open_orders: number; low_stock: number; on_website: number };
    bySource: { source: string; label: string; count: number; total: string }[];
    topProducts: { name: string; quantity: number; total: string }[];
    recent: SaleRow[];
}

export default function SalesSummary({ period, stats, bySource, topProducts, recent }: Props) {
    const currency = useCurrency();
    const can = useCan();
    const m = (v: string) => money(v, currency);

    return (
        <ClubLayout title="ملخص المبيعات">
            <PageHeader title="ملخص المبيعات" subtitle={`المبيعات المكتملة من نقطة البيع والموقع من ${date(period.from)} إلى ${date(period.to)}`} />

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="مبيعات اليوم" value={m(stats.today)} icon={<Wallet />} tone="green" />
                <StatCard label="طلبات الموقع المفتوحة" value={number(stats.open_orders)} hint="بانتظار التجهيز أو التسليم" icon={<Globe />} tone={stats.open_orders ? 'amber' : 'gray'} href={can('orders.manage') ? route('club.sales.orders.index') : undefined} />
                <StatCard label="منتجات تحتاج إعادة طلب" value={number(stats.low_stock)} icon={<Package />} tone={stats.low_stock ? 'red' : 'gray'} href={can('products.manage') ? route('club.products.index', { low: 1 }) : undefined} />
                <StatCard label="منتجات ظاهرة في الموقع" value={number(stats.on_website)} icon={<ShoppingBag />} tone="blue" href={can('products.manage') ? route('club.products.index', { website: 1 }) : undefined} />
            </div>

            <PeriodFilter routeName="club.sales.summary" from={period.from} to={period.to} />

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard label="إجمالي المبيعات" value={m(stats.total)} icon={<TrendingUp />} tone="brand" href={can('reports.view') ? route('club.reports.show', { report: 'sales', ...period }) : undefined} />
                <StatCard label="عدد العمليات" value={number(stats.count)} icon={<ReceiptText />} tone="gray" />
                <StatCard label="متوسط العملية" value={m(stats.average)} icon={<Wallet />} tone="gray" />
            </div>

            <div className="mb-6 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="حسب المصدر" subtitle="نقطة البيع والموقع الإلكتروني" />
                    {bySource.length ? (
                        <HBars data={bySource.map((s) => ({ label: `${s.label} (${number(s.count)})`, value: Number(s.total) }))} format={(v) => money(v, currency)} />
                    ) : (
                        <p className="py-6 text-center text-sm text-muted">لا مبيعات في هذه الفترة.</p>
                    )}
                </Card>
                <Card>
                    <CardHeader title="الأكثر مبيعًا" actions={can('reports.view') && <Link href={route('club.reports.show', { report: 'sales', ...period })} className="text-[13px] text-muted hover:text-ink">تقرير المبيعات</Link>} />
                    {topProducts.length ? (
                        <HBars data={topProducts.map((p) => ({ label: `${p.name} × ${number(p.quantity)}`, value: Number(p.total) }))} format={(v) => money(v, currency)} />
                    ) : (
                        <p className="py-6 text-center text-sm text-muted">لا مبيعات في هذه الفترة.</p>
                    )}
                </Card>
            </div>

            <Card padded={false}>
                <div className="p-5 pb-2"><CardHeader title="آخر العمليات" className="mb-0" actions={<Link href={route('club.sales.index')} className="text-[13px] text-muted hover:text-ink">كل العمليات</Link>} /></div>
                {recent.length ? (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الرقم</th><th>الوقت</th><th>المصدر</th><th>العميل</th><th>الإجمالي</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {recent.map((s) => (
                                    <tr key={s.id}>
                                        <td className="font-medium num">{s.number}</td>
                                        <td className="text-xs text-muted num">{s.at}</td>
                                        <td><SourceBadge source={s.source} label={s.source_label} /></td>
                                        <td className="text-sm">{s.buyer}</td>
                                        <td className="num font-medium">{m(s.total)}</td>
                                        <td><Badge tone={saleStatusTone[s.status]}>{s.status_label}</Badge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState icon={<ReceiptText />} title="لا عمليات بيع بعد" description="ابدأ من نقطة البيع، أو اعرض منتجاتك في متجر الموقع." />
                )}
            </Card>
        </ClubLayout>
    );
}
