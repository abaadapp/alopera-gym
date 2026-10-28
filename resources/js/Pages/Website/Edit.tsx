import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ExternalLink, ImagePlus, Mail, Monitor, Plus, RotateCw, Smartphone, Trash2, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, Field, Input, PageHeader, Textarea, Toggle } from '@/Components/ui';
import { cn } from '@/lib/cn';

interface Settings {
    is_published: boolean;
    hero_title: string | null;
    hero_subtitle: string | null;
    hero_cta_label: string | null;
    hero_badge: string | null;
    about_title: string | null;
    about_body: string | null;
    announcement: string | null;
    contact_phone: string | null;
    whatsapp_number: string | null;
    contact_email: string | null;
    address: string | null;
    map_url: string | null;
    working_hours: { days: string; hours: string }[] | null;
    social_links: Record<string, string | null> | null;
    stats: { label: string; value: string }[] | null;
    features: { title: string; text: string }[];
    seo_title: string | null;
    seo_description: string | null;
    hero_image_url: string | null;
    about_image_url: string | null;
    og_image_url: string | null;
}

interface Media {
    id: number;
    title: string | null;
    in_gallery: boolean;
    sort: number;
    url: string | null;
}

interface Props {
    settings: Settings;
    media: Media[];
    siteUrl: string;
    unread: number;
}

const SOCIAL = [
    { key: 'instagram', label: 'إنستغرام' },
    { key: 'tiktok', label: 'تيك توك' },
    { key: 'snapchat', label: 'سناب شات' },
    { key: 'x', label: 'إكس (تويتر)' },
    { key: 'facebook', label: 'فيسبوك' },
];

type SiteForm = {
    is_published: boolean;
    hero_title: string;
    hero_subtitle: string;
    hero_cta_label: string;
    hero_badge: string;
    about_title: string;
    about_body: string;
    announcement: string;
    contact_phone: string;
    whatsapp_number: string;
    contact_email: string;
    address: string;
    map_url: string;
    working_hours: { days: string; hours: string }[];
    social_links: Record<string, string>;
    stats: { label: string; value: string }[];
    features: { title: string; text: string }[];
    seo_title: string;
    seo_description: string;
    hero_image: File | null;
    about_image: File | null;
    og_image: File | null;
};

