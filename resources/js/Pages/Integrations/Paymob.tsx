import { Link } from '@inertiajs/react';
import { AlertTriangle, CircleCheck, CircleDashed, ShieldCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, CardHeader, EmptyState, PageHeader } from '@/Components/ui';
import { CheckList, CopyRow, IntegrationBack, IntegrationSummary, KeysForm, VerifyButton, type IntegrationStatusData, type ProviderForm } from '@/Components/Integrations';

interface Props {
    integration: ProviderForm;
    status: IntegrationStatusData;
    warning: string | null;
    checks: { label: string; ok: boolean; at: string | null }[];
    usage: { label: string; on: boolean; route?: string | null }[];
    intents: { id: number; reference: string; invoice: string | null; channel: string; amount: string; currency: string; status: string; failure: string | null; at: string }[];
    events: { id: number; reference: string | null; valid: boolean; outcome: string; ok: boolean; at: string }[];
    endpoints: { webhook: string; return: string };
    currency: string;
}

const FIELDS = [
    { key: 'secret_key', label: 'Secret key', hint: 'من لوحة Paymob ← Settings ← Account info', secret: true },
    { key: 'public_key', label: 'Public key' },
    { key: 'hmac_secret', label: 'HMAC secret', hint: 'للتحقق من توقيع إشعارات الدفع', secret: true },
    { key: 'integration_ids', label: 'Integration IDs', hint: 'رقم أو أكثر مفصولة بفاصلة (بطاقة، Apple Pay…)' },
];

const INTENT_STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'gray' }> = {
    pending: { label: 'بانتظار الدفع', tone: 'amber' },
    paid: { label: 'مدفوع', tone: 'green' },
    failed: { label: 'فشل', tone: 'red' },
    expired: { label: 'انتهت المهلة', tone: 'gray' },
};

export default function PaymobIntegration({ integration, status, warning, checks, usage, intents, events, endpoints }: Props) {
    const complete = checks[0]?.ok;

    return (
        <ClubLayout title="Paymob — الدفع الإلكتروني">
            <PageHeader back={<IntegrationBack />} title="Paymob — الدفع الإلكتروني" subtitle="مفاتيح حساب النادي لدى Paymob، ووضع الاختبار أو الإنتاج، وسجل عمليات الدفع والإشعارات." />

            <IntegrationSummary service="paymob" title="Paymob" status={status}>
                <VerifyButton provider="paymob" disabled={!complete} />
            </IntegrationSummary>

            {warning && (
                <p className="mb-6 flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" /> {warning}
                </p>
            )}

            <div className="grid gap-6 lg:grid-cols-5 [&>*]:min-w-0">
                <Card className="lg:col-span-3">
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

                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="التحقق" subtitle="«متصل» لا تظهر إلا بعد قبول Paymob للمفاتيح فعلًا" />
                        <CheckList items={checks} />
                        <p className="mt-3 text-xs leading-6 text-muted">
                            «اختبار الاتصال» يفتح لدى Paymob صفحة دفع تجريبية بمبلغ 1.000 لا تُرسل لأحد ولا يُخصم منها شيء، ثم تنتهي وحدها.
                        </p>
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
