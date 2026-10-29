import { useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Copy, CreditCard, Info, MessageCircle } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, Button, Card, CardHeader, Field, Input, PageHeader, Select, Toggle } from '@/Components/ui';

interface Integration {
    is_enabled: boolean;
    mode: string;
    present: Record<string, boolean>;
    public: Record<string, string | null>;
    last_error: string | null;
}

interface Props {
    integrations: { whatsapp: Integration; paymob: Integration };
    endpoints: { paymob_webhook: string; paymob_return_example: string; whatsapp_webhook: string };
    paymob_ready: boolean;
    whatsapp_driver: string;
}

const WHATSAPP_FIELDS = [
    { key: 'phone_number_id', label: 'Phone number ID', hint: 'من WhatsApp Manager ← الأرقام' },
    { key: 'business_account_id', label: 'WhatsApp Business Account ID' },
    { key: 'access_token', label: 'Access token (System user)', hint: 'رمز دائم من مستخدم نظام في Meta Business', secret: true },
];

const PAYMOB_FIELDS = [
    { key: 'secret_key', label: 'Secret key', secret: true },
    { key: 'public_key', label: 'Public key' },
    { key: 'hmac_secret', label: 'HMAC secret', hint: 'للتحقق من توقيع الإشعارات', secret: true },
    { key: 'integration_ids', label: 'Integration IDs', hint: 'رقم أو أكثر مفصولة بفاصلة (بطاقة، Apple Pay…)' },
];

export default function Integrations({ integrations, endpoints, paymob_ready, whatsapp_driver }: Props) {
    return (
        <ClubLayout title="الربط">
            <PageHeader back={<SettingsBack />} title="الربط: الدفع الإلكتروني وواتساب" subtitle="مفاتيح النادي الخاصة. تُحفظ مشفّرة ولا تُعرض مرة أخرى بعد الحفظ." />

            <div className="mb-6 flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-7 text-sky-900">
                <Info className="mt-1 size-4 shrink-0" />
                <p>
                    التكامل الفعلي يتطلب حساب <b>Meta Business</b> باسم النادي مع رقم واتساب أعمال وقوالب معتمدة، وحساب <b>Paymob</b> (عُمان) بمفاتيح التاجر.
                    بعد إدخال المفاتيح جرّبي في وضع «اختبار» قبل التحويل إلى «حقيقي». لا تُعتبر المدفوعات ناجحة إلا بعد وصول إشعار Paymob الموقّع إلى الخادم.
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-2 [&>*]:min-w-0">
                <ProviderCard
                    provider="whatsapp"
                    title="WhatsApp Business Platform"
                    icon={<MessageCircle className="size-[18px]" />}
                    integration={integrations.whatsapp}
                    fields={WHATSAPP_FIELDS}
                    status={
                        whatsapp_driver === 'log' ? (
                            <Badge tone="amber">وضع التطوير: الرسائل تُسجَّل ولا تُرسل</Badge>
                        ) : integrations.whatsapp.is_enabled ? (
                            <Badge tone="green">مفعّل</Badge>
                        ) : (
                            <Badge>غير مفعّل</Badge>
                        )
                    }
                    extra={(form) => (
                        <Field label="رقم العرض (اختياري)" hint="الرقم الظاهر للمشتركات، للعرض فقط">
                            <Input dir="ltr" value={form.display_phone} onChange={(e) => form.set('display_phone', e.target.value)} placeholder="+968 9123 4567" />
                        </Field>
                    )}
                    endpoints={[{ label: 'Callback URL (Webhook) في تطبيق Meta', url: endpoints.whatsapp_webhook }]}
                    note="رمز التحقق (Verify token) وسر التطبيق (App secret) إعدادات على مستوى المنصة في ملف البيئة، يضبطها مسؤول الخادم."
                />
                <ProviderCard
                    provider="paymob"
                    title="Paymob — الدفع الإلكتروني"
                    icon={<CreditCard className="size-[18px]" />}
                    integration={integrations.paymob}
                    fields={PAYMOB_FIELDS}
                    status={paymob_ready ? <Badge tone="green">جاهز لاستقبال المدفوعات</Badge> : <Badge tone="amber">غير مكتمل</Badge>}
                    endpoints={[
                        { label: 'Transaction processed callback', url: endpoints.paymob_webhook },
                        { label: 'صفحة العودة (تُضبط تلقائيًا لكل عملية)', url: endpoints.paymob_return_example },
                    ]}
                    note="يُستخدم نطاق Paymob عُمان افتراضيًا. العملة ريال عُماني بثلاث خانات عشرية."
                />
            </div>
        </ClubLayout>
    );
}

