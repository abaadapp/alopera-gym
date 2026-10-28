import { Link } from '@inertiajs/react';
import { ArrowLeftRight, Banknote, CreditCard, Landmark, Scale, TrendingDown, TrendingUp, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodFilter from '@/Components/PeriodFilter';
import { Badge, Card, CardHeader, EmptyState, PageHeader, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money } from '@/lib/format';

type MoneyAccount = { id: number; code: string; name: string; kind: string; balance: string; bank_name: string | null; masked: string | null };
type Entry = { id: number; number: string; date: string; description: string; total: string; source: string; reversed: boolean; is_reversal: boolean };

interface Props {
    summary: Record<'cash' | 'banks' | 'clearing' | 'receivable' | 'salaries_payable' | 'vat_payable' | 'revenue' | 'expenses' | 'salaries' | 'net', string>;
    accounts: MoneyAccount[];
    overdue: { count: number; amount: string };
    unpaidSalaries: string;
    recent: Entry[];
    filters: { from: string; to: string };
}

export const kindIcon = { cash: Banknote, bank: Landmark, card: CreditCard, gateway: ArrowLeftRight } as Record<string, typeof Wallet>;

export default function FinanceSummary({ summary, accounts, overdue, unpaidSalaries, recent, filters }: Props) {
    const currency = useCurrency();
    const m = (v: string) => money(v, currency);
    const negative = Number(summary.net) < 0;

    return (
        <ClubLayout title="الملخص المالي">
            <PageHeader title="الملخص المالي" subtitle={`أين أموال النادي الآن، وما له وما عليه، ونتيجة الفترة من ${date(filters.from)} إلى ${date(filters.to)}`} />

            <div className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <StatCard label="الصندوق" value={m(summary.cash)} icon={<Banknote className="size-5" />} tone="green" href={route('club.finance.banks')} />
                <StatCard label="الحسابات البنكية" value={m(summary.banks)} icon={<Landmark className="size-5" />} tone="blue" href={route('club.finance.banks')} />
                <StatCard label="بطاقات وPaymob قيد التسوية" value={m(summary.clearing)} hint="تصل إلى البنك عند التسوية" icon={<CreditCard className="size-5" />} tone="violet" />
                <StatCard label="ذمم المشتركات" value={m(summary.receivable)} hint={overdue.count ? `${overdue.count} فاتورة متأخرة بـ ${m(overdue.amount)}` : 'لا فواتير متأخرة'} icon={<Users className="size-5" />} tone={overdue.count ? 'amber' : 'gray'} href={route('club.dues')} />
            </div>

            <PeriodFilter routeName="club.finance.summary" from={filters.from} to={filters.to} />

            <div className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <StatCard label="الإيرادات" value={m(summary.revenue)} icon={<TrendingUp className="size-5" />} tone="green" />
                <StatCard label="المصروفات" value={m(summary.expenses)} hint={`منها الرواتب ${m(summary.salaries)}`} icon={<TrendingDown className="size-5" />} tone="red" />
                <StatCard label="صافي النتيجة" value={<span className={cn(negative && 'text-red-700')}>{m(summary.net)}</span>} icon={<Scale className="size-5" />} tone={negative ? 'red' : 'brand'} href={route('club.finance.statements', filters)} />
                <StatCard label="رواتب معتمدة لم تُصرف" value={m(unpaidSalaries)} hint={Number(summary.vat_payable) ? `ضريبة مستحقة ${m(summary.vat_payable)}` : undefined} icon={<Wallet className="size-5" />} tone={Number(unpaidSalaries) ? 'amber' : 'gray'} href={route('club.payroll.payouts')} />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="الحسابات" subtitle="الأرصدة الحالية" actions={<Link href={route('club.finance.banks')} className="text-[13px] text-muted hover:text-ink">إدارة الحسابات</Link>} />
                    <ul className="divide-y divide-line">
                        {accounts.map((a) => {
                            const Icon = kindIcon[a.kind] ?? Wallet;
                            return (
                                <li key={a.id}>
                                    <Link href={route('club.finance.movement', { account_id: a.id })} className="flex items-center gap-3 py-3 hover:text-brand">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#f2f2f0] text-[#4b4b4b]"><Icon className="size-4" /></span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">{a.name}</span>
                                            {a.masked && <span className="block text-xs text-muted" dir="ltr">{a.masked}</span>}
                                        </span>
                                        <span className={cn('text-sm font-semibold num', Number(a.balance) < 0 && 'text-red-700')}>{m(a.balance)}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </Card>

                <Card padded={false} className="lg:col-span-2">
                    <div className="p-5 pb-2"><CardHeader title="آخر القيود" className="mb-0" actions={<Link href={route('club.finance.journal')} className="text-[13px] text-muted hover:text-ink">كل القيود</Link>} /></div>
                    {recent.length ? (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>التاريخ</th><th>القيد</th><th>البيان</th><th>المصدر</th><th className="text-end">المبلغ</th></tr></thead>
                                <tbody>
                                    {recent.map((e) => (
                                        <tr key={e.id}>
                                            <td className="num">{date(e.date)}</td>
                                            <td className="num text-muted">{e.number}</td>
                                            <td className={cn('max-w-80 truncate', e.reversed && 'text-muted line-through')}>{e.description}</td>
                                            <td><Badge tone={e.is_reversal ? 'amber' : 'gray'}>{e.source}</Badge></td>
                                            <td className="text-end font-medium num">{m(e.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <EmptyState title="لا قيود بعد" description="تُسجَّل القيود تلقائيًا مع الفواتير والتحصيل والمصروفات والرواتب." />
                    )}
                </Card>
            </div>
        </ClubLayout>
    );
}
