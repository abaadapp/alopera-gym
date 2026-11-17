import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { AlertCircle, Gift, Lock, MessageCircle, ShieldCheck, Store } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { GiftTermsList, displayPhone, isWhatsAppNumber, normalizePhone, type GiftTermsData } from '@/Components/Site/Gift';
import { phrase } from '@/Components/Site/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { useCarryOver } from '@/lib/carry-over';
import { date, dayCount, money } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { planAccess, say } from '@/lib/plan-access';
import type { PlanData } from './Plans';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    plan: PlanData;
    /** The total as the gift's invoice will be issued: the plan's price and its tax, nothing else. */
    summary: { price: string; tax: string; tax_rate: number; total: string };
    onlinePayments: boolean;
    /** The recipient confirms her WhatsApp number before payment: not possible while the club's WhatsApp is not connected. */
    whatsapp: { available: boolean; refusal: string | null };
    terms: GiftTermsData;
}

const MESSAGE_MAX = 300;

export const giftInput = 'h-[52px] w-full min-w-0 rounded-2xl border border-[rgba(22,19,15,0.18)] bg-white px-4 text-base text-[var(--site-ink)] outline-none placeholder:text-[#9A9284] focus:border-[var(--site-gold)] focus:ring-2 focus:ring-[var(--site-gold)]/25 aria-[invalid=true]:border-[#A33A22]';

export function GiftField({ label, error, hint, required, children, className }: { label: string; error?: string; hint?: ReactNode; required?: boolean; children: ReactNode; className?: string }) {
    return (
        <label className={cn('flex min-w-0 flex-col gap-1.5', className)}>
            <span className="text-sm font-semibold">{label}{required && <span className="text-[var(--site-accent)]"> *</span>}</span>
            {children}
            {error ? <span className="text-sm text-[#A33A22]">{error}</span> : hint && <span className="text-[13px] text-[#7A7060]">{hint}</span>}
        </label>
    );
}

export function GiftAlert({ children }: { children: ReactNode }) {
    return (
        <div role="alert" className="flex items-start gap-3 rounded-2xl border border-[#A33A22]/30 bg-[#FBEDEA] px-4 py-3 text-[15px] leading-7 text-[#7A2A18]">
            <AlertCircle className="mt-1 size-5 shrink-0" />
            <p className="m-0">{children}</p>
        </div>
    );
}

/**
 * «إهداء هذه الباقة»: the giver's details, the recipient's name and WhatsApp number (required: the gift is sent there)
 * and a message, the gift's conditions, its expiry and no-refund policy to agree to, and the total. Next comes the
 * recipient's confirmation of her number (GiftVerify), then the gateway. No account is needed. The gift is usable —
 * and sent — only once the gateway's signed confirmation paid it.
 */
