import { Link, router } from '@inertiajs/react';
import { Download, Ticket } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, PageHeader, Pagination, Select, Tabs } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money, dayCount } from '@/lib/format';
import { label, membershipKind, membershipStatus } from '@/lib/labels';
import type { Option, Paginated } from '@/types';

interface Row {
    id: number;
    plan: string;
    kind: string;
    status: string;
    starts_on: string;
    ends_on: string;
    days_left: number;
    sessions_total: number | null;
    sessions_used: number;
    sessions_left: number | null;
    total: string;
    invoice_id: number | null;
    member: { id: number; full_name: string; member_no: string; phone: string } | null;
}

const FILTERS = [
    { key: 'active', label: 'نشطة' },
    { key: 'expiring', label: 'تنتهي خلال 7 أيام' },
    { key: 'pending', label: 'بانتظار الدفع' },
    { key: 'scheduled', label: 'مجدولة' },
    { key: 'frozen', label: 'مجمّدة' },
    { key: 'expired', label: 'منتهية' },
    { key: 'cancelled', label: 'ملغاة' },
] as const;

export default function MembershipsIndex({ memberships, counts, plans, filters }: { memberships: Paginated<Row>; counts: Record<string, number>; plans: Option[]; filters: { filter: string; plan_id: number | null } }) {
    const can = useCan();
    const currency = useCurrency();

    const go = (params: Record<string, string | number | undefined>) =>
        router.get(route('club.memberships.index'), { filter: filters.filter, plan_id: filters.plan_id ?? undefined, ...params }, { preserveState: true, preserveScroll: true });

    return (
        <ClubLayout title="العضويات">
            <PageHeader
                title="العضويات"
                subtitle="متابعة الاشتراكات حسب الحالة: النشطة، القريبة من الانتهاء، المنتظرة للدفع، المجمّدة والمنتهية"
                actions={
                    can('members.export') && (
                        <>
                            <Button variant="outline" href={route('club.memberships.index', { filter: filters.filter, plan_id: filters.plan_id ?? undefined, format: 'xlsx' })} external icon={<Download className="size-4" />}>Excel</Button>
                            <Button variant="outline" href={route('club.memberships.index', { filter: filters.filter, plan_id: filters.plan_id ?? undefined, format: 'csv' })} external icon={<Download className="size-4" />}>CSV</Button>
                        </>
                    )
                }
            />

            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <Tabs
                        tabs={FILTERS.map((f) => ({ key: f.key as string, label: f.label, count: counts[f.key] ?? 0 }))}
                        value={filters.filter}
                        onChange={(key) => go({ filter: key })}
                        className="border-0"
                    />
                    <div className="w-full sm:w-56">
                        <Select value={filters.plan_id ?? ''} onChange={(e) => go({ plan_id: e.target.value || undefined })} placeholder="كل الباقات">
                            {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </Select>
                    </div>
                </div>

                {memberships.data.length === 0 ? (
                    <EmptyState icon={<Ticket className="size-6" />} title="لا توجد عضويات بهذه الحالة" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>العضو</th><th>الباقة</th><th>النوع</th><th>الفترة</th><th>المتبقي</th><th>الحصص</th><th>الإجمالي</th><th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {memberships.data.map((m) => {
                                    const [l, tone] = label(membershipStatus, m.status);
                                    return (
                                        <tr key={m.id}>
                                            <td>
                                                {m.member ? (
                                                    <Link href={route('club.members.show', m.member.id)} className="font-medium hover:text-brand">
                                                        {m.member.full_name}
                                                        <span className="block text-xs font-normal text-muted num">{m.member.member_no}</span>
                                                    </Link>
                                                ) : '—'}
                                            </td>
                                            <td>{m.plan}</td>
                                            <td className="text-xs text-muted">{membershipKind[m.kind] ?? m.kind}</td>
                                            <td className="text-xs whitespace-nowrap">{date(m.starts_on)} ← {date(m.ends_on)}</td>
                                            <td>
                                                {['active', 'frozen'].includes(m.status) ? (
                                                    <Badge tone={m.days_left <= 3 ? 'red' : m.days_left <= 7 ? 'amber' : 'gray'}><span className="num">{dayCount(m.days_left)}</span></Badge>
                                                ) : '—'}
                                            </td>
                                            <td className="num text-xs">{m.sessions_total !== null ? `${m.sessions_used}/${m.sessions_total}` : 'غير محدود'}</td>
                                            <td className="num">{money(m.total, currency)}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                            <td className="text-end">
                                                {m.status === 'pending' && m.invoice_id && can('invoices.view') && (
                                                    <Link href={route('club.invoices.show', m.invoice_id)} className="text-sm font-medium text-brand">تحصيل</Link>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={memberships} />
            </Card>
        </ClubLayout>
    );
}
