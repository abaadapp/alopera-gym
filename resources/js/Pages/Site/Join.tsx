import { useMemo, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CreditCard, Lock, Sparkles, Store } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button, Checkbox, Field, Input } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { PageHero, PlanCard, type PlanData } from './Plans';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    plans: PlanData[];
    selected: number | null;
    onlinePayments: boolean;
    payAtReception: boolean;
}

export default function Join({ site, seo, plans, selected, onlinePayments, payAtReception }: Props) {
    const currency = useCurrency();
    const form = useForm({
        membership_plan_id: selected && plans.some((p) => p.id === selected) ? selected : (plans.find((p) => p.is_featured)?.id ?? plans[0]?.id ?? null) as number | null,
        full_name: '',
        phone: '',
        email: '',
        date_of_birth: '',
        password: '',
        password_confirmation: '',
        coupon_code: '',
        marketing_opt_in: false,
        accept_terms: false,
        pay_now: onlinePayments,
        website: '',
    });

    const plan = useMemo(() => plans.find((p) => p.id === form.data.membership_plan_id) ?? null, [plans, form.data.membership_plan_id]);
    const payOnline = onlinePayments && form.data.pay_now;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('site.join.store'), { preserveScroll: true, onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }) });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="اشتركي الآن" subtitle="اختاري باقتك، أكملي بياناتك، وابدئي رحلتك خلال دقائق." icon={<Sparkles className="size-6" />} />

            <form onSubmit={submit} className="mx-auto max-w-6xl px-4 py-12">
                <h2 className="mb-1 font-display text-xl font-semibold"><span className="text-brand num">1.</span> اختاري الباقة</h2>
                {form.errors.membership_plan_id && <p className="mb-2 text-sm text-red-600">{form.errors.membership_plan_id}</p>}
                {plans.length === 0 ? (
                    <p className="py-10 text-center text-muted">لا توجد باقات متاحة للتسجيل الإلكتروني حاليًا. تواصلي مع النادي.</p>
                ) : (
                    <div className="mt-5 grid gap-5 pt-3 sm:grid-cols-2 lg:grid-cols-3">
                        {plans.map((p) => (
                            <PlanCard key={p.id} plan={p} currency={currency} selected={form.data.membership_plan_id === p.id} onSelect={(id) => form.setData('membership_plan_id', id)} />
                        ))}
                    </div>
                )}

                <div className="mt-14 grid gap-8 lg:grid-cols-3">
                    <div className="rounded-3xl border border-line bg-white p-6 shadow-sm sm:p-8 lg:col-span-2">
                        <h2 className="font-display text-xl font-semibold"><span className="text-brand num">2.</span> بياناتك</h2>
                        <div className="mt-6 grid gap-4 sm:grid-cols-2">
                            <Field label="الاسم الكامل" error={form.errors.full_name} required className="sm:col-span-2">
                                <Input value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} autoComplete="name" required />
                            </Field>
                            <Field label="رقم الهاتف (واتساب)" error={form.errors.phone} required hint="سنرسل تأكيد الاشتراك والفواتير على واتساب">
                                <Input type="tel" dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} autoComplete="tel" placeholder="9XXXXXXX" required />
                            </Field>
                            <Field label="البريد الإلكتروني" error={form.errors.email}>
                                <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="email" />
                            </Field>
                            <Field label="تاريخ الميلاد" error={form.errors.date_of_birth}>
                                <Input type="date" value={form.data.date_of_birth} onChange={(e) => form.setData('date_of_birth', e.target.value)} />
                            </Field>
                            <Field label="كود الخصم" error={form.errors.coupon_code}>
                                <Input dir="ltr" value={form.data.coupon_code} onChange={(e) => form.setData('coupon_code', e.target.value.toUpperCase())} placeholder="اختياري" />
                            </Field>
                            <Field label="كلمة المرور" error={form.errors.password} required hint="لدخول بوابة المشتركة — 8 أحرف على الأقل">
                                <Input type="password" dir="ltr" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" required />
                            </Field>
                            <Field label="تأكيد كلمة المرور" error={form.errors.password_confirmation} required>
                                <Input type="password" dir="ltr" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" required />
                            </Field>
                            <div className="hidden" aria-hidden="true">
                                <label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} /></label>
                            </div>
                        </div>

                        <div className="mt-6 space-y-3 rounded-2xl bg-brand-50/60 p-4">
                            <Checkbox
                                label="أوافق على استلام العروض والأخبار التسويقية عبر واتساب (يمكنك إلغاء ذلك في أي وقت)"
                                checked={form.data.marketing_opt_in}
                                onChange={(e) => form.setData('marketing_opt_in', e.target.checked)}
                            />
                            <div>
                                <Checkbox label="أوافق على الشروط والأحكام وسياسة النادي" checked={form.data.accept_terms} onChange={(e) => form.setData('accept_terms', e.target.checked)} />
                                {form.errors.accept_terms && <p className="mt-1 text-xs font-medium text-red-600">{form.errors.accept_terms}</p>}
                            </div>
                        </div>
                    </div>

                    <aside className="h-fit space-y-4 lg:sticky lg:top-24">
                        <div className="rounded-3xl border border-line bg-white p-6 shadow-sm">
                            <h2 className="font-display text-lg font-semibold">ملخص الاشتراك</h2>
                            {plan ? (
                                <dl className="mt-4 space-y-2.5 text-sm">
                                    <div className="flex justify-between gap-3"><dt className="text-muted">الباقة</dt><dd className="font-medium">{plan.name}</dd></div>
                                    <div className="flex justify-between gap-3"><dt className="text-muted">المدة</dt><dd>{plan.duration_label}</dd></div>
                                    <div className="flex justify-between gap-3"><dt className="text-muted">السعر</dt><dd className="num">{money(plan.price, currency)}</dd></div>
                                    {Number(plan.signup_fee) > 0 && <div className="flex justify-between gap-3"><dt className="text-muted">رسوم التسجيل</dt><dd className="num">{money(plan.signup_fee, currency)}</dd></div>}
                                    <div className="flex justify-between gap-3 border-t border-line pt-3 text-base font-semibold">
                                        <dt>الإجمالي</dt>
                                        <dd className="num">{money(Number(plan.price) + Number(plan.signup_fee), currency)}</dd>
                                    </div>
                                    {form.data.coupon_code && <p className="text-xs text-muted">يُطبّق كود الخصم عند إصدار الفاتورة.</p>}
                                </dl>
                            ) : (
                                <p className="mt-3 text-sm text-muted">اختاري باقة أولًا.</p>
                            )}
                        </div>

                        <div className="rounded-3xl border border-line bg-white p-6 shadow-sm">
                            <h2 className="font-display text-lg font-semibold">طريقة الدفع</h2>
                            <div className="mt-4 space-y-2">
                                {onlinePayments && (
                                    <button type="button" onClick={() => form.setData('pay_now', true)} className={cn('flex w-full items-start gap-3 rounded-2xl border p-3 text-start transition', form.data.pay_now ? 'border-brand bg-brand-50' : 'border-line')}>
                                        <CreditCard className="mt-0.5 size-5 text-brand" />
                                        <span><span className="block text-sm font-medium">دفع إلكتروني الآن</span><span className="block text-xs text-muted">بطاقة بنكية عبر بوابة دفع آمنة، ويُفعَّل اشتراكك فور تأكيد الدفع.</span></span>
                                    </button>
                                )}
                                {(payAtReception || !onlinePayments) && (
                                    <button type="button" onClick={() => form.setData('pay_now', false)} className={cn('flex w-full items-start gap-3 rounded-2xl border p-3 text-start transition', !form.data.pay_now ? 'border-brand bg-brand-50' : 'border-line')}>
                                        <Store className="mt-0.5 size-5 text-brand" />
                                        <span><span className="block text-sm font-medium">الدفع في الاستقبال</span><span className="block text-xs text-muted">نصدر فاتورتك الآن، ويُفعَّل الاشتراك بعد تأكيد الدفع في النادي.</span></span>
                                    </button>
                                )}
                            </div>
                            <Button type="submit" size="lg" className="mt-5 w-full rounded-full" loading={form.processing} disabled={!plan} icon={<Lock className="size-4" />}>
                                {payOnline ? 'التسجيل والانتقال للدفع' : 'إتمام التسجيل'}
                            </Button>
                            <p className="mt-3 text-center text-xs text-muted">
                                لديكِ حساب؟ <Link href={route('portal.login')} className="font-medium text-brand">سجّلي الدخول</Link>
                            </p>
                        </div>
                    </aside>
                </div>
            </form>
        </SiteLayout>
    );
}
