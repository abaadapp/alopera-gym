import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CircleCheck, CircleDashed, KeyRound, RefreshCw, ShieldCheck, Smartphone } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, EmptyState, Field, Input, Notice, PageHeader, Toggle } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { CheckList, CopyRow, IntegrationBack, IntegrationSummary, KeysForm, VerifyButton, type IntegrationStatusData, type ProviderForm } from '@/Components/Integrations';
import { number } from '@/lib/format';

type TemplateState = { key: string; label: string; state: string };

interface Props {
    integration: ProviderForm;
    status: IntegrationStatusData;
    number: { verified_name?: string; display_phone?: string; quality_rating?: string };
    platform: { driver: string; app_secret: boolean; verify_token: boolean; last_webhook_at: string | null };
    endpoints: { webhook: string; webhook_360: string; token_header: string };
    vendors: Record<string, string>;
    coexistence: { state: boolean | null; platform_type: string | null; connection: string | null; disconnected_reason: string | null; fields: string[]; webhook_token: boolean };
    imports: { history: boolean; contacts: boolean; history_state: string | null; history_note: string | null; history_progress: number | null; history_requested_at: string | null; contacts_requested_at: string | null; contacts_count: number };
    webhooks: { last_7_days: Record<string, number>; failures: { id: number; fields: string | null; error: string | null; attempts: number; at: string }[] };
    canManage: boolean;
    features: { key: string; label: string; detail: string; route: string; templates: TemplateState[] }[];
    consent: { members: number; whatsapp: number; marketing: number };
    stats: Record<string, number>;
    log: { id: number; to: string; what: string; status: string; logged: boolean; error: string | null; error_message: string | null; at: string }[];
}

const FIELDS = [
    { key: 'phone_number_id', label: 'Phone number ID', hint: 'من WhatsApp Manager ← Phone numbers (مع 360dialog: من لوحة 360dialog؛ يوجّه الإشعارات للنادي)' },
    { key: 'business_account_id', label: 'WhatsApp Business Account ID', hint: 'يُستخدم أيضًا لمعرفة انفصال الرقم عن المنصة' },
    { key: 'access_token', label: 'Access token (System user)', hint: 'رمز دائم من مستخدم نظام في Meta Business Suite', secret: true, vendor: 'meta' },
    { key: 'api_key', label: 'D360-API-KEY', hint: 'مفتاح الرقم من لوحة 360dialog', secret: true, vendor: '360dialog' },
];

const HISTORY_STATE: Record<string, string> = { importing: 'جارٍ الاستيراد', done: 'اكتمل الاستيراد', declined: 'رفض صاحب الرقم مشاركة السجل من التطبيق' };

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