export default function GiftBuy({ site, seo, plan, summary, onlinePayments, whatsapp, terms }: Props) {
    const currency = useCurrency();
    const t = useT();
    const english = useLocale() === 'en';
    const form = useForm({
        giver_name: '',
        giver_phone: '',
        giver_email: '',
        recipient_name: '',
        recipient_phone: '',
        message: '',
        accept_terms: false,
        website: '',
    });

    // What was typed survives the language switch (never the honeypot).
    useCarryOver('gift', form.data, (saved) => form.setData((d) => ({ ...d, ...saved })));

    const errors = form.errors as Record<string, string | undefined>;
    const access = planAccess(plan);

    const ready = onlinePayments && whatsapp.available;
    // The number as it will be used, shown back to check it; its shape only (never proof of a WhatsApp account).
    const typed = normalizePhone(form.data.recipient_phone);
    const shapeOk = isWhatsAppNumber(typed);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!ready) return;
        form.post(route('site.gift.store', { plan: plan.id }), { preserveScroll: true });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <div className="bg-[var(--site-surface)]">
                <form onSubmit={submit} className="mx-auto flex max-w-2xl flex-col gap-5 px-4 pt-8 pb-16 sm:px-6 sm:pt-12">
                    <div className="hidden" aria-hidden="true">
                        <label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} /></label>
                    </div>

                    <header className="flex flex-col gap-1.5">
                        <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--site-accent)]"><Gift className="size-4" /> {t('إهداء باقة')}</span>
                        <h1 className="m-0 text-[clamp(26px,6vw,36px)] leading-tight font-bold">{t('إهداء :plan', { plan: plan.name })}</h1>
                        <p className="m-0 text-[15px] text-[#5E5648]">{t('تؤكد المستلمة رقم واتسابها برسالة منها، ثم يتم الدفع، ونرسل لها الهدية على واتساب بعد تأكيد الدفع، وتختار يوم بدء اشتراكها.')}</p>
                    </header>

                    {/* The plan, as it will be given. */}
                    <section className="flex flex-col gap-2 rounded-[24px] bg-[#1A1612] p-5 text-white sm:p-6">
                        <span className="text-xs font-semibold tracking-wide text-[#C9A042]">{plan.type_label}</span>
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span className="text-xl font-bold break-words">{plan.name}</span>
                            <span className="text-lg font-bold num">{money(plan.price, currency)}</span>
                        </div>
                        <span className="text-sm text-[#CFC7B8]">{t('المدة')}: {plan.duration_label}</span>
                        {access.included.length > 0 && <span className="text-sm text-[#E9DFC8]">{access.included.map((x) => say(x, t, english)).join(' · ')}</span>}
                        {access.notes.map((x, i) => <span key={i} className="text-[13px] text-[#9A9284]">{say(x, t, english)}</span>)}
                    </section>

                    {onlinePayments && !whatsapp.available && (
                        <div role="status" className="flex items-start gap-3 rounded-2xl bg-white px-5 py-4 text-[15px] leading-7 ring-1 ring-[rgba(26,22,18,0.08)]">
                            <MessageCircle className="mt-1 size-5 shrink-0 text-[#8A6A1F]" />
                            <p className="m-0">{whatsapp.refusal ?? t('تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.')}</p>
                        </div>
                    )}

                    {!onlinePayments && (
                        <div role="status" className="flex items-start gap-3 rounded-2xl bg-white px-5 py-4 text-[15px] leading-7 ring-1 ring-[rgba(26,22,18,0.08)]">
                            <Store className="mt-1 size-5 shrink-0 text-[#8A6A1F]" />
                            <p className="m-0">{t('الدفع الإلكتروني غير متاح حاليًا. يمكن شراء الهدية من استقبال النادي.')}</p>
                        </div>
                    )}

                    <section className="grid gap-4 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:grid-cols-2 sm:p-7">
                        <GiftField label={t('اسمك (المُهدي)')} error={errors.giver_name} required className="sm:col-span-2">
                            <input className={giftInput} value={form.data.giver_name} onChange={(e) => form.setData('giver_name', e.target.value)} maxLength={120} autoComplete="name" aria-invalid={!!errors.giver_name} required />
                        </GiftField>
                        <GiftField label={t('رقم هاتفك للتواصل')} error={errors.giver_phone} hint={t('لا يظهر في بطاقة الهدية.')} required>
                            <input className={cn(giftInput, 'text-start')} type="tel" inputMode="tel" dir="ltr" value={form.data.giver_phone} onChange={(e) => form.setData('giver_phone', e.target.value)} maxLength={32} autoComplete="tel" placeholder="9XXXXXXX" aria-invalid={!!errors.giver_phone} required />
                        </GiftField>
                        <GiftField label={t('بريدك الإلكتروني (اختياري)')} error={errors.giver_email}>
                            <input className={cn(giftInput, 'text-start')} type="email" inputMode="email" dir="ltr" value={form.data.giver_email} onChange={(e) => form.setData('giver_email', e.target.value)} maxLength={190} autoComplete="email" aria-invalid={!!errors.giver_email} />
                        </GiftField>
                        <GiftField label={t('اسم المستلمة')} error={errors.recipient_name} required className="sm:col-span-2">
                            <input className={giftInput} value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} maxLength={120} autoComplete="off" aria-invalid={!!errors.recipient_name} required />
                        </GiftField>
                        <GiftField
                            label={t('رقم واتساب المستلمة')}
                            required
                            className="sm:col-span-2"
                            error={errors.recipient_phone}
                            hint={
                                form.data.recipient_phone.trim() === '' ? t('نرسل الهدية إلى واتساب هذا الرقم. قبل الدفع تؤكد المستلمة الرقم برسالة منها.')
                                    : shapeOk ? phrase(t('سنرسل إلى: :number. تأكد أنه رقم واتساب المستلمة.'), { number: <bdi dir="ltr" className="font-semibold num">{displayPhone(typed)}</bdi> })
                                    : t('اكتب رقمًا عمانيًا يبدأ بـ 9 أو 7 (8 أرقام)، أو رقمًا دوليًا يبدأ بـ +.')
                            }
                        >
                            <span className="flex min-w-0 items-stretch gap-2" dir="ltr">
                                {!/^\s*(\+|00)/.test(form.data.recipient_phone) && <span className="inline-flex h-[52px] shrink-0 items-center rounded-2xl border border-[rgba(22,19,15,0.18)] bg-[#F6F3EE] px-3 text-base text-[#5E5648] num" aria-hidden>+968</span>}
                                <input className={cn(giftInput, 'text-start')} type="tel" inputMode="tel" dir="ltr" value={form.data.recipient_phone} onChange={(e) => form.setData('recipient_phone', e.target.value)} maxLength={32} autoComplete="off" placeholder="9XXXXXXX" required aria-invalid={!!errors.recipient_phone || (form.data.recipient_phone.trim() !== '' && !shapeOk)} aria-label={t('رقم واتساب المستلمة')} />
                            </span>
                        </GiftField>
                        <GiftField
                            label={t('رسالة الإهداء (اختيارية)')}
                            error={errors.message}
                            hint={<span dir="ltr" className="num [unicode-bidi:isolate]">{form.data.message.length}/{MESSAGE_MAX}</span>}
                            className="sm:col-span-2"
                        >
                            <textarea
                                className={cn(giftInput, 'h-auto min-h-[112px] resize-y py-3 leading-7')}
                                value={form.data.message}
                                onChange={(e) => form.setData('message', e.target.value.slice(0, MESSAGE_MAX))}
                                maxLength={MESSAGE_MAX}
                                rows={4}
                                aria-invalid={!!errors.message}
                            />
                        </GiftField>
                    </section>

                    <section className="flex flex-col gap-4 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7">
                        <h2 className="m-0 text-base font-bold">{t('شروط الهدية')}</h2>
                        <GiftTermsList terms={terms} />
                    </section>

                    {/* The expiry and no-refund policy, stated plainly before payment, and the agreement to it. */}
                    <section aria-labelledby="gift-policy" className="flex flex-col gap-3 rounded-[24px] border-2 border-[var(--site-gold)] bg-[#FBF6EA] p-5 sm:p-7">
                        <h2 id="gift-policy" className="m-0 flex items-center gap-2 text-lg font-bold"><ShieldCheck className="size-5 shrink-0 text-[#8A6A1F]" /> {t('سياسة الهدية')}</h2>
                        <ul className="m-0 flex list-disc flex-col gap-1.5 ps-5 text-[15px] leading-7 font-medium text-[var(--site-ink)] marker:text-[#8A6A1F]">
                            <li>
                                {phrase(t('صالحة للاستلام :days من تاريخ الدفع (حتى :date إن تمّ الدفع اليوم).', { days: dayCount(terms.redeem_days) }), {
                                    date: <strong className="font-bold">{date(terms.expires_if_paid_today)}</strong>,
                                })}
                            </li>
                            <li>{t('إذا انقضت المهلة دون استلامها تنتهي الهدية ولا تُردّ قيمتها.')}</li>
                            <li>{t('لا تُردّ قيمة الهدية بعد استلامها، ولا يُردّ جزء منها.')}</li>
                        </ul>
                        <label className="flex cursor-pointer items-start gap-3 border-t border-[#C9A042]/40 pt-4 text-[15px] leading-6">
                            <input type="checkbox" checked={form.data.accept_terms} onChange={(e) => form.setData('accept_terms', e.target.checked)} className="mt-0.5 size-5 shrink-0 accent-[#C9A042]" aria-invalid={!!errors.accept_terms} />
                            <span className="flex flex-col">
                                {phrase(t('أوافق على سياسة الهدية وشروطها و:terms'), {
                                    terms: <a href={route('site.terms')} target="_blank" rel="noopener" className="font-semibold text-[var(--site-accent)] underline underline-offset-4">{t('الشروط والأحكام')}</a>,
                                })}
                                {errors.accept_terms && <span className="text-sm text-[#A33A22]">{errors.accept_terms}</span>}
                            </span>
                        </label>
                    </section>

                    <section className="flex flex-col gap-2 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7">
                        <Line k={t('سعر الباقة')} v={money(summary.price, currency)} />
                        {Number(summary.tax) > 0 && (
                            <Line k={phrase(t('الضريبة (:rate)'), { rate: <span className="num">{Number(summary.tax_rate)}%</span> })} v={money(summary.tax, currency)} />
                        )}
                        <div className="mt-2 flex items-baseline justify-between gap-3 rounded-2xl bg-[#1A1612] px-5 py-4 text-white">
                            <span className="text-base font-semibold">{t('الإجمالي')}</span>
                            <span className="text-2xl font-bold num">{money(summary.total, currency)}</span>
                        </div>
                    </section>

                    {errors.error && <GiftAlert>{errors.error}</GiftAlert>}

                    <button
                        type="submit"
                        disabled={!ready || form.processing}
                        className="inline-flex h-[54px] items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? t('جارٍ الإرسال…') : t('متابعة: تأكيد رقم المستلمة')}
                        {!form.processing && <Lock className="size-4" />}
                    </button>
                    <p className="m-0 flex items-center justify-center gap-1.5 text-center text-[13px] text-[#7A7060]"><Lock className="size-3.5" /> {t('الدفع عبر بوابة دفع آمنة، ولا نحفظ بيانات البطاقة.')}</p>
                </form>
            </div>
        </SiteLayout>
    );
}

function Line({ k, v }: { k: ReactNode; v: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-[15px]">
            <span className="min-w-0 text-[#5E5648]">{k}</span>
            <span className="min-w-0 text-end font-medium num">{v}</span>
        </div>
    );
}