export default function WebsiteEdit({ settings, media, siteUrl, unread }: Props) {
    const form = useForm<SiteForm>({
        is_published: settings.is_published,
        hero_title: settings.hero_title ?? '',
        hero_subtitle: settings.hero_subtitle ?? '',
        hero_cta_label: settings.hero_cta_label ?? '',
        hero_badge: settings.hero_badge ?? '',
        about_title: settings.about_title ?? '',
        about_body: settings.about_body ?? '',
        announcement: settings.announcement ?? '',
        contact_phone: settings.contact_phone ?? '',
        whatsapp_number: settings.whatsapp_number ?? '',
        contact_email: settings.contact_email ?? '',
        address: settings.address ?? '',
        map_url: settings.map_url ?? '',
        working_hours: settings.working_hours ?? [],
        social_links: Object.fromEntries(SOCIAL.map((s) => [s.key, settings.social_links?.[s.key] ?? ''])),
        stats: settings.stats ?? [],
        features: settings.features,
        seo_title: settings.seo_title ?? '',
        seo_description: settings.seo_description ?? '',
        hero_image: null,
        about_image: null,
        og_image: null,
    });

    const errors = form.errors as Record<string, string | undefined>;
    const set = <K extends keyof SiteForm>(key: K, value: SiteForm[K]) => form.setData(key, value as never);

    // Bumped after each save, so the preview shows what visitors now see.
    const [previewKey, setPreviewKey] = useState(0);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.website.update'), {
            preserveScroll: true,
            onSuccess: () => {
                // What was saved is the new starting point: the save bar goes away until the next change.
                const saved = { ...form.data, hero_image: null, about_image: null, og_image: null };
                form.setData(saved);
                form.setDefaults(saved);
                setPreviewKey((k) => k + 1);
            },
        });
    };

    const updateRow = <T extends Record<string, string>>(key: 'working_hours' | 'stats' | 'features', index: number, patch: Partial<T>) => {
        const rows = [...(form.data[key] as unknown as T[])];
        rows[index] = { ...rows[index], ...patch };
        set(key, rows as never);
    };

    return (
        <ClubLayout title="محتوى الموقع">
            <PageHeader
                title="محتوى الموقع الإلكتروني"
                subtitle="النصوص والصور وبيانات التواصل وتحسين الظهور في محركات البحث. الباقات والبرامج والمدربات تُعرض تلقائيًا من النظام."
                actions={
                    <>
                        <Button variant="outline" href={route('club.website.messages')} icon={<Mail className="size-4" />}>
                            رسائل التواصل {unread > 0 && <Badge tone="brand" className="num">{unread}</Badge>}
                        </Button>
                        <Button variant="outline" href={siteUrl} external icon={<ExternalLink className="size-4" />}>فتح الموقع</Button>
                    </>
                }
            />

            {/* The editor beside a live view of the site (wide screens); on smaller ones, «فتح الموقع» opens it. */}
            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="min-w-0">
                    <form onSubmit={submit} className="space-y-6">
                        <Card>
                            <CardHeader title="النشر" />
                            <Toggle checked={form.data.is_published} onChange={(v) => set('is_published', v)} label="الموقع منشور" hint="عند الإيقاف تبقى بوابة المشتركات تعمل" />
                        </Card>

                        <Card>
                            <CardHeader title="الواجهة الرئيسية" subtitle="أول ما تراه الزائرة" />
                            <div className="grid gap-4">
                                <Field label="شارة أعلى العنوان" error={errors.hero_badge} hint="اتركيها فارغة لإخفائها">
                                    <Input value={form.data.hero_badge} onChange={(e) => set('hero_badge', e.target.value)} maxLength={60} placeholder="نادٍ مخصص للنساء" />
                                </Field>
                                <Field label="العنوان الرئيسي" error={errors.hero_title}>
                                    <Input value={form.data.hero_title} onChange={(e) => set('hero_title', e.target.value)} maxLength={160} />
                                </Field>
                                <Field label="النص التعريفي" error={errors.hero_subtitle}>
                                    <Textarea rows={2} value={form.data.hero_subtitle} onChange={(e) => set('hero_subtitle', e.target.value)} maxLength={500} />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="نص زر الاشتراك" error={errors.hero_cta_label}>
                                        <Input value={form.data.hero_cta_label} onChange={(e) => set('hero_cta_label', e.target.value)} maxLength={40} placeholder="اشتركي الآن" />
                                    </Field>
                                    <Field label="شريط إعلان أعلى الموقع" error={errors.announcement} hint="اتركيه فارغًا لإخفائه">
                                        <Input value={form.data.announcement} onChange={(e) => set('announcement', e.target.value)} maxLength={200} placeholder="خصم 20% على الباقات السنوية هذا الشهر" />
                                    </Field>
                                </div>
                                <ImageField label="صورة الواجهة" current={settings.hero_image_url} file={form.data.hero_image} onChange={(f) => set('hero_image', f)} error={errors.hero_image} hint="يُفضّل 1920×1080، تُضغط تلقائيًا" />
                            </div>
                        </Card>

                        <Card>
                            <CardHeader title="عن النادي" />
                            <div className="grid gap-4">
                                <Field label="العنوان" error={errors.about_title}>
                                    <Input value={form.data.about_title} onChange={(e) => set('about_title', e.target.value)} maxLength={160} />
                                </Field>
                                <Field label="النص" error={errors.about_body}>
                                    <Textarea rows={6} value={form.data.about_body} onChange={(e) => set('about_body', e.target.value)} maxLength={5000} />
                                </Field>
                                <ImageField label="صورة القسم" current={settings.about_image_url} file={form.data.about_image} onChange={(f) => set('about_image', f)} error={errors.about_image} />
                            </div>
                        </Card>

                        <Card>
                            <CardHeader
                                title="لماذا نحن"
                                subtitle="ثلاث بطاقات أسفل الواجهة؛ احذفيها كلها لإخفاء القسم"
                                actions={form.data.features.length < 3 ? <Button type="button" size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => set('features', [...form.data.features, { title: '', text: '' }])}>إضافة</Button> : undefined}
                            />
                            <div className="space-y-3">
                                {form.data.features.map((f, i) => (
                                    <RowEditor key={i} onRemove={() => set('features', form.data.features.filter((_, j) => j !== i))}>
                                        <div className="grid flex-1 gap-2 sm:grid-cols-[12rem_1fr]">
                                            <Input value={f.title} onChange={(e) => updateRow('features', i, { title: e.target.value })} placeholder="العنوان" maxLength={40} aria-label={`عنوان البطاقة ${i + 1}`} />
                                            <Input value={f.text} onChange={(e) => updateRow('features', i, { text: e.target.value })} placeholder="الوصف" maxLength={140} aria-label={`وصف البطاقة ${i + 1}`} />
                                        </div>
                                    </RowEditor>
                                ))}
                            </div>
                            {(errors['features.0.title'] || errors['features.1.title'] || errors['features.2.title'] || errors['features.0.text'] || errors['features.1.text'] || errors['features.2.text']) && <p className="mt-2 text-sm text-red-600">أكملي عنوان ووصف كل بطاقة.</p>}
                        </Card>

                        <Card>
                            <CardHeader
                                title="أرقام مميزة"
                                subtitle="تظهر في الصفحة الرئيسية (حتى 4)"
                                actions={form.data.stats.length < 4 ? <Button type="button" size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => set('stats', [...form.data.stats, { label: '', value: '' }])}>إضافة</Button> : undefined}
                            />
                            {form.data.stats.length === 0 && <p className="text-sm text-muted">مثال: «+500 مشتركة»، «12 مدربة»، «30 حصة أسبوعيًا».</p>}
                            <div className="space-y-2">
                                {form.data.stats.map((s, i) => (
                                    <RowEditor key={i} onRemove={() => set('stats', form.data.stats.filter((_, j) => j !== i))}>
                                        <Input className="w-28" value={s.value} onChange={(e) => updateRow('stats', i, { value: e.target.value })} placeholder="+500" maxLength={20} />
                                        <Input value={s.label} onChange={(e) => updateRow('stats', i, { label: e.target.value })} placeholder="مشتركة سعيدة" maxLength={40} />
                                    </RowEditor>
                                ))}
                            </div>
                        </Card>

                        <Card>
                            <CardHeader title="التواصل والموقع" />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="هاتف التواصل" error={errors.contact_phone}>
                                    <Input dir="ltr" value={form.data.contact_phone} onChange={(e) => set('contact_phone', e.target.value)} placeholder="96891234567" />
                                </Field>
                                <Field label="رقم واتساب" error={errors.whatsapp_number}>
                                    <Input dir="ltr" value={form.data.whatsapp_number} onChange={(e) => set('whatsapp_number', e.target.value)} placeholder="96891234567" />
                                </Field>
                                <Field label="البريد الإلكتروني" error={errors.contact_email}>
                                    <Input type="email" dir="ltr" value={form.data.contact_email} onChange={(e) => set('contact_email', e.target.value)} />
                                </Field>
                                <Field label="العنوان" error={errors.address}>
                                    <Input value={form.data.address} onChange={(e) => set('address', e.target.value)} />
                                </Field>
                                <Field label="رابط الخريطة (Google Maps)" error={errors.map_url} className="sm:col-span-2">
                                    <Input dir="ltr" value={form.data.map_url} onChange={(e) => set('map_url', e.target.value)} placeholder="https://maps.google.com/…" />
                                </Field>
                            </div>

                            <div className="mt-5">
                                <div className="mb-2 flex items-center justify-between">
                                    <p className="label mb-0">أوقات العمل</p>
                                    {form.data.working_hours.length < 7 && (
                                        <Button type="button" size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => set('working_hours', [...form.data.working_hours, { days: '', hours: '' }])}>صف</Button>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    {form.data.working_hours.map((w, i) => (
                                        <RowEditor key={i} onRemove={() => set('working_hours', form.data.working_hours.filter((_, j) => j !== i))}>
                                            <Input value={w.days} onChange={(e) => updateRow('working_hours', i, { days: e.target.value })} placeholder="السبت – الخميس" />
                                            <Input value={w.hours} onChange={(e) => updateRow('working_hours', i, { hours: e.target.value })} placeholder="6 ص – 11 م" />
                                        </RowEditor>
                                    ))}
                                </div>
                                {errors['working_hours.0.days'] && <p className="mt-1 text-xs text-red-600">أكملي بيانات أوقات العمل.</p>}
                            </div>

                            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                {SOCIAL.map((s) => (
                                    <Field key={s.key} label={s.label} error={errors[`social_links.${s.key}`]}>
                                        <Input dir="ltr" value={form.data.social_links[s.key] ?? ''} onChange={(e) => set('social_links', { ...form.data.social_links, [s.key]: e.target.value })} placeholder="https://" />
                                    </Field>
                                ))}
                            </div>
                        </Card>

                        <Card>
                            <CardHeader title="محركات البحث (SEO)" subtitle="العنوان والوصف الظاهران في نتائج Google وروابط المشاركة" />
                            <div className="space-y-4">
                                <Field label={<Counter label="عنوان الصفحة" value={form.data.seo_title} max={70} ideal={60} />} error={errors.seo_title}>
                                    <Input value={form.data.seo_title} onChange={(e) => set('seo_title', e.target.value)} maxLength={70} />
                                </Field>
                                <Field label={<Counter label="الوصف" value={form.data.seo_description} max={300} ideal={160} />} error={errors.seo_description}>
                                    <Textarea rows={3} value={form.data.seo_description} onChange={(e) => set('seo_description', e.target.value)} maxLength={300} />
                                </Field>
                                <div className="rounded-xl border border-line p-3">
                                    <p className="truncate text-sm text-[#1a0dab]">{form.data.seo_title || form.data.hero_title || 'عنوان الموقع'}</p>
                                    <p className="truncate text-xs text-emerald-700" dir="ltr">{siteUrl}</p>
                                    <p className="line-clamp-2 text-xs text-muted">{form.data.seo_description || form.data.hero_subtitle || 'وصف الموقع'}</p>
                                </div>
                                <ImageField label="صورة المشاركة (OG)" current={settings.og_image_url} file={form.data.og_image} onChange={(f) => set('og_image', f)} error={errors.og_image} hint="1200×630 لروابط واتساب ووسائل التواصل" />
                            </div>
                        </Card>

                        {/* Shown while there are unsaved changes; stays at the bottom of the screen as the page scrolls. */}
                        {form.isDirty && (
                            <div className="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-white/95 px-4 py-3 shadow-[0_6px_24px_-8px_rgba(17,17,17,0.10)] backdrop-blur">
                                <p className="text-sm text-muted">لديك تغييرات غير محفوظة</p>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" onClick={() => form.reset()} disabled={form.processing}>تجاهل</Button>
                                    <Button type="submit" loading={form.processing}>حفظ التغييرات</Button>
                                </div>
                            </div>
                        )}
                    </form>

                    <Gallery media={media} />
                </div>

                <SitePreview url={siteUrl} reloadKey={previewKey} published={settings.is_published} />
            </div>
        </ClubLayout>
    );
}

