import { Link } from '@inertiajs/react';
import { AlertTriangle, CircleCheck, CircleDashed, ShieldCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, CardHeader, EmptyState, Field, Input, Notice, PageHeader } from '@/Components/ui';
import { CheckList, CopyRow, IntegrationBack, IntegrationSummary, KeysForm, VerifyButton, type IntegrationStatusData, type ProviderForm } from '@/Components/Integrations';
import { number } from '@/lib/format';

type TemplateState = { key: string; label: string; state: string };

interface Props {
    integration: ProviderForm;
    status: IntegrationStatusData;
    number: { verified_name?: string; display_phone?: string; quality_rating?: string };
    platform: { driver: string; app_secret: boolean; verify_token: boolean; last_webhook_at: string | null };
    endpoints: { webhook: string };
    features: { key: string; label: string; detail: string; route: string; templates: TemplateState[] }[];
    consent: { members: number; whatsapp: number; marketing: number };
    stats: Record<string, number>;
    log: { id: number; to: string; what: string; status: string; logged: boolean; error: string | null; error_message: string | null; at: string }[];
}

const FIELDS = [
    { key: 'phone_number_id', label: 'Phone number ID', hint: 'من WhatsApp Manager ← Phone numbers' },
    { key: 'business_account_id', label: 'WhatsApp Business Account ID' },
    { key: 'access_token', label: 'Access token (System user)', hint: 'رمز دائم من مستخدم نظام في Meta Business Suite', secret: true },
];

const TEMPLATE_STATE: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'gray' }> = {
    approved: { label: 'معتمد', tone: 'green' },
    draft: { label: 'مسودة', tone: 'gray' },
    pending: { label: 'بانتظار Meta', tone: 'amber' },
    rejected: { label: 'مرفوض', tone: 'red' },
    inactive: { label: 'موقوف', tone: 'gray' },
    missing: { label: 'غير موجود', tone: 'gray' },
};

const MESSAGE_STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'gray' | 'blue' }> = {
    queued: { label: 'في الانتظار', tone: 'amber' },
    scheduled: { label: 'مجدولة', tone: 'blue' },
    sent: { label: 'أُرسلت', tone: 'blue' },
    delivered: { label: 'وصلت', tone: 'green' },
    read: { label: 'قُرئت', tone: 'green' },
    failed: { label: 'فشلت', tone: 'red' },
    skipped: { label: 'لم تُرسل', tone: 'gray' },
};

const SKIP_REASONS: Record<string, string> = {
    template_missing: 'لا يوجد قالب لهذا الحدث',
    template_not_approved: 'القالب غير معتمد',
    opted_out: 'العضو أوقف رسائل واتساب',
    no_marketing_consent: 'لا توجد موافقة تسويقية',
    no_phone: 'لا يوجد رقم',
    not_configured: 'واتساب غير مفعّل',
    window_closed: 'انتهت نافذة الـ24 ساعة',
    campaign_cancelled: 'أُلغيت الحملة',
};

