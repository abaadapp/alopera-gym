import { Link } from '@inertiajs/react';
import { Download, Printer, TrendingDown, TrendingUp } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodFilter from '@/Components/PeriodFilter';
import { Badge, Button, Card, CardHeader, PageHeader, SummaryCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money } from '@/lib/format';

type Row = { code: string; name: string; amount: string; contra: boolean };
interface Props {
    income: { revenue: Row[]; expenses: Row[]; total_revenue: string; total_expenses: string; net: string };
    trial: { rows: { code: string; name: string; type: string; debit: string; credit: string; balance: string; side: string }[]; total_debit: string; total_credit: string };
    filters: { from: string; to: string; view: 'income' | 'trial' };
}

export default function FinanceStatements({ income, trial, filters }: Props) {
    const currency = useCurrency();
    const m = (v: string | number) => money(v, currency);
    const view = filters.view;
    const balanced = trial.total_debit === trial.total_credit;
    const exportLink = (format: string) => route('club.finance.statements.export', { from: filters.from, to: filters.to, view, format });

    return (
        <ClubLayout title="التقارير المالية">
            <PageHeader
                title="التقارير المالية"
                subtitle={`من ${date(filters.from)} إلى ${date(filters.to)}`}
                actions={
                    <>
                        <Button variant="outline" icon={<Printer className="size-4" />} onClick={() => window.print()}>طباعة</Button>
                        <Button variant="outline" href={exportLink('xlsx')} external icon={<Download className="size-4" />}>Excel</Button>
                        <Button variant="outline" href={exportLink('csv')} external icon={<Download className="size-4" />}>CSV</Button>
                    </>
                }
            />

            <PeriodFilter routeName="club.finance.statements" from={filters.from} to={filters.to} extra={{ view }} className="no-print">
                <span className="mx-1 h-5 w-px bg-line" />
                {(['income', 'trial'] as const).map((v) => (
                    <Link key={v} href={route('club.finance.statements', { from: filters.from, to: filters.to, view: v })} preserveState className={cn('grid h-8 place-items-center rounded-lg px-3 text-[13px]', view === v ? 'bg-[#f2f2f0] font-semibold text-ink' : 'text-muted hover:bg-ink/[0.045]')}>
                        {v === 'income' ? 'قائمة الدخل' : 'ميزان المراجعة'}
                    </Link>
                ))}
            </PeriodFilter>

            {view === 'income' ? (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Section title="الإيرادات" rows={income.revenue} total={income.total_revenue} m={m} />
                    <Section title="المصروفات" rows={income.expenses} total={income.total_expenses} m={m} />
                    <SummaryCard
                        className="lg:col-span-2"
                        label="صافي النتيجة للفترة"
                        value={m(income.net)}
                        negative={Number(income.net) < 0}
                        icon={Number(income.net) < 0 ? <TrendingDown /> : <TrendingUp />}
                        tone={Number(income.net) < 0 ? 'red' : 'green'}
                        hint={<Badge tone={Number(income.net) < 0 ? 'red' : 'green'}>{Number(income.net) < 0 ? 'خسارة' : 'ربح'}</Badge>}
                    />
                </div>
            ) : (
                <Card padded={false}>
                    <div className="flex items-center justify-between p-5 pb-2">
                        <CardHeader title="ميزان المراجعة" subtitle={`الأرصدة حتى ${date(filters.to)}`} className="mb-0" />
                        <Badge tone={balanced ? 'green' : 'red'}>{balanced ? 'متوازن' : 'غير متوازن'}</Badge>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الرمز</th><th>الحساب</th><th className="text-end">مدين</th><th className="text-end">دائن</th><th className="text-end">الرصيد</th></tr></thead>
                            <tbody>
                                {trial.rows.map((r) => (
                                    <tr key={r.code}>
                                        <td className="num text-muted">{r.code}</td>
                                        <td>{r.name}</td>
                                        <td className="text-end num">{m(r.debit)}</td>
                                        <td className="text-end num">{m(r.credit)}</td>
                                        <td className={cn('text-end font-medium num', Number(r.balance) < 0 && 'text-red-700')}>{m(r.balance)} <span className="text-[11px] text-muted">{r.side === 'debit' ? 'مدين' : 'دائن'}</span></td>
                                    </tr>
                                ))}
                                <tr className="font-semibold"><td /><td>الإجمالي</td><td className="text-end num">{m(trial.total_debit)}</td><td className="text-end num">{m(trial.total_credit)}</td><td /></tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </ClubLayout>
    );
}

function Section({ title, rows, total, m }: { title: string; rows: Row[]; total: string; m: (v: string) => string }) {
    return (
        <Card padded={false}>
            <div className="p-5 pb-2"><CardHeader title={title} className="mb-0" /></div>
            <div className="overflow-x-auto">
            <table className="table-base">
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.code}>
                            <td className="num text-muted">{r.code}</td>
                            <td className="max-w-56">{r.name}{r.contra && <span className="ms-1 text-[11px] text-muted">(تُخصم)</span>}</td>
                            <td className="text-end num">{m(r.amount)}</td>
                        </tr>
                    ))}
                    <tr className="font-semibold"><td /><td>الإجمالي</td><td className="text-end num">{m(total)}</td></tr>
                </tbody>
            </table>
            </div>
        </Card>
    );
}
