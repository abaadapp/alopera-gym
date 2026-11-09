import { Link } from '@inertiajs/react';
import { Card, CardHeader, Field, Input } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { checkHref, contrastRatio, isHexColor, THEME_PRESETS, themeWarnings } from '@/lib/site-builder';
import type { FieldSchema, SiteDoc } from '@/types/site';
import { useBuilder } from './context';
import FieldInput from './FieldInput';

type Patch = (fn: (doc: SiteDoc) => SiteDoc) => void;

const LINK_FIELDS: FieldSchema[] = [
    { key: 'label', type: 'text', label: 'النص', max: 30 },
    { key: 'href', type: 'href', label: 'يفتح' },
];

/** «القائمة العلوية والتذييل»: the menu's links and button, and the footer's line, links and what it shows. */
export function MenuPanel({ doc, patch }: { doc: SiteDoc; patch: Patch }) {
    const set = <K extends keyof SiteDoc['header']>(key: K, v: SiteDoc['header'][K]) => patch((d) => ({ ...d, header: { ...d.header, [key]: v } }));
    const setFooter = <K extends keyof SiteDoc['footer']>(key: K, v: SiteDoc['footer'][K]) => patch((d) => ({ ...d, footer: { ...d.footer, [key]: v } }));
    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card className="space-y-4">
                <CardHeader title="القائمة العلوية" subtitle="الروابط التي تظهر أعلى كل صفحة. رابط لقسم مخفي لا يظهر للزوار." className="mb-0" />
                <FieldInput field={{ key: 'links', type: 'list', label: 'روابط القائمة', item: 'رابط', max: 9, fields: LINK_FIELDS }} value={doc.header.links} onChange={(v) => set('links', v as SiteDoc['header']['links'])} path="header" />
                <FieldInput field={{ key: 'cta', type: 'link', label: 'الزر البارز في القائمة', help: 'مثل «انضمي الآن». اترك النص فارغًا لإخفائه.' }} value={doc.header.cta} onChange={(v) => set('cta', v as SiteDoc['header']['cta'])} path="header" />
                <FieldInput field={{ key: 'show_login', type: 'toggle', label: 'إظهار «دخول العضوات» في القائمة' }} value={doc.header.show_login} onChange={(v) => set('show_login', v as boolean)} path="header" />
            </Card>
            <Card className="space-y-4">
                <CardHeader title="التذييل" subtitle="أسفل كل صفحة: الشعار ونبذة قصيرة وروابط ومعلومات التواصل." className="mb-0" />
                <FieldInput field={{ key: 'line', type: 'textarea', label: 'نبذة قصيرة', max: 300, rows: 2 }} value={doc.footer.line} onChange={(v) => setFooter('line', v as string)} path="footer" />
                <FieldInput field={{ key: 'links', type: 'list', label: 'روابط التذييل', item: 'رابط', max: 12, fields: LINK_FIELDS }} value={doc.footer.links} onChange={(v) => setFooter('links', v as SiteDoc['footer']['links'])} path="footer" />
                <FieldInput field={{ key: 'show_hours', type: 'toggle', label: 'إظهار أوقات العمل' }} value={doc.footer.show_hours} onChange={(v) => setFooter('show_hours', v as boolean)} path="footer" />
                <FieldInput field={{ key: 'show_social', type: 'toggle', label: 'إظهار روابط التواصل الاجتماعي' }} value={doc.footer.show_social} onChange={(v) => setFooter('show_social', v as boolean)} path="footer" />
            </Card>
        </div>
    );
}