export default function WhatsAppIntegration({ integration, status, number: num, platform, endpoints, vendors, coexistence, imports, webhooks, canManage, features, consent, stats, log }: Props) {
    const vendor = integration.public?.vendor ?? 'meta';
    const keysReady = integration.present.phone_number_id && (vendor === '360dialog' ? integration.present.api_key : integration.present.access_token);
    const { flash } = useShared();
    const importForm = useForm({ import_history: imports.history, import_contacts: imports.contacts });
    const logged = Number(stats.logged ?? 0);
    const sent = ['sent', 'delivered', 'read'].reduce((sum, k) => sum + Number(stats[k] ?? 0), 0) - logged;

    return (
        <ClubLayout title="WhatsApp Business">
            <PageHeader back={<IntegrationBack />} title="إعدادات واتساب" subtitle="الرقم ومزود الخدمة والمفاتيح، وCoexistence مع تطبيق WhatsApp Business، والإشعارات وسجل الإرسال والأخطاء." />

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
                        vendors={vendors}
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
                        <div className="mt-4 space-y-2">
                            {vendor === '360dialog' ? (
                                <>
                                    <CopyRow label="Webhook URL في 360dialog" value={endpoints.webhook_360} />
                                    <p className="text-xs leading-6 text-muted">سجّله مع ترويسة <span dir="ltr" className="font-mono">{endpoints.token_header}</span> بقيمة الرمز السري أدناه. الإشعار بلا الرمز الصحيح يُرفض.</p>
                                    {flash?.webhook_token && <CopyRow label="الرمز السري (يظهر مرة واحدة)" value={flash.webhook_token} />}
                                    {canManage && (
                                        <Button size="sm" variant="outline" icon={<KeyRound className="size-3.5" />} onClick={() => router.post(route('club.whatsapp.settings.token'), {}, { preserveScroll: true })}>
                                            {coexistence.webhook_token ? 'إنشاء رمز جديد (يوقف القديم)' : 'إنشاء الرمز السري'}
                                        </Button>
                                    )}
                                </>
                            ) : (
                                <>
                                    <CopyRow label="Callback URL (Webhook) في تطبيق Meta" value={endpoints.webhook} />
                                    <p className="text-xs leading-6 text-muted">رمز التحقق (Verify token) وسر التطبيق (App secret) إعدادات على مستوى الخادم يضبطها مسؤوله، ولا تُعرض هنا. الإشعار بلا توقيع صحيح يُرفض.</p>
                                </>
                            )}
                            <div className="text-xs leading-6 text-muted">
                                الأحداث المطلوب الاشتراك فيها: {coexistence.fields.map((f) => <span key={f} dir="ltr" className="me-1 inline-block rounded bg-canvas px-1.5 font-mono text-[11px] text-ink">{f}</span>)}
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Coexistence مع تطبيق الهاتف" subtitle="الرقم نفسه على تطبيق WhatsApp Business في هاتفك وعلى النظام معًا." />
                        <CheckList items={[
                            { label: coexistence.state === null ? (vendor === '360dialog' ? 'تحقق من حالة Coexistence في لوحة 360dialog' : 'لم يُتحقق بعد: اضغط «اختبار الاتصال»') : coexistence.state ? 'تحققت Meta: الرقم على التطبيق وعلى Cloud API معًا' : 'Meta تقول إن الرقم ليس على تطبيق WhatsApp Business (ليس Coexistence)', ok: coexistence.state === true },
                            { label: coexistence.connection === 'disconnected' ? `الرقم منفصل عن المنصة (${coexistence.disconnected_reason ?? ''})` : 'الرقم متصل بالمنصة', ok: coexistence.connection !== 'disconnected' },
                        ]} />
                        <p className="mt-3 flex gap-2 text-xs leading-6 text-muted"><Smartphone className="mt-1 size-3.5 shrink-0" />ما ترسله من التطبيق يظهر في المحادثات بعلامة «من تطبيق WhatsApp Business» ولا يُنسب لموظف. افتح التطبيق على الهاتف بانتظام، فطول انقطاعه يفصل الرقم عن المنصة.</p>

                        <div className="mt-4 border-t border-line pt-3">
                            <h4 className="mb-1 text-[13px] font-semibold">الاستيراد من الهاتف</h4>
                            <p className="mb-2 text-xs leading-6 text-muted">بعد الربط مباشرة تتيح Meta مرة واحدة (خلال 24 ساعة) جلب جهات الاتصال وسجل المحادثات حتى 6 أشهر، بموافقة صاحب الرقم من التطبيق. لا يُستورد شيء إلا إذا فعّلته هنا، ولا تُنشأ عضويات من جهات الاتصال.</p>
                            <form onSubmit={(e) => { e.preventDefault(); importForm.put(route('club.whatsapp.settings.imports'), { preserveScroll: true }); }}>
                                <Toggle checked={importForm.data.import_history} onChange={(v) => importForm.setData('import_history', v)} label="استيراد سجل المحادثات" hint={imports.history_state ? `${HISTORY_STATE[imports.history_state] ?? imports.history_state}${imports.history_progress !== null && imports.history_state === 'importing' ? ` (${imports.history_progress}%)` : ''}` : undefined} />
                                <Toggle checked={importForm.data.import_contacts} onChange={(v) => importForm.setData('import_contacts', v)} label="استيراد أسماء جهات الاتصال" hint={imports.contacts_count ? `${number(imports.contacts_count)} جهة محفوظة` : undefined} />
                                {canManage && <Button type="submit" size="sm" variant="outline" loading={importForm.processing}>حفظ</Button>}
                            </form>
                            {canManage && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} disabled={!imports.contacts} onClick={() => router.post(route('club.whatsapp.settings.sync'), { type: 'smb_app_state_sync' }, { preserveScroll: true })}>طلب جهات الاتصال</Button>
                                    <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} disabled={!imports.history} onClick={() => router.post(route('club.whatsapp.settings.sync'), { type: 'history' }, { preserveScroll: true })}>طلب سجل المحادثات</Button>
                                </div>
                            )}
                            {(imports.history_requested_at || imports.contacts_requested_at) && <p className="mt-2 text-[11px] text-muted num">طُلبت: {imports.history_requested_at ?? ''} {imports.contacts_requested_at ?? ''}</p>}
                            {imports.history_note && <p className="mt-2 text-xs text-amber-700">{imports.history_note}</p>}
                        </div>
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
                <CardHeader title="الإشعارات الواردة (Webhooks)" subtitle={`آخر 7 أيام: ${number(Number(webhooks.last_7_days.processed ?? 0))} عولجت · ${number(Number(webhooks.last_7_days.ignored ?? 0))} لا شيء فيها · ${number(Number(webhooks.last_7_days.received ?? 0))} في الانتظار · ${number(Number(webhooks.last_7_days.failed ?? 0))} فشلت`} />
                {webhooks.failures.length === 0 ? (
                    <p className="text-sm text-muted">لا أخطاء في معالجة الإشعارات. كل إشعار يُحفظ أولًا ويُعاد المحاولة عند الفشل.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>النوع</th><th>المحاولات</th><th>الخطأ</th></tr></thead>
                            <tbody>
                                {webhooks.failures.map((f) => <tr key={f.id}><td className="num text-xs">{f.at}</td><td dir="ltr" className="text-xs">{f.fields}</td><td className="num">{f.attempts}</td><td className="text-xs text-red-700">{f.error}</td></tr>)}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

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
