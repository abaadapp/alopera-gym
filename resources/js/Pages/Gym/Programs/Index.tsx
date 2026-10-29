import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Clock, Dumbbell, Eye, EyeOff, ImagePlus, Pencil, Plus, Trash2, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardFooter, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
import { number } from '@/lib/format';

type Program = {
    id: number;
    name: string;
    slug: string;
    category: string;
    level: string;
    description: string | null;
    color: string;
    duration_minutes: number;
    default_capacity: number;
    is_public: boolean;
    is_active: boolean;
    sort: number;
    schedules_count: number;
    image_url: string | null;
};

const CATEGORIES: Record<string, string> = { class: 'حصة جماعية', program: 'برنامج تدريبي', pt: 'تدريب شخصي' };
const LEVELS: Record<string, string> = { all: 'كل المستويات', beginner: 'مبتدئ', intermediate: 'متوسط', advanced: 'متقدم' };

type FormData = {
    name: string;
    category: string;
    level: string;
    description: string;
    color: string;
    duration_minutes: number | string;
    default_capacity: number | string;
    is_public: boolean;
    is_active: boolean;
    sort: number | string;
    image: File | null;
};

const empty: FormData = {
    name: '',
    category: 'class',
    level: 'all',
    description: '',
    color: '#db2777',
    duration_minutes: 60,
    default_capacity: 15,
    is_public: true,
    is_active: true,
    sort: 0,
    image: null,
};

