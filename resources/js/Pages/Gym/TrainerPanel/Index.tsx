import { Link } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, Phone, Sparkles, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PtCloseActions from '@/Components/PtCloseActions';
import { Badge, Button, Card, CardHeader, EmptyState, PageHeader, StatCard } from '@/Components/ui';
import { dayName, phone as fmtPhone, time } from '@/lib/format';
import { cn } from '@/lib/cn';

type SessionItem = {
    id: number;
    program: string | null;
    color: string | null;
    starts_at: string;
    date: string;
    time: string;
    end_time: string;
    capacity: number;
    booked: number;
    is_full: boolean;
    room: string | null;
    status: string;
};

type PtItem = { id: number; member: string | null; phone: string | null; starts_at: string; ends_at: string; status: string; notes: string | null; started: boolean };

const PT_STATUS: Record<string, [string, 'blue' | 'green' | 'red' | 'gray']> = {
    scheduled: ['مجدولة', 'blue'],
    completed: ['تمت', 'green'],
    no_show: ['غابت', 'red'],
    cancelled: ['ملغاة', 'gray'],
};

const todayStr = new Date().toLocaleDateString('en-CA');

function SessionCard({ s }: { s: SessionItem }) {
    const isToday = s.date === todayStr;
    return (
        <Link href={route('club.trainer.session', s.id)} className={cn('flex items-center gap-3 rounded-2xl border bg-white p-4 transition hover:shadow-md', isToday ? 'border-brand-300' : 'border-line')}>
            <span className="h-12 w-1.5 shrink-0 rounded-full" style={{ background: s.color ?? 'var(--brand)' }} />
            <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{s.program}</p>
                <p className="text-sm text-muted">{isToday ? 'اليوم' : dayName(s.date)} · {time(s.time)}{s.room ? ` · ${s.room}` : ''}</p>
            </div>
            <div className="text-end">
                {s.status === 'cancelled' ? <Badge tone="red">ملغاة</Badge> : s.is_full ? <Badge tone="red">مكتملة</Badge> : null}
                <p className="mt-1 text-sm"><Users className="me-1 inline size-3.5 text-muted" /><span className="num">{s.booked}/{s.capacity}</span></p>
            </div>
            <ChevronLeft className="size-4 text-muted" />
        </Link>
    );
}

export default function TrainerPanel({ trainer, sessions, recent, pt }: { trainer: { id: number; name: string } | null; sessions: SessionItem[]; recent: SessionItem[]; pt: PtItem[] }) {
    if (!trainer) {
        return (
            <ClubLayout title="شاشة المدربة">
                <Card>
                    <EmptyState icon={<Sparkles className="size-6" />} title="حسابك غير مرتبط بملف مدربة" description="اطلبي من الإدارة ربط حسابك بملفك في صفحة المدربات لتظهر حصصك هنا." />
                </Card>
            </ClubLayout>
        );
    }

    const today = sessions.filter((s) => s.date === todayStr);
    const later = sessions.filter((s) => s.date !== todayStr);
    const ptUpcoming = pt.filter((p) => p.status === 'scheduled');

    return (
        <ClubLayout title="شاشة المدربة">
            <PageHeader title={`أهلًا ${trainer.name.split(' ')[0]} 👋`} subtitle="حصصك وجلساتك هذا الأسبوع — اضغطي على الحصة لتسجيل الحضور" />

            <div className="mb-6 grid grid-cols-3 gap-3">
                <StatCard label="حصص اليوم" value={today.length} icon={<CalendarDays className="size-5" />} />
                <StatCard label="حصص الأسبوع" value={sessions.length} tone="blue" />
                <StatCard label="جلسات فردية" value={ptUpcoming.length} tone="violet" />
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <section>
                        <h2 className="mb-3 text-sm font-semibold text-muted">اليوم</h2>
                        {today.length === 0 ? <Card><p className="text-center text-sm text-muted">لا حصص لكِ اليوم.</p></Card> : <div className="space-y-2">{today.map((s) => <SessionCard key={s.id} s={s} />)}</div>}
                    </section>
                    <section>
                        <h2 className="mb-3 text-sm font-semibold text-muted">الأيام القادمة</h2>
                        {later.length === 0 ? <Card><p className="text-center text-sm text-muted">لا حصص مجدولة.</p></Card> : <div className="space-y-2">{later.map((s) => <SessionCard key={s.id} s={s} />)}</div>}
                    </section>
                    {recent.length > 0 && (
                        <section>
                            <h2 className="mb-3 text-sm font-semibold text-muted">آخر الحصص (لاستكمال الحضور)</h2>
                            <div className="space-y-2">{recent.map((s) => <SessionCard key={s.id} s={s} />)}</div>
                        </section>
                    )}
                </div>

                <Card>
                    <CardHeader title="الجلسات الفردية" />
                    {pt.length === 0 ? (
                        <EmptyState title="لا جلسات فردية" />
                    ) : (
                        <ul className="space-y-2">
                            {pt.map((p) => {
                                const [pl, ptone] = PT_STATUS[p.status] ?? [p.status, 'gray'];
                                return (
                                    <li key={p.id} className="rounded-xl border border-line p-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">{p.member}</p>
                                                <p className="text-xs text-muted">{dayName(p.starts_at.slice(0, 10))} · {time(p.starts_at)}</p>
                                                {p.phone && <a href={`tel:+${p.phone}`} className="text-xs text-muted num"><Phone className="me-0.5 inline size-3" />{fmtPhone(p.phone)}</a>}
                                            </div>
                                            <Badge tone={ptone}>{pl}</Badge>
                                        </div>
                                        {p.status === 'scheduled' && (
                                            <div className="mt-2 flex gap-1.5">
                                                <PtCloseActions id={p.id} started={p.started} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>
            </div>
        </ClubLayout>
    );
}