const DEVICES = [
    { key: 'desktop', label: 'كمبيوتر', icon: Monitor, width: 1280 },
    { key: 'mobile', label: 'جوال', icon: Smartphone, width: 390 },
] as const;

/**
 * The club's site as visitors see it, beside the editor: drawn at a real desktop or phone width and scaled to fit
 * the panel. It shows the saved site and reloads after each save.
 */
function SitePreview({ url, reloadKey, published }: { url: string; reloadKey: number; published: boolean }) {
    const [device, setDevice] = useState<(typeof DEVICES)[number]['key']>('desktop');
    const [manualReload, setManualReload] = useState(0);
    const box = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });

    useEffect(() => {
        const el = box.current;
        if (!el) return;
        const observer = new ResizeObserver(([entry]) => setSize({ width: entry.contentRect.width, height: entry.contentRect.height }));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const width = DEVICES.find((d) => d.key === device)!.width;
    const scale = size.width ? Math.min(1, size.width / width) : 1;

    return (
        <aside className="sticky top-[5.5rem] hidden h-[calc(100vh-7rem)] min-h-[32rem] flex-col xl:flex" aria-label="معاينة الموقع">
            <div className="mb-3 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <p className="text-sm font-semibold text-ink">المعاينة</p>
                    {!published && <Badge tone="amber">غير منشور</Badge>}
                </div>
                <div className="flex items-center gap-1">
                    <div className="flex rounded-[10px] bg-[#f2f2f0] p-0.5">
                        {DEVICES.map((d) => (
                            <button
                                key={d.key}
                                type="button"
                                onClick={() => setDevice(d.key)}
                                aria-pressed={device === d.key}
                                title={d.label}
                                className={cn('grid size-8 place-items-center rounded-lg transition', device === d.key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink')}
                            >
                                <d.icon className="size-4" />
                                <span className="sr-only">{d.label}</span>
                            </button>
                        ))}
                    </div>
                    <button type="button" onClick={() => setManualReload((n) => n + 1)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" title="تحديث المعاينة">
                        <RotateCw className="size-4" />
                    </button>
                    <a href={url} target="_blank" rel="noreferrer" className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" title="فتح الموقع">
                        <ExternalLink className="size-4" />
                    </a>
                </div>
            </div>
            <div ref={box} className="relative flex-1 overflow-hidden rounded-2xl border border-line bg-white">
                {size.width > 0 && (
                    <iframe
                        key={`${reloadKey}-${manualReload}`}
                        src={url}
                        title="معاينة الموقع"
                        className="absolute top-0 border-0"
                        // Scaled from the top centre: a phone-width view sits in the middle of the panel.
                        style={{ width, height: size.height / scale, left: '50%', transform: `translateX(-50%) scale(${scale})`, transformOrigin: 'top center' }}
                    />
                )}
            </div>
        </aside>
    );
}

function Counter({ label, value, max, ideal }: { label: string; value: string; max: number; ideal: number }) {
    const n = value.length;
    return (
        <span className="flex w-full items-center justify-between">
            <span>{label}</span>
            <span className={`text-xs num ${n > ideal ? 'text-amber-600' : 'text-muted'}`}>{n}/{max}</span>
        </span>
    );
}

function RowEditor({ children, onRemove }: { children: ReactNode; onRemove: () => void }) {
    return (
        <div className="flex items-center gap-2">
            {children}
            <button type="button" onClick={onRemove} className="grid size-9 shrink-0 place-items-center rounded-lg text-red-600 hover:bg-red-50" aria-label="حذف">
                <X className="size-4" />
            </button>
        </div>
    );
}

function ImageField({ label, current, file, onChange, error, hint }: { label: string; current: string | null; file: File | null; onChange: (f: File | null) => void; error?: string; hint?: string }) {
    const preview = useMemo(() => (file ? URL.createObjectURL(file) : current), [file, current]);
    return (
        <Field label={label} error={error} hint={hint}>
            <div className="flex items-center gap-3">
                <div className="grid h-20 w-32 shrink-0 place-items-center overflow-hidden rounded-xl border border-dashed border-line bg-canvas">
                    {preview ? <img src={preview} alt="" className="h-full w-full object-cover" /> : <ImagePlus className="size-5 text-muted" />}
                </div>
                <div className="flex flex-col gap-1.5">
                    <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-xs hover:bg-brand-50">
                        <ImagePlus className="size-3.5" /> {preview ? 'تغيير الصورة' : 'اختيار صورة'}
                        <input type="file" accept="image/*" className="hidden" onChange={(e) => onChange(e.target.files?.[0] ?? null)} />
                    </label>
                    {file && <button type="button" className="text-start text-xs text-muted hover:text-red-600" onClick={() => onChange(null)}>إلغاء الاختيار</button>}
                </div>
            </div>
        </Field>
    );
}

function Gallery({ media }: { media: Media[] }) {
    const [files, setFiles] = useState<File[]>([]);
    const [title, setTitle] = useState('');
    const [busy, setBusy] = useState(false);

    const upload = () => {
        if (files.length === 0) return;
        setBusy(true);
        router.post(route('club.website.media.store'), { images: files, title }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setFiles([]);
                setTitle('');
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card className="mt-6">
            <CardHeader title="معرض الصور" subtitle="تظهر في الصفحة الرئيسية؛ تُحوَّل إلى WebP ويُصغَّر حجمها تلقائيًا لسرعة التحميل" />
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-brand-300 px-4 py-2.5 text-sm text-brand-700 hover:bg-brand-50">
                    <ImagePlus className="size-4" /> {files.length ? `${files.length} صورة مختارة` : 'اختيار صور (حتى 12)'}
                    <input type="file" accept="image/*" multiple className="hidden" onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 12))} />
                </label>
                <Input className="w-56" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="وصف اختياري" />
                <Button type="button" onClick={upload} loading={busy} disabled={files.length === 0}>رفع</Button>
            </div>
            {media.length === 0 ? (
                <p className="text-sm text-muted">لا توجد صور بعد.</p>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    {media.map((m) => (
                        <div key={m.id} className="group relative aspect-square overflow-hidden rounded-xl border border-line">
                            {m.url && <img src={m.url} alt={m.title ?? ''} className="h-full w-full object-cover" loading="lazy" />}
                            <div className="absolute inset-x-1 bottom-1 opacity-0 transition group-hover:opacity-100">
                                <ConfirmAction url={route('club.website.media.destroy', m.id)} method="delete" title="حذف الصورة" message="ستُحذف الصورة من الموقع." variant="danger" icon={<Trash2 className="size-3.5" />}>
                                    حذف
                                </ConfirmAction>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}