type FieldDef = { key: string; label: string; hint?: string; secret?: boolean };

function ProviderCard({
    provider,
    title,
    icon,
    integration,
    fields,
    status,
    endpoints,
    note,
    extra,
}: {
    provider: 'whatsapp' | 'paymob';
    title: string;
    icon: ReactNode;
    integration: Integration;
    fields: FieldDef[];
    status: ReactNode;
    endpoints: { label: string; url: string }[];
    note: string;
    extra?: (form: { display_phone: string; set: (k: 'display_phone', v: string) => void }) => ReactNode;
}) {
    const form = useForm({
        is_enabled: integration.is_enabled,
        mode: integration.mode,
        credentials: Object.fromEntries(fields.map((f) => [f.key, ''])) as Record<string, string>,
        display_phone: integration.public?.display_phone ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.integrations.update', provider), {
            preserveScroll: true,
            onSuccess: () => form.setData('credentials', Object.fromEntries(fields.map((f) => [f.key, ''])) as Record<string, string>),
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Card>
            <CardHeader title={<span className="flex items-center gap-2"><span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-[#f2f2f0] text-ink">{icon}</span>{title}</span>} actions={status} />
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Toggle checked={form.data.is_enabled} onChange={(v) => form.setData('is_enabled', v)} label="تفعيل" />
                    <Field label="الوضع" error={errors.mode}>
                        <Select value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                            <option value="test">اختبار</option>
                            <option value="live">حقيقي</option>
                        </Select>
                    </Field>
                </div>

                {fields.map((f) => {
                    const saved = integration.present?.[f.key];
                    return (
                        <Field
                            key={f.key}
                            label={
                                <span className="flex w-full items-center justify-between">
                                    <span dir="ltr">{f.label}</span>
                                    {saved ? <Badge tone="green"><Check className="size-3.5" /> محفوظ</Badge> : <Badge>غير مُدخل</Badge>}
                                </span>
                            }
                            hint={f.hint}
                            error={errors[`credentials.${f.key}`]}
                        >
                            <Input
                                dir="ltr"
                                type={f.secret ? 'password' : 'text'}
                                autoComplete="off"
                                value={form.data.credentials[f.key] ?? ''}
                                onChange={(e) => form.setData('credentials', { ...form.data.credentials, [f.key]: e.target.value })}
                                placeholder={saved ? '•••••••• (اتركيه فارغًا للإبقاء على القيمة المحفوظة)' : ''}
                            />
                        </Field>
                    );
                })}

                {extra?.({ display_phone: form.data.display_phone, set: (k, v) => form.setData(k, v) })}

                {integration.public && Object.entries(integration.public).some(([, v]) => v) && (
                    <div className="rounded-xl bg-canvas px-3 py-2 text-xs text-muted">
                        {Object.entries(integration.public).filter(([, v]) => v).map(([k, v]) => (
                            <p key={k}><span dir="ltr">{k}</span>: <span dir="ltr" className="text-ink">{v}</span></p>
                        ))}
                    </div>
                )}

                {integration.last_error && <p className="rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700">آخر خطأ: {integration.last_error}</p>}

                <div className="space-y-2">
                    {endpoints.map((ep) => <CopyRow key={ep.label} label={ep.label} value={ep.url} />)}
                </div>
                <p className="text-xs text-muted">{note}</p>

                <Button type="submit" loading={form.processing}>حفظ</Button>
            </form>
        </Card>
    );
}

function CopyRow({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <div>
            <p className="mb-1 text-xs text-muted">{label}</p>
            <div className="flex items-center gap-2 rounded-xl border border-line bg-canvas px-3 py-2">
                <code className="min-w-0 flex-1 truncate text-xs" dir="ltr">{value}</code>
                <button
                    type="button"
                    className="grid size-7 shrink-0 place-items-center rounded-lg hover:bg-white"
                    onClick={() => navigator.clipboard?.writeText(value).then(() => {
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    })}
                    aria-label="نسخ"
                >
                    {copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
                </button>
            </div>
        </div>
    );
}
