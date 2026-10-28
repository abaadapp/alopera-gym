import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, CreditCard, ExternalLink, PlayCircle, Users } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Button, Card, CardHeader, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Stat } from '@/Components/ui';
import { date, money, number } from '@/lib/format';
import { label, tenantStatus } from '@/lib/labels';

interface Tenant {
    id: number;
    name: string;
    slug: string;
    legal_name: string | null;
    status: string;
    domain: string | null;
    email: string | null;
    phone: string | null;
    city: string | null;
    primary_color: string;
    secondary_color: string;
    suspension_reason: string | null;
    logo_url: string | null;
    created_at: string;
    trial_ends_at: string | null;
    listed_on_landing: boolean;
}

interface PlatformInvoiceRow {
    id: number;
    number: string;
    status: string;
    amount: string;
    currency: string;
    payment_method: string | null;
    period: string;
    due_on: string;
    paid_at: string | null;
}

interface Props {
    tenant: Tenant;
    owner: { id: number; name: string; email: string } | null;
    usage: { members: number; active_memberships: number; staff: number };
    subscriptions: { id: number; plan: string | null; cycle: string; status: string; price: string; starts_on: string; ends_on: string }[];
    invoices: PlatformInvoiceRow[];
    plans: { id: number; name: string; price_monthly: string; price_yearly: string }[];
}

export const SUB_STATUS: Record<string, [string, 'blue' | 'green' | 'amber' | 'gray' | 'red']> = {
    trialing: ['تجريبي', 'blue'],
    active: ['نشط', 'green'],
    past_due: ['بانتظار السداد', 'amber'],
    cancelled: ['ملغى', 'gray'],
    expired: ['منتهٍ', 'red'],
};

export const PLATFORM_INVOICE_STATUS: Record<string, [string, 'amber' | 'green' | 'gray']> = { unpaid: ['غير مدفوعة', 'amber'], paid: ['مدفوعة', 'green'], void: ['ملغاة', 'gray'] };

export const PAY_METHODS: Record<string, string> = { bank_transfer: 'تحويل بنكي', cash: 'نقدًا', card: 'بطاقة', other: 'أخرى' };

