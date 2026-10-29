import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CalendarDays, Clock, MapPin, Plus, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Toggle } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { WEEKDAYS, time } from '@/lib/format';
import type { Option } from '@/types';

type Slot = {
    id: number;
    weekday: number;
    starts_at: string;
    duration_minutes: number;
    capacity: number;
    room: string | null;
    is_active: boolean;
    program_id: number;
    trainer_id: number | null;
    branch_id: number | null;
    program: string | null;
    color: string | null;
    trainer: string | null;
    valid_from: string;
    valid_until: string | null;
};

type ProgramOption = Option & { color: string; duration_minutes: number; default_capacity: number };

type SlotForm = {
    program_id: number | string;
    trainer_id: number | string | null;
    branch_id: number | string | null;
    weekday: number | string;
    starts_at: string;
    duration_minutes: number | string;
    capacity: number | string;
    room: string;
    valid_from: string;
    valid_until: string;
    is_active: boolean;
};

/** The club week starts on Saturday. */
const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5];

function endTime(start: string, minutes: number): string {
    const [h, m] = start.split(':').map(Number);
    const total = h * 60 + m + minutes;
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export default function ScheduleIndex({ slots, programs, trainers, branches }: { slots: Slot[]; programs: ProgramOption[]; trainers: Option[]; branches: Option[] }) {
    const can = useCan();
    const manage = can('classes.manage');
    const [editing, setEditing] = useState<Slot | null>(null);
    const [open, setOpen] = useState(false);
    const [showStopped, setShowStopped] = useState(false);

    const form = useForm<SlotForm>({
        program_id: '',
        trainer_id: null,
        branch_id: null,
        weekday: 6,
        starts_at: '09:00',
        duration_minutes: 60,
        capacity: 15,
        room: '',
        valid_from: '',
        valid_until: '',
        is_active: true,
    });

    const visible = slots.filter((s) => showStopped || s.is_active);
    const byDay = (day: number) => visible.filter((s) => s.weekday === day).sort((a, b) => a.starts_at.localeCompare(b.starts_at));

    const openCreate = (weekday = 6) => {
        const first = programs[0];
        setEditing(null);
        form.clearErrors();
        form.setData({
            program_id: first?.id ?? '',
            trainer_id: null,
            branch_id: null,
            weekday,
            starts_at: '09:00',
            duration_minutes: first?.duration_minutes ?? 60,
            capacity: first?.default_capacity ?? 15,
            room: '',
            valid_from: '',
            valid_until: '',
            is_active: true,
        });
        setOpen(true);
    };

    const openEdit = (s: Slot) => {
        if (!manage) return;
        setEditing(s);
        form.clearErrors();
        form.setData({
            program_id: s.program_id,
            trainer_id: s.trainer_id,
            branch_id: s.branch_id,
            weekday: s.weekday,
            starts_at: s.starts_at,
            duration_minutes: s.duration_minutes,
            capacity: s.capacity,
            room: s.room ?? '',
            valid_from: s.valid_from,
            valid_until: s.valid_until ?? '',
            is_active: s.is_active,
        });
        setOpen(true);
    };

    const pickProgram = (id: string) => {
        const p = programs.find((x) => String(x.id) === id);
        form.setData((d) => ({
            ...d,
            program_id: id,
            duration_minutes: editing ? d.duration_minutes : p?.duration_minutes ?? d.duration_minutes,
            capacity: editing ? d.capacity : p?.default_capacity ?? d.capacity,
        }));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('club.schedule.update', editing.id), options);
        else form.post(route('club.schedule.store'), options);
    };

    const activeCount = slots.filter((s) => s.is_active).length;

    return (
        <ClubLayout title="الجدول الأسبوعي">
            <PageHeader
                title="الجدول الأسبوعي"
                subtitle={`${activeCount} حصة أسبوعيًا — تُولَّد منها مواعيد الحصص القادمة تلقائيًا`}
                actions={
                    <>
                        <Button variant="outline" href={route('club.sessions.index')} icon={<CalendarDays className="size-4" />}>الحصص المجدولة</Button>
                        {manage && <Button onClick={() => openCreate()} icon={<Plus className="size-4" />} disabled={programs.length === 0}>حصة في الجدول</Button>}
                    </>
                }
            />

            {programs.length === 0 && manage && (
                <Card className="mb-4 border-amber-200 bg-amber-50/50">
                    <p className="text-sm text-amber-800">
                        أضف البرامج أولًا من <Link href={route('club.programs.index')} className="font-medium underline">صفحة البرامج</Link> ثم ارجع لبناء الجدول.
                    </p>
                </Card>
            )}

            <div className="mb-3 flex justify-end">
                <label className="inline-flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" checked={showStopped} onChange={(e) => setShowStopped(e.target.checked)} className="accent-[var(--brand)]" />
                    إظهار الحصص الموقوفة
                </label>
            </div>

            {visible.length === 0 ? (
                <Card>
                    <EmptyState icon={<CalendarDays className="size-6" />} title="الجدول فارغ" description="أضف الحصص الأسبوعية المتكررة (اليوم، الوقت، المدرب، السعة)." action={manage && programs.length > 0 ? <Button onClick={() => openCreate()}>إضافة حصة</Button> : undefined} />
                </Card>
            ) : (
                <div className="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
                    {WEEK_ORDER.map((day) => {
                        const daySlots = byDay(day);
                        return (
                            <div key={day} className="flex flex-col rounded-2xl border border-line bg-white">
                                <div className="flex items-center justify-between border-b border-line px-3 py-2.5">
                                    <p className="text-sm font-semibold">{WEEKDAYS[day]}</p>
                                    <span className="text-xs text-muted num">{daySlots.length}</span>
                                </div>
                                <div className="flex-1 space-y-2 p-2">
                                    {daySlots.length === 0 && <p className="py-4 text-center text-xs text-muted/70">—</p>}
                                    {daySlots.map((s) => (
                                        <button
                                            key={s.id}
                                            type="button"
                                            onClick={() => openEdit(s)}
                                            className={`block w-full rounded-xl border p-2.5 text-start transition ${s.is_active ? 'border-transparent hover:shadow-md' : 'border-dashed border-line opacity-60'}`}
                                            style={{ background: s.is_active ? `${s.color ?? '#db2777'}14` : undefined, borderInlineStartColor: s.color ?? undefined, borderInlineStartWidth: 3 }}
                                        >
                                            <p className="text-xs font-semibold num" dir="ltr" style={{ textAlign: 'end' }}>{s.starts_at} – {endTime(s.starts_at, s.duration_minutes)}</p>
                                            <p className="mt-0.5 truncate text-sm font-medium">{s.program}</p>
                                            <p className="truncate text-xs text-muted">{s.trainer ?? 'بدون مدرب'}</p>
                                            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-muted">
                                                <span className="inline-flex items-center gap-0.5"><Users className="size-3.5" /><span className="num">{s.capacity}</span></span>
                                                {s.room && <span className="inline-flex items-center gap-0.5"><MapPin className="size-3.5" />{s.room}</span>}
                                                {!s.is_active && <Badge tone="gray">موقوفة</Badge>}
                                            </div>
                                        </button>
                                    ))}
                                    {manage && (
                                        <button type="button" onClick={() => openCreate(day)} className="flex w-full items-center justify-center gap-1 rounded-xl border border-dashed border-line py-1.5 text-xs text-muted hover:border-brand-300 hover:text-brand">
                                            <Plus className="size-3.5" /> إضافة
                                        </button>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? 'تعديل حصة الجدول' : 'حصة جديدة في الجدول'}
                size="lg"
                footer={
                    <div className="flex w-full flex-wrap items-center justify-between gap-2">
                        <div>
                            {editing && editing.is_active && (
                                <ConfirmAction
                                    url={route('club.schedule.destroy', editing.id)}
                                    method="delete"
                                    title="إيقاف الحصة الأسبوعية"
                                    message="تُحذف المواعيد القادمة غير المحجوزة. المواعيد التي فيها حجوزات تبقى حتى تُلغيها يدويًا من صفحة الحصة."
                                    confirmLabel="إيقاف"
                                >
                                    إيقاف الحصة
                                </ConfirmAction>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
                            <Button type="submit" form="slot-form" loading={form.processing}>حفظ</Button>
                        </div>
                    </div>
                }
            >
                <form id="slot-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="البرنامج" error={form.errors.program_id} required className="sm:col-span-2">
                        <Select value={form.data.program_id} onChange={(e) => pickProgram(e.target.value)} required>
                            {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="اليوم" error={form.errors.weekday}>
                        <Select value={form.data.weekday} onChange={(e) => form.setData('weekday', Number(e.target.value))}>
                            {WEEK_ORDER.map((d) => <option key={d} value={d}>{WEEKDAYS[d]}</option>)}
                        </Select>
                    </Field>
                    <Field label="وقت البداية" error={form.errors.starts_at} hint={form.data.starts_at ? `تنتهي ${time(endTime(form.data.starts_at, Number(form.data.duration_minutes) || 0))}` : undefined}>
                        <Input type="time" dir="ltr" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="المدرب" error={form.errors.trainer_id}>
                        <Select value={form.data.trainer_id ?? ''} onChange={(e) => form.setData('trainer_id', e.target.value || null)} placeholder="— بدون —">
                            {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="المدة (دقيقة)" error={form.errors.duration_minutes}>
                        <Input type="number" min={10} max={300} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', e.target.value)} />
                    </Field>
                    <Field label="السعة القصوى" error={form.errors.capacity} hint="يتوقف الحجز عند اكتمالها وتظهر «مكتملة»">
                        <Input type="number" min={1} max={500} value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} />
                    </Field>
                    <Field label="القاعة" error={form.errors.room}>
                        <Input value={form.data.room} onChange={(e) => form.setData('room', e.target.value)} placeholder="مثال: الاستوديو 1" />
                    </Field>
                    {branches.length > 1 && (
                        <Field label="الفرع" error={form.errors.branch_id}>
                            <Select value={form.data.branch_id ?? ''} onChange={(e) => form.setData('branch_id', e.target.value || null)} placeholder="—">
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field label="يبدأ من" error={form.errors.valid_from} hint="فارغ = من اليوم">
                        <Input type="date" value={form.data.valid_from} onChange={(e) => form.setData('valid_from', e.target.value)} />
                    </Field>
                    <Field label="ينتهي في" error={form.errors.valid_until} hint="فارغ = مستمر">
                        <Input type="date" value={form.data.valid_until} onChange={(e) => form.setData('valid_until', e.target.value)} />
                    </Field>
                    {editing && (
                        <div className="sm:col-span-2">
                            <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="نشطة" hint="إيقافها يحذف المواعيد القادمة غير المحجوزة" />
                        </div>
                    )}
                    {editing && (
                        <p className="flex items-start gap-2 rounded-xl bg-canvas p-3 text-xs text-muted sm:col-span-2">
                            <Clock className="mt-0.5 size-3.5 shrink-0" />
                            عند الحفظ تُعاد بناء المواعيد القادمة غير المحجوزة. المواعيد المحجوزة تحتفظ بوقتها وتأخذ المدرب والقاعة والسعة الجديدة.
                        </p>
                    )}
                </form>
            </Modal>
        </ClubLayout>
    );
}
