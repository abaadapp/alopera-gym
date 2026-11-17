import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, CheckCircle2, Clock, Copy, CreditCard, Lock, MessageCircle, Pencil, RefreshCw, Smartphone } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { GiftTermsList, displayPhone, isWhatsAppNumber, normalizePhone, type GiftCardData, type GiftTermsData } from '@/Components/Site/Gift';
import { phrase } from '@/Components/Site/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, money, time } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { GiftAlert, GiftField, giftInput } from './GiftBuy';

/** What the server says about the recipient's number (GiftWhatsApp::verification). */
export interface GiftVerification {
    state: 'waiting' | 'expired' | 'verified' | 'unavailable';
    phone: string;
    verified_at: string | null;
    expires_at: string | null;
    code: string | null;
    /** The message she sends: her consent to the club's messages and the code; nothing of a gift or a price. */
    text: string | null;
    /** wa.me to the club's number with that message ready. */
    link: string | null;
    club_number: string | null;
    refusal: string | null;
    refusal_text: string | null;
}

interface Props {
    site: SiteInfo;
    seo: { title: string };
    gift: GiftCardData;
    token: string;
    recipient_phone: string;
    verification: GiftVerification;
    total: string | null;
    can_pay: boolean;
    online_payments: boolean;
    terms: GiftTermsData;
    flash?: { error?: string };
}

/**
 * «تأكيد رقم المستلمة»: between the gift's form and the payment, on the buyer's own page. The number as it will be
 * used (to check it, and correct it before it is confirmed), and how the recipient confirms it: from her own WhatsApp
 * she sends the ready message to the club's number — the only proof the number reaches her. The page asks the server
 * every few seconds and offers the payment once it is confirmed; the server refuses the payment before that anyway.
 */
