import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Apple, CircleCheck, CircleDashed, CreditCard, Landmark, Link2, Plug, Plus, ShieldCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Notice, PageHeader } from '@/Components/ui';
import { CheckList, CopyRow, IntegrationBack, IntegrationSummary, KeysForm, type IntegrationStatusData, type ProviderForm } from '@/Components/Integrations';
import { PaymentLinksLog, type PaymentLinksData } from '@/Components/PaymentLinks';

interface Props {
    integration: ProviderForm;
    status: IntegrationStatusData;
    warning: string | null;
    methods: { key: 'card' | 'omannet' | 'apple_pay'; label: string; saved: boolean; state: 'not_set' | 'saved' | 'checked' | 'enabled' | 'available' | 'wrong_mode'; reason: string; within_cards?: boolean }[];
    /** The settings read without a call to Paymob. */
    review: { label: string; ok: boolean; detail: string | null }[];
    /**
     * manage_route: «المالية ← روابط الدفع», where links are followed (null without the permission); can_create: the
     * invoice form, which issues the invoice and its link in one step; recent: the latest links (null without permission).
     */
    links: { on: boolean; reason: string | null; invoices_route: string; create_route: string; manage_route: string | null; can_create: boolean; recent: PaymentLinksData | null };
    checks: { label: string; ok: boolean; at: string | null }[];
    usage: { label: string; on: boolean; route?: string | null }[];
    intents: { id: number; reference: string; invoice: string | null; channel: string; amount: string; currency: string; status: string; failure: string | null; at: string }[];
    events: { id: number; reference: string | null; valid: boolean; outcome: string; ok: boolean; at: string }[];
    endpoints: { webhook: string; return: string };
    currency: string;
}

const FIELDS = [
    { key: 'secret_key', label: 'Secret key', hint: 'من لوحة Paymob ← Settings ← API Keys، ويختلف بين وضعي الاختبار والحقيقي', secret: true },
    { key: 'public_key', label: 'Public key' },
    { key: 'hmac_secret', label: 'HMAC secret', hint: 'للتحقق من توقيع إشعارات الدفع', secret: true },
    { key: 'integration_ids', label: 'أرقام ربط إضافية (اختياري)', hint: 'الطريقة السابقة: أرقام مفصولة بفاصلة. يكفي رقم البطاقات في قسمه أدناه.' },
];

const METHOD_STATE: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'gray' }> = {
    not_set: { label: 'غير مضاف', tone: 'gray' },
    saved: { label: 'رقم محفوظ', tone: 'amber' },
    checked: { label: 'تم التحقق من الربط', tone: 'amber' },
    enabled: { label: 'مفعّلة لدى Paymob', tone: 'amber' },
    available: { label: 'متاحة فعليًا', tone: 'green' },
    wrong_mode: { label: 'لا تناسب بيئة المفاتيح', tone: 'red' },
};

type MethodKey = 'card' | 'omannet' | 'apple_pay';

/** The three method sections share one form: the numbers are kept with the keys and never come back (blank keeps). */
function useMethodForm() {
    return useForm<{ card: string; omannet: string; apple_pay: string; clear: MethodKey[] }>({ card: '', omannet: '', apple_pay: '', clear: [] });
}

function StateLine({ method }: { method: Props['methods'][number] }) {
    return (
        <div className="space-y-1">
            <Badge tone={METHOD_STATE[method.state]?.tone ?? 'gray'}>{METHOD_STATE[method.state]?.label ?? method.state}</Badge>
            <p className="text-xs leading-6 text-muted">{method.reason}</p>
        </div>
    );
}

function MethodSection({ method, form, icon, title, subtitle, hint, note }: {
    method: Props['methods'][number]; form: ReturnType<typeof useMethodForm>; icon: React.ReactNode; title: string; subtitle: string; hint: string; note?: React.ReactNode;
}) {
    const key = method.key;
    const clearing = form.data.clear.includes(key);
    const error = (form.errors as Record<string, string | undefined>)[key];

    return (
        <Card>
            <CardHeader title={<span className="flex items-center gap-2">{icon}{title}</span>} subtitle={subtitle} />
            <form
                className="space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(route('club.integrations.paymob.methods'), { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <label htmlFor={`method-${key}`} className="block text-sm font-medium text-ink">رقم التكامل (Integration ID)</label>
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        id={`method-${key}`}
                        inputMode="numeric"
                        dir="ltr"
                        autoComplete="off"
                        disabled={clearing}
                        placeholder={method.saved ? 'محفوظ — اكتب رقمًا آخر لتغييره' : 'غير مضاف'}
                        className="min-w-0 flex-1 rounded-lg border border-line bg-white px-3 py-2 text-sm disabled:opacity-50"
                        value={form.data[key]}
                        onChange={(e) => form.setData(key, e.target.value.replace(/[^\d]/g, ''))}
                    />
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-ink px-3 py-2 text-sm text-white disabled:opacity-50">حفظ</button>
                </div>
                <p className="text-xs leading-6 text-muted">{hint}</p>
                {method.saved && (
                    <label className="flex items-center gap-1.5 text-xs text-muted">
                        <input type="checkbox" checked={clearing} onChange={(e) => form.setData('clear', e.target.checked ? [...form.data.clear, key] : form.data.clear.filter((k) => k !== key))} />
                        إزالة الرقم المحفوظ
                    </label>
                )}
                {error && <p className="text-xs text-red-600">{error}</p>}
                {note}
                <div className="border-t border-line pt-3"><StateLine method={method} /></div>
            </form>
        </Card>
    );
}

