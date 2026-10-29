import { router } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Scale } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PeriodFilter from '@/Components/PeriodFilter';
import { Badge, Card, EmptyState, PageHeader, Pagination, Select, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money } from '@/lib/format';
import type { Paginated } from '@/types';

type Line = { id: number; date: string; number: string; description: string; source: string; account: string; kind: string; in: string; out: string; memo: string | null; reversed: boolean };

interface Props {
    lines: Paginated<Line>;
    stats: { in: string; out: string; net: string };
    opening: string | null;
    closing: string | null;
    accounts: { id: number; name: string; kind: string }[];
    filters: { from: string; to: string; account_id: number | null; direction: string | null };
}

const directions = [
    { key: '', label: 'الكل' },
    { key: 'in', label: 'داخل' },
    { key: 'out', label: 'خارج' },
];

export default function FinanceMovement({ lines, stats, opening, closing, accounts, filters }: Props) {
    const currency = useCurrency();
    const m = (v: string) => money(v, currency);
    const go = (patch: Record<string, string | number | null>) => {
        const next = { ...filters, ...patch } as Record<string, string | number | null>;
        router.get(route('club.finance.movement'), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '')) as Record<string, string>, { preserveState: true, replace: true });
    };

    return (
        <ClubLayout title="الحركة المالية">
            <PageHeader title="الحركة المالية" subtitle="كل مبلغ دخل إلى الصندوق والبنوك وحسابات التسوية أو خرج منها" />

            <PeriodFilter routeName="club.finance.movement" from={filters.from} to={filters.to} extra={{ account_id: filters.account_id, direction: filters.direction }}>
                <Select className="h-8 w-52 py-1 text-[13px]" value={filters.account_id ?? ''} onChange={(e) => go({ account_id: e.target.value || null, page: null })} aria-label="الحساب">
                    <option value="">كل الحسابات</option>
                    {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </Select>
                <span className="mx-1 h-5 w-px bg-line" />
                {directions.map((d) => (
                    <button key={d.key} type="button" onClick={() => go({ direction: d.key || null, page: null })} className={cn('h-8 rounded-lg px-3 text-[13px]', (filters.direction ?? '') === d.key ? 'bg-[#f2f2f0] font-semibold text-ink' : 'text-muted hover:bg-ink/[0.045]')}>
                        {d.label}
                    </button>
                ))}
            </PeriodFilter>

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="داخل" value={m(stats.in)} icon={<ArrowDownLeft className="size-5" />} tone="green" />
                <StatCard label="خارج" value={m(stats.out)} icon={<ArrowUpRight className="size-5" />} tone="red" />
                <StatCard label="الصافي" value={m(stats.net)} icon={<Scale className="size-5" />} tone="brand" />
                {opening !== null && closing !== null && <StatCard label="الرصيد" value={m(closing)} hint={`أول الفترة ${m(opening)}`} icon={<Scale className="size-5" />} tone="gray" />}
            </div>

            <Card padded={false}>
                {lines.data.length ? (
                    <>
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>التاريخ</th><th>القيد</th><th>البيان</th><th>الحساب</th><th>المصدر</th><th className="text-end">داخل</th><th className="text-end">خارج</th></tr></thead>
                                <tbody>
                                    {lines.data.map((l) => (
                                        <tr key={l.id}>
                                            <td className="num">{date(l.date)}</td>
                                            <td className="num text-muted">{l.number}</td>
                                            <td className={cn('max-w-96', l.reversed && 'text-muted line-through')}>{l.description}{l.memo && <span className="block text-xs text-muted">{l.memo}</span>}</td>
                                            <td className="text-muted">{l.account}</td>
                                            <td><Badge>{l.source}</Badge></td>
                                            <td className="text-end font-medium text-emerald-700 num">{Number(l.in) ? m(l.in) : '—'}</td>
                                            <td className="text-end font-medium text-red-700 num">{Number(l.out) ? m(l.out) : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination data={lines} />
                    </>
                ) : (
                    <EmptyState title="لا حركة في هذه الفترة" description="غيّر الفترة أو الحساب لعرض حركة أخرى." />
                )}
            </Card>
        </ClubLayout>
    );
}
