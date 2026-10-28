import { useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Ban, Check, Clock, MapPin, Pencil, Phone, UserCheck, UserRound, Users, UserX, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, MemberPicker, Modal, PageHeader, Select, Stat } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { dayName, phone as fmtPhone, time } from '@/lib/format';
import { bookingStatus, label, sessionStatus } from '@/lib/labels';
import { cn } from '@/lib/cn';
import type { Option } from '@/types';

type Session = {
    id: number;
    program: string | null;
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
    cancel_reason: string | null;
};

type BookingRow = {
    id: number;
    status: string;
    source: string;
    consumed_session: boolean;
    cancel_reason: string | null;
    member: { id: number; full_name: string; member_no: string; phone: string } | null;
    attended_at: string | null;
    created_at: string;
};

const SOURCES: Record<string, string> = { reception: 'الاستقبال', portal: 'البوابة', website: 'الموقع', trainer: 'المدربة' };

function parseLocal(value: string): Date {
    return new Date(value.replace(' ', 'T'));
}

export default function SessionShow({ session, bookings, trainers }: { session: Session; bookings: BookingRow[]; trainers: Option[] }) {
    const can = useCan();
    const [editOpen, setEditOpen] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);
    const scheduled = session.status === 'scheduled';
    const startsAt = parseLocal(session.starts_at);
    const upcoming = startsAt.getTime() > Date.now();
    const attendanceOpen = startsAt.getTime() - Date.now() <= 30 * 60 * 1000;
    const live = bookings.filter((b) => b.status !== 'cancelled');
    const cancelled = bookings.filter((b) => b.status === 'cancelled');
    const attended = live.filter((b) => b.status === 'attended').length;
    const [statusLabel, statusTone] = label(sessionStatus, session.status);

    const form = useForm({ trainer_id: (session.trainer_id ?? '') as number | string, room: session.room ?? '', capacity: session.capacity as number | string, starts_at: session.starts_at.replace(' ', 'T') });

    const submitEdit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, trainer_id: d.trainer_id || null, starts_at: d.starts_at.replace('T', ' ') }));
        form.put(route('club.sessions.update', session.id), { preserveScroll: true, onSuccess: () => setEditOpen(false) });
    };

    const mark = (b: BookingRow, status: 'attended' | 'no_show') => {
        setBusy(b.id);
        router.post(route('club.bookings.attendance', b.id), { status }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <ClubLayout title={session.program ?? 'حصة'}>
            <PageHeader
                back={<Link href={route('club.sessions.index', { week: session.date })} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> الحصص</Link>}
                title={<span className="flex items-center gap-3"><span className="h-8 w-1.5 rounded-full" style={{ background: session.color ?? 'var(--brand)' }} />{session.program}</span>}
                subtitle={`${dayName(session.date)} · ${time(session.time)} – ${time(session.end_time)}`}
                actions={
                    can('classes.manage') && scheduled && (
                        <>
                            <Button variant="outline" icon={<Pencil className="size-4" />} onClick={() => { form.clearErrors(); setEditOpen(true); }} disabled={!upcoming}>تعديل</Button>
                            <ConfirmAction
                                url={route('club.sessions.cancel', session.id)}
                                title="إلغاء الحصة"
                                message={`سيُلغى ${live.length} حجز وتُستعاد حصصهن وتُبلَّغن عبر واتساب.`}
                                reason="سبب الإلغاء (يصل للمشتركات)"
                                confirmLabel="إلغاء الحصة"
                                size="md"
                                icon={<Ban className="size-4" />}
                            >
                                إلغاء الحصة
                            </ConfirmAction>
                        </>
                    )
                }
            />

            {session.status === 'cancelled' && (
                <Card className="mb-4 border-red-200 bg-red-50/60">
                    <p className="text-sm text-red-700">هذه الحصة ملغاة{session.cancel_reason ? ` — ${session.cancel_reason}` : ''}.</p>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6">
                    <Card>
                        <dl className="grid grid-cols-2 gap-4">
                            <Stat label="الحالة" value={<Badge tone={statusTone}>{statusLabel}</Badge>} />
                            <Stat label="المدربة" value={session.trainer ?? '—'} />
                            <Stat label="القاعة" value={session.room ? <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{session.room}</span> : '—'} />
                            <Stat label="السعة" value={<span className="num">{session.capacity}</span>} />
                        </dl>
                        <div className="mt-5">
                            <div className="mb-1.5 flex items-center justify-between text-sm">
                                <span className="inline-flex items-center gap-1 text-muted"><Users className="size-4" /> المحجوز</span>
                                {session.is_full ? <Badge tone="red">مكتملة</Badge> : <span className="text-muted">متبقي <span className="num font-medium text-ink">{session.seats_left}</span> مقعد</span>}
                            </div>
                            <div className="h-2.5 overflow-hidden rounded-full bg-brand-50">
                                <div className={cn('h-full rounded-full', session.is_full ? 'bg-red-500' : 'bg-brand')} style={{ width: `${Math.min(100, (session.booked / Math.max(1, session.capacity)) * 100)}%` }} />
                            </div>
                            <p className="mt-1.5 text-sm num">{session.booked} / {session.capacity}</p>
                        </div>
                        {attended > 0 && <p className="mt-3 text-sm text-emerald-700">حضرت <span className="num">{attended}</span> من <span className="num">{live.length}</span></p>}
                    </Card>

                    {can('bookings.manage') && scheduled && upcoming && (
                        <Card>
                            <CardHeader title="حجز لمشتركة" subtitle="يُتحقق من العضوية والسعة تلقائيًا" />
                            {session.is_full ? (
                                <p className="rounded-xl bg-red-50 p-3 text-sm text-red-700">اكتملت المقاعد — لا يمكن إضافة حجوزات.</p>
                            ) : (
                                <MemberPicker onSelect={(m) => router.post(route('club.bookings.store', session.id), { member_id: m.id }, { preserveScroll: true })} />
                            )}
                        </Card>
                    )}
                </div>

                <Card className="lg:col-span-2" padded={false}>
                    <div className="p-5 pb-0">
                        <CardHeader
                            title={`الحجوزات (${live.length})`}
                            subtitle={attendanceOpen && scheduled ? 'سجّلي الحضور والغياب' : upcoming ? 'يُفتح تسجيل الحضور قبل الحصة بنصف ساعة' : undefined}
                        />
                    </div>
                    {live.length === 0 ? (
                        <EmptyState icon={<Users className="size-6" />} title="لا حجوزات بعد" />
                    ) : (
                        <ul className="divide-y divide-line">
                            {live.map((b) => {
                                const [bl, bt] = label(bookingStatus, b.status);
                                return (
                                    <li key={b.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                                        <span className="grid size-10 place-items-center rounded-full bg-brand-50 text-brand"><UserRound className="size-4" /></span>
                                        <div className="min-w-0 flex-1">
                                            {b.member ? (
                                                <Link href={route('club.members.show', b.member.id)} className="font-medium hover:text-brand">{b.member.full_name}</Link>
                                            ) : '—'}
                                            <p className="text-xs text-muted">
                                                <span className="num">{b.member?.member_no}</span>
                                                {b.member?.phone && <> · <a href={`tel:+${b.member.phone}`} className="num inline-flex items-center gap-0.5"><Phone className="size-3" />{fmtPhone(b.member.phone)}</a></>}
                                                {' · '}{SOURCES[b.source] ?? b.source}
                                            </p>
                                        </div>
                                        <Badge tone={bt}>{bl}{b.attended_at && <span className="num">{b.attended_at}</span>}</Badge>
                                        <div className="flex gap-1.5">
                                            {can('attendance.mark') && session.status !== 'cancelled' && (
                                                <>
                                                    <Button size="sm" variant={b.status === 'attended' ? 'success' : 'outline'} disabled={!attendanceOpen || busy === b.id} onClick={() => mark(b, 'attended')} icon={<UserCheck className="size-3.5" />}>حضرت</Button>
                                                    <Button size="sm" variant={b.status === 'no_show' ? 'danger' : 'outline'} disabled={!attendanceOpen || busy === b.id} onClick={() => mark(b, 'no_show')} icon={<UserX className="size-3.5" />}>غابت</Button>
                                                </>
                                            )}
                                            {can('bookings.manage') && b.status === 'booked' && upcoming && (
                                                <ConfirmAction url={route('club.bookings.cancel', b.id)} title="إلغاء الحجز" message="ستُستعاد الحصة لرصيد المشتركة وتُبلَّغ." reason="السبب" confirmLabel="إلغاء الحجز" variant="ghost" icon={<X className="size-3.5" />}>
                                                    إلغاء
                                                </ConfirmAction>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {cancelled.length > 0 && (
                        <details className="border-t border-line px-5 py-3">
                            <summary className="cursor-pointer text-sm text-muted">حجوزات ملغاة ({cancelled.length})</summary>
                            <ul className="mt-2 space-y-1 text-sm">
                                {cancelled.map((b) => (
                                    <li key={b.id} className="flex justify-between gap-2 text-muted">
                                        <span>{b.member?.full_name}</span>
                                        <span className="text-xs">{b.cancel_reason}</span>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </Card>
            </div>

            <Modal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                title="تعديل هذه الحصة"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditOpen(false)}>إلغاء</Button>
                        <Button type="submit" form="session-edit" loading={form.processing} icon={<Check className="size-4" />}>حفظ</Button>
                    </>
                }
            >
                <form id="session-edit" onSubmit={submitEdit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="الموعد" error={form.errors.starts_at} className="sm:col-span-2">
                        <Input type="datetime-local" dir="ltr" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="المدربة" error={form.errors.trainer_id}>
                        <Select value={form.data.trainer_id} onChange={(e) => form.setData('trainer_id', e.target.value)} placeholder="— بدون —">
                            {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="القاعة" error={form.errors.room}>
                        <Input value={form.data.room} onChange={(e) => form.setData('room', e.target.value)} />
                    </Field>
                    <Field label="السعة" error={form.errors.capacity} hint={`لا تقل عن المحجوز (${session.booked})`} className="sm:col-span-2">
                        <Input type="number" min={Math.max(1, session.booked)} max={500} value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} />
                    </Field>
                    <p className="flex items-start gap-2 rounded-xl bg-canvas p-3 text-xs text-muted sm:col-span-2">
                        <Clock className="mt-0.5 size-3.5 shrink-0" />
                        عند تغيير الموعد أو المدربة أو القاعة تُبلَّغ المحجوزات تلقائيًا عبر واتساب (إن كان مفعّلًا).
                    </p>
                </form>
            </Modal>
        </ClubLayout>
    );
}
