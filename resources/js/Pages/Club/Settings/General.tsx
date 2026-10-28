import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { ImagePlus, Plus, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, PageHeader, Textarea, Toggle } from '@/Components/ui';

interface Club {
    name: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    primary_color: string;
    secondary_color: string;
    latitude: string | null;
    longitude: string | null;
    domain: string | null;
    logo_url: string | null;
}

type Rules = {
    booking_window_days: number;
    booking_cancel_cutoff_hours: number;
    checkin_class_window_minutes: number;
    block_access_with_dues: boolean;
    auto_mark_no_show: boolean;
    expiry_reminder_days: number[];
    expired_followup_days: number[];
    notify_class_changes: boolean;
    class_session_weeks_ahead: number;
    vat_rate: number;
    vat_number: string | null;
    invoice_footer: string | null;
    invoice_due_days: number;
    allow_pay_at_reception: boolean;
    member_signup_enabled: boolean;
};

type SettingsForm = {
    name: string;
    legal_name: string;
    email: string;
    phone: string;
    address: string;
    city: string;
    latitude: string;
    longitude: string;
    primary_color: string;
    secondary_color: string;
    logo: File | null;
    rules: Rules;
};

export default function GeneralSettings({ club, rules }: { club: Club; rules: Rules & Record<string, unknown> }) {
    const form = useForm<SettingsForm>({
        name: club.name,
        legal_name: club.legal_name ?? '',
        email: club.email ?? '',
        phone: club.phone ?? '',
        address: club.address ?? '',
        city: club.city ?? '',
        latitude: club.latitude ?? '',
        longitude: club.longitude ?? '',
        primary_color: club.primary_color,
        secondary_color: club.secondary_color,
        logo: null,
        rules: {
            booking_window_days: Number(rules.booking_window_days),
            booking_cancel_cutoff_hours: Number(rules.booking_cancel_cutoff_hours),
            checkin_class_window_minutes: Number(rules.checkin_class_window_minutes),
            block_access_with_dues: Boolean(rules.block_access_with_dues),
            auto_mark_no_show: Boolean(rules.auto_mark_no_show),
            expiry_reminder_days: (rules.expiry_reminder_days ?? []).map(Number),
            expired_followup_days: (rules.expired_followup_days ?? []).map(Number),
            notify_class_changes: Boolean(rules.notify_class_changes),
            class_session_weeks_ahead: Number(rules.class_session_weeks_ahead),
            vat_rate: Number(rules.vat_rate),
            vat_number: rules.vat_number ?? '',
            invoice_footer: rules.invoice_footer ?? '',
            invoice_due_days: Number(rules.invoice_due_days),
            allow_pay_at_reception: Boolean(rules.allow_pay_at_reception),
            member_signup_enabled: Boolean(rules.member_signup_enabled),
        },
    });

    const errors = form.errors as Record<string, string | undefined>;
    const setRule = <K extends keyof Rules>(key: K, value: Rules[K]) => form.setData('rules', { ...form.data.rules, [key]: value });
    const logoPreview = useMemo(() => (form.data.logo ? URL.createObjectURL(form.data.logo) : club.logo_url), [form.data.logo, club.logo_url]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => form.setData('logo', null) };
        if (form.data.logo) {
            // Files need a multipart POST; Laravel reads the intended PUT from _method.
            form.transform((d) => ({ ...d, _method: 'put' }));
            form.post(route('club.settings.update'), { ...options, forceFormData: true });
        } else {
            form.transform((d) => d);
            form.put(route('club.settings.update'), options);
        }
    };

    return (
        <ClubLayout title="إعدادات النادي">
            <PageHeader title="إعدادات النادي" subtitle="الهوية وبيانات التواصل وقواعد العمل التي يطبقها النظام تلقائيًا" />

            <form onSubmit={submit} className="space-y-6">
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader title="بيانات النادي" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="اسم النادي" error={errors.name} required>
                                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label="الاسم القانوني (للفواتير)" error={errors.legal_name}>
                                <Input value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} />
                            </Field>
                            <Field label="البريد الإلكتروني" error={errors.email}>
                                <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </Field>
                            <Field label="الهاتف" error={errors.phone}>
                                <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            </Field>
                            <Field label="العنوان" error={errors.address}>
                                <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            </Field>
                            <Field label="المدينة" error={errors.city}>
                                <Input value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                            </Field>
                            <Field label="خط العرض" error={errors.latitude}>
                                <Input dir="ltr" inputMode="decimal" value={form.data.latitude} onChange={(e) => form.setData('latitude', e.target.value)} placeholder="23.5880" />
                            </Field>
                            <Field label="خط الطول" error={errors.longitude}>
                                <Input dir="ltr" inputMode="decimal" value={form.data.longitude} onChange={(e) => form.setData('longitude', e.target.value)} placeholder="58.3829" />
                            </Field>
                        </div>
                        {club.domain && <p className="mt-4 text-xs text-muted">نطاق الموقع: <span dir="ltr" className="font-medium text-ink">{club.domain}</span> (يُدار من إدارة المنصة)</p>}
                    </Card>

                    <Card>
                        <CardHeader title="الهوية البصرية" subtitle="تنعكس على اللوحة والموقع وبوابة المشتركات" />
                        <div className="flex items-center gap-3">
                            <div className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-2xl border border-dashed border-line bg-canvas">
                                {logoPreview ? <img src={logoPreview} alt="" className="h-full w-full object-cover" /> : <ImagePlus className="size-5 text-muted" />}
                            </div>
                            <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-xs hover:bg-brand-50">
                                <ImagePlus className="size-3.5" /> تغيير الشعار
                                <input type="file" accept="image/*" className="hidden" onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} />
                            </label>
                        </div>
                        {errors.logo && <p className="mt-1 text-xs text-red-600">{errors.logo}</p>}
                        <div className="mt-4 grid grid-cols-2 gap-3">
                            <ColorField label="اللون الأساسي" value={form.data.primary_color} onChange={(v) => form.setData('primary_color', v)} error={errors.primary_color} />
                            <ColorField label="اللون الثانوي" value={form.data.secondary_color} onChange={(v) => form.setData('secondary_color', v)} error={errors.secondary_color} />
                        </div>
                        <div className="mt-4 overflow-hidden rounded-2xl border border-line">
                            <div className="px-4 py-3 text-sm font-medium text-white" style={{ background: form.data.primary_color }}>{form.data.name || 'اسم النادي'}</div>
                            <div className="flex items-center gap-2 p-4" style={{ background: `color-mix(in oklab, ${form.data.secondary_color} 25%, white)` }}>
                                <span className="rounded-full px-3 py-1 text-xs text-white" style={{ background: form.data.primary_color }}>اشتركي الآن</span>
                                <span className="rounded-full border px-3 py-1 text-xs" style={{ borderColor: form.data.primary_color, color: form.data.primary_color }}>الباقات</span>
                            </div>
                        </div>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="الحجوزات والحضور" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <NumberField label="نافذة الحجز (أيام)" hint="أقصى مدة مسبقة للحجز من البوابة" value={form.data.rules.booking_window_days} onChange={(v) => setRule('booking_window_days', v)} error={errors['rules.booking_window_days']} min={1} max={90} />
                            <NumberField label="مهلة إلغاء الحجز (ساعات)" hint="قبل موعد الحصة؛ بعدها لا تُسترد الحصة" value={form.data.rules.booking_cancel_cutoff_hours} onChange={(v) => setRule('booking_cancel_cutoff_hours', v)} error={errors['rules.booking_cancel_cutoff_hours']} min={0} max={72} />
                            <NumberField label="نافذة الحضور عند الدخول (دقائق)" hint="دخول النادي يسجّل حضور حصة تبدأ خلالها" value={form.data.rules.checkin_class_window_minutes} onChange={(v) => setRule('checkin_class_window_minutes', v)} error={errors['rules.checkin_class_window_minutes']} min={0} max={240} />
                            <NumberField label="توليد الحصص مسبقًا (أسابيع)" value={form.data.rules.class_session_weeks_ahead} onChange={(v) => setRule('class_session_weeks_ahead', v)} error={errors['rules.class_session_weeks_ahead']} min={1} max={12} />
                        </div>
                        <div className="mt-4 divide-y divide-line">
                            <Toggle checked={form.data.rules.auto_mark_no_show} onChange={(v) => setRule('auto_mark_no_show', v)} label="تسجيل الغياب تلقائيًا" hint="بعد انتهاء الحصة لمن لم يُسجَّل حضورها" />
                            <Toggle checked={form.data.rules.block_access_with_dues} onChange={(v) => setRule('block_access_with_dues', v)} label="منع الدخول عند وجود مستحقات متأخرة" hint="حتى لو كانت العضوية سارية" />
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="رسائل واتساب التلقائية" subtitle="قواعد التذكير والمتابعة (تتطلب قوالب معتمدة)" />
                        <DaysField label="تذكير قبل انتهاء العضوية بـ (أيام)" values={form.data.rules.expiry_reminder_days} onChange={(v) => setRule('expiry_reminder_days', v)} max={60} min={0} error={errors['rules.expiry_reminder_days']} />
                        <DaysField label="متابعة بعد الانتهاء بـ (أيام)" values={form.data.rules.expired_followup_days} onChange={(v) => setRule('expired_followup_days', v)} max={180} min={1} error={errors['rules.expired_followup_days']} />
                        <div className="mt-2">
                            <Toggle checked={form.data.rules.notify_class_changes} onChange={(v) => setRule('notify_class_changes', v)} label="إبلاغ المحجوزات عند تعديل الحصة" hint="تغيير الموعد أو المدربة أو القاعة" />
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="الفواتير والضريبة" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <NumberField label="نسبة ضريبة القيمة المضافة %" value={form.data.rules.vat_rate} onChange={(v) => setRule('vat_rate', v)} error={errors['rules.vat_rate']} min={0} max={30} step={0.5} hint="0 لتعطيلها" />
                            <Field label="الرقم الضريبي" error={errors['rules.vat_number']}>
                                <Input dir="ltr" value={form.data.rules.vat_number ?? ''} onChange={(e) => setRule('vat_number', e.target.value)} />
                            </Field>
                            <NumberField label="مهلة السداد (أيام)" value={form.data.rules.invoice_due_days} onChange={(v) => setRule('invoice_due_days', v)} error={errors['rules.invoice_due_days']} min={0} max={90} hint="تحدد تاريخ استحقاق الفاتورة" />
                            <Field label="تذييل الفاتورة" error={errors['rules.invoice_footer']} className="sm:col-span-2">
                                <Textarea rows={2} value={form.data.rules.invoice_footer ?? ''} onChange={(e) => setRule('invoice_footer', e.target.value)} maxLength={500} placeholder="شكرًا لاختيارك ناديّنا. المبالغ المدفوعة غير مستردة بعد تفعيل العضوية." />
                            </Field>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="الاشتراك أونلاين" />
                        <div className="divide-y divide-line">
                            <Toggle checked={form.data.rules.member_signup_enabled} onChange={(v) => setRule('member_signup_enabled', v)} label="السماح بالتسجيل من الموقع" hint="اختيار الباقة وإنشاء حساب المشتركة" />
                            <Toggle checked={form.data.rules.allow_pay_at_reception} onChange={(v) => setRule('allow_pay_at_reception', v)} label="السماح بالدفع في الاستقبال" hint="تُفعّل العضوية بعد تسجيل الدفع من الاستقبال" />
                        </div>
                    </Card>
                </div>

                <div className="sticky bottom-4 z-10 flex justify-end">
                    <Button type="submit" size="lg" loading={form.processing} className="shadow-lg">حفظ الإعدادات</Button>
                </div>
            </form>
        </ClubLayout>
    );
}

