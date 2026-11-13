import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, CreditCard, Lock, Store } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Diamond, priceText } from '@/Components/Site/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { currencyLabel, money } from '@/lib/format';
import { useCarryOver } from '@/lib/carry-over';
import { useLocale, useT } from '@/lib/i18n';
import { planAccess, say } from '@/lib/plan-access';
import type { PlanData } from './Plans';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    plans: PlanData[];
    selected: number | null;
    onlinePayments: boolean;
    payAtReception: boolean;
}

/** The four steps, and which fields each one holds (a server error sends the visitor back to its step). */
const STEPS = ['الباقة', 'البيانات', 'الملخص', 'الدفع'] as const;
const FIELD_STEP: Record<string, number> = {
    membership_plan_id: 0,
    full_name: 1, phone: 1, email: 1, date_of_birth: 1, password: 1, password_confirmation: 1,
    coupon_code: 2, marketing_opt_in: 2, accept_terms: 2,
    pay_now: 3,
};
const PHONE = /^[+0-9٠-٩\s-]{8,20}$/u;

const input = 'h-[52px] w-full min-w-0 rounded-2xl border border-[rgba(22,19,15,0.18)] bg-white px-4 text-base text-[var(--site-ink)] outline-none placeholder:text-[#9A9284] focus:border-[var(--site-gold)] focus:ring-2 focus:ring-[var(--site-gold)]/25 aria-[invalid=true]:border-[#A33A22]';

/** What the plan gives (the owner's «صلاحيات الباقة»), said before paying: what it includes, then what it does not. */
function PlanAccessLine({ plan, t, english }: { plan: PlanData; t: ReturnType<typeof useT>; english: boolean }) {
    const access = planAccess(plan);
    if (access.included.length === 0) return null;

    return (
        <span className="mt-0.5 flex flex-col gap-0.5 text-sm">
            <span className="font-medium text-[#3D362C]">{access.included.map((x) => say(x, t, english)).join(' · ')}</span>
            {access.notes.map((x, i) => <span key={i} className="text-[13px] text-[#7A7060]">{say(x, t, english)}</span>)}
        </span>
    );
}

function FieldRow({ label, error, hint, required, children, className }: { label: string; error?: string; hint?: string; required?: boolean; children: ReactNode; className?: string }) {
    return (
        <label className={cn('flex min-w-0 flex-col gap-1.5', className)}>
            <span className="text-sm font-semibold">{label}{required && <span className="text-[var(--site-accent)]"> *</span>}</span>
            {children}
            {error ? <span className="text-sm text-[#A33A22]">{error}</span> : hint && <span className="text-[13px] text-[#7A7060]">{hint}</span>}
        </label>
    );
}

/**
 * Sign-up in four steps, made for the phone first: the plan, her details, the summary, then payment.
 * A bar of steps stays under the header; on phones the total and the next button stay at the bottom of the
 * screen. Nothing is sent before the last step, and the server's checks stay the only ones that count.
 */