export default function GiftVerify({ site, seo, gift, token, recipient_phone, verification: v, total, can_pay, online_payments, terms }: Props) {
    const t = useT();
    const currency = useCurrency();
    const [editing, setEditing] = useState(false);
    const [copied, setCopied] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => () => clearTimeout(timer.current), []);

    // Waiting for her message: ask again every 5 seconds (only the state), stop once it is decided.
    useEffect(() => {
        if (v.state !== 'waiting') return;
        const id = setInterval(() => router.reload({ only: ['verification', 'can_pay'] }), 5000);
        return () => clearInterval(id);
    }, [v.state]);

    const phone = useForm({ recipient_phone: '' });
    const typed = normalizePhone(phone.data.recipient_phone);
    const changePhone = (e: FormEvent) => {
        e.preventDefault();
        phone.post(route('site.gift.phone', { token }), { preserveScroll: true, onSuccess: () => { setEditing(false); phone.reset(); } });
    };
    const pay = useForm({});
    const code = useForm({});

    const copy = async () => {
        if (!v.link) return;
        try {
            await navigator.clipboard.writeText(v.link);
        } catch {
            return; // no clipboard: the link is shown below to copy by hand
        }
        setCopied(true);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => setCopied(false), 2500);
    };

    const verified = v.state === 'verified';

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <Head>
                <meta name="robots" content="noindex,nofollow" />
                <meta name="referrer" content="no-referrer" />
            </Head>
            <div className="bg-[var(--site-surface)]">
                <section className="mx-auto flex max-w-2xl flex-col gap-5 px-4 pt-8 pb-16 sm:px-6 sm:pt-12">
                    <header className="flex flex-col gap-2">
                        <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--site-accent)]"><Smartphone className="size-4" /> {t('الخطوة 2 من 3')}</span>
                        <h1 className="m-0 text-[clamp(24px,6vw,34px)] leading-tight font-bold">{t('تأكيد رقم واتساب المستلمة')}</h1>
                        <p className="m-0 text-[15px] text-[#5E5648]">
                            {phrase(t('هدية :plan إلى :name. نرسلها إلى واتساب المستلمة بعد الدفع، لذلك تؤكد المستلمة رقمها أولًا برسالة منها.'), {
                                plan: <b>{gift.plan_name}</b>, name: <b>{gift.recipient_name}</b>,
                            })}
                        </p>
                    </header>

                    {/* The number, as it will be used. */}
                    <div className="flex flex-col gap-3 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="min-w-0">
                                <p className="m-0 text-[13px] text-[#7A7060]">{t('رقم واتساب المستلمة')}</p>
                                <p className="m-0 text-xl font-bold num" dir="ltr">{displayPhone(recipient_phone)}</p>
                            </div>
                            {!verified && !editing && (
                                <button type="button" onClick={() => setEditing(true)} className="inline-flex h-10 items-center gap-1.5 rounded-full border border-[rgba(26,22,18,0.2)] px-4 text-sm font-semibold hover:border-[var(--site-ink)]">
                                    <Pencil className="size-4" /> {t('تعديل الرقم')}
                                </button>
                            )}
                            {verified && <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700"><CheckCircle2 className="size-4" /> {t('مؤكَّد')}</span>}
                        </div>
                        {editing && (
                            <form onSubmit={changePhone} className="flex flex-col gap-3">
                                <GiftField label={t('الرقم الصحيح')} required error={phone.errors.recipient_phone}
                                    hint={isWhatsAppNumber(typed) ? phrase(t('سنرسل إلى: :number. تأكد أنه رقم واتساب المستلمة.'), { number: <bdi dir="ltr" className="font-semibold num">{displayPhone(typed)}</bdi> }) : t('اكتب رقمًا عمانيًا يبدأ بـ 9 أو 7 (8 أرقام)، أو رقمًا دوليًا يبدأ بـ +.')}>
                                    <input className={cn(giftInput, 'text-start')} type="tel" inputMode="tel" dir="ltr" value={phone.data.recipient_phone} onChange={(e) => phone.setData('recipient_phone', e.target.value)} maxLength={32} placeholder="9XXXXXXX" autoFocus required />
                                </GiftField>
                                <div className="flex flex-wrap gap-2">
                                    <button type="submit" disabled={phone.processing} className="inline-flex h-11 items-center rounded-full bg-[var(--site-ink)] px-5 text-sm font-bold text-white disabled:opacity-60">{t('حفظ الرقم')}</button>
                                    <button type="button" onClick={() => { setEditing(false); phone.reset(); phone.clearErrors(); }} className="inline-flex h-11 items-center rounded-full px-4 text-sm font-semibold text-[#5E5648]">{t('إلغاء')}</button>
                                </div>
                            </form>
                        )}
                        <p className="m-0 text-[13px] text-[#7A7060]">{t('صحة شكل الرقم لا تعني أن له حسابًا على واتساب: يتأكد ذلك فقط عندما تصلنا رسالة من الرقم نفسه.')}</p>
                    </div>

                    {v.state === 'unavailable' && <GiftAlert>{v.refusal_text ?? t('تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.')}</GiftAlert>}

                    {(v.state === 'waiting' || v.state === 'expired') && (
                        <div className="flex flex-col gap-4 rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-6">
                            <h2 className="m-0 flex items-center gap-2 text-lg font-bold"><MessageCircle className="size-5 text-[#8A6A1F]" /> {t('كيف تؤكد المستلمة رقمها')}</h2>
                            {v.state === 'expired' ? (
                                <p className="m-0 text-[15px]">{t('انتهت صلاحية رمز التأكيد. اطلب رمزًا جديدًا ثم أرسله للمستلمة.')}</p>
                            ) : (
                                <>
                                    <ol className="m-0 flex list-decimal flex-col gap-2 ps-5 text-[15px] leading-7">
                                        <li>{t('من هاتف المستلمة، وعلى واتساب الرقم أعلاه، تُرسل الرسالة الجاهزة إلى رقم النادي.')}</li>
                                        <li>{t('إن لم تكن المستلمة معك: انسخ رابط التأكيد وأرسله لها لتفتحه وترسل الرسالة من هاتفها.')}</li>
                                        <li>{t('تتحدث هذه الصفحة وحدها عند وصول الرسالة، ثم يظهر زر الدفع.')}</li>
                                    </ol>
                                    <div className="rounded-2xl bg-[#F6F3EE] px-4 py-3 text-[14px] leading-7">
                                        <p className="m-0 text-[12px] text-[#7A7060]">{t('الرسالة التي تُرسلها المستلمة (لا تذكر الهدية ولا سعرها):')}</p>
                                        <p className="m-0 font-medium">{v.text}</p>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {v.link && (
                                            <a href={v.link} target="_blank" rel="noopener noreferrer" className="inline-flex h-[52px] items-center justify-center gap-2 rounded-full bg-[#1F7A4D] px-5 text-[15px] font-bold text-white hover:bg-[#19643F]">
                                                <MessageCircle className="size-4" /> {t('فتح واتساب وإرسال الرسالة')}
                                            </a>
                                        )}
                                        <button type="button" onClick={copy} className="inline-flex h-[52px] items-center justify-center gap-2 rounded-full border border-[rgba(26,22,18,0.2)] bg-white px-5 text-[15px] font-semibold hover:border-[var(--site-ink)]">
                                            {copied ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />} {copied ? t('نُسخ الرابط') : t('نسخ رابط التأكيد')}
                                        </button>
                                    </div>
                                    <p className="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-[#7A7060]" aria-live="polite">
                                        <Clock className="size-3.5" />
                                        {t('بانتظار رسالة المستلمة…')}
                                        {v.expires_at && <span>{phrase(t('الرمز صالح حتى :time.'), { time: <span className="num">{date(v.expires_at)} {time(v.expires_at)}</span> })}</span>}
                                    </p>
                                </>
                            )}
                            <button type="button" disabled={code.processing} onClick={() => code.post(route('site.gift.code', { token }), { preserveScroll: true })}
                                className="inline-flex h-10 items-center justify-center gap-1.5 self-start rounded-full px-3 text-sm font-semibold text-[var(--site-accent)] disabled:opacity-60">
                                <RefreshCw className="size-4" /> {t('رمز جديد')}
                            </button>
                        </div>
                    )}

                    {verified && (
                        <div role="status" className="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-[15px] leading-7 text-emerald-800">
                            <CheckCircle2 className="mt-1 size-5 shrink-0" />
                            <p className="m-0">{t('تم تأكيد الرقم برسالة من المستلمة. بعد تأكيد الدفع نرسل لها الهدية على واتساب.')}</p>
                        </div>
                    )}

                    {verified && !online_payments && <GiftAlert>{t('الدفع الإلكتروني غير متاح حاليًا. يمكن شراء الهدية من استقبال النادي.')}</GiftAlert>}

                    <div className="rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7">
                        <h2 className="m-0 mb-3 text-base font-bold">{t('شروط الهدية')}</h2>
                        <GiftTermsList terms={terms} />
                    </div>

                    <button type="button" disabled={!can_pay || pay.processing} onClick={() => pay.post(route('site.gift.pay', { token }))}
                        className="inline-flex h-[54px] items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] disabled:cursor-not-allowed disabled:opacity-60">
                        <CreditCard className="size-5" />
                        {pay.processing ? t('جارٍ التحويل إلى الدفع…') : total ? phrase(t('ادفع :amount وأهدِ الآن'), { amount: <span className="num">{money(total, currency)}</span> }) : t('ادفع وأهدِ الآن')}
                    </button>
                    {!verified && <p className="m-0 text-center text-[13px] text-[#7A7060]">{t('يظهر الدفع بعد تأكيد رقم المستلمة.')}</p>}
                    <p className="m-0 flex items-center justify-center gap-1.5 text-center text-[13px] text-[#7A7060]"><Lock className="size-3.5" /> {t('الدفع عبر بوابة دفع آمنة، ولا نحفظ بيانات البطاقة.')}</p>
                </section>
            </div>
        </SiteLayout>
    );
}
