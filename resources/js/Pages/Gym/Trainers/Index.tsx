import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { CalendarClock, ImagePlus, Link2, Mail, Pencil, Phone, Plus, Trash2, UserRound, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardFooter, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
import { WEEKDAYS, phone as fmtPhone } from '@/lib/format';
import type { Option } from '@/types';

type Slot = { weekday: number; starts_at: string; ends_at: string };

type Trainer = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    bio: string | null;
    specialties: string[] | null;
    color: string | null;
    is_public: boolean;
    is_active: boolean;
    sort: number;
    user_id: number | null;
    branch_id: number | null;
    photo_url: string | null;
    user: { id: number; name: string; email: string } | null;
    availability: Slot[];
};

type TrainerForm = {
    name: string;
    phone: string;
    email: string;
    bio: string;
    specialties: string[];
    color: string;
    is_public: boolean;
    is_active: boolean;
    sort: number | string;
    branch_id: number | string | null;
    user_id: number | string | null;
    photo: File | null;
};

const blank: TrainerForm = {
    name: '',
    phone: '',
    email: '',
    bio: '',
    specialties: [],
    color: '#be185d',
    is_public: true,
    is_active: true,
    sort: 0,
    branch_id: null,
    user_id: null,
    photo: null,
};

/** Saturday first, as the club week reads. */
const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5];