function PayLinks({ links }: { links: Props['links'] }) {
    return (
        <Card>
            <CardHeader title={<span className="flex items-center gap-2"><Link2 className="size-4" />روابط الدفع</span>} subtitle="كل رابط على فاتورة: «إنشاء رابط دفع» يصدر الفاتورة ورابطها معًا من نموذج الفاتورة، ورابط فاتورة صادرة يُضاف من صفحتها دون فاتورة ثانية." />
            <ul className="space-y-2 text-sm">
                <li className="flex items-center gap-2.5">
                    {links.on ? <CircleCheck className="size-[18px] shrink-0 text-emerald-600" /> : <CircleDashed className="size-[18px] shrink-0 text-[#9ca3af]" />}
                    <span className={links.on ? 'text-ink' : 'text-muted'}>{links.on ? 'روابط الفواتير تفتح صفحة الدفع' : 'روابط الفواتير تعرض الفاتورة فقط حتى يُفعَّل الدفع الإلكتروني'}</span>
                </li>
                {!links.on && links.reason && <li className="ps-7 text-xs leading-6 text-muted">{links.reason}</li>}
            </ul>
            {links.can_create && (
                <div className="mt-3">
                    <Button href={route(links.create_route)} size="sm" icon={<Plus />}>إنشاء رابط دفع (فاتورة ورابطها)</Button>
                </div>
            )}
            <div className="mt-3 flex flex-wrap gap-3 text-xs">
                {links.manage_route && <Link href={route(links.manage_route)} className="font-medium text-[#1d4ed8] underline">المالية ← روابط الدفع</Link>}
                <Link href={route(links.invoices_route)} className="text-[#1d4ed8] underline">رابط لفاتورة صادرة (من صفحتها)</Link>
            </div>
            <p className="mt-3 text-xs leading-6 text-muted">
                لا تُسجَّل دفعة عند إصدار الرابط ولا عند رجوع العميل من Paymob؛ تُسجَّل فقط بإشعار موقّع ومطابق، مرة واحدة. روابط Paymob السريعة (QuickLink) غير مستخدمة: لم تثبت وثائق Paymob ربط إشعارها الموقّع بالفاتورة.
            </p>
        </Card>
    );
}

const INTENT_STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'gray' }> = {
    pending: { label: 'بانتظار الدفع', tone: 'amber' },
    paid: { label: 'مدفوع', tone: 'green' },
    failed: { label: 'فشل', tone: 'red' },
    expired: { label: 'انتهت المهلة', tone: 'gray' },
};

/**
 * The connection test opens a payment intention at Paymob (1.000, sent to no one, gone in ten minutes): only after this
 * dialog says so — in live mode, in the club's real account. The settings check above it calls nothing.
 */
function ProbeButton({ disabled, live }: { disabled: boolean; live: boolean }) {
    if (disabled) {
        return <Button variant="outline" size="sm" icon={<Plug />} disabled>اختبار الاتصال</Button>;
    }
    return (
        <ConfirmAction
            url={route('club.integrations.verify', 'paymob')}
            data={{ confirm_intention: 1 }}
            title="اختبار الاتصال بـ Paymob"
            message={`يفتح لدى Paymob صفحة دفع تجريبية (Payment Intention) بمبلغ 1.000 لا تُرسل لأحد ولا يُخصم منها شيء، وتنتهي وحدها خلال 10 دقائق${live ? '، في حسابك الحقيقي لأن الوضع «حقيقي»' : ''}. لا تُسجَّل دفعة ولا تُصدر فاتورة. «فحص الإعدادات» لا يحتاج هذا الاختبار.`}
            confirmLabel="افتح صفحة الاختبار"
            tone="primary"
            icon={<Plug />}
        >
            اختبار الاتصال
        </ConfirmAction>
    );
}