export default function Join({ site, seo, plans, selected, onlinePayments, payAtReception }: Props) {
    const currency = useCurrency();
    const t = useT();
    const english = useLocale() === 'en';
    const preselected = selected && plans.some((p) => p.id === selected) ? selected : null;
    const form = useForm({
        membership_plan_id: (preselected ?? plans.find((p) => p.is_featured)?.id ?? plans[0]?.id ?? null) as number | null,
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

    // What was typed survives the language switch (never the password); a plan no longer offered is not restored.
    useCarryOver('join', form.data, (saved) => form.setData((d) => ({ ...d, ...saved, membership_plan_id: plans.some((p) => p.id === saved.membership_plan_id) ? (saved.membership_plan_id as number) : d.membership_plan_id })));

    // A plan chosen on the plans page opens on her details.
    const [step, setStep] = useState(preselected ? 1 : 0);
    const [local, setLocal] = useState<Record<string, string>>({});
    const top = useRef<HTMLDivElement>(null);

    const plan = useMemo(() => plans.find((p) => p.id === form.data.membership_plan_id) ?? null, [plans, form.data.membership_plan_id]);
    const total = plan ? Number(plan.price) + Number(plan.signup_fee) : 0;
    const payOnline = onlinePayments && form.data.pay_now;
    const errors = { ...local, ...(form.errors as Record<string, string>) };

    const go = (n: number) => {
        setStep(n);
        top.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // The server refused something: back to the first step that holds it.
    useEffect(() => {
        const keys = Object.keys(form.errors);
        if (keys.length) go(Math.min(...keys.map((k) => FIELD_STEP[k] ?? 0)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.errors]);

    /** Light checks before moving on, so she fixes a field where she typed it; the server checks everything again. */
    const check = (n: number): boolean => {
        const e: Record<string, string> = {};
        if (n === 0 && !plan) e.membership_plan_id = t('اختاري باقة للمتابعة.');
        if (n === 1) {
            if (!form.data.full_name.trim()) e.full_name = t('اكتبي اسمكِ الكامل.');
            if (!PHONE.test(form.data.phone.trim())) e.phone = t('اكتبي رقم هاتف صحيحًا (8 أرقام على الأقل).');
            if (form.data.email && !/^\S+@\S+\.\S+$/.test(form.data.email)) e.email = t('البريد الإلكتروني غير صحيح.');
            if (form.data.password.length < 8) e.password = t('كلمة المرور 8 أحرف على الأقل.');
            else if (form.data.password !== form.data.password_confirmation) e.password_confirmation = t('كلمتا المرور غير متطابقتين.');
        }
        if (n === 2 && !form.data.accept_terms) e.accept_terms = t('يجب الموافقة على الشروط والأحكام.');
        setLocal(e);
        form.clearErrors();
        return Object.keys(e).length === 0;
    };

    /** Sets a field and drops the note under it: she has just answered it. */
    const set = <K extends keyof typeof form.data>(key: K, value: (typeof form.data)[K]) => {
        form.setData((d) => ({ ...d, [key]: value }));
        if (local[key as string]) setLocal(({ [key as string]: _gone, ...rest }) => rest);
    };

    const next = () => { if (check(step)) go(step + 1); };
    const back = () => { setLocal({}); go(step - 1); };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (step < STEPS.length - 1) return next();
        if (![0, 1, 2].every((n) => check(n))) return;
        form.post(route('site.join.store'), { preserveScroll: true });
    };

    const last = step === STEPS.length - 1;
    const primaryLabel = last ? (payOnline ? t('ادفعي الآن') : t('إتمام التسجيل')) : t('التالي');

    if (plans.length === 0) {
        return (
            <SiteLayout site={site} seoTitle={seo.title}>
                <div className="mx-auto max-w-xl px-5 py-24 text-center">
                    <h1 className="m-0 text-3xl font-bold">{t('اشتركي الآن')}</h1>
                    <p className="mt-4 text-[#5E5648]">{t('لا توجد باقات متاحة للتسجيل الإلكتروني حاليًا. تواصل مع النادي.')}</p>
                </div>
            </SiteLayout>
        );
    }

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <div ref={top} className="scroll-mt-16 bg-[var(--site-surface)]">
                {/* The steps, kept in view under the header. */}
                <nav aria-label={t('خطوات التسجيل')} className="sticky top-16 z-40 border-b border-[rgba(26,22,18,0.08)] bg-[var(--site-surface)]/95 backdrop-blur">
                    <ol className="mx-auto m-0 flex max-w-3xl list-none items-start px-4 py-3 sm:px-6">
                        {STEPS.map((label, i) => {
                            const done = i < step;
                            const current = i === step;
                            return (
                                <li key={label} className="flex min-w-0 flex-1 flex-col items-center gap-1.5">
                                    <div className="flex w-full items-center">
                                        <span className={cn('h-0.5 flex-1 rounded-full', i === 0 ? 'opacity-0' : i <= step ? 'bg-[var(--site-gold)]' : 'bg-[rgba(26,22,18,0.12)]')} />
                                        <button
                                            type="button"
                                            onClick={() => done && go(i)}
                                            disabled={!done}
                                            aria-current={current ? 'step' : undefined}
                                            aria-label={t(label)}
                                            className={cn('grid size-9 shrink-0 place-items-center rounded-full text-sm font-bold transition-colors',
                                                current && 'bg-[var(--site-ink)] text-[var(--site-gold)] ring-4 ring-[var(--site-gold)]/25',
                                                done && 'cursor-pointer bg-[var(--site-gold)] text-[var(--site-ink)]',
                                                !current && !done && 'border border-[rgba(26,22,18,0.18)] bg-white text-[#7A7060]')}
                                        >
                                            {done ? <Check className="size-4" strokeWidth={3} /> : <span className="num">{i + 1}</span>}
                                        </button>
                                        <span className={cn('h-0.5 flex-1 rounded-full', i === STEPS.length - 1 ? 'opacity-0' : i < step ? 'bg-[var(--site-gold)]' : 'bg-[rgba(26,22,18,0.12)]')} />
                                    </div>
                                    <span className={cn('truncate text-xs sm:text-[13px]', current ? 'font-bold text-[var(--site-ink)]' : 'text-[#7A7060]')}>{t(label)}</span>
                                </li>
                            );
                        })}
                    </ol>
                </nav>

                <form onSubmit={submit} noValidate className="mx-auto max-w-3xl px-4 pt-6 pb-36 sm:px-6 sm:pt-10 sm:pb-16">
                    <div className="hidden" aria-hidden="true">
                        <label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} /></label>
                    </div>

                    {step === 0 && (
                        <section className="flex flex-col gap-5">
                            <header>
                                <h1 className="m-0 text-[clamp(26px,6vw,36px)] leading-tight font-bold">{t('اختاري باقتكِ')}</h1>
                                <p className="m-0 mt-1.5 text-[15px] text-[#5E5648]">{t('يمكنكِ تغييرها قبل الدفع.')}</p>
                            </header>
                            {errors.membership_plan_id && <p className="m-0 text-sm text-[#A33A22]">{errors.membership_plan_id}</p>}
                            <div className="flex flex-col gap-3" role="radiogroup" aria-label={t('الباقات')}>
                                {plans.map((p) => {
                                    const on = form.data.membership_plan_id === p.id;
                                    return (
                                        <button
                                            key={p.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={on}
                                            onClick={() => set('membership_plan_id', p.id)}
                                            className={cn('flex w-full cursor-pointer flex-col gap-3 rounded-[22px] border bg-white p-4 text-start transition sm:p-5',
                                                on ? 'border-[var(--site-gold)] shadow-[0_10px_30px_rgba(26,22,18,0.08)] ring-2 ring-[var(--site-gold)]' : 'border-[rgba(26,22,18,0.1)] hover:border-[rgba(26,22,18,0.25)]')}
                                        >
                                            <span className="flex w-full items-start gap-3">
                                                <span className={cn('mt-1 grid size-[22px] shrink-0 place-items-center rounded-full border-2', on ? 'border-[var(--site-gold)] bg-[var(--site-gold)]' : 'border-[rgba(26,22,18,0.25)]')}>
                                                    {on && <Check className="size-3.5 text-[var(--site-ink)]" strokeWidth={3.5} />}
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <span className="text-lg font-bold">{p.name}</span>
                                                        {p.is_featured && <span className="rounded-full bg-[var(--site-gold)] px-2.5 py-0.5 text-[11px] font-semibold text-[var(--site-ink)]">{t('الأكثر طلبًا')}</span>}
                                                    </span>
                                                    <PlanAccessLine plan={p} t={t} english={english} />
                                                    {p.description && <span className="text-sm text-[#5E5648]">{p.description}</span>}
                                                </span>
                                                <span className="flex shrink-0 flex-col items-end">
                                                    <span dir="ltr" className="text-[26px] leading-none font-bold [unicode-bidi:isolate]">{priceText(p.price)}</span>
                                                    <span className="mt-1 text-xs text-[#7A7060]">{currencyLabel(currency)} / {p.duration_label}</span>
                                                </span>
                                            </span>
                                            {on && (p.features ?? []).filter(Boolean).length > 0 && (
                                                <ul className="m-0 flex w-full list-none flex-col gap-1.5 border-t border-[rgba(26,22,18,0.08)] p-0 pt-3 ps-[34px]">
                                                    {(p.features ?? []).filter(Boolean).map((f, i) => <li key={i} className="flex gap-2.5 text-sm"><Diamond />{f}</li>)}
                                                </ul>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    {step === 1 && (
                        <section className="flex flex-col gap-5">
                            <header>
                                <h1 className="m-0 text-[clamp(26px,6vw,36px)] leading-tight font-bold">{t('بياناتكِ')}</h1>
                                <p className="m-0 mt-1.5 text-[15px] text-[#5E5648]">{t('لإنشاء حسابكِ في بوابة الأعضاء.')}</p>
                            </header>
                            <div className="grid gap-4 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:grid-cols-2 sm:p-7">
                                <FieldRow label={t('الاسم الكامل')} error={errors.full_name} required className="sm:col-span-2">
                                    <input className={input} value={form.data.full_name} onChange={(e) => set('full_name', e.target.value)} autoComplete="name" aria-invalid={!!errors.full_name} enterKeyHint="next" />
                                </FieldRow>
                                <FieldRow label={t('رقم الهاتف (واتساب)')} error={errors.phone} hint={t('سنرسل تأكيد الاشتراك والفواتير على واتساب')} required>
                                    <input className={cn(input, 'text-start')} type="tel" inputMode="tel" dir="ltr" value={form.data.phone} onChange={(e) => set('phone', e.target.value)} autoComplete="tel" placeholder="9XXXXXXX" aria-invalid={!!errors.phone} enterKeyHint="next" />
                                </FieldRow>
                                <FieldRow label={t('البريد الإلكتروني')} error={errors.email} hint={t('اختياري')}>
                                    <input className={cn(input, 'text-start')} type="email" inputMode="email" dir="ltr" value={form.data.email} onChange={(e) => set('email', e.target.value)} autoComplete="email" aria-invalid={!!errors.email} enterKeyHint="next" />
                                </FieldRow>
                                <FieldRow label={t('تاريخ الميلاد')} error={errors.date_of_birth} hint={t('اختياري')} className="sm:col-span-2">
                                    <input className={input} type="date" value={form.data.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} autoComplete="bday" aria-invalid={!!errors.date_of_birth} />
                                </FieldRow>
                                <FieldRow label={t('كلمة المرور')} error={errors.password} hint={t('لدخول بوابة الأعضاء — 8 أحرف على الأقل')} required>
                                    <input className={cn(input, 'text-start')} type="password" dir="ltr" value={form.data.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" aria-invalid={!!errors.password} />
                                </FieldRow>
                                <FieldRow label={t('تأكيد كلمة المرور')} error={errors.password_confirmation} required>
                                    <input className={cn(input, 'text-start')} type="password" dir="ltr" value={form.data.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} autoComplete="new-password" aria-invalid={!!errors.password_confirmation} enterKeyHint="done" />
                                </FieldRow>
                            </div>
                            <p className="m-0 text-center text-sm text-[#5E5648]">
                                {t('لديك حساب؟')} <Link href={route('portal.login')} className="font-semibold text-[var(--site-accent)] underline underline-offset-4">{t('سجّل الدخول')}</Link>
                            </p>
                        </section>
                    )}

                    {step === 2 && plan && (
                        <section className="flex flex-col gap-5">
                            <header>
                                <h1 className="m-0 text-[clamp(26px,6vw,36px)] leading-tight font-bold">{t('ملخص الاشتراك')}</h1>
                                <p className="m-0 mt-1.5 text-[15px] text-[#5E5648]">{t('راجعي كل شيء قبل الدفع.')}</p>
                            </header>
                            <div className="flex flex-col gap-4 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7">
                                <SummaryBlock title={t('الباقة')} onEdit={() => go(0)} editLabel={t('تغيير')}>
                                    <Row k={plan.name} v={money(plan.price, currency)} strong />
                                    <Row k={t('المدة')} v={plan.duration_label} />
                                    {Number(plan.signup_fee) > 0 && <Row k={t('رسوم التسجيل')} v={money(plan.signup_fee, currency)} />}
                                </SummaryBlock>
                                <SummaryBlock title={t('بياناتكِ')} onEdit={() => go(1)} editLabel={t('تعديل')}>
                                    <Row k={t('الاسم')} v={form.data.full_name} />
                                    <Row k={t('الهاتف')} v={<span dir="ltr" className="[unicode-bidi:isolate]">{form.data.phone}</span>} />
                                    {form.data.email && <Row k={t('البريد')} v={<span dir="ltr" className="break-all [unicode-bidi:isolate]">{form.data.email}</span>} />}
                                </SummaryBlock>
                                <FieldRow label={t('كود الخصم')} error={errors.coupon_code} hint={form.data.coupon_code ? t('يُطبّق كود الخصم عند إصدار الفاتورة.') : t('اختياري')}>
                                    <input className={cn(input, 'text-start uppercase')} dir="ltr" value={form.data.coupon_code} onChange={(e) => form.setData('coupon_code', e.target.value.toUpperCase())} autoCapitalize="characters" autoComplete="off" />
                                </FieldRow>
                                <div className="flex items-baseline justify-between gap-3 rounded-2xl bg-[#1A1612] px-5 py-4 text-white">
                                    <span className="text-base font-semibold">{t('الإجمالي')}</span>
                                    <span className="text-2xl font-bold num">{money(total, currency)}</span>
                                </div>
                                <div className="flex flex-col gap-3 pt-1">
                                    <Consent checked={form.data.accept_terms} onChange={(v) => set('accept_terms', v)} error={errors.accept_terms}>
                                        {t('أوافق على الشروط والأحكام وسياسة النادي')}
                                    </Consent>
                                    <Consent checked={form.data.marketing_opt_in} onChange={(v) => form.setData('marketing_opt_in', v)}>
                                        {t('أوافق على استلام العروض والأخبار التسويقية عبر واتساب (يمكنك إلغاء ذلك في أي وقت)')}
                                    </Consent>
                                </div>
                            </div>
                        </section>
                    )}

                    {step === 3 && plan && (
                        <section className="flex flex-col gap-5">
                            <header>
                                <h1 className="m-0 text-[clamp(26px,6vw,36px)] leading-tight font-bold">{t('الدفع')}</h1>
                                <p className="m-0 mt-1.5 text-[15px] text-[#5E5648]">{t('اختاري طريقة الدفع.')}</p>
                            </header>
                            <div className="flex flex-col gap-3" role="radiogroup" aria-label={t('طريقة الدفع')}>
                                {onlinePayments && (
                                    <PayOption on={form.data.pay_now} onClick={() => form.setData('pay_now', true)} icon={<CreditCard className="size-5" />} title={t('دفع إلكتروني الآن')} text={t('بطاقة بنكية عبر بوابة دفع آمنة، ويُفعَّل اشتراكك فور تأكيد الدفع.')} />
                                )}
                                {(payAtReception || !onlinePayments) && (
                                    <PayOption on={!form.data.pay_now} onClick={() => form.setData('pay_now', false)} icon={<Store className="size-5" />} title={t('الدفع في الاستقبال')} text={t('نصدر فاتورتك الآن، ويُفعَّل الاشتراك بعد تأكيد الدفع في النادي.')} />
                                )}
                            </div>
                            <div className="flex items-baseline justify-between gap-3 rounded-2xl bg-white px-5 py-4 ring-1 ring-[rgba(26,22,18,0.08)]">
                                <span className="text-[15px] text-[#5E5648]">{plan.name}</span>
                                <span className="text-xl font-bold num">{money(total, currency)}</span>
                            </div>
                            <p className="m-0 flex items-center justify-center gap-1.5 text-[13px] text-[#7A7060]"><Lock className="size-3.5" /> {t('بياناتكِ محمية، ولا نحفظ بيانات بطاقتكِ.')}</p>
                        </section>
                    )}

                    {/* Actions: pinned to the bottom on phones, under the step on wider screens. */}
                    <div className="fixed inset-x-0 bottom-0 z-40 border-t border-[rgba(26,22,18,0.08)] bg-white/95 px-4 pt-3 pb-[max(12px,env(safe-area-inset-bottom))] backdrop-blur sm:static sm:mt-8 sm:border-0 sm:bg-transparent sm:p-0 sm:backdrop-blur-none">
                        <div className="mx-auto flex max-w-3xl items-center gap-3">
                            {step > 0 ? (
                                <button type="button" onClick={back} className="inline-flex h-[54px] shrink-0 items-center justify-center gap-1.5 rounded-full border border-[rgba(26,22,18,0.2)] bg-white px-5 text-[15px] font-semibold transition-colors hover:border-[var(--site-ink)]">
                                    <ArrowRight className="size-4 ltr:rotate-180" /> {t('رجوع')}
                                </button>
                            ) : plan && (
                                <span className="flex min-w-0 flex-col sm:hidden">
                                    <span className="truncate text-xs text-[#7A7060]">{plan.name}</span>
                                    <span className="text-lg font-bold num">{money(total, currency)}</span>
                                </span>
                            )}
                            <button
                                type="submit"
                                disabled={form.processing || (step === 0 && !plan)}
                                className="inline-flex h-[54px] min-w-0 flex-1 items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] disabled:cursor-not-allowed disabled:opacity-60 sm:ms-auto sm:max-w-xs"
                            >
                                {form.processing ? t('جارٍ الإرسال…') : primaryLabel}
                                {!form.processing && (last ? <Lock className="size-4" /> : <ArrowLeft className="size-4 ltr:rotate-180" />)}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </SiteLayout>
    );
}

function SummaryBlock({ title, onEdit, editLabel, children }: { title: string; onEdit: () => void; editLabel: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2 border-b border-[rgba(26,22,18,0.08)] pb-4">
            <div className="flex items-center justify-between">
                <span className="text-xs font-semibold tracking-wide text-[var(--site-accent)]">{title}</span>
                <button type="button" onClick={onEdit} className="text-sm font-semibold text-[var(--site-ink)] underline underline-offset-4">{editLabel}</button>
            </div>
            {children}
        </div>
    );
}

function Row({ k, v, strong }: { k: ReactNode; v: ReactNode; strong?: boolean }) {
    return (
        <div className={cn('flex items-baseline justify-between gap-4 text-[15px]', strong && 'text-base font-bold')}>
            <span className={cn('min-w-0', !strong && 'text-[#5E5648]')}>{k}</span>
            <span className="min-w-0 text-end font-medium num">{v}</span>
        </div>
    );
}

function Consent({ checked, onChange, error, children }: { checked: boolean; onChange: (v: boolean) => void; error?: string; children: ReactNode }) {
    return (
        <label className="flex cursor-pointer items-start gap-3 text-[15px] leading-6">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 size-5 shrink-0 accent-[#C9A042]" />
            <span className="flex flex-col">
                {children}
                {error && <span className="text-sm text-[#A33A22]">{error}</span>}
            </span>
        </label>
    );
}

function PayOption({ on, onClick, icon, title, text }: { on: boolean; onClick: () => void; icon: ReactNode; title: string; text: string }) {
    return (
        <button type="button" role="radio" aria-checked={on} onClick={onClick}
            className={cn('flex w-full cursor-pointer items-start gap-4 rounded-[22px] border bg-white p-4 text-start transition sm:p-5', on ? 'border-[var(--site-gold)] ring-2 ring-[var(--site-gold)]' : 'border-[rgba(26,22,18,0.1)] hover:border-[rgba(26,22,18,0.25)]')}>
            <span className={cn('grid size-11 shrink-0 place-items-center rounded-2xl', on ? 'bg-[var(--site-ink)] text-[var(--site-gold)]' : 'bg-[#F1ECE3] text-[#5E5648]')}>{icon}</span>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-base font-bold">{title}</span>
                <span className="text-sm text-[#5E5648]">{text}</span>
            </span>
            <span className={cn('mt-1 grid size-[22px] shrink-0 place-items-center rounded-full border-2', on ? 'border-[var(--site-gold)] bg-[var(--site-gold)]' : 'border-[rgba(26,22,18,0.25)]')}>
                {on && <Check className="size-3.5 text-[var(--site-ink)]" strokeWidth={3.5} />}
            </span>
        </button>
    );
}
