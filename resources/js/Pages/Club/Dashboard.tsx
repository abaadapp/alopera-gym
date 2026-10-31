import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, CalendarX, CreditCard, FileClock, Landmark, LogIn, PackageX, Snowflake, UserPlus, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { AreaChart, Badge, Button, Card, CardHeader, PageHeader, StatCard } from '@/Components/ui';
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

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                {financial && (
                    <Card className="lg:col-span-2">
                        <CardHeader title="التحصيل خلال آخر 30 يومًا" subtitle="صافي الدفعات المكتملة بعد المردودات" />
                        <AreaChart data={revenue_trend.map((d) => ({ label: d.date.slice(5), value: d.amount }))} valueLabel={(v) => money(v, currency)} />
                    </Card>
                )}
                <Card className={financial ? '' : 'lg:col-span-3'}>
                    <CardHeader title="حصص اليوم" actions={<AllLink href={route('club.sessions.index')} />} />
                    {today_sessions.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-[#9ca3af]">لا حصص اليوم</p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {today_sessions.map((s) => (
                                <li key={s.id}>
                                    <Link href={route('club.sessions.show', s.id)} className="flex items-center justify-between gap-3 rounded-[10px] transition-colors hover:bg-[#fafafa]">
                                        <span className="flex min-w-0 items-center gap-2.5">
                                            <span className="h-8 w-1 shrink-0 rounded-full" style={{ background: s.color }} />
                                            <span className="min-w-0">
                                                <span className="block truncate text-[13px] text-[#111]">{s.program}</span>
                                                <span className="block text-[11px] text-[#9ca3af]"><span className="num">{time(s.time)}</span> · {s.trainer ?? '—'}</span>
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-[12px] tabular-nums text-[#6b7280]">{s.booked >= s.capacity ? <Badge tone="red">مكتملة</Badge> : <span className="num">{s.booked}/{s.capacity}</span>}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2" padded={false}>
                    <div className="p-5 pb-0">
                        <CardHeader title="عضويات قريبة من الانتهاء" subtitle="تواصل معهم للتجديد" actions={<AllLink href={route('club.memberships.index', { filter: 'expiring' })} />} />
                    </div>
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>العضو</th><th>الباقة</th><th>تنتهي في</th><th>المتبقي</th></tr>
                            </thead>
                            <tbody>
                                {expiring.length === 0 ? (
                                    <tr><td colSpan={4} className="px-4 py-14 text-center text-sm whitespace-normal text-[#9ca3af]">لا توجد عضويات تنتهي خلال أسبوع</td></tr>
                                ) : expiring.map((m) => (
                                    <tr key={m.id}>
                                        <td><Link href={route('club.members.show', m.member_id)} className="font-medium text-[#111] hover:underline">{m.member}</Link></td>
                                        <td>{m.plan}</td>
                                        <td className="num text-[#6b7280]">{date(m.ends_on)}</td>
                                        <td><Badge tone={m.days_left <= 2 ? 'red' : 'amber'}><span className="num">{dayCount(m.days_left)}</span></Badge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <div className="flex flex-col gap-4">
                    {documents && (
                        <Card>
                            <CardHeader title="تنبيهات المستندات" subtitle={`${number(documents.expiring)} قريب الانتهاء · ${number(documents.expired)} منتهٍ`} actions={<AllLink href={route('club.business-documents.index', { view: documents.expired ? 'expired' : 'expiring' })} />} />
                            {documents.alerts.length === 0 ? (
                                <p className="py-6 text-center text-[13px] text-[#9ca3af]">لا مستندات تنتهي قريبًا</p>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {documents.alerts.map((a) => (
                                        <li key={a.id} className="flex items-center justify-between gap-3">
                                            <span className="min-w-0">
                                                <span className="block truncate text-[13px] text-[#111]">{a.name}</span>
                                                <span className="block text-[11px] text-[#9ca3af] num">{date(a.expiry_date)}</span>
                                            </span>
                                            {a.days_left !== null && a.days_left < 0 ? <Badge tone="red">منتهٍ</Badge> : <Badge tone="amber">{a.days_left === 0 ? 'ينتهي اليوم' : `بعد ${dayCount(a.days_left ?? 0)}`}</Badge>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                    <Card>
                        <CardHeader title="يحتاج متابعة" />
                        <ul className="flex flex-col gap-3">
                            <FollowUp icon={<Landmark />} label="تحويلات بنكية بانتظار التأكيد" value={Number(kpis.pending_transfers)} href={route('club.payments.index', { status: 'pending' })} />
                            <FollowUp icon={<PackageX />} label="منتجات وصلت لحد إعادة الطلب" value={Number(kpis.low_stock)} href={route('club.products.index', { low: 1 })} />
                            <FollowUp icon={<FileClock />} label="فواتير متأخرة" value={Number(kpis.overdue_invoices)} href={route('club.invoices.index', { state: 'overdue' })} />
                        </ul>
                    </Card>
                </div>
            </div>
        </ClubLayout>
    );
}

/** A card's way to its full list: «الكل» with a leading arrow. */
function AllLink({ href }: { href: string }) {
    return <Button variant="ghost" size="sm" href={href}>الكل <ArrowLeft /></Button>;
}

/** A count that needs someone's attention; a link while there is something to see, a quiet line otherwise. */
function FollowUp({ icon, label, value, href }: { icon: ReactNode; label: string; value: number; href: string }) {
    const row = (
        <span className="flex items-center justify-between gap-3">
            <span className="flex min-w-0 items-center gap-2.5 text-[13px] text-[#111] [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-[#9ca3af]">{icon}<span className="truncate">{label}</span></span>
            <span className={value > 0 ? 'shrink-0 text-[12px] font-semibold tabular-nums text-[#b91c1c]' : 'shrink-0 text-[12px] tabular-nums text-[#9ca3af]'}><span className="num">{number(value)}</span></span>
        </span>
    );
    return <li>{value > 0 ? <Link href={href} className="block rounded-[8px] hover:bg-[#fafafa]">{row}</Link> : row}</li>;
}