export default function WhatsAppIntegration({ integration, status, number: num, platform, endpoints, features, consent, stats, log }: Props) {
    const keysReady = integration.present.phone_number_id && integration.present.access_token;
    const logged = Number(stats.logged ?? 0);
    const sent = ['sent', 'delivered', 'read'].reduce((sum, k) => sum + Number(stats[k] ?? 0), 0) - logged;

    return (
        <ClubLayout title="WhatsApp Business">
            <PageHeader back={<IntegrationBack />} title="WhatsApp Business" subtitle="حساب Meta Business والرقم والمفاتيح، وما يُرسل من خلاله وسجل الإرسال." />

            <IntegrationSummary service="whatsapp" title="WhatsApp Business" status={status}>
                <VerifyButton provider="whatsapp" disabled={!keysReady} />
            </IntegrationSummary>

            {platform.driver === 'log' && (
                <Notice tone="amber" icon={<AlertTriangle />} className="mb-6">
                    الخادم في وضع التطوير: الرسائل تُسجَّل في سجل الخادم ولا تُرسل إلى واتساب، حتى لو اكتمل الربط. يُغيَّر من إعدادات الخادم (WHATSAPP_DRIVER=meta).
                </Notice>
            )}

            <div className="grid gap-6 lg:grid-cols-5 [&>*]:min-w-0">
                <Card className="lg:col-span-3">
                    <CardHeader title="الحساب والمفاتيح" subtitle="من حساب Meta Business الخاص بالنادي، برقم واتساب أعمال مسجّل." />
                    <KeysForm
                        provider="whatsapp"
                        integration={integration}
                        fields={FIELDS}
                        modeHint="«اختبار» مع رقم Meta التجريبي، و«إنتاج» مع رقم النادي."
                        extra={(value, set, error) => (
                            <Field label="رقم العرض (اختياري)" hint="الرقم الظاهر للأعضاء، للعرض فقط" error={error}>
                                <Input dir="ltr" value={value} onChange={(e) => set(e.target.value)} placeholder="+968 9123 4567" />
                            </Field>
                        )}
                    />
                    {integration.last_error && (
                        <p className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-xs leading-6 text-red-700">
                            آخر خطأ{integration.last_error_at ? ` (${integration.last_error_at})` : ''}: {integration.last_error}
                        </p>
                    )}
                </Card>

                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="التحقق والربط" />
                        <CheckList items={[
                            { label: 'Phone number ID وAccess token مُدخلان', ok: Boolean(keysReady) },
                            { label: num.verified_name ? `تحققت Meta من الرقم: ${num.verified_name}` : 'تحققت Meta من الرقم والرمز', ok: status.state === 'connected' },
                            { label: 'سر التطبيق ورمز التحقق مضبوطان على الخادم', ok: platform.app_secret && platform.verify_token },
                            { label: 'وصل إشعار موقّع من Meta (رسالة أو حالة تسليم)', ok: platform.last_webhook_at !== null, at: platform.last_webhook_at },
                        ]} />
                        {(num.display_phone || num.quality_rating) && (
                            <p className="mt-3 text-xs text-muted">
                                {num.display_phone && <>الرقم: <span dir="ltr" className="text-ink">{num.display_phone}</span></>}
                                {num.quality_rating && <> · التقييم لدى Meta: <span dir="ltr" className="text-ink">{num.quality_rating}</span></>}
                            </p>
                        )}
                        <div className="mt-4">
                            <CopyRow label="Callback URL (Webhook) في تطبيق Meta" value={endpoints.webhook} />
                        </div>
                        <p className="mt-3 text-xs leading-6 text-muted">رمز التحقق (Verify token) وسر التطبيق (App secret) إعدادات على مستوى الخادم يضبطها مسؤوله، ولا تُعرض هنا.</p>
                    </Card>

                    <Card>
                        <CardHeader title="الموافقة والقواعد" />
                        <dl className="grid grid-cols-3 gap-3 text-center">
                            <div className="rounded-xl bg-canvas p-3"><dt className="text-[11px] text-muted">الأعضاء</dt><dd className="num mt-1 font-semibold">{number(consent.members)}</dd></div>
                            <div className="rounded-xl bg-canvas p-3"><dt className="text-[11px] text-muted">يقبلون واتساب</dt><dd className="num mt-1 font-semibold">{number(consent.whatsapp)}</dd></div>
                            <div className="rounded-xl bg-canvas p-3"><dt className="text-[11px] text-muted">موافقة تسويقية</dt><dd className="num mt-1 font-semibold">{number(consent.marketing)}</dd></div>
                        </dl>
                        <ul className="mt-4 space-y-1.5 text-xs leading-6 text-muted">
                            <li className="flex gap-2"><ShieldCheck className="mt-1 size-3.5 shrink-0" />الرسائل التلقائية بقوالب معتمدة من Meta فقط، والرد الحر خلال 24 ساعة من آخر رسالة للعضو.</li>
                            <li className="flex gap-2"><ShieldCheck className="mt-1 size-3.5 shrink-0" />لا يُراسل عضو أوقف رسائل واتساب، ولا تُرسل حملة تسويقية إلا لمن وافق عليها.</li>
                            <li className="flex gap-2"><ShieldCheck className="mt-1 size-3.5 shrink-0" />لا تتكرر رسالة الحدث الواحد لنفس العضو.</li>
                        </ul>
                    </Card>
                </div>
            </div>

            <Card className="mt-6">
                <CardHeader title="ما يُرسل عبر واتساب" subtitle="كل وظيفة وقوالبها؛ لا يُرسل قالب قبل اعتماده" />
                <div className="grid gap-4 md:grid-cols-2">
                    {features.map((f) => (
                        <div key={f.key} className="rounded-[12px] border border-line p-4">
                            <div className="flex items-center justify-between gap-2">
                                <h4 className="text-[14px] font-semibold text-[#111]">{f.label}</h4>
                                <Link href={route(f.route)} className="text-xs text-muted underline hover:text-ink">فتح</Link>
                            </div>
                            <p className="mt-1 text-[13px] leading-6 text-muted">{f.detail}</p>
                            {f.templates.length > 0 && (
                                <ul className="mt-3 space-y-1.5">
                                    {f.templates.map((t) => (
                                        <li key={t.key} className="flex items-center gap-2 text-[13px]">
                                            {t.state === 'approved' ? <CircleCheck className="size-4 shrink-0 text-emerald-600" /> : <CircleDashed className="size-4 shrink-0 text-[#9ca3af]" />}
                                            <span className="min-w-0 flex-1 truncate">{t.label}</span>
                                            <Badge tone={TEMPLATE_STATE[t.state]?.tone ?? 'gray'}>{TEMPLATE_STATE[t.state]?.label ?? t.state}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    ))}
                </div>
            </Card>

            <Card padded={false} className="mt-6">
                <div className="p-5 pb-0">
                    <CardHeader
                        title="سجل الإرسال"
                        subtitle={`آخر 30 يومًا: ${number(sent)} أُرسلت · ${number(Number(stats.failed ?? 0))} فشلت · ${number(Number(stats.skipped ?? 0))} لم تُرسل${logged ? ` · ${number(logged)} سُجّلت في وضع التطوير ولم تُرسل` : ''}`}
                        actions={<Link href={route('club.messages.index')} className="text-xs text-muted underline hover:text-ink">السجل الكامل</Link>}
                    />
                </div>
                {log.length === 0 ? (
                    <EmptyState title="لا توجد رسائل بعد" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>إلى</th><th>الرسالة</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {log.map((m) => (
                                    <tr key={m.id}>
                                        <td className="num text-xs">{m.at}</td>
                                        <td>{m.to}</td>
                                        <td>{m.what}</td>
                                        <td>
                                            {m.logged ? <Badge tone="gray">سُجّلت ولم تُرسل</Badge> : <Badge tone={MESSAGE_STATUS[m.status]?.tone ?? 'gray'}>{MESSAGE_STATUS[m.status]?.label ?? m.status}</Badge>}
                                            {m.error && <p className="mt-0.5 max-w-[16rem] text-[11px] text-red-600">{SKIP_REASONS[m.error] ?? m.error_message ?? m.error}</p>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
