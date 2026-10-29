import { Link } from '@inertiajs/react';
import { CalendarClock, CalendarX, CreditCard, FileClock, LogIn, Snowflake, UserPlus, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, BarTrend, Card, CardHeader, EmptyState, PageHeader, StatCard } from '@/Components/ui';
import DashboardTiles, { type TilePreferences } from '@/Components/DashboardTiles';
import { useCurrency } from '@/hooks/useShared';
import { date, money, number, time, dayCount } from '@/lib/format';

interface Props {
    kpis: Record<string, number | string | null>;
    expiring: { id: number; member_id: number; member: string; phone: string; plan: string; ends_on: string; days_left: number }[];
    today_sessions: { id: number; program: string; color: string; trainer: string | null; time: string; booked: number; capacity: number }[];
    revenue_trend: { date: string; amount: number }[];
    /** Business document alerts (null without documents.view). */
    documents: { expiring: number; expired: number; alerts: { id: number; name: string; expiry_date: string; days_left: number | null }[] } | null;
    /** Change against the period before, only where there is something to compare with. */
    trends: Record<string, { change: number; label: string }>;
    /** The user's tile order and hidden tiles. */
    tiles: TilePreferences;
}

export default function Dashboard({ kpis, expiring, today_sessions, revenue_trend, documents, trends, tiles }: Props) {
    const currency = useCurrency();
    const financial = kpis.revenue_month !== null;

    return (
        <ClubLayout title="لوحة التحكم">
            <PageHeader title="لوحة التحكم" subtitle="نظرة سريعة على حالة النادي اليوم" />

            <DashboardTiles
                preferences={tiles}
                tiles={{
                    active_members: { label: 'عضويات نشطة', node: <StatCard label="عضويات نشطة" value={number(kpis.active_members)} icon={<Users className="size-5" />} href={route('club.memberships.index', { filter: 'active' })} /> },
                    expiring_7_days: { label: 'تنتهي خلال 7 أيام', node: <StatCard label="تنتهي خلال 7 أيام" value={number(kpis.expiring_7_days)} tone="amber" icon={<CalendarClock className="size-5" />} href={route('club.memberships.index', { filter: 'expiring' })} /> },
                    expired_members: { label: 'منتهية', node: <StatCard label="منتهية" value={number(kpis.expired_members)} tone="gray" icon={<CalendarX className="size-5" />} href={route('club.members.index', { membership: 'expired' })} /> },
                    frozen_members: { label: 'مجمّدة', node: <StatCard label="مجمّدة" value={number(kpis.frozen_members)} tone="violet" icon={<Snowflake className="size-5" />} /> },
                    revenue_month: financial ? { label: 'إيرادات الشهر', node: <StatCard label="إيرادات الشهر" value={money(kpis.revenue_month, currency)} tone="green" icon={<Wallet className="size-5" />} trend={trends.revenue_month} hint={`اليوم: ${money(kpis.revenue_today, currency)}`} /> } : null,
                    outstanding: financial ? { label: 'المستحقات', node: <StatCard label="المستحقات" value={money(kpis.outstanding, currency)} tone="red" icon={<CreditCard className="size-5" />} hint={`${number(kpis.overdue_invoices)} فاتورة متأخرة`} href={route('club.dues')} /> } : null,
                    checkins_today: { label: 'دخول اليوم', node: <StatCard label="دخول اليوم" value={number(kpis.checkins_today)} tone="blue" icon={<LogIn className="size-5" />} trend={trends.checkins_today} /> },
                    new_members_month: { label: 'أعضاء جدد هذا الشهر', node: <StatCard label="أعضاء جدد هذا الشهر" value={number(kpis.new_members_month)} icon={<UserPlus className="size-5" />} trend={trends.new_members_month} hint={`تجديدات: ${number(kpis.renewals_month)} · إلغاءات: ${number(kpis.cancellations_month)}`} /> },
                }}
            />

            {documents && (documents.alerts.length > 0 || documents.expired > 0) && (
                <Card className="mt-4">
                    <CardHeader
                        title={<span className="flex items-center gap-2"><FileClock className="size-4" /> تنبيهات المستندات</span>}
                        subtitle={`${number(documents.expiring)} قريب الانتهاء · ${number(documents.expired)} منتهٍ`}
                        actions={<Link href={route('club.business-documents.index', { view: documents.expired ? 'expired' : 'expiring' })} className="text-[13px] text-muted hover:text-ink">قسم المستندات</Link>}
                        className="mb-2"
                    />
                    <ul className="divide-y divide-line">
                        {documents.alerts.map((a) => (
                            <li key={a.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                                <span className="truncate font-medium">{a.name}</span>
                                <span className="flex shrink-0 items-center gap-2">
                                    <span className="text-xs text-muted num">{date(a.expiry_date)}</span>
                                    {a.days_left !== null && a.days_left < 0 ? <Badge tone="red">منتهٍ</Badge> : <Badge tone="amber">{a.days_left === 0 ? 'ينتهي اليوم' : `بعد ${dayCount(a.days_left ?? 0)}`}</Badge>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {(Number(kpis.pending_transfers) > 0 || Number(kpis.low_stock) > 0) && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {Number(kpis.pending_transfers) > 0 && (
                        <Link href={route('club.payments.index', { status: 'pending' })} className="rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-800">
                            {number(kpis.pending_transfers)} تحويل بنكي بانتظار التأكيد
                        </Link>
                    )}
                    {Number(kpis.low_stock) > 0 && (
                        <Link href={route('club.products.index', { low: 1 })} className="rounded-xl bg-red-50 px-4 py-2 text-sm text-red-700">
                            {number(kpis.low_stock)} منتج وصل لحد إعادة الطلب
                        </Link>
                    )}
                </div>
            )}

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                {financial && (
                    <Card className="lg:col-span-2">
                        <CardHeader title="التحصيل خلال آخر 30 يومًا" subtitle="صافي الدفعات المكتملة بعد المردودات" />
                        <BarTrend data={revenue_trend.map((d) => ({ label: d.date.slice(5), value: d.amount }))} valueLabel={(v) => money(v, currency)} />
                    </Card>
                )}
                <Card className={financial ? '' : 'lg:col-span-3'}>
                    <CardHeader title="حصص اليوم" actions={<Link href={route('club.sessions.index')} className="text-sm text-brand">الكل</Link>} />
                    {today_sessions.length === 0 ? (
                        <EmptyState title="لا حصص اليوم" />
                    ) : (
                        <ul className="space-y-2">
                            {today_sessions.map((s) => (
                                <li key={s.id}>
                                    <Link href={route('club.sessions.show', s.id)} className="flex items-center gap-3 rounded-xl border border-line p-3 hover:border-brand-200">
                                        <span className="h-10 w-1 rounded-full" style={{ background: s.color }} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">{s.program}</span>
                                            <span className="text-xs text-muted">{time(s.time)} · {s.trainer ?? '—'}</span>
                                        </span>
                                        <Badge tone={s.booked >= s.capacity ? 'red' : 'brand'}>
                                            {s.booked >= s.capacity ? 'مكتملة' : <span className="num">{s.booked}/{s.capacity}</span>}
                                        </Badge>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <Card className="mt-6" padded={false}>
                <div className="p-5 pb-0">
                    <CardHeader title="عضويات قريبة من الانتهاء" subtitle="تواصل معهم للتجديد" />
                </div>
                {expiring.length === 0 ? (
                    <EmptyState title="لا توجد عضويات تنتهي خلال أسبوع" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>العضو</th><th>الباقة</th><th>تنتهي في</th><th>المتبقي</th></tr>
                            </thead>
                            <tbody>
                                {expiring.map((m) => (
                                    <tr key={m.id}>
                                        <td><Link href={route('club.members.show', m.member_id)} className="font-medium hover:text-brand">{m.member}</Link></td>
                                        <td>{m.plan}</td>
                                        <td>{date(m.ends_on)}</td>
                                        <td><Badge tone={m.days_left <= 2 ? 'red' : 'amber'}><span className="num">{dayCount(m.days_left)}</span></Badge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
