import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import QRCode from 'react-qr-code';
import { CalendarCheck, CalendarClock, CreditCard, Dumbbell, FileText, Footprints, QrCode, RefreshCw, Snowflake, UserRound } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { Badge } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, dayName, money, number, time, dayCount } from '@/lib/format';
import { label, membershipStatus } from '@/lib/labels';
import type { SessionData } from '../Site/Schedule';

export interface MembershipData {
    id: number;
    plan: string;
    plan_id: number;
    kind: string;
    status: string;
    starts_on: string;
    ends_on: string;
    days_left: number;
    sessions_total: number | null;
    sessions_used: number;
    sessions_left: number | null;
    total: string;
    discount: string;
    credit_applied: string;
    freeze_days_used: number;
    freezes_count: number;
    freeze_allowed: boolean;
    max_freeze_days: number;
    invoice_id: number | null;
    cancel_reason: string | null;
    activated_at: string | null;
    created_at: string | null;
}

interface Props {
    site: SiteInfo;
    seo: { title: string };
    profile: { full_name: string; member_no: string; phone: string; email: string | null; qr_token: string | null; photo_url: string | null };
    current: MembershipData | null;
    next: MembershipData | null;
    upcoming: { id: number; session: SessionData }[];
    openInvoices: { id: number; number: string; total: string; balance: string; issue_date: string }[];
    visits: number;
    onlinePayments: boolean;
}

/** The membership "card": status, expiry, days and sessions left, with a progress ring of the period. */
export function MembershipCard({ membership, compact = false }: { membership: MembershipData; compact?: boolean }) {
    const [statusLabel, tone] = label(membershipStatus, membership.status);
    const total = Math.max(1, Math.round((new Date(membership.ends_on).getTime() - new Date(membership.starts_on).getTime()) / 86400000) + 1);
    const pct = Math.min(100, Math.max(0, Math.round((membership.days_left / total) * 100)));
    const warn = membership.status === 'active' && membership.days_left <= 7;

    return (
        <div className={cn('relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-brand to-brand-900 text-white shadow-xl shadow-brand/20', compact ? 'p-5' : 'p-6 sm:p-8')}>
            <div className="pointer-events-none absolute -top-16 -end-16 size-56 rounded-full bg-white/10" />
            <div className="pointer-events-none absolute -bottom-20 -start-10 size-48 rounded-full bg-white/5" />
            <div className="relative flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm text-white/75">عضويتي</p>
                    <p className="mt-1 font-display text-2xl font-semibold">{membership.plan}</p>
                </div>
                <Badge tone={tone} className="bg-white/95">{statusLabel}</Badge>
            </div>
            <div className="relative mt-6 grid grid-cols-3 gap-3">
                <div className="rounded-2xl bg-white/10 p-3">
                    <p className="text-xs text-white/70">تنتهي في</p>
                    <p className="mt-1 text-sm font-semibold">{date(membership.ends_on)}</p>
                </div>
                <div className="rounded-2xl bg-white/10 p-3">
                    <p className="text-xs text-white/70">الأيام المتبقية</p>
                    <p className="mt-1 font-display text-xl font-semibold num">{membership.days_left}</p>
                </div>
                <div className="rounded-2xl bg-white/10 p-3">
                    <p className="text-xs text-white/70">الحصص المتبقية</p>
                    <p className="mt-1 font-display text-xl font-semibold num">{membership.sessions_left ?? '∞'}</p>
                </div>
            </div>
            {membership.status === 'active' && (
                <div className="relative mt-5">
                    <div className="h-2 overflow-hidden rounded-full bg-white/15">
                        <div className={cn('h-full rounded-full', warn ? 'bg-amber-300' : 'bg-white')} style={{ width: `${pct}%` }} />
                    </div>
                    <p className="mt-2 text-xs text-white/75">من {date(membership.starts_on)} إلى {date(membership.ends_on)}</p>
                </div>
            )}
            {membership.status === 'frozen' && (
                <p className="relative mt-5 flex items-center gap-2 rounded-2xl bg-white/10 p-3 text-sm"><Snowflake className="size-4" /> العضوية مجمّدة — يُمدَّد تاريخ الانتهاء بعدد أيام التجميد.</p>
            )}
        </div>
    );
}