export default function TrainersIndex({ trainers, users, branches }: { trainers: Trainer[]; users: (Option & { email: string })[]; branches: Option[] }) {
    const [editing, setEditing] = useState<Trainer | null>(null);
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);
    const [tag, setTag] = useState('');
    const [availabilityFor, setAvailabilityFor] = useState<Trainer | null>(null);
    const form = useForm<TrainerForm>(blank);
    const slotsForm = useForm<{ slots: Slot[] }>({ slots: [] });

    const linkedUserIds = new Set(trainers.filter((t) => t.user_id && t.id !== editing?.id).map((t) => t.user_id));

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(blank);
        setPreview(null);
        setOpen(true);
    };

    const openEdit = (t: Trainer) => {
        setEditing(t);
        form.clearErrors();
        form.setData({
            name: t.name,
            phone: t.phone ?? '',
            email: t.email ?? '',
            bio: t.bio ?? '',
            specialties: t.specialties ?? [],
            color: t.color ?? '#be185d',
            is_public: t.is_public,
            is_active: t.is_active,
            sort: t.sort,
            branch_id: t.branch_id,
            user_id: t.user_id,
            photo: null,
        });
        setPreview(t.photo_url);
        setOpen(true);
    };

    const addTag = () => {
        const v = tag.trim();
        if (v && !form.data.specialties.includes(v)) form.setData('specialties', [...form.data.specialties, v]);
        setTag('');
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(route('club.trainers.update', editing.id), options);
        } else {
            form.transform((data) => data);
            form.post(route('club.trainers.store'), options);
        }
    };

    const openAvailability = (t: Trainer) => {
        slotsForm.clearErrors();
        slotsForm.setData('slots', t.availability.map((s) => ({ ...s })));
        setAvailabilityFor(t);
    };

    const updateSlot = (index: number, patch: Partial<Slot>) => {
        slotsForm.setData('slots', slotsForm.data.slots.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    };

    const saveAvailability = () => {
        if (!availabilityFor) return;
        slotsForm.put(route('club.trainers.availability', availabilityFor.id), { preserveScroll: true, onSuccess: () => setAvailabilityFor(null) });
    };

    return (
        <ClubLayout title="فريق التدريب">
            <PageHeader
                title="فريق التدريب"
                subtitle="الملفات التعريفية، أوقات التوفر، وربط حساب الدخول للوحة المدرب"
                actions={<Button onClick={openCreate} icon={<Plus className="size-4" />}>مدرب جديد</Button>}
            />

            {trainers.length === 0 ? (
                <Card>
                    <EmptyState icon={<UserRound className="size-6" />} title="لا يوجد مدربون بعد" action={<Button onClick={openCreate}>إضافة مدرب</Button>} />
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {trainers.map((t) => (
                        <Card key={t.id} fill className={t.is_active ? '' : 'opacity-60'}>
                            <div className="flex items-start gap-4">
                                {t.photo_url ? (
                                    <img src={t.photo_url} alt="" className="size-16 rounded-2xl object-cover" loading="lazy" />
                                ) : (
                                    <span className="grid size-16 place-items-center rounded-2xl font-display text-xl text-white" style={{ background: t.color ?? 'var(--brand)' }}>{t.name[0]}</span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[15px] font-semibold text-[#111]">{t.name}</p>
                                    <div className="mt-1 flex flex-wrap gap-1.5">
                                        {t.is_public ? <Badge tone="green">في الموقع</Badge> : <Badge tone="gray">مخفي</Badge>}
                                        {!t.is_active && <Badge tone="gray">موقوف</Badge>}
                                        {t.user ? <Badge tone="blue"><Link2 className="size-3.5" />{t.user.name}</Badge> : <Badge tone="amber">بدون حساب دخول</Badge>}
                                    </div>
                                </div>
                            </div>
                            {(t.specialties ?? []).length > 0 && (
                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    {(t.specialties ?? []).map((s) => <span key={s} className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs text-brand-700">{s}</span>)}
                                </div>
                            )}
                            <div className="mt-3 space-y-1 text-xs text-muted">
                                {t.phone && <p className="flex items-center gap-1.5"><Phone className="size-3.5" /><span className="num">{fmtPhone(t.phone)}</span></p>}
                                {t.email && <p className="flex items-center gap-1.5"><Mail className="size-3.5" />{t.email}</p>}
                            </div>
                            <div className="mt-3 rounded-xl bg-canvas p-3">
                                <p className="mb-1.5 text-xs font-medium text-muted">أوقات التوفر</p>
                                {t.availability.length === 0 ? (
                                    <p className="text-xs text-muted">لم تُحدَّد — لا يمكن جدولة جلسات فردية له.</p>
                                ) : (
                                    <ul className="space-y-0.5 text-xs">
                                        {[...t.availability].sort((a, b) => WEEK_ORDER.indexOf(a.weekday) - WEEK_ORDER.indexOf(b.weekday) || a.starts_at.localeCompare(b.starts_at)).map((s, i) => (
                                            <li key={i} className="flex justify-between gap-2"><span>{WEEKDAYS[s.weekday]}</span><span className="num">{s.starts_at} – {s.ends_at}</span></li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            <CardFooter>
                                <Button size="sm" variant="outline" icon={<Pencil className="size-3.5" />} onClick={() => openEdit(t)}>تعديل</Button>
                                <Button size="sm" variant="secondary" icon={<CalendarClock className="size-3.5" />} onClick={() => openAvailability(t)}>أوقات التوفر</Button>
                                <ConfirmAction url={route('club.trainers.destroy', t.id)} method="delete" title="حذف المدرب" message="سيُخفى من الموقع والقوائم. الحصص السابقة لا تتأثر." variant="ghost" icon={<Trash2 className="size-3.5" />} confirmLabel="حذف">
                                    حذف
                                </ConfirmAction>
                            </CardFooter>
                        </Card>
                    ))}
                </div>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? `تعديل: ${editing.name}` : 'مدرب جديد'}
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
                        <Button type="submit" form="trainer-form" loading={form.processing}>حفظ</Button>
                    </>
                }
            >
                <form id="trainer-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="الاسم" error={form.errors.name} required className="sm:col-span-2">
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus />
                    </Field>
                    <Field label="الهاتف" error={form.errors.phone}>
                        <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                    </Field>
                    <Field label="البريد" error={form.errors.email}>
                        <Input dir="ltr" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    </Field>
                    <Field label="حساب الدخول (لوحة المدرب)" error={form.errors.user_id} hint="اربطه بمستخدم بدور «المدرب» ليرى حصصه ويسجّل الحضور">
                        <Select value={form.data.user_id ?? ''} onChange={(e) => form.setData('user_id', e.target.value || null)} placeholder="— بدون —">
                            {users.filter((u) => !linkedUserIds.has(u.id)).map((u) => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                        </Select>
                    </Field>
                    <Field label="الفرع" error={form.errors.branch_id}>
                        <Select value={form.data.branch_id ?? ''} onChange={(e) => form.setData('branch_id', e.target.value || null)} placeholder="كل الفروع">
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="التخصصات" error={form.errors.specialties} className="sm:col-span-2">
                        <div className="flex gap-2">
                            <Input value={tag} onChange={(e) => setTag(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } }} placeholder="مثال: يوغا، بيلاتس، تغذية…" />
                            <Button type="button" variant="secondary" onClick={addTag}>إضافة</Button>
                        </div>
                        {form.data.specialties.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {form.data.specialties.map((s) => (
                                    <span key={s} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700">
                                        {s}
                                        <button type="button" onClick={() => form.setData('specialties', form.data.specialties.filter((x) => x !== s))} aria-label="إزالة"><X className="size-3" /></button>
                                    </span>
                                ))}
                            </div>
                        )}
                    </Field>
                    <Field label="نبذة" error={form.errors.bio} className="sm:col-span-2" hint="تظهر في صفحة فريق التدريب على الموقع">
                        <Textarea value={form.data.bio} onChange={(e) => form.setData('bio', e.target.value)} rows={3} />
                    </Field>
                    <Field label="اللون" error={form.errors.color}>
                        <input type="color" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} className="h-10 w-full cursor-pointer rounded-xl border border-line" />
                    </Field>
                    <Field label="الترتيب" error={form.errors.sort}>
                        <Input type="number" value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} />
                    </Field>
                    <Field label="الصورة" error={form.errors.photo} className="sm:col-span-2">
                        <label className="flex cursor-pointer items-center gap-4 rounded-xl border border-dashed border-line p-3 hover:border-brand-300">
                            {preview ? <img src={preview} alt="" className="size-16 rounded-xl object-cover" /> : <span className="grid size-16 place-items-center rounded-xl bg-brand-50 text-brand"><ImagePlus className="size-6" /></span>}
                            <span className="text-sm text-muted">اختر صورة</span>
                            <input type="file" accept="image/*" className="hidden" onChange={(e) => {
                                const file = e.target.files?.[0] ?? null;
                                form.setData('photo', file);
                                setPreview(file ? URL.createObjectURL(file) : editing?.photo_url ?? null);
                            }} />
                        </label>
                    </Field>
                    <div className="space-y-2 sm:col-span-2">
                        <Toggle checked={form.data.is_public} onChange={(v) => form.setData('is_public', v)} label="يظهر في الموقع" />
                        <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="نشط" />
                    </div>
                </form>
            </Modal>

            <Modal
                open={availabilityFor !== null}
                onClose={() => setAvailabilityFor(null)}
                title={`أوقات توفر ${availabilityFor?.name ?? ''}`}
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setAvailabilityFor(null)}>إلغاء</Button>
                        <Button onClick={saveAvailability} loading={slotsForm.processing}>حفظ الأوقات</Button>
                    </>
                }
            >
                <p className="mb-3 text-sm text-muted">تُستخدم لجدولة الجلسات الفردية (PT): لا يُقبل موعد خارج هذه الأوقات.</p>
                <div className="space-y-2">
                    {slotsForm.data.slots.length === 0 && <p className="rounded-xl bg-canvas p-4 text-center text-sm text-muted">لا توجد أوقات. أضف فترة.</p>}
                    {slotsForm.data.slots.map((s, i) => (
                        <div key={i} className="grid grid-cols-[1fr_auto_auto_auto] items-center gap-2 rounded-xl border border-line p-2 sm:grid-cols-[1fr_8rem_8rem_auto]">
                            <Select value={s.weekday} onChange={(e) => updateSlot(i, { weekday: Number(e.target.value) })}>
                                {WEEK_ORDER.map((d) => <option key={d} value={d}>{WEEKDAYS[d]}</option>)}
                            </Select>
                            <Input type="time" value={s.starts_at} onChange={(e) => updateSlot(i, { starts_at: e.target.value })} dir="ltr" />
                            <Input type="time" value={s.ends_at} onChange={(e) => updateSlot(i, { ends_at: e.target.value })} dir="ltr" />
                            <button type="button" className="grid size-9 place-items-center rounded-lg text-muted hover:bg-red-50 hover:text-red-600" onClick={() => slotsForm.setData('slots', slotsForm.data.slots.filter((_, j) => j !== i))} aria-label="حذف">
                                <Trash2 className="size-4" />
                            </button>
                            {(slotsForm.errors as Record<string, string>)[`slots.${i}.ends_at`] && (
                                <p className="col-span-full text-xs text-red-600">{(slotsForm.errors as Record<string, string>)[`slots.${i}.ends_at`]}</p>
                            )}
                        </div>
                    ))}
                </div>
                <Button type="button" variant="secondary" className="mt-3" icon={<Plus className="size-4" />} onClick={() => slotsForm.setData('slots', [...slotsForm.data.slots, { weekday: 6, starts_at: '09:00', ends_at: '13:00' }])}>
                    إضافة فترة
                </Button>
            </Modal>
        </ClubLayout>
    );
}
