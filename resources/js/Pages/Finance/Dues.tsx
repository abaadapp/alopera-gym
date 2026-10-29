import { Link, router } from '@inertiajs/react';
import { HandCoins } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, Checkbox, EmptyState, PageHeader, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { invoiceStatus, invoiceType, label } from '@/lib/labels';

interface DueRow {
    id: number;
    number: string;
    type: string;
    status: string;
    customer_name: string;
    customer_phone: string | null;
    total: string;
    paid_total: string;
    balance: string;
    issue_date: string;
    due_date: string | null;
    billable_type: string | null;
    billable_id: number | null;
    days_overdue: number;
}

interface Props {
    invoices: DueRow[];
    aging: { current: number; '1_30': number; '31_60': number; '60_plus': number };
    total: number;
    filters: { overdue: boolean };
}

/** Collection follow-up (spec 1.5): outstanding balances and arrears by age. */
export default function Dues({ invoices, aging, total, filters }: Props) {
    const currency = useCurrency();

    return (
        <ClubLayout title="المستحقات">
            <PageHeader title="المستحقات والمتأخرات" subtitle={`إجمالي غير المحصّل: ${money(total, currency)}`} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="غير مستحقة بعد" value={money(aging.current, currency)} tone="blue" />
                <StatCard label="متأخرة 1–30 يومًا" value={money(aging['1_30'], currency)} tone="amber" />
                <StatCard label="متأخرة 31–60 يومًا" value={money(aging['31_60'], currency)} tone="red" />
                <StatCard label="أكثر من 60 يومًا" value={money(aging['60_plus'], currency)} tone="red" />
            </div>

            <Card className="mt-6" padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <p className="text-sm text-muted"><span className="num">{invoices.length}</span> فاتورة مفتوحة</p>
                    <Checkbox
                        label="المتأخرة فقط"
                        checked={filters.overdue}
                        onChange={(e) => router.get(route('club.dues'), e.target.checked ? { overdue: 1 } : {}, { preserveState: true, replace: true })}
                    />
                </div>
                {invoices.length === 0 ? (
                    <EmptyState icon={<HandCoins className="size-6" />} title="لا توجد مستحقات" description="كل الفواتير مسددة." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الفاتورة</th><th>العميل</th><th>النوع</th><th>الاستحقاق</th><th>التأخير</th><th>الإجمالي</th><th>المتبقي</th><th>الحالة</th></tr>
                            </thead>
                            <tbody>
                                {invoices.map((inv) => {
                                    const [statusLabel, tone] = label(invoiceStatus, inv.status);
                                    return (
                                        <tr key={inv.id}>
                                            <td><Link href={route('club.invoices.show', inv.id)} className="font-medium text-brand num hover:underline">{inv.number}</Link></td>
                                            <td>
                                                {inv.billable_type === 'member' && inv.billable_id ? (
                                                    <Link href={route('club.members.show', inv.billable_id)} className="font-medium hover:text-brand">{inv.customer_name}</Link>
                                                ) : (
                                                    <span className="font-medium">{inv.customer_name}</span>
                                                )}
                                                {inv.customer_phone && <p className="text-xs text-muted num">{inv.customer_phone}</p>}
                                            </td>
                                            <td className="text-muted">{invoiceType[inv.type] ?? inv.type}</td>
                                            <td>{date(inv.due_date)}</td>
                                            <td>
                                                {inv.days_overdue > 0 ? (
                                                    <Badge tone={inv.days_overdue > 30 ? 'red' : 'amber'}><span className="num">{inv.days_overdue}</span> يوم</Badge>
                                                ) : (
                                                    <span className="text-xs text-muted">—</span>
                                                )}
                                            </td>
                                            <td className="num">{money(inv.total, currency)}</td>
                                            <td className="font-semibold text-red-600 num">{money(inv.balance, currency)}</td>
                                            <td><Badge tone={tone}>{statusLabel}</Badge></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
