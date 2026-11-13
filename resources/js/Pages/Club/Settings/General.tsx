import { useMemo, type FormEvent, type ReactNode } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Building2, CalendarClock, Globe, Landmark, Palette, Printer, Receipt } from 'lucide-react';
import { PageActions, SettingsScreen, SettingsSection } from '@/Components/Settings';
import { Field, FileInput, Input, Select, Textarea, Toggle } from '@/Components/ui';
import { IMAGE_ACCEPT, pickImage } from '@/lib/image-prep';

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
    currency: string;
    logo_url: string;
    logo_custom: boolean;
    logo_download_url?: string | null;
    currency_locked: boolean;
}

type Rules = {
    booking_window_days: number;
    booking_cancel_cutoff_hours: number;
    checkin_class_window_minutes: number;
    duplicate_scan_minutes: number;
    block_access_with_dues: boolean;
    auto_mark_no_show: boolean;
    class_session_weeks_ahead: number;
    vat_registered: boolean;
    vat_rate: number;
    vat_number: string | null;
    cr_number: string | null;
    governorate: string | null;
    bank_name: string | null;
    bank_account_name: string | null;
    bank_iban: string | null;
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
    currency: string;
    logo: File | null;
    logo_preview: File | null;
    remove_logo: boolean;
    rules: Rules;
};

