import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CalendarDays, Clock, MapPin, UserRound } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { dayName, time } from '@/lib/format';
import { PageHero } from './Plans';

export interface SessionData {
    id: number;
    program: string | null;
    program_id: number;
    color: string | null;
    trainer: string | null;
    trainer_id: number | null;
    starts_at: string;
    ends_at: string;
    date: string;
    time: string;
    end_time: string;
    capacity: number;
    booked: number;
    seats_left: number;
    is_full: boolean;
    room: string | null;
    status: string;
}

/** Group sessions by calendar date, keeping chronological order. */
export function groupByDay<T extends { date: string }>(sessions: T[]): [string, T[]][] {
    const map = new Map<string, T[]>();
    for (const s of sessions) {
        if (!map.has(s.date)) map.set(s.date, []);
        map.get(s.date)!.push(s);
    }
    return Array.from(map.entries());
}

export function DayChips({ days, value, onChange }: { days: string[]; value: string; onChange: (d: string) => void }) {
    return (
        <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
            {days.map((d) => {
                const dt = new Date(`${d}T00:00:00`);
                return (
                    <button
                        key={d}
                        type="button"
                        onClick={() => onChange(d)}
                        className={cn(
                            'flex min-w-[4.5rem] flex-col items-center rounded-2xl border px-3 py-2.5 transition',
                            value === d ? 'border-brand bg-brand text-white shadow-md shadow-brand/25' : 'border-line bg-white text-ink hover:border-brand-200',
                        )}
                    >
                        <span className="text-xs opacity-80">{new Intl.DateTimeFormat('ar-OM', { weekday: 'short' }).format(dt)}</span>
                        <span className="mt-0.5 font-display text-lg font-semibold num">{dt.getDate()}</span>
                    </button>
                );
            })}
        </div>
    );
}

export function SeatsBadge({ session }: { session: SessionData }) {
    if (session.is_full) return <span className="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">مكتملة</span>;
    return (
        <span className={cn('rounded-full px-2.5 py-1 text-xs font-medium', session.seats_left <= 3 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700')}>
            متبقٍ <span className="num">{session.seats_left}</span> {session.seats_left === 1 ? 'مقعد' : 'مقاعد'}
        </span>
    );
}

export function SessionLine({ session, action }: { session: SessionData; action?: React.ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-line bg-white p-4 shadow-sm sm:flex-nowrap">
            <div className="w-24 shrink-0 text-center">
                <p className="font-display text-lg font-semibold text-ink">{time(session.time)}</p>
                <p className="text-xs text-muted">حتى {time(session.end_time)}</p>
            </div>
            <span className="hidden h-12 w-1 shrink-0 rounded-full sm:block" style={{ background: session.color ?? 'var(--brand)' }} />
            <div className="min-w-0 flex-1">
                <p className="font-medium text-ink">{session.program}</p>
                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    {session.trainer && <span className="inline-flex items-center gap-1"><UserRound className="size-3.5" />{session.trainer}</span>}
                    {session.room && <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{session.room}</span>}
                    <span className="inline-flex items-center gap-1"><Clock className="size-3.5" /><span className="num">{session.booked}/{session.capacity}</span></span>
                </div>
            </div>
            <div className="flex items-center gap-2">
                <SeatsBadge session={session} />
                {action}
            </div>
        </div>
    );
}

export default function Schedule({ site, seo, sessions }: { site: SiteInfo; seo: { title: string }; sessions: SessionData[]; from: string }) {
    const { member } = useShared();
    const days = useMemo(() => groupByDay(sessions), [sessions]);
    const [active, setActive] = useState(days[0]?.[0] ?? '');
    const current = days.find(([d]) => d === active)?.[1] ?? [];
    const bookHref = route(member ? 'portal.classes' : 'portal.login');

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="جدول الحصص" subtitle="الأسبوع القادم بحسب المقاعد المتاحة الآن. الحجز متاح للمشتركات من بوابة المشتركة." icon={<CalendarDays className="size-6" />} />
            <section className="mx-auto max-w-4xl px-4 py-10">
                {days.length === 0 ? (
                    <p className="py-16 text-center text-muted">لا توجد حصص مجدولة خلال الأيام القادمة.</p>
                ) : (
                    <>
                        <DayChips days={days.map(([d]) => d)} value={active} onChange={setActive} />
                        <h2 className="mt-8 mb-4 font-display text-xl font-semibold">{dayName(active)}</h2>
                        <div className="space-y-3">
                            {current.map((s) => (
                                <SessionLine
                                    key={s.id}
                                    session={s}
                                    action={
                                        s.is_full ? (
                                            <span className="rounded-full border border-line px-4 py-2 text-xs text-muted">مكتملة</span>
                                        ) : (
                                            <Link href={bookHref} className="rounded-full bg-brand px-4 py-2 text-xs font-medium text-white hover:bg-brand-600">احجزي</Link>
                                        )
                                    }
                                />
                            ))}
                        </div>
                        {!member && (
                            <p className="mt-8 rounded-2xl bg-brand-50 p-4 text-center text-sm text-brand-900">
                                الحجز متاح للمشتركات. <Link href={route('portal.login')} className="font-medium underline">سجّلي الدخول</Link>
                                {site.signup_enabled && <> أو <Link href={route('site.join')} className="font-medium underline">اشتركي الآن</Link></>}.
                            </p>
                        )}
                    </>
                )}
            </section>
        </SiteLayout>
    );
}