export default function TenantShow({ tenant, owner, usage, subscriptions, invoices, plans }: Props) {
    const [st, tone] = label(tenantStatus, tenant.status);
    const [paying, setPaying] = useState<PlatformInvoiceRow | null>(null);

    const profile = useForm({
        name: tenant.name,
        legal_name: tenant.legal_name ?? '',
        domain: tenant.domain ?? '',
        email: tenant.email ?? '',
        phone: tenant.phone ?? '',
        city: tenant.city ?? '',
        listed_on_landing: tenant.listed_on_landing,
    });
    const subscribe = useForm({ platform_plan_id: plans[0] ? String(plans[0].id) : '', billing_cycle: 'monthly' });

    const saveProfile = (e: FormEvent) => {
        e.preventDefault();
        profile.put(route('platform.tenants.update', tenant.id), { preserveScroll: true });
    };
    const startSubscription = (e: FormEvent) => {
        e.preventDefault();
        subscribe.post(route('platform.tenants.subscribe', tenant.id), { preserveScroll: true });
    };

    const selectedPlan = plans.find((p) => String(p.id) === subscribe.data.platform_plan_id);

    return (
        <PlatformLayout title={tenant.name}>
            <PageHeader
                back={<Link href={route('platform.tenants.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight className="size-4" /> الأندية</Link>}
                title={<span className="flex items-center gap-3">{tenant.logo_url ? <img src={tenant.logo_url} alt="" className="size-10 rounded-xl object-cover" /> : <span className="grid size-10 place-items-center rounded-xl text-white" style={{ background: tenant.primary_color }}>{tenant.name[0]}</span>}{tenant.name}</span>}
                subtitle={<span className="flex flex-wrap items-center gap-2"><Badge tone={tone}>{st}</Badge><span dir="ltr" className="text-xs">/c/{tenant.slug}</span>{tenant.trial_ends_at && tenant.status === 'trial' && <span className="text-xs">التجربة حتى {date(tenant.trial_ends_at)}</span>}</span>}
                actions={
                    <>
                        <Button variant="outline" href={route('site.home', { club: tenant.slug })} external icon={<ExternalLink className="size-4" />}>موقع النادي</Button>
                        {tenant.status === 'suspended' ? (
                            <ConfirmAction url={route('platform.tenants.reactivate', tenant.id)} title="إعادة تفعيل النادي" message="ستعود لوحة النادي وموقعه للعمل فورًا." tone="primary" variant="success" size="md" icon={<PlayCircle className="size-4" />}>إعادة التفعيل</ConfirmAction>
                        ) : (
                            <ConfirmAction url={route('platform.tenants.suspend', tenant.id)} title="إيقاف النادي" message="تتوقف لوحة النادي وموقعه وبوابة المشتركات وأجهزة الدخول. لا تُحذف أي بيانات." reason="سبب الإيقاف (يظهر للنادي)" confirmLabel="إيقاف" size="md">إيقاف</ConfirmAction>
                        )}
                    </>
                }
            />

            {tenant.suspension_reason && tenant.status === 'suspended' && <div className="mb-6 rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-800">سبب الإيقاف: {tenant.suspension_reason}</div>}

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="بيانات النادي" />
                    <form onSubmit={saveProfile} className="grid gap-4 sm:grid-cols-2">
                        <Field label="الاسم" error={profile.errors.name} required>
                            <Input value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} />
                        </Field>
                        <Field label="الاسم القانوني" error={profile.errors.legal_name}>
                            <Input value={profile.data.legal_name} onChange={(e) => profile.setData('legal_name', e.target.value)} />
                        </Field>
                        <Field label="نطاق مخصص للموقع" error={profile.errors.domain} hint="مثال: alopera.om — وجّهي سجل DNS إلى خادم المنصة وفعّلي SSL" className="sm:col-span-2">
                            <Input dir="ltr" value={profile.data.domain} onChange={(e) => profile.setData('domain', e.target.value.trim().toLowerCase())} placeholder="club-domain.com" />
                        </Field>
                        <Field label="البريد" error={profile.errors.email}>
                            <Input type="email" dir="ltr" value={profile.data.email} onChange={(e) => profile.setData('email', e.target.value)} />
                        </Field>
                        <Field label="الهاتف" error={profile.errors.phone}>
                            <Input dir="ltr" value={profile.data.phone} onChange={(e) => profile.setData('phone', e.target.value)} />
                        </Field>
                        <Field label="المدينة" error={profile.errors.city}>
                            <Input value={profile.data.city} onChange={(e) => profile.setData('city', e.target.value)} />
                        </Field>
                        <div className="sm:col-span-2">
                            <Checkbox label="إظهار النادي في صفحة المنصة العامة (بموافقة النادي)" checked={profile.data.listed_on_landing} onChange={(e) => profile.setData('listed_on_landing', e.target.checked)} />
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" loading={profile.processing}>حفظ</Button>
                        </div>
                    </form>
                </Card>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title={<span className="flex items-center gap-2"><Users className="size-4" /> الاستخدام</span>} />
                        <dl className="grid grid-cols-3 gap-3">
                            <Stat label="مشتركات" value={<span className="num text-lg">{number(usage.members)}</span>} />
                            <Stat label="عضويات نشطة" value={<span className="num text-lg">{number(usage.active_memberships)}</span>} />
                            <Stat label="مستخدمات" value={<span className="num text-lg">{number(usage.staff)}</span>} />
                        </dl>
                    </Card>
                    <Card>
                        <CardHeader title="المالكة" />
                        {owner ? (
                            <div className="text-sm">
                                <p className="font-medium">{owner.name}</p>
                                <p className="text-xs text-muted" dir="ltr">{owner.email}</p>
                            </div>
                        ) : (
                            <p className="text-sm text-muted">لا يوجد حساب مالكة نشط.</p>
                        )}
                        <p className="mt-3 text-xs text-muted">أُنشئ في {date(tenant.created_at)}</p>
                    </Card>
                </div>
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card padded={false} className="lg:col-span-2">
                    <div className="p-5 pb-0"><CardHeader title="الاشتراكات" /></div>
                    {subscriptions.length === 0 ? (
                        <EmptyState title="لا توجد اشتراكات" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>الباقة</th><th>الدورة</th><th>السعر</th><th>الفترة</th><th>الحالة</th></tr></thead>
                                <tbody>
                                    {subscriptions.map((s) => {
                                        const [l, t] = SUB_STATUS[s.status] ?? [s.status, 'gray' as const];
                                        return (
                                            <tr key={s.id}>
                                                <td className="font-medium">{s.plan ?? '—'}</td>
                                                <td>{s.cycle === 'yearly' ? 'سنوي' : 'شهري'}</td>
                                                <td className="num">{money(s.price, 'OMR')}</td>
                                                <td className="text-xs">{date(s.starts_on)} ← {date(s.ends_on)}</td>
                                                <td><Badge tone={t}>{l}</Badge></td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card>
                    <CardHeader title="بدء/تغيير الاشتراك" subtitle="يلغي الاشتراك الحالي ويصدر فاتورة منصة جديدة" />
                    <form onSubmit={startSubscription} className="space-y-4">
                        <Field label="الباقة" error={subscribe.errors.platform_plan_id}>
                            <Select value={subscribe.data.platform_plan_id} onChange={(e) => subscribe.setData('platform_plan_id', e.target.value)}>
                                {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="الدورة" error={subscribe.errors.billing_cycle}>
                            <Select value={subscribe.data.billing_cycle} onChange={(e) => subscribe.setData('billing_cycle', e.target.value)}>
                                <option value="monthly">شهري {selectedPlan && `— ${money(selectedPlan.price_monthly, 'OMR')}`}</option>
                                <option value="yearly">سنوي {selectedPlan && `— ${money(selectedPlan.price_yearly, 'OMR')}`}</option>
                            </Select>
                        </Field>
                        <Button type="submit" className="w-full" loading={subscribe.processing}>إصدار الاشتراك والفاتورة</Button>
                    </form>
                </Card>
            </div>

            <Card padded={false} className="mt-6">
                <div className="p-5 pb-0"><CardHeader title="فواتير المنصة" /></div>
                {invoices.length === 0 ? (
                    <EmptyState title="لا توجد فواتير" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الرقم</th><th>الفترة</th><th>المبلغ</th><th>الاستحقاق</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {invoices.map((i) => {
                                    const [l, t] = PLATFORM_INVOICE_STATUS[i.status] ?? [i.status, 'gray' as const];
                                    return (
                                        <tr key={i.id}>
                                            <td className="num">{i.number}</td>
                                            <td className="text-xs num">{i.period}</td>
                                            <td className="num">{money(i.amount, i.currency)}</td>
                                            <td>{date(i.due_on)}</td>
                                            <td>
                                                <Badge tone={t}>{l}</Badge>
                                                {i.paid_at && <span className="ms-2 text-xs text-muted">{PAY_METHODS[i.payment_method ?? ''] ?? i.payment_method} · {date(i.paid_at)}</span>}
                                            </td>
                                            <td className="text-end">
                                                {i.status === 'unpaid' && <Button size="sm" variant="secondary" icon={<CreditCard className="size-3.5" />} onClick={() => setPaying(i)}>تسجيل السداد</Button>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {paying && <PayInvoiceModal invoice={paying} onClose={() => setPaying(null)} />}
        </PlatformLayout>
    );
}

export function PayInvoiceModal({ invoice, onClose }: { invoice: { id: number; number: string; amount: string; currency: string }; onClose: () => void }) {
    const form = useForm({ method: 'bank_transfer', reference: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('platform.invoices.pay', invoice.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} size="sm" title={`سداد فاتورة ${invoice.number}`} footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>تأكيد السداد</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm">المبلغ: <b className="num">{money(invoice.amount, invoice.currency)}</b> — يُفعّل اشتراك النادي فور التأكيد.</p>
                <Field label="طريقة الدفع" error={form.errors.method}>
                    <Select value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                        {Object.entries(PAY_METHODS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                    </Select>
                </Field>
                <Field label="المرجع" error={form.errors.reference} hint="رقم التحويل أو الإيصال">
                    <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}