const SOCIAL: { key: keyof SiteDoc['contact']['social']; label: string; placeholder: string }[] = [
    { key: 'instagram', label: 'إنستغرام', placeholder: 'https://www.instagram.com/…' },
    { key: 'tiktok', label: 'تيك توك', placeholder: 'https://www.tiktok.com/@…' },
    { key: 'snapchat', label: 'سناب شات', placeholder: 'https://www.snapchat.com/add/…' },
    { key: 'x', label: 'إكس (تويتر)', placeholder: 'https://x.com/…' },
    { key: 'facebook', label: 'فيسبوك', placeholder: 'https://www.facebook.com/…' },
    { key: 'youtube', label: 'يوتيوب', placeholder: 'https://www.youtube.com/@…' },
];

const EMAIL = /^[^\s@<>()"',;:]+@[^\s@<>()"',;:]+\.[^\s@<>()"',;:]{2,}$/;

/** A web address that must be http(s) (the map, social pages), checked as it is typed. */
function webError(value: string): string | undefined {
    if (!value) return undefined;
    if (!/^https?:\/\//i.test(value)) return 'الرابط يبدأ بـ https://';
    return checkHref(value) ?? undefined;
}

/** «التواصل وأوقات العمل»: phone, WhatsApp, email, address, map, opening hours and social pages. */
export function ContactPanel({ doc, patch }: { doc: SiteDoc; patch: Patch }) {
    const { errors } = useBuilder();
    const c = doc.contact;
    const set = <K extends keyof SiteDoc['contact']>(key: K, v: SiteDoc['contact'][K]) => patch((d) => ({ ...d, contact: { ...d.contact, [key]: v } }));
    const phoneHint = 'بالأرقام مع رمز الدولة، مثل 96890000000';
    const phoneError = (v: string) => (v && !/^\+?[0-9 ]{6,20}$/.test(v) ? 'أرقام فقط مع رمز الدولة، مثل 96890000000' : undefined);

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card className="space-y-4">
                <CardHeader title="بيانات التواصل" subtitle="تظهر في قسم «معلومات التواصل» وفي التذييل، وتُحدَّث في الموقع عند النشر." className="mb-0" />
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="الهاتف" hint={phoneHint} error={errors['contact.phone'] ?? phoneError(c.phone)}>
                        <Input dir="ltr" type="tel" inputMode="tel" maxLength={20} value={c.phone} onChange={(e) => set('phone', e.target.value)} />
                    </Field>
                    <Field label="واتساب" hint={phoneHint} error={errors['contact.whatsapp'] ?? phoneError(c.whatsapp)}>
                        <Input dir="ltr" type="tel" inputMode="tel" maxLength={20} value={c.whatsapp} onChange={(e) => set('whatsapp', e.target.value)} />
                    </Field>
                </div>
                <Field label="البريد الإلكتروني" error={errors['contact.email'] ?? (c.email && !EMAIL.test(c.email) ? 'البريد الإلكتروني غير صحيح.' : undefined)}>
                    <Input dir="ltr" type="email" maxLength={255} value={c.email} onChange={(e) => set('email', e.target.value.trim())} />
                </Field>
                <Field label="العنوان" error={errors['contact.address']}>
                    <Input maxLength={255} value={c.address} onChange={(e) => set('address', e.target.value)} />
                </Field>
                <Field label="رابط الموقع على الخريطة" hint="انسخ رابط المشاركة من خرائط Google." error={errors['contact.map_url'] ?? webError(c.map_url)}>
                    <Input dir="ltr" maxLength={1000} value={c.map_url} placeholder="https://maps.app.goo.gl/…" onChange={(e) => set('map_url', e.target.value.trim())} />
                </Field>
            </Card>
            <div className="space-y-4">
                <Card className="space-y-3">
                    <CardHeader title="أوقات العمل" subtitle="مثل: «السبت – الخميس» و«٦ص – ١١م»." className="mb-0" />
                    <FieldInput
                        field={{ key: 'hours', type: 'list', label: 'الأوقات', item: 'وقت', max: 7, fields: [{ key: 'days', type: 'text', label: 'الأيام', max: 60 }, { key: 'hours', type: 'text', label: 'الساعات', max: 60 }] }}
                        value={c.hours}
                        onChange={(v) => set('hours', v as SiteDoc['contact']['hours'])}
                        path="contact"
                    />
                </Card>
                <Card className="space-y-3">
                    <CardHeader title="حسابات التواصل الاجتماعي" subtitle="اترك الحقل فارغًا لإخفاء الحساب." className="mb-0" />
                    <div className="grid gap-3 sm:grid-cols-2">
                        {SOCIAL.map((s) => (
                            <Field key={s.key} label={s.label} error={errors[`contact.social.${s.key}`] ?? webError(c.social[s.key])}>
                                <Input dir="ltr" maxLength={255} value={c.social[s.key]} placeholder={s.placeholder} onChange={(e) => set('social', { ...c.social, [s.key]: e.target.value.trim() })} />
                            </Field>
                        ))}
                    </div>
                </Card>
            </div>
        </div>
    );
}