export default function Dashboard({ site, seo, profile, current, next, upcoming, openInvoices, visits, onlinePayments }: Props) {
    const currency = useCurrency();
    const [paying, setPaying] = useState<number | null>(null);
    const expiringSoon = current?.status === 'active' && current.days_left <= 7 && !next;

    const pay = (id: number) => {
        setPaying(id);
        router.post(route('portal.invoices.pay', { invoice: id }), {}, { onFinish: () => setPaying(null) });
    };

    return (
        <PortalLayout site={site} title={seo.title}>
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {current ? (
                        <MembershipCard membership={current} />
                    ) : (
                        <div className="rounded-[2rem] border border-dashed border-brand-200 bg-white p-8 text-center">
                            <Dumbbell className="mx-auto size-10 text-brand-300" />
                            <p className="mt-3 font-display text-lg font-semibold">لا توجد عضوية نشطة</p>
                            <p className="mt-1 text-sm text-muted">{next?.status === 'pending' ? 'لديك اشتراك بانتظار الدفع.' : 'اختر باقة وابدأ الآن.'}</p>
                            <Link href={route('portal.renew')} className="mt-5 inline-flex items-center gap-2 rounded-full bg-brand px-6 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                                <RefreshCw className="size-4" /> اشترك / جدد
                            </Link>
                        </div>
                    )}

                    {expiringSoon && (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                            <p className="flex items-center gap-2 text-sm text-amber-800"><CalendarClock className="size-5 shrink-0" /><span>عضويتك تنتهي بعد <span className="num font-semibold">{dayCount(current?.days_left)}</span>. جدد الآن دون أن تفقد أي يوم.</span></p>
                            <Link href={route('portal.renew')} className="rounded-full bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">جدد الآن</Link>
                        </div>
                    )}

                    {next && (
                        <div className="rounded-2xl border border-line bg-white p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="font-medium">{next.status === 'pending' ? 'اشتراك بانتظار الدفع' : 'اشتراكك القادم'}</p>
                                <Badge tone={label(membershipStatus, next.status)[1]}>{label(membershipStatus, next.status)[0]}</Badge>
                            </div>
                            <p className="mt-2 text-sm text-muted">{next.plan} · من {date(next.starts_on)} إلى {date(next.ends_on)}</p>
                            {next.status === 'pending' && <p className="mt-2 text-xs text-muted">يُفعَّل تلقائيًا بعد تأكيد الدفع.</p>}
                        </div>
                    )}

                    {openInvoices.length > 0 && (
                        <section className="rounded-2xl border border-line bg-white p-5">
                            <h2 className="flex items-center gap-2 font-display text-lg font-semibold"><CreditCard className="size-5 text-brand" /> فواتير مستحقة</h2>
                            <ul className="mt-4 divide-y divide-line">
                                {openInvoices.map((inv) => (
                                    <li key={inv.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div>
                                            <p className="text-sm font-medium num">{inv.number}</p>
                                            <p className="text-xs text-muted">{date(inv.issue_date)} · المتبقي <span className="num">{money(inv.balance, currency)}</span></p>
                                        </div>
                                        {onlinePayments ? (
                                            <button onClick={() => pay(inv.id)} disabled={paying === inv.id} className="rounded-full bg-brand px-4 py-2 text-xs font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                                                {paying === inv.id ? 'جارٍ التحويل…' : 'ادفع الآن'}
                                            </button>
                                        ) : (
                                            <span className="text-xs text-muted">ادفع في الاستقبال</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section className="rounded-2xl border border-line bg-white p-5">
                        <div className="flex items-center justify-between">
                            <h2 className="flex items-center gap-2 font-display text-lg font-semibold"><CalendarCheck className="size-5 text-brand" /> حصصي القادمة</h2>
                            <Link href={route('portal.classes')} className="text-sm text-brand">احجز حصة</Link>
                        </div>
                        {upcoming.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted">لا توجد حجوزات قادمة.</p>
                        ) : (
                            <ul className="mt-4 space-y-2">
                                {upcoming.map(({ id, session }) => (
                                    <li key={id} className="flex items-center gap-3 rounded-xl bg-brand-50/50 p-3">
                                        <span className="h-10 w-1 rounded-full" style={{ background: session.color ?? 'var(--brand)' }} />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{session.program}</p>
                                            <p className="text-xs text-muted">{dayName(session.date)} · {time(session.time)}{session.trainer ? ` · ${session.trainer}` : ''}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <aside className="space-y-6">
                    <div className="rounded-[2rem] border border-line bg-white p-6 text-center shadow-sm">
                        <p className="flex items-center justify-center gap-2 font-medium"><QrCode className="size-5 text-brand" /> رمز الدخول</p>
                        {profile.qr_token ? (
                            <>
                                <div className="mx-auto mt-4 w-fit rounded-2xl border border-line bg-white p-4">
                                    <QRCode value={profile.qr_token} size={176} level="M" fgColor="#2a1f2d" title="رمز الدخول إلى النادي" />
                                </div>
                                <p className="mt-3 text-xs text-muted">اعرض الرمز على قارئ الدخول أو في الاستقبال. يُسمح بالدخول تلقائيًا ما دامت عضويتك سارية.</p>
                            </>
                        ) : (
                            <p className="mt-4 text-sm text-muted">رمز الدخول غير متاح حاليًا. تواصل مع الاستقبال.</p>
                        )}
                    </div>

                    <div className="rounded-2xl border border-line bg-white p-5">
                        <div className="flex items-center gap-3">
                            {profile.photo_url ? (
                                <img src={profile.photo_url} alt="" width={48} height={48} className="size-12 rounded-full object-cover" />
                            ) : (
                                <span className="grid size-12 place-items-center rounded-full bg-brand-50 text-brand"><UserRound className="size-5" /></span>
                            )}
                            <div className="min-w-0">
                                <p className="truncate font-medium">{profile.full_name}</p>
                                <p className="text-xs text-muted">رقم العضوية <span className="num">{profile.member_no}</span></p>
                            </div>
                        </div>
                        <div className="mt-4 flex items-center justify-between rounded-xl bg-brand-50/60 p-3 text-sm">
                            <span className="flex items-center gap-2 text-muted"><Footprints className="size-4 text-brand" /> زياراتك آخر 30 يومًا</span>
                            <span className="font-display text-lg font-semibold num">{number(visits)}</span>
                        </div>
                        <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <Link href={route('portal.invoices')} className="flex items-center justify-center gap-1.5 rounded-xl border border-line py-2 hover:bg-brand-50"><FileText className="size-4" /> الفواتير</Link>
                            <Link href={route('portal.renew')} className="flex items-center justify-center gap-1.5 rounded-xl border border-line py-2 hover:bg-brand-50"><RefreshCw className="size-4" /> التجديد</Link>
                        </div>
                    </div>
                </aside>
            </div>
        </PortalLayout>
    );
}