export default function GeneralSettings({ club, rules, governorates, currencies }: { club: Club; rules: Rules & Record<string, unknown>; governorates: string[]; currencies: Record<string, string> }) {
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
        currency: club.currency,
        logo: null,
        logo_preview: null,
        remove_logo: false,
        rules: {
            booking_window_days: Number(rules.booking_window_days),
            booking_cancel_cutoff_hours: Number(rules.booking_cancel_cutoff_hours),
            checkin_class_window_minutes: Number(rules.checkin_class_window_minutes),
            duplicate_scan_minutes: Number(rules.duplicate_scan_minutes ?? 5),
            block_access_with_dues: Boolean(rules.block_access_with_dues),
            auto_mark_no_show: Boolean(rules.auto_mark_no_show),
            class_session_weeks_ahead: Number(rules.class_session_weeks_ahead),
            vat_registered: Boolean(rules.vat_registered),
            vat_rate: Number(rules.vat_rate),
            vat_number: rules.vat_number ?? '',
            cr_number: rules.cr_number ?? '',
            governorate: rules.governorate ?? '',
            bank_name: rules.bank_name ?? '',
            bank_account_name: rules.bank_account_name ?? '',
            bank_iban: rules.bank_iban ?? '',
            invoice_footer: rules.invoice_footer ?? '',
            invoice_due_days: Number(rules.invoice_due_days),
            allow_pay_at_reception: Boolean(rules.allow_pay_at_reception),
            member_signup_enabled: Boolean(rules.member_signup_enabled),
        },
    });

    const errors = form.errors as Record<string, string | undefined>;
    const setRule = <K extends keyof Rules>(key: K, value: Rules[K]) => form.setData('rules', { ...form.data.rules, [key]: value });
    const chosen = useMemo(() => (form.data.logo ? URL.createObjectURL(form.data.logo_preview ?? form.data.logo) : null), [form.data.logo, form.data.logo_preview]);
    const logoShown = chosen ?? (form.data.remove_logo ? '/brand/alopera-gym-logo-screen.png' : club.logo_url);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => form.setData((d) => ({ ...d, logo: null, logo_preview: null, remove_logo: false })) };
        if (form.data.logo) {
            // A file needs a multipart POST; Laravel reads the intended PUT from _method.
            form.transform((d) => ({ ...d, _method: 'put' }));
            form.post(route('club.settings.update'), { ...options, forceFormData: true });
        } else {
            form.transform((d) => d);
            form.put(route('club.settings.update'), options);
        }
    };

    return (
        <SettingsScreen title="إعدادات النادي">
            <form onSubmit={submit} className="space-y-6">
                    <SettingsSection icon={Building2} title="بيانات النشاط" description="الاسم والسجل وبيانات التواصل التي تظهر في الفواتير والإيصالات والموقع">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="اسم النادي" error={errors.name} required>
                                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label="الاسم القانوني (للفواتير)" error={errors.legal_name}>
                                <Input value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} />
                            </Field>
                            <Field label="رقم السجل التجاري" hint="يُطبع تحت هوية النادي في الفواتير والسندات" error={errors['rules.cr_number']}>
                                <Input dir="ltr" value={form.data.rules.cr_number ?? ''} onChange={(e) => setRule('cr_number', e.target.value)} />
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
                            <Field label="المدينة / الولاية" error={errors.city}>
                                <Input value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                            </Field>
                            <Field label="المحافظة" error={errors['rules.governorate']}>
                                <Select value={form.data.rules.governorate ?? ''} onChange={(e) => setRule('governorate', e.target.value)} placeholder="اختر المحافظة">
                                    <option value="">بدون</option>
                                    {governorates.map((g) => <option key={g} value={g}>{g}</option>)}
                                </Select>
                            </Field>
                            <Field label="خط العرض" error={errors.latitude}>
                                <Input dir="ltr" inputMode="decimal" value={form.data.latitude} onChange={(e) => form.setData('latitude', e.target.value)} placeholder="23.5880" />
                            </Field>
                            <Field label="خط الطول" error={errors.longitude}>
                                <Input dir="ltr" inputMode="decimal" value={form.data.longitude} onChange={(e) => form.setData('longitude', e.target.value)} placeholder="58.3829" />
                            </Field>
                        </div>
                        {club.domain && <p className="mt-4 text-[12px] text-[#9ca3af]">نطاق الموقع: <span dir="ltr" className="font-medium text-ink">{club.domain}</span> (يُضبط مع إعداد الخادم)</p>}
                    </SettingsSection>

                    <SettingsSection icon={Palette} title="الشعار والهوية" description="الشعار على لوحة التحكم والدخول والموقع والفواتير والإيصالات وملفات PDF">
                        <div className="flex flex-wrap items-start gap-5">
                            <div className="flex h-24 w-32 shrink-0 items-center justify-center rounded-[14px] border border-[#e8e8e8] bg-[#fafafa] p-2">
                                <img src={logoShown} alt="شعار النادي" className="h-full w-auto object-contain" />
                            </div>
                            <div className="min-w-0 flex-1 space-y-2">
                                <Field label="شعار النادي" error={errors.logo} hint={club.logo_custom && !form.data.remove_logo ? 'شعار مرفوع. يظهر مرة واحدة في رأس كل ورقة.' : 'الشعار الرسمي المعتمد. ارفع صورة PNG شفافة لاستبداله، حتى 4 ميغابايت.'}>
                                    <FileInput accept={IMAGE_ACCEPT} file={form.data.logo} onChange={(f) => pickImage(f, (file, source) => form.setData((d) => (source && d.logo !== file ? d : { ...d, logo: file, logo_preview: source, remove_logo: false })))} placeholder="اختيار صورة الشعار" />
                                </Field>
                                {club.logo_custom && !form.data.logo && (
                                    <button type="button" className="text-[13px] font-medium text-[#111] underline-offset-2 hover:underline" onClick={() => form.setData('remove_logo', !form.data.remove_logo)}>
                                        {form.data.remove_logo ? 'التراجع عن استعادة الشعار الرسمي' : 'استعادة الشعار الرسمي'}
                                    </button>
                                )}
                                {club.logo_download_url && !form.data.logo && !form.data.remove_logo && (
                                    <a href={club.logo_download_url} className="text-[13px] text-[#6b7280] underline-offset-2 hover:text-[#111] hover:underline">تنزيل الشعار الأصلي</a>
                                )}
                            </div>
                        </div>
                        <div className="mt-5 grid grid-cols-2 gap-3">
                            <ColorField label="اللون الأساسي" value={form.data.primary_color} onChange={(v) => form.setData('primary_color', v)} error={errors.primary_color} />
                            <ColorField label="اللون الثانوي" value={form.data.secondary_color} onChange={(v) => form.setData('secondary_color', v)} error={errors.secondary_color} />
                        </div>
                        <div className="mt-4 overflow-hidden rounded-2xl border border-line">
                            <div className="px-4 py-3 text-sm font-medium text-white" style={{ background: form.data.primary_color }}>{form.data.name || 'اسم النادي'}</div>
                            <div className="flex items-center gap-2 p-4" style={{ background: `color-mix(in oklab, ${form.data.secondary_color} 25%, white)` }}>
                                <span className="rounded-full px-3 py-1 text-xs text-white" style={{ background: form.data.primary_color }}>اشترك الآن</span>
                                <span className="rounded-full border px-3 py-1 text-xs" style={{ borderColor: form.data.primary_color, color: form.data.primary_color }}>الباقات</span>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection icon={CalendarClock} title="الحجوزات والحضور" description="قواعد يطبقها النظام تلقائيًا على الحجز والدخول والغياب">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <NumberField label="نافذة الحجز (أيام)" hint="أقصى مدة مسبقة للحجز من البوابة" value={form.data.rules.booking_window_days} onChange={(v) => setRule('booking_window_days', v)} error={errors['rules.booking_window_days']} min={1} max={90} />
                            <NumberField label="مهلة إلغاء الحجز (ساعات)" hint="قبل موعد الحصة؛ بعدها لا تُسترد الحصة" value={form.data.rules.booking_cancel_cutoff_hours} onChange={(v) => setRule('booking_cancel_cutoff_hours', v)} error={errors['rules.booking_cancel_cutoff_hours']} min={0} max={72} />
                            <NumberField label="نافذة الحضور عند الدخول (دقائق)" hint="دخول النادي يسجّل حضور حصة تبدأ خلالها" value={form.data.rules.checkin_class_window_minutes} onChange={(v) => setRule('checkin_class_window_minutes', v)} error={errors['rules.checkin_class_window_minutes']} min={0} max={240} />
                            <NumberField label="تجاهل المسح المكرر (دقائق)" hint="مسح نفس العضو خلالها يُسجَّل كمحاولة ولا يُحتسب دخولًا جديدًا (0 = إيقاف)" value={form.data.rules.duplicate_scan_minutes} onChange={(v) => setRule('duplicate_scan_minutes', v)} error={errors['rules.duplicate_scan_minutes']} min={0} max={120} />
                            <NumberField label="توليد الحصص مسبقًا (أسابيع)" value={form.data.rules.class_session_weeks_ahead} onChange={(v) => setRule('class_session_weeks_ahead', v)} error={errors['rules.class_session_weeks_ahead']} min={1} max={12} />
                        </div>
                        <div className="mt-4 divide-y divide-line">
                            <Toggle checked={form.data.rules.auto_mark_no_show} onChange={(v) => setRule('auto_mark_no_show', v)} label="تسجيل الغياب تلقائيًا" hint="بعد انتهاء الحصة لمن لم يُسجَّل حضوره" />
                            <Toggle checked={form.data.rules.block_access_with_dues} onChange={(v) => setRule('block_access_with_dues', v)} label="منع الدخول عند وجود مستحقات متأخرة" hint="حتى لو كانت العضوية سارية" />
                        </div>
                    </SettingsSection>

                    <SettingsSection icon={Receipt} title="الضريبة والعملة" description="ما يُحتسب ويُطبع على الفواتير بحسب تسجيل النشاط">
                        <div className="divide-y divide-line">
                            <Toggle checked={form.data.rules.vat_registered} onChange={(v) => setRule('vat_registered', v)} label="النشاط مسجّل في ضريبة القيمة المضافة" hint="بدونه لا تُحتسب ضريبة ولا يُطبع رقم ضريبي" />
                        </div>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {form.data.rules.vat_registered && (
                                <>
                                    <NumberField label="نسبة ضريبة القيمة المضافة %" value={form.data.rules.vat_rate} onChange={(v) => setRule('vat_rate', v)} error={errors['rules.vat_rate']} min={0} max={30} step={0.5} />
                                    <Field label="الرقم الضريبي" error={errors['rules.vat_number']}>
                                        <Input dir="ltr" value={form.data.rules.vat_number ?? ''} onChange={(e) => setRule('vat_number', e.target.value)} />
                                    </Field>
                                </>
                            )}
                            <Field label="العملة" error={errors.currency} hint={club.currency_locked ? 'ثابتة بعد تسجيل أول فاتورة أو دفعة، لأن المبالغ المسجلة بها.' : undefined}>
                                <Select value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} disabled={club.currency_locked}>
                                    {Object.entries(currencies).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                                </Select>
                            </Field>
                        </div>
                    </SettingsSection>

                    <SettingsSection icon={Landmark} title="بيانات السداد" description="الحساب البنكي للتحويل، يُطبع على فاتورة العميل ما دام عليها مبلغ">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="اسم البنك" error={errors['rules.bank_name']}>
                                <Input value={form.data.rules.bank_name ?? ''} onChange={(e) => setRule('bank_name', e.target.value)} />
                            </Field>
                            <Field label="اسم الحساب" error={errors['rules.bank_account_name']}>
                                <Input value={form.data.rules.bank_account_name ?? ''} onChange={(e) => setRule('bank_account_name', e.target.value)} />
                            </Field>
                            <Field label="رقم الحساب أو IBAN" error={errors['rules.bank_iban']} className="sm:col-span-2">
                                <Input dir="ltr" value={form.data.rules.bank_iban ?? ''} onChange={(e) => setRule('bank_iban', e.target.value.toUpperCase())} placeholder="OM00 0000 0000 0000 0000 000" />
                            </Field>
                        </div>
                    </SettingsSection>

                    <SettingsSection icon={Printer} title="الفواتير والطباعة" description="مهلة السداد وتذييل الفاتورة، والقوالب والطابعات في صفحاتها">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <NumberField label="مهلة السداد (أيام)" value={form.data.rules.invoice_due_days} onChange={(v) => setRule('invoice_due_days', v)} error={errors['rules.invoice_due_days']} min={0} max={90} hint="تحدد تاريخ استحقاق الفاتورة" />
                            <Field label="تذييل الفاتورة" hint="يُطبع أسفل فاتورة العميل ما لم يُكتب تذييل آخر في «قوالب الأوراق»" error={errors['rules.invoice_footer']} className="sm:col-span-2">
                                <Textarea rows={2} value={form.data.rules.invoice_footer ?? ''} onChange={(e) => setRule('invoice_footer', e.target.value)} maxLength={500} placeholder="شكرًا لاختيارك ناديّنا. المبالغ المدفوعة غير مستردة بعد تفعيل العضوية." />
                            </Field>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[13px] font-medium text-[#111]">
                            <Link href={route('club.settings.papers')} className="underline-offset-2 hover:underline">قوالب الأوراق ومقاس الورق ←</Link>
                            <Link href={route('club.settings.pos-devices')} className="underline-offset-2 hover:underline">الطابعات وأجهزة نقطة البيع ←</Link>
                        </div>
                    </SettingsSection>

                    <SettingsSection icon={Globe} title="الاشتراك أونلاين" description="ما يستطيع العضو فعله من الموقع">
                        <div className="divide-y divide-line">
                            <Toggle checked={form.data.rules.member_signup_enabled} onChange={(v) => setRule('member_signup_enabled', v)} label="السماح بالتسجيل من الموقع" hint="اختيار الباقة وإنشاء حساب العضو" />
                            <Toggle checked={form.data.rules.allow_pay_at_reception} onChange={(v) => setRule('allow_pay_at_reception', v)} label="السماح بالدفع في الاستقبال" hint="تُفعّل العضوية بعد تسجيل الدفع من الاستقبال" />
                        </div>
                    </SettingsSection>

                <PageActions processing={form.processing} note="تُطبَّق القواعد على العمليات الجديدة من لحظة الحفظ." />
            </form>
        </SettingsScreen>
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
