import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AlertCircle, CreditCard, Info } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { Button, Field, Input } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date } from '@/lib/format';
import { PlanCard, type PlanData } from '../Site/Plans';
import { MembershipCard, type MembershipData } from './Dashboard';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    plans: PlanData[];
    current: MembershipData | null;
    hasPending: boolean;
}

export default function Renew({ site, seo, plans, current, hasPending }: Props) {
    const currency = useCurrency();
    const [showCoupon, setShowCoupon] = useState(false);
    const form = useForm({
        membership_plan_id: (current && plans.some((p) => p.id === current.plan_id) ? current.plan_id : plans[0]?.id ?? null) as number | null,
        coupon_code: '',
    });

    const running = current && ['active', 'frozen', 'scheduled'].includes(current.status);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('portal.renew.store'));
    };

    return (
        <PortalLayout site={site} title={seo.title}>
            {current && (
                <div className="mb-6">
                    <MembershipCard membership={current} compact />
                </div>
            )}

            {hasPending ? (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                    <p className="flex items-center gap-2 text-sm text-amber-800"><AlertCircle className="size-5" /> لديك طلب اشتراك بانتظار الدفع. أكمل دفعه أولًا.</p>
                    <Link href={route('portal.invoices')} className="rounded-full bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">الذهاب للفواتير</Link>
                </div>
            ) : (
                <form onSubmit={submit}>
                    <p className="mb-4 flex items-start gap-2 rounded-2xl bg-brand-50/70 p-4 text-sm text-brand-900">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        {running && current
                            ? <>يبدأ التجديد مباشرة بعد انتهاء عضويتك الحالية في {date(current.ends_on)} — لن تفقد أي يوم.</>
                            : <>يبدأ اشتراكك الجديد من تاريخ تأكيد الدفع.</>}
                    </p>
                    {form.errors.membership_plan_id && <p className="mb-3 text-sm text-red-600">{form.errors.membership_plan_id}</p>}
                    <div className="grid gap-5 pt-3 sm:grid-cols-2">
                        {plans.map((p) => (
                            <PlanCard key={p.id} plan={p} currency={currency} selected={form.data.membership_plan_id === p.id} onSelect={(id) => form.setData('membership_plan_id', id)} />
                        ))}
                    </div>

                    <div className="mt-6 rounded-2xl border border-line bg-white p-5">
                        {showCoupon ? (
                            <Field label="كود الخصم" error={form.errors.coupon_code} className="max-w-xs">
                                <Input dir="ltr" value={form.data.coupon_code} onChange={(e) => form.setData('coupon_code', e.target.value.toUpperCase())} autoFocus />
                            </Field>
                        ) : (
                            <button type="button" onClick={() => setShowCoupon(true)} className="text-sm text-brand hover:underline">لديك كود خصم؟</button>
                        )}
                        <Button type="submit" size="lg" className="mt-5 w-full rounded-full sm:w-auto" loading={form.processing} disabled={!form.data.membership_plan_id} icon={<CreditCard className="size-4" />}>
                            تأكيد التجديد والدفع
                        </Button>
                        <p className="mt-2 text-xs text-muted">نصدر فاتورة التجديد وننقلك للدفع الإلكتروني إن كان متاحًا، وإلا يمكنك الدفع في الاستقبال.</p>
                    </div>
                </form>
            )}
        </PortalLayout>
    );
}