export default function ProgramsIndex({ programs }: { programs: Program[] }) {
    const [editing, setEditing] = useState<Program | null>(null);
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);
    const form = useForm<FormData>(empty);

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(empty);
        setPreview(null);
        setOpen(true);
    };

    const openEdit = (p: Program) => {
        setEditing(p);
        form.clearErrors();
        form.setData({
            name: p.name,
            category: p.category,
            level: p.level,
            description: p.description ?? '',
            color: p.color,
            duration_minutes: p.duration_minutes,
            default_capacity: p.default_capacity,
            is_public: p.is_public,
            is_active: p.is_active,
            sort: p.sort,
            image: null,
        });
        setPreview(p.image_url);
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(route('club.programs.update', editing.id), options);
        } else {
            form.transform((data) => data);
            form.post(route('club.programs.store'), options);
        }
    };

    const togglePublic = (p: Program) => {
        router.post(
            route('club.programs.update', p.id),
            {
                _method: 'put',
                name: p.name,
                category: p.category,
                level: p.level,
                description: p.description ?? '',
                color: p.color,
                duration_minutes: p.duration_minutes,
                default_capacity: p.default_capacity,
                is_public: !p.is_public,
                is_active: p.is_active,
                sort: p.sort,
            },
            { preserveScroll: true },
        );
    };

    return (
        <ClubLayout title="البرامج التدريبية">
            <PageHeader
                title="البرامج التدريبية"
                subtitle="أنواع الحصص والبرامج التي تظهر في الجدول والموقع وتُربط بالباقات"
                actions={<Button onClick={openCreate} icon={<Plus className="size-4" />}>برنامج جديد</Button>}
            />

            {programs.length === 0 ? (
                <Card>
                    <EmptyState icon={<Dumbbell className="size-6" />} title="لا توجد برامج بعد" description="أضف أول برنامج (زومبا، بيلاتس، تمارين قوة…) ثم أضفه للجدول الأسبوعي." action={<Button onClick={openCreate}>إضافة برنامج</Button>} />
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {programs.map((p) => (
                        <Card key={p.id} padded={false} fill className={p.is_active ? 'overflow-hidden' : 'overflow-hidden opacity-60'}>
                            <div className="relative h-32" style={{ background: `linear-gradient(135deg, ${p.color}, ${p.color}99)` }}>
                                {p.image_url && <img src={p.image_url} alt="" className="absolute inset-0 size-full object-cover" loading="lazy" />}
                                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/50 to-transparent p-3">
                                    <p className="font-display text-lg font-semibold text-white">{p.name}</p>
                                </div>
                                <div className="absolute top-3 start-3 flex gap-1.5">
                                    {!p.is_active && <Badge tone="gray">موقوف</Badge>}
                                    {p.is_public ? <Badge tone="green">ظاهر في الموقع</Badge> : <Badge tone="gray">مخفي</Badge>}
                                </div>
                            </div>
                            <div className="flex flex-1 flex-col p-5">
                                <div className="flex flex-wrap gap-1.5">
                                    <Badge tone="brand">{CATEGORIES[p.category] ?? p.category}</Badge>
                                    <Badge tone="violet">{LEVELS[p.level] ?? p.level}</Badge>
                                </div>
                                {p.description && <p className="mt-3 line-clamp-2 text-sm text-muted">{p.description}</p>}
                                <div className="mt-3 flex flex-wrap gap-4 text-xs text-muted">
                                    <span className="inline-flex items-center gap-1"><Clock className="size-3.5" /><span className="num">{p.duration_minutes}</span> دقيقة</span>
                                    <span className="inline-flex items-center gap-1"><Users className="size-3.5" />سعة <span className="num">{p.default_capacity}</span></span>
                                    <span>في الجدول: <span className="num">{number(p.schedules_count)}</span></span>
                                </div>
                                <CardFooter>
                                    <Button size="sm" variant="outline" icon={<Pencil className="size-3.5" />} onClick={() => openEdit(p)}>تعديل</Button>
                                    <Button size="sm" variant="ghost" icon={p.is_public ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />} onClick={() => togglePublic(p)}>
                                        {p.is_public ? 'إخفاء من الموقع' : 'إظهار في الموقع'}
                                    </Button>
                                    <ConfirmAction
                                        url={route('club.programs.destroy', p.id)}
                                        method="delete"
                                        title="حذف البرنامج"
                                        message="سيُحذف البرنامج من القوائم. لا يمكن حذفه إن كان مستخدمًا في الجدول الأسبوعي."
                                        variant="ghost"
                                        icon={<Trash2 className="size-3.5" />}
                                        confirmLabel="حذف"
                                    >
                                        حذف
                                    </ConfirmAction>
                                </CardFooter>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? `تعديل: ${editing.name}` : 'برنامج جديد'}
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
                        <Button type="submit" form="program-form" loading={form.processing}>حفظ</Button>
                    </>
                }
            >
                <form id="program-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="اسم البرنامج" error={form.errors.name} required className="sm:col-span-2">
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus />
                    </Field>
                    <Field label="النوع" error={form.errors.category}>
                        <Select value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                            {Object.entries(CATEGORIES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <Field label="المستوى" error={form.errors.level}>
                        <Select value={form.data.level} onChange={(e) => form.setData('level', e.target.value)}>
                            {Object.entries(LEVELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <Field label="المدة (دقيقة)" error={form.errors.duration_minutes}>
                        <Input type="number" min={10} max={300} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', e.target.value)} />
                    </Field>
                    <Field label="السعة الافتراضية" error={form.errors.default_capacity} hint="عدد المقاعد عند إضافة الحصة للجدول">
                        <Input type="number" min={1} max={500} value={form.data.default_capacity} onChange={(e) => form.setData('default_capacity', e.target.value)} />
                    </Field>
                    <Field label="اللون في الجدول" error={form.errors.color}>
                        <div className="flex items-center gap-2">
                            <input type="color" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} className="h-10 w-14 cursor-pointer rounded-lg border border-line" />
                            <Input value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} dir="ltr" className="font-mono" />
                        </div>
                    </Field>
                    <Field label="الترتيب" error={form.errors.sort}>
                        <Input type="number" value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} />
                    </Field>
                    <Field label="الوصف" error={form.errors.description} className="sm:col-span-2" hint="يظهر في صفحة البرامج على الموقع">
                        <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} />
                    </Field>
                    <Field label="صورة البرنامج" error={form.errors.image} className="sm:col-span-2" hint="تُضغط تلقائيًا لتسريع الموقع">
                        <label className="flex cursor-pointer items-center gap-4 rounded-xl border border-dashed border-line p-3 hover:border-brand-300">
                            {preview ? <img src={preview} alt="" className="size-16 rounded-lg object-cover" /> : <span className="grid size-16 place-items-center rounded-lg bg-brand-50 text-brand"><ImagePlus className="size-6" /></span>}
                            <span className="text-sm text-muted">اختر صورة (JPG/PNG/WebP)</span>
                            <input
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    form.setData('image', file);
                                    setPreview(file ? URL.createObjectURL(file) : editing?.image_url ?? null);
                                }}
                            />
                        </label>
                    </Field>
                    <div className="space-y-2 sm:col-span-2">
                        <Toggle checked={form.data.is_public} onChange={(v) => form.setData('is_public', v)} label="يظهر في الموقع" hint="صفحة البرامج وجدول الحصص العام" />
                        <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="نشط" hint="البرامج الموقوفة لا تُضاف للجدول" />
                    </div>
                </form>
            </Modal>
        </ClubLayout>
    );
}
