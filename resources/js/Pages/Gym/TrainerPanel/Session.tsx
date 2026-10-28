import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, Phone, UserRound, Users, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, EmptyState } from '@/Components/ui';
import { dayName, phone as fmtPhone, time } from '@/lib/format';
import { cn } from '@/lib/cn';

type Session = {
    id: number;
    program: string | null;
    color: string | null;
    trainer: string | null;
    starts_at: string;
    date: string;
    time: string;
    end_time: string;
    capacity: number;
    booked: number;
    room: string | null;
    status: string;
};

type Row = { id: number; status: string; member: { id: number; full_name: string; member_no: string; phone: string } | null };

export default function TrainerSession({ session, bookings }: { session: Session; bookings: Row[] }) {
    const [busy, setBusy] = useState<number | null>(null);
    const startsIn = new Date(session.starts_at.replace(' ', 'T')).getTime() - Date.now();
    const canMark = startsIn <= 30 * 60 * 1000 && session.status !== 'cancelled';
    const attended = bookings.filter((b) => b.status === 'attended').length;
    const absent = bookings.filter((b) => b.status === 'no_show').length;

    const mark = (b: Row, status: 'attended' | 'no_show') => {
        setBusy(b.id);
        router.post(route('club.bookings.attendance', b.id), { status }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <ClubLayout title={session.program ?? 'الحصة'}>
            <Link href={route('club.trainer')} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> حصصي</Link>

            <div className="mb-5 overflow-hidden rounded-3xl p-5 text-white" style={{ background: `linear-gradient(135deg, ${session.color ?? 'var(--brand)'}, ${session.color ?? 'var(--brand)'}cc)` }}>
                <p className="font-display text-2xl font-semibold">{session.program}</p>
                <p className="mt-1 text-white/85">{dayName(session.date)} · {time(session.time)} – {time(session.end_time)}{session.room ? ` · ${session.room}` : ''}</p>
                <div className="mt-4 flex flex-wrap gap-2 text-sm">
                    <span className="rounded-full bg-white/20 px-3 py-1"><Users className="me-1 inline size-4" /><span className="num">{session.booked}/{session.capacity}</span></span>
                    <span className="rounded-full bg-white/20 px-3 py-1">حضرت <span className="num">{attended}</span></span>
                    <span className="rounded-full bg-white/20 px-3 py-1">غابت <span className="num">{absent}</span></span>
                </div>
            </div>

            {session.status === 'cancelled' && <Card className="mb-4 border-red-200 bg-red-50/60"><p className="text-sm text-red-700">الحصة ملغاة.</p></Card>}
            {!canMark && session.status !== 'cancelled' && <p className="mb-4 rounded-xl bg-sky-50 p-3 text-sm text-sky-800">يُفتح تسجيل الحضور قبل الحصة بنصف ساعة.</p>}

            {bookings.length === 0 ? (
                <Card><EmptyState icon={<Users className="size-6" />} title="لا حجوزات في هذه الحصة" /></Card>
            ) : (
                <ul className="space-y-3">
                    {bookings.map((b) => (
                        <li key={b.id} className={cn('rounded-2xl border bg-white p-4', b.status === 'attended' ? 'border-emerald-300 bg-emerald-50/40' : b.status === 'no_show' ? 'border-red-200 bg-red-50/40' : 'border-line')}>
                            <div className="flex items-center gap-3">
                                <span className="grid size-11 shrink-0 place-items-center rounded-full bg-brand-50 text-brand"><UserRound className="size-5" /></span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">{b.member?.full_name ?? '—'}</p>
                                    <p className="text-xs text-muted">
                                        <span className="num">{b.member?.member_no}</span>
                                        {b.member?.phone && <> · <a href={`tel:+${b.member.phone}`} className="num"><Phone className="me-0.5 inline size-3" />{fmtPhone(b.member.phone)}</a></>}
                                    </p>
                                </div>
                                {b.status === 'attended' && <Badge tone="green">حضرت</Badge>}
                                {b.status === 'no_show' && <Badge tone="red">غابت</Badge>}
                            </div>
                            {canMark && (
                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        disabled={busy === b.id}
                                        onClick={() => mark(b, 'attended')}
                                        className={cn('flex h-12 items-center justify-center gap-2 rounded-xl text-base font-medium transition disabled:opacity-60', b.status === 'attended' ? 'bg-emerald-600 text-white' : 'border border-emerald-300 text-emerald-700 hover:bg-emerald-50')}
                                    >
                                        <Check className="size-5" /> حضرت
                                    </button>
                                    <button
                                        type="button"
                                        disabled={busy === b.id}
                                        onClick={() => mark(b, 'no_show')}
                                        className={cn('flex h-12 items-center justify-center gap-2 rounded-xl text-base font-medium transition disabled:opacity-60', b.status === 'no_show' ? 'bg-red-600 text-white' : 'border border-red-200 text-red-700 hover:bg-red-50')}
                                    >
                                        <X className="size-5" /> غابت
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </ClubLayout>
    );
}