function ColorField({ label, value, onChange, error }: { label: string; value: string; onChange: (v: string) => void; error?: string }) {
    return (
        <Field label={label} error={error}>
            <div className="flex items-center gap-2 rounded-xl border border-line px-2 py-1.5">
                <input type="color" value={value} onChange={(e) => onChange(e.target.value)} className="size-8 cursor-pointer rounded-lg border-0 bg-transparent p-0" />
                <input dir="ltr" value={value} onChange={(e) => onChange(e.target.value)} className="w-full bg-transparent text-xs outline-none num" maxLength={7} />
            </div>
        </Field>
    );
}

function NumberField({ label, value, onChange, error, hint, min, max, step = 1 }: { label: ReactNode; value: number; onChange: (v: number) => void; error?: string; hint?: string; min?: number; max?: number; step?: number }) {
    return (
        <Field label={label} error={error} hint={hint}>
            <Input type="number" value={Number.isFinite(value) ? value : ''} min={min} max={max} step={step} onChange={(e) => onChange(e.target.value === '' ? 0 : Number(e.target.value))} />
        </Field>
    );
}

function DaysField({ label, values, onChange, min, max, error }: { label: string; values: number[]; onChange: (v: number[]) => void; min: number; max: number; error?: string }) {
    const [draft, setDraft] = useState('');
    const add = () => {
        const n = Number(draft);
        if (draft === '' || !Number.isInteger(n) || n < min || n > max || values.includes(n) || values.length >= 5) return;
        onChange([...values, n].sort((a, b) => b - a));
        setDraft('');
    };

    return (
        <Field label={label} error={error} className="mb-4" hint={values.length === 0 ? 'لا يوجد — هذه الرسائل متوقفة' : `حتى 5 قيم، بين ${min} و${max}`}>
            <div className="flex flex-wrap items-center gap-1.5">
                {values.map((d) => (
                    <span key={d} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700">
                        <span className="num">{d}</span> يوم
                        <button type="button" onClick={() => onChange(values.filter((x) => x !== d))} aria-label="حذف"><X className="size-3" /></button>
                    </span>
                ))}
                {values.length < 5 && (
                    <span className="inline-flex items-center gap-1">
                        <input
                            type="number"
                            className="input h-8 w-20 py-1 text-xs"
                            value={draft}
                            min={min}
                            max={max}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    add();
                                }
                            }}
                        />
                        <button type="button" onClick={add} className="grid size-8 place-items-center rounded-lg border border-line hover:bg-brand-50" aria-label="إضافة"><Plus className="size-3.5" /></button>
                    </span>
                )}
            </div>
        </Field>
    );
}
