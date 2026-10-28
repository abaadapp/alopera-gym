import { useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { CalendarPlus, ChevronLeft, ChevronRight, Plus, RefreshCw, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Field, Input, Modal, PageHeader, Select } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { WEEKDAYS, time } from '@/lib/format';
import { cn } from '@/lib/cn';
import type { Option } from '@/types';

export type SessionItem = {
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
};

type ProgramOption = Option & { color: string; duration_minutes: number; default_capacity: number };

function addDays(day: string, n: number): string {
    const d = new Date(`${day}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
}

function dayLabel(day: string): { weekday: string; date: string } {
    const d = new Date(`${day}T00:00:00Z`);
    return { weekday: WEEKDAYS[d.getUTCDay()], date: new Intl.DateTimeFormat('ar-OM', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(d) };
}

const today = new Date().toLocaleDateString('en-CA');

export default function SessionsIndex({ week, sessions, programs, trainers, filters, own_trainer_id }: { week: string; sessions: SessionItem[]; programs: ProgramOption[]; trainers: Option[]; filters: { trainer_id?: string | number; program_id?: string | number }; own_trainer_id: number | null }) {
    const can = useCan();
    const manage = can('classes.manage');
    const [open, setOpen] = useState(false);
    const days = Array.from({ length: 7 }, (_, i) => addDays(week, i));

    const go = (params: Record<string, string | number | undefined>) =>
        router.get(route('club.sessions.index'), { week, trainer_id: filters.trainer_id || undefined, program_id: filters.program_id || undefined, ...params }, { preserveState: true, preserveScroll: true });

    const form = useForm({ program_id: '' as number | string, trainer_id: '' as number | string, starts_at: '', duration_minutes: 60 as number | string, capacity: 15 as number | string, room: '' });

    const openCreate = () => {
        const p = programs[0];
        form.clearErrors();
        form.setData({ program_id: p?.id ?? '', trainer_id: '', starts_at: `${today}T18:00`, duration_minutes: p?.duration_minutes ?? 60, capacity: p?.default_capacity ?? 15, room: '' });
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, trainer_id: d.trainer_id || null, starts_at: d.starts_at.replace('T', ' ') }));
        form.post(route('club.sessions.store'), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    const total = sessions.filter((s) => s.status !== 'cancelled');
    const booked = total.reduce((sum, s) => sum + s.booked, 0);
    const capacity = total.reduce((sum, s) => sum + s.capacity, 0);

    return (
        <ClubLayout title="الحصص والحجوزات">
            <PageHeader
                title="الحصص والحجوزات"
                subtitle={`${total.length} حصة هذا الأسبوع · الإشغال ${capacity ? Math.round((booked / capacity) * 100) : 0}%`}
                actions={
                    manage && (
                        <>
                            <Button variant="outline" icon={<RefreshCw className="size-4" />} onClick={() => router.post(route('club.sessions.generate'), {}, { preserveScroll: true })}>
                                توليد من الجدول
                            </Button>
                            <Button icon={<CalendarPlus className="size-4" />} onClick={openCreate} disabled={programs.length === 0}>حصة إضافية</Button>
                        </>
                    )
                }
            />

            <Card className="mb-4" padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-3">
                    <div className="flex items-center gap-1">
                        <Button variant="ghost" size="sm" onClick={() => go({ week: addDays(week, -7) })} icon={<ChevronRight className="size-4" />}>السابق</Button>
                        <Button variant="outline" size="sm" onClick={() => go({ week: today })}>هذا الأسبوع</Button>
                        <Button variant="ghost" size="sm" onClick={() => go({ week: addDays(week, 7) })}>التالي<ChevronLeft className="size-4" /></Button>
                        <span className="ms-2 text-sm text-muted">
                            {dayLabel(days[0]).date} – {dayLabel(days[6]).date}
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Select value={filters.trainer_id ?? ''} onChange={(e) => go({ trainer_id: e.target.value || undefined })} placeholder="كل المدربات" className="h-9 w-44 py-1.5">
                            {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                        <Select value={filters.program_id ?? ''} onChange={(e) => go({ program_id: e.target.value || undefined })} placeholder="كل البرامج" className="h-9 w-44 py-1.5">
                            {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </Select>
                    </div>
                </div>
            </Card>

            <div className="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
                {days.map((day) => {
                    const items = sessions.filter((s) => s.date === day);
                    const label = dayLabel(day);
                    return (
                        <div key={day} className={cn('rounded-2xl border bg-white', day === today ? 'border-brand-300 ring-2 ring-brand/10' : 'border-line')}>
                            <div className="flex items-baseline justify-between border-b border-line px-3 py-2.5">
                                <p className="text-sm font-semibold">{label.weekday}</p>
                                <p className="text-xs text-muted">{label.date}</p>
                            </div>
                            <div className="space-y-2 p-2">
                                {items.length === 0 && <p className="py-4 text-center text-xs text-muted/70">لا حصص</p>}
                                {items.map((s) => {
                                    const cancelled = s.status === 'cancelled';
                                    const pct = s.capacity ? Math.min(100, (s.booked / s.capacity) * 100) : 0;
                                    // Trainers open only their own rosters; other classes are shown for reference.
                                    const linkable = own_trainer_id === null || s.trainer_id === own_trainer_id;
                                    const Tile = linkable ? Link : 'div';
                                    return (
                                        <Tile
                                            key={s.id}
                                            href={linkable ? route('club.sessions.show', s.id) : undefined}
                                            className={cn('block rounded-xl border border-transparent p-2.5 transition', linkable && 'hover:shadow-md', cancelled && 'opacity-50')}
                                            style={{ background: `${s.color ?? '#db2777'}14`, borderInlineStartColor: s.color ?? undefined, borderInlineStartWidth: 3 }}
                                        >
                                            <div className="flex items-center justify-between gap-1">
                                                <span className="text-xs font-semibold">{time(s.time)}</span>
                                                {cancelled ? <Badge tone="red">ملغاة</Badge> : s.is_full ? <Badge tone="red">مكتملة</Badge> : s.status === 'completed' ? <Badge tone="gray">منتهية</Badge> : null}
                                            </div>
                                            <p className={cn('mt-0.5 truncate text-sm font-medium', cancelled && 'line-through')}>{s.program}</p>
                                            <p className="truncate text-xs text-muted">{s.trainer ?? '—'}{s.room ? ` · ${s.room}` : ''}</p>
                                            {!cancelled && (
                                                <div className="mt-2">
                                                    <div className="flex items-center justify-between text-[11px] text-muted">
                                                        <span className="inline-flex items-center gap-0.5"><Users className="size-3" /><span className="num">{s.booked}/{s.capacity}</span></span>
                                                        {!s.is_full && <span>متبقي <span className="num">{s.seats_left}</span></span>}
                                                    </div>
                                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-white">
                                                        <div className={cn('h-full rounded-full', s.is_full ? 'bg-red-500' : 'bg-brand')} style={{ width: `${pct}%` }} />
                                                    </div>
                                                </div>
                                            )}
                                        </Tile>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>

            {sessions.length === 0 && (
                <p className="mt-4 text-center text-sm text-muted">
                    لا حصص في هذا الأسبوع. {manage && <>ابني <Link href={route('club.schedule.index')} className="text-brand underline">الجدول الأسبوعي</Link> ثم اضغطي «توليد من الجدول».</>}
                </p>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="حصة إضافية (خارج الجدول الأسبوعي)"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
                        <Button type="submit" form="session-form" loading={form.processing} icon={<Plus className="size-4" />}>إضافة</Button>
                    </>
                }
            >
                <form id="session-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="البرنامج" error={form.errors.program_id} required className="sm:col-span-2">
                        <Select value={form.data.program_id} onChange={(e) => {
                            const p = programs.find((x) => String(x.id) === e.target.value);
                            form.setData((d) => ({ ...d, program_id: e.target.value, duration_minutes: p?.duration_minutes ?? d.duration_minutes, capacity: p?.default_capacity ?? d.capacity }));
                        }}>
                            {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="الموعد" error={form.errors.starts_at} required>
                        <Input type="datetime-local" dir="ltr" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="المدربة" error={form.errors.trainer_id}>
                        <Select value={form.data.trainer_id} onChange={(e) => form.setData('trainer_id', e.target.value)} placeholder="— بدون —">
                            {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="المدة (دقيقة)" error={form.errors.duration_minutes}>
                        <Input type="number" min={10} max={300} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', e.target.value)} />
                    </Field>
                    <Field label="السعة" error={form.errors.capacity}>
                        <Input type="number" min={1} max={500} value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} />
                    </Field>
                    <Field label="القاعة" error={form.errors.room} className="sm:col-span-2">
                        <Input value={form.data.room} onChange={(e) => form.setData('room', e.target.value)} />
                    </Field>
                </form>
            </Modal>
        </ClubLayout>
    );
}
