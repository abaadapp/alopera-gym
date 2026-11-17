import type { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { LogIn, UserPlus } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { GiftArt, GiftForNumberNote, type GiftCardData } from '@/Components/Site/Gift';
import { phrase } from '@/Components/Site/ui';
import { cn } from '@/lib/cn';
import { useCarryOver } from '@/lib/carry-over';
import { useT } from '@/lib/i18n';
import { GiftAlert, GiftField, giftInput } from './GiftBuy';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    gift: GiftCardData;
    token: string;
    /** Signing in from here comes back to the redemption page. */
    login_url: string;
}

/** «استلمي هديتك»: she signs in, or creates her account here, then picks the day her membership starts. */
export default function GiftClaim({ site, seo, gift, token, login_url }: Props) {
    const t = useT();
    const form = useForm({
        full_name: gift.recipient_name ?? '',
        phone: '',
        email: '',
        password: '',
        password_confirmation: '',
        accept_terms: false,
        website: '',
    });

    // What was typed survives the language switch (never the passwords or the honeypot).
    useCarryOver('gift-claim', form.data, (saved) => form.setData((d) => ({ ...d, ...saved })));

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('site.gift.register', { token }), { preserveScroll: true, onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <Head>
                {/* The secret link is never passed on to another site. */}
                <meta name="referrer" content="no-referrer" />
            </Head>
            <div className="bg-[var(--site-surface)]">
                <section className="mx-auto flex max-w-3xl flex-col gap-6 px-4 pt-8 pb-16 sm:px-6 sm:pt-12">
                    <header className="flex flex-col gap-1.5">
                        <h1 className="m-0 text-[clamp(26px,6vw,36px)] leading-tight font-bold">{t('استلمي هديتك')}</h1>
                        <p className="m-0 text-[15px] text-[#5E5648]">{t('سجّلي الدخول أو أنشئي حسابكِ، ثم اختاري يوم بدء اشتراككِ.')}</p>
                    </header>

                    <GiftArt gift={gift} compact />

                    <GiftForNumberNote gift={gift} className="-mt-2 px-1" />

                    {errors.error && <GiftAlert>{errors.error}</GiftAlert>}

                    <div className="grid gap-5 md:grid-cols-5">
                        <div className="flex flex-col gap-3 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7 md:col-span-2 md:self-start">
                            <h2 className="m-0 text-lg font-bold">{t('لديّ حساب')}</h2>
                            <p className="m-0 text-sm leading-6 text-[#5E5648]">{t('بعد تسجيل الدخول تعودين إلى صفحة استلام الهدية.')}</p>
                            <Link href={login_url} className="inline-flex h-[52px] items-center justify-center gap-2 rounded-full bg-[var(--site-ink)] px-5 text-[15px] font-semibold text-white transition-colors hover:bg-black">
                                <LogIn className="size-4" /> {t('تسجيل الدخول')}
                            </Link>
                        </div>

                        <form onSubmit={submit} className="flex flex-col gap-4 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7 md:col-span-3">
                            <div className="hidden" aria-hidden="true">
                                <label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} /></label>
                            </div>
                            <h2 className="m-0 text-lg font-bold">{t('عضوة جديدة')}</h2>
                            <GiftField label={t('الاسم الكامل')} error={errors.full_name} required>
                                <input className={giftInput} value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} maxLength={160} autoComplete="name" aria-invalid={!!errors.full_name} required />
                            </GiftField>
                            <GiftField label={t('رقم الهاتف')} error={errors.phone} hint={gift.for_recipient_number ? t('استخدمي رقم الجوال الذي أدخله المُهدي.') : undefined} required>
                                <input className={cn(giftInput, 'text-start')} type="tel" inputMode="tel" dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} maxLength={32} autoComplete="tel" placeholder="9XXXXXXX" aria-invalid={!!errors.phone} required />
                            </GiftField>
                            <GiftField label={t('البريد الإلكتروني')} error={errors.email} hint={t('اختياري')}>
                                <input className={cn(giftInput, 'text-start')} type="email" inputMode="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} maxLength={255} autoComplete="email" aria-invalid={!!errors.email} />
                            </GiftField>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <GiftField label={t('كلمة المرور')} error={errors.password} hint={t('8 أحرف على الأقل')} required>
                                    <input className={cn(giftInput, 'text-start')} type="password" dir="ltr" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" aria-invalid={!!errors.password} required />
                                </GiftField>
                                <GiftField label={t('تأكيد كلمة المرور')} error={errors.password_confirmation} required>
                                    <input className={cn(giftInput, 'text-start')} type="password" dir="ltr" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" aria-invalid={!!errors.password_confirmation} required />
                                </GiftField>
                            </div>
                            <label className="flex cursor-pointer items-start gap-3 text-[15px] leading-6">
                                <input type="checkbox" checked={form.data.accept_terms} onChange={(e) => form.setData('accept_terms', e.target.checked)} className="mt-0.5 size-5 shrink-0 accent-[#C9A042]" aria-invalid={!!errors.accept_terms} />
                                <span className="flex flex-col">
                                    {phrase(t('أوافق على :terms و:privacy'), {
                                        terms: <a href={route('site.terms')} target="_blank" rel="noopener noreferrer" className="font-semibold text-[var(--site-accent)] underline underline-offset-4">{t('الشروط والأحكام')}</a>,
                                        privacy: <a href={route('site.privacy')} target="_blank" rel="noopener noreferrer" className="font-semibold text-[var(--site-accent)] underline underline-offset-4">{t('سياسة الخصوصية')}</a>,
                                    })}
                                    {errors.accept_terms && <span className="text-sm text-[#A33A22]">{errors.accept_terms}</span>}
                                </span>
                            </label>
                            <button type="submit" disabled={form.processing} className="inline-flex h-[54px] items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] disabled:cursor-not-allowed disabled:opacity-60">
                                {form.processing ? t('جارٍ الإرسال…') : t('إنشاء الحساب ومتابعة الاستلام')}
                                {!form.processing && <UserPlus className="size-4" />}
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </SiteLayout>
    );
}