const COLORS: { key: keyof SiteDoc['theme']; label: string; hint: string }[] = [
    { key: 'accent', label: 'اللون المميز', hint: 'العناوين الصغيرة والروابط ولمسات التصميم.' },
    { key: 'ink', label: 'لون النصوص والخلفيات الداكنة', hint: 'النصوص والأزرار والواجهة والتذييل.' },
    { key: 'surface', label: 'لون الأقسام الفاتحة', hint: 'خلفية الأقسام «البيج».' },
];

/** «الألوان»: three colours of the site, ready sets that keep the text readable, and a warning when it is not. */
export function ThemePanel({ doc, patch }: { doc: SiteDoc; patch: Patch }) {
    const { errors } = useBuilder();
    const t = doc.theme;
    const set = (key: keyof SiteDoc['theme'], v: string) => patch((d) => ({ ...d, theme: { ...d.theme, [key]: v } }));
    const warnings = themeWarnings(t);
    const valid = isHexColor(t.accent) && isHexColor(t.ink) && isHexColor(t.surface);
    const can = useCan();

    return (
        <div className="grid gap-4 xl:grid-cols-[1fr_minmax(280px,380px)]">
            <Card className="space-y-2 xl:col-span-2">
                <CardHeader title="الشعار" subtitle="يعرض الموقع شعار النادي الرسمي كما هو، دون تمديد أو قص. والشعار نفسه يظهر في لوحة النادي والفواتير." className="mb-0" />
                {can('settings.manage') ? (
                    <p className="text-sm">
                        لتغييره ارفع ملف الشعار الجديد من{' '}
                        <Link href={route('club.settings.edit')} className="font-medium text-brand underline underline-offset-4">إعدادات النادي</Link>
                        ، فيظهر في الموقع مباشرة دون نشر.
                    </p>
                ) : (
                    <p className="text-sm text-muted">تغيير الشعار من «إعدادات النادي»، ويحتاج صلاحية إدارة الإعدادات.</p>
                )}
            </Card>
            <Card className="space-y-4">
                <CardHeader title="ألوان الموقع" subtitle="تخص الموقع العام فقط، ولا تغيّر ألوان لوحة النادي." className="mb-0" />
                <div>
                    <span className="label">مجموعات جاهزة</span>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {THEME_PRESETS.map((p) => {
                            const active = p.theme.accent === t.accent && p.theme.ink === t.ink && p.theme.surface === t.surface;
                            return (
                                <button
                                    key={p.label}
                                    type="button"
                                    onClick={() => patch((d) => ({ ...d, theme: { ...p.theme } }))}
                                    aria-pressed={active}
                                    className={`flex items-center gap-2 rounded-xl border p-2.5 text-start text-[13px] font-medium transition ${active ? 'border-ink ring-2 ring-ink/10' : 'border-line hover:border-[#9ca3af]'}`}
                                >
                                    <span className="flex shrink-0 overflow-hidden rounded-full border border-line">
                                        {[p.theme.ink, p.theme.accent, p.theme.surface].map((c) => <span key={c} className="size-5" style={{ background: c }} />)}
                                    </span>
                                    {p.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    {COLORS.map((c) => (
                        <Field key={c.key} label={c.label} hint={c.hint} error={errors[`theme.${c.key}`] ?? (isHexColor(t[c.key]) ? undefined : 'اكتب اللون هكذا: #8A6700')}>
                            <div className="flex items-center gap-2">
                                <input type="color" value={isHexColor(t[c.key]) ? t[c.key] : '#000000'} onChange={(e) => set(c.key, e.target.value.toUpperCase())} className="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-line bg-white p-1" aria-label={c.label} />
                                <Input dir="ltr" value={t[c.key]} maxLength={7} onChange={(e) => set(c.key, e.target.value.trim())} className="font-mono" />
                            </div>
                        </Field>
                    ))}
                </div>
                {warnings.length > 0 && (
                    <ul className="space-y-1 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-900">
                        {warnings.map((w) => <li key={w}>{w}</li>)}
                    </ul>
                )}
            </Card>
            <Card>
                <CardHeader title="معاينة سريعة" className="mb-3" />
                {valid ? (
                    <div className="overflow-hidden rounded-xl border border-line text-[13px]">
                        <div className="px-4 py-5" style={{ background: t.ink, color: '#FFFFFF' }}>
                            <p style={{ color: t.surface }} className="text-[12px]">سطر تمهيدي</p>
                            <p className="mt-1 text-lg font-bold">عنوان الواجهة</p>
                            <span className="mt-3 inline-block rounded-lg bg-white px-3 py-1.5 font-semibold" style={{ color: t.ink }}>زر رئيسي</span>
                        </div>
                        <div className="bg-white px-4 py-4" style={{ color: t.ink }}>
                            <p className="text-[12px] font-semibold" style={{ color: t.accent }}>عنوان صغير</p>
                            <p className="mt-1 font-bold">عنوان قسم أبيض</p>
                            <span className="mt-2 inline-block rounded-lg px-3 py-1.5 font-semibold text-white" style={{ background: t.ink }}>زر</span>
                        </div>
                        <div className="px-4 py-4" style={{ background: t.surface, color: t.ink }}>
                            <p className="font-bold">قسم بخلفية فاتحة</p>
                            <p className="num mt-1 text-[11px] opacity-70">تباين النص: {contrastRatio(t.ink, t.surface).toFixed(1)}:1</p>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-muted">صحّح الألوان لتظهر المعاينة.</p>
                )}
            </Card>
        </div>
    );
}

/** «الإعلان ومحركات البحث»: the strip over every page, and the title and description search engines show. */
export function SeoPanel({ doc, patch }: { doc: SiteDoc; patch: Patch }) {
    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card className="space-y-3">
                <CardHeader title="شريط الإعلان" subtitle="سطر قصير أعلى كل صفحة (عرض أو تنبيه). اتركه فارغًا لإخفائه." className="mb-0" />
                <FieldInput field={{ key: 'announcement', type: 'text', label: 'نص الإعلان', max: 200 }} value={doc.announcement} onChange={(v) => patch((d) => ({ ...d, announcement: v as string }))} path="" />
            </Card>
            <Card className="space-y-3">
                <CardHeader title="محركات البحث والمشاركة" subtitle="ما يظهر في نتائج Google وعند مشاركة رابط الموقع." className="mb-0" />
                <FieldInput field={{ key: 'title', type: 'text', label: 'عنوان الموقع', max: 70 }} value={doc.seo.title} onChange={(v) => patch((d) => ({ ...d, seo: { ...d.seo, title: v as string } }))} path="seo" />
                <FieldInput field={{ key: 'description', type: 'textarea', label: 'وصف الموقع', max: 300, rows: 3 }} value={doc.seo.description} onChange={(v) => patch((d) => ({ ...d, seo: { ...d.seo, description: v as string } }))} path="seo" />
            </Card>
        </div>
    );
}