export default function PaymobIntegration({ integration, status, warning, review, methods, links, checks, usage, intents, events, endpoints }: Props) {
    const complete = checks[0]?.ok;
    const methodForm = useMethodForm();
    const byKey = Object.fromEntries(methods.map((m) => [m.key, m])) as Record<MethodKey, Props['methods'][number]>;

    return (
        <ClubLayout title="Paymob — الدفع الإلكتروني">
            <PageHeader back={<IntegrationBack />} title="Paymob — الدفع الإلكتروني" subtitle="مفاتيح حساب النادي لدى Paymob، ووضع الاختبار أو الإنتاج، وسجل عمليات الدفع والإشعارات." />

            <IntegrationSummary service="paymob" title="Paymob" status={status}>
                <ProbeButton disabled={!complete} live={integration.mode === 'live'} />
            </IntegrationSummary>

            {warning && (
                <Notice tone="amber" icon={<AlertTriangle />} className="mb-6">{warning}</Notice>
            )}

            <div className="grid gap-6 lg:grid-cols-5 [&>*]:min-w-0">
                <div className="space-y-6 lg:col-span-3">
                <Card>
                    <CardHeader title="المفاتيح والوضع" subtitle="تُحفظ مشفّرة. وضع «اختبار» لمفاتيح الاختبار، و«إنتاج» لاستقبال مدفوعات حقيقية." />
                    <KeysForm
                        provider="paymob"
                        integration={integration}
                        fields={FIELDS}
                        modeHint="جرّب بمفاتيح الاختبار أولًا، ثم انتقل إلى الإنتاج بمفاتيحه."
                    />
                    {integration.last_error && (
                        <p className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-xs leading-6 text-red-700">
                            آخر خطأ{integration.last_error_at ? ` (${integration.last_error_at})` : ''}: {integration.last_error}
                        </p>
                    )}
                </Card>

                <MethodSection method={byKey.card} form={methodForm} icon={<CreditCard className="size-4" />} title="البطاقات البنكية"
                    subtitle="Visa وMastercard وغيرها، من تكامل البطاقات في حسابك."
                    hint="من لوحة Paymob ← Payment Integrations، بالبيئة نفسها التي مفاتيحها أعلاه (Test أو Live)." />
                <MethodSection method={byKey.omannet} form={methodForm} icon={<Landmark className="size-4" />} title="OmanNet"
                    subtitle="بطاقات الخصم العُمانية."
                    hint="اختياري: أدخله فقط إن أعطتك Paymob تكاملًا مستقلًا لـ OmanNet برقم غير رقم البطاقات. إن كان OmanNet مفعّلًا ضمن تكامل البطاقات فاترك الحقل فارغًا."
                    note={byKey.omannet.within_cards ? <p className="rounded-lg bg-[#f8fafc] px-3 py-2 text-xs leading-6 text-muted">بلا رقم مستقل: يتبع OmanNet تكامل البطاقات.</p> : undefined} />
                <MethodSection method={byKey.apple_pay} form={methodForm} icon={<Apple className="size-4" />} title="Apple Pay"
                    subtitle="يظهر داخل صفحة دفع Paymob على الأجهزة التي تدعمه، بعد أن تفعّله Paymob لحسابك. لا يعرض النظام زرًا خاصًا به."
                    hint="اختياري: رقم تكامل Apple Pay الحقيقي (Live) — لا أرقام تجريبية له لدى Paymob، فلا يُرسل مع مفاتيح الاختبار." />
                <PayLinks links={links} />
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="حالة وسائل الدفع" subtitle="«متاحة فعليًا» لا تظهر لمجرد حفظ رقم: يلزم دفعة موقّعة عبرها والدفع الإلكتروني مفعّل." />
                        <ul className="space-y-3 text-sm">
                            {methods.map((m) => (
                                <li key={m.key} className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-ink">{m.label}</span>
                                    <Badge tone={METHOD_STATE[m.state]?.tone ?? 'gray'}>{METHOD_STATE[m.state]?.label ?? m.state}</Badge>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card>
                        <CardHeader title="فحص الإعدادات" subtitle="يُقرأ من الإعدادات المحفوظة دون أي اتصال بـ Paymob ودون فتح صفحة دفع." />
                        <ul className="divide-y divide-line" data-testid="paymob-review">
                            {review.map((item) => (
                                <li key={item.label} className="flex items-start gap-3 py-2.5 text-sm">
                                    {item.ok ? <CircleCheck className="mt-0.5 size-[18px] shrink-0 text-emerald-600" /> : <AlertTriangle className="mt-0.5 size-[18px] shrink-0 text-amber-600" />}
                                    <span className="min-w-0 flex-1">
                                        <span className={item.ok ? 'text-ink' : 'text-amber-800'}>{item.label}</span>
                                        {item.detail && <span className="block text-xs leading-6 text-muted">{item.detail}</span>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card>
                        <CardHeader title="اختبار الاتصال" subtitle="«متصل» لا تظهر إلا بعد قبول Paymob للمفاتيح وأرقام الربط فعلًا" />
                        <CheckList items={checks} />
                        <p className="mt-3 text-xs leading-6 text-muted">
                            «اختبار الاتصال» يفتح لدى Paymob صفحة دفع تجريبية بمبلغ 1.000 لا تُرسل لأحد ولا يُخصم منها شيء، ثم تنتهي وحدها.
                        </p>
                        <div className="mt-3"><ProbeButton disabled={!complete} live={integration.mode === 'live'} /></div>
                    </Card>

                    <Card>
                        <CardHeader title="أين يُستخدم الدفع الإلكتروني" />
                        <ul className="space-y-2.5 text-sm">
                            {usage.map((u) => (
                                <li key={u.label} className="flex items-center gap-2.5">
                                    {u.on ? <CircleCheck className="size-[18px] shrink-0 text-emerald-600" /> : <CircleDashed className="size-[18px] shrink-0 text-[#9ca3af]" />}
                                    <span className={u.on ? 'text-ink' : 'text-muted'}>{u.label}</span>
                                    {u.route && <Link href={route(u.route)} className="ms-auto text-xs text-muted underline hover:text-ink">الإعداد</Link>}
                                </li>
                            ))}
                        </ul>
                        {usage.some((u) => u.on) && status.state !== 'connected' && (
                            <p className="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs leading-6 text-amber-800">
                                <AlertTriangle className="mt-1 size-3.5 shrink-0" />
                                الدفع الإلكتروني معروض للأعضاء في هذه الأماكن بينما لم تُقبل المفاتيح بعد. أوقف «تفعيل» حتى ينجح «اختبار الاتصال».
                            </p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="عناوين الربط" subtitle="تُضبط في لوحة Paymob" />
                        <div className="space-y-3">
                            <CopyRow label="Transaction processed callback (Webhook)" value={endpoints.webhook} />
                            <CopyRow label="صفحة العودة (تُرسل تلقائيًا مع كل عملية)" value={endpoints.return} />
                        </div>
                        <p className="mt-3 flex items-start gap-2 text-xs leading-6 text-muted">
                            <ShieldCheck className="mt-1 size-3.5 shrink-0" />
                            لا تُعتبر أي دفعة ناجحة من صفحة العودة. تُسجَّل الدفعة فقط عند وصول إشعار موقّع بـ HMAC بمبلغ مطابق، ومرة واحدة مهما تكرر الإشعار.
                        </p>
                    </Card>
                </div>
            </div>

            {links.recent && (
                <div className="mt-6">
                    <PaymentLinksLog data={links.recent} subtitle="آخر 5 روابط، كما في «المالية ← روابط الدفع». «مدفوع» بعد إشعار Paymob الموقّع فقط." />
                </div>
            )}

            <div className="mt-6 grid gap-6 xl:grid-cols-2 [&>*]:min-w-0">
                <Card padded={false}>
                    <div className="p-5 pb-0"><CardHeader title="آخر عمليات الدفع الإلكتروني" /></div>
                    {intents.length === 0 ? (
                        <EmptyState title="لا توجد عمليات بعد" description="تظهر هنا كل صفحة دفع فُتحت لعضو أو لطلب من المتجر." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>العملية</th><th>القناة</th><th>المبلغ</th><th>الحالة</th></tr></thead>
                                <tbody>
                                    {intents.map((i) => (
                                        <tr key={i.id}>
                                            <td>
                                                <p className="font-medium">{i.invoice ?? '—'}</p>
                                                <p className="num text-[11px] text-muted">{i.at}</p>
                                            </td>
                                            <td>{i.channel}</td>
                                            <td className="num">{Number(i.amount).toFixed(3)} {i.currency}</td>
                                            <td>
                                                <Badge tone={INTENT_STATUS[i.status]?.tone ?? 'gray'}>{INTENT_STATUS[i.status]?.label ?? i.status}</Badge>
                                                {i.failure && <p className="mt-0.5 max-w-[12rem] truncate text-[11px] text-red-600" title={i.failure}>{i.failure}</p>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card padded={false}>
                    <div className="p-5 pb-0"><CardHeader title="آخر إشعارات Paymob" subtitle="ما وصل الخادم وما فُعل به" /></div>
                    {events.length === 0 ? (
                        <EmptyState title="لم يصل أي إشعار بعد" description="بعد أول دفعة حقيقية أو تجريبية تظهر هنا نتيجة التحقق من توقيعها." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>الوقت</th><th>التوقيع</th><th>النتيجة</th></tr></thead>
                                <tbody>
                                    {events.map((e) => (
                                        <tr key={e.id}>
                                            <td className="num text-xs">{e.at}</td>
                                            <td>{e.valid ? <Badge tone="green">صحيح</Badge> : <Badge tone="red">غير صحيح</Badge>}</td>
                                            <td className={e.ok ? '' : 'text-muted'}>{e.outcome}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </ClubLayout>
    );
}
