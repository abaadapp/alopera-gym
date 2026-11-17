import { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Check, Copy, CreditCard, Gift, Info, MessageCircle } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { GiftArt, GiftForNumberNote, GiftTermsList, giftStatusText, type GiftCardData, type GiftTermsData } from '@/Components/Site/Gift';
import { useT } from '@/lib/i18n';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    gift: GiftCardData;
    token: string;
    /** The card's own address, to pass on; null until it is paid. */
    link: string | null;
    /** A WhatsApp share of the link, while it can be redeemed. */
    whatsapp: string | null;
    /** «استلمي هديتك», while it can be redeemed. */
    claim_url: string | null;
    /** The buyer, back before the payment was confirmed: finish paying. */
    pay_url: string | null;
    is_buyer: boolean;
    terms: GiftTermsData;
}

/**
 * The gift at its secret link (never indexed). It is shown usable only once the gateway's signed confirmation paid
 * it: the server decides, this page only shows what it says.
 */
export default function GiftCard({ site, seo, gift, link, whatsapp, claim_url, pay_url, terms }: Props) {
    const t = useT();
    const [copied, setCopied] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => () => clearTimeout(timer.current), []);

    const note = giftStatusText(gift.status, t);

    const copy = async () => {
        if (!link) return;
        try {
            await navigator.clipboard.writeText(link);
        } catch {
            // No clipboard (an old browser, or not allowed): the link is shown below to copy by hand.
            return;
        }
        setCopied(true);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => setCopied(false), 2500);
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <Head>
                <meta name="robots" content="noindex,nofollow" />
                {/* The secret link is never passed on to another site, the WhatsApp share included. */}
                <meta name="referrer" content="no-referrer" />
            </Head>
            <div className="bg-[var(--site-surface)]">
                <section className="mx-auto flex max-w-2xl flex-col gap-6 px-4 pt-8 pb-16 sm:px-6 sm:pt-14">
                    <GiftArt gift={gift} />

                    <GiftForNumberNote gift={gift} className="-mt-2 px-1" />

                    {note && (
                        <div role="status" className="flex items-start gap-3 rounded-2xl bg-white px-5 py-4 text-[15px] leading-7 ring-1 ring-[rgba(26,22,18,0.08)]">
                            <Info className="mt-1 size-5 shrink-0 text-[#8A6A1F]" />
                            <p className="m-0">{note}</p>
                        </div>
                    )}

                    {(claim_url || pay_url || link || whatsapp) && (
                        <div className="flex flex-col gap-3">
                            {claim_url && (
                                <Link href={claim_url} className="inline-flex h-[54px] items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C]">
                                    <Gift className="size-5" /> {t('استلمي هديتك')}
                                </Link>
                            )}
                            {pay_url && (
                                <Link href={pay_url} className="inline-flex h-[54px] items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C]">
                                    <CreditCard className="size-5" /> {t('أكملي الدفع')}
                                </Link>
                            )}
                            {(link || whatsapp) && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {link && (
                                        <button type="button" onClick={copy} className="inline-flex h-[52px] items-center justify-center gap-2 rounded-full border border-[rgba(26,22,18,0.2)] bg-white px-5 text-[15px] font-semibold transition-colors hover:border-[var(--site-ink)]">
                                            {copied ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />}
                                            {t('نسخ رابط الهدية')}
                                        </button>
                                    )}
                                    {whatsapp && (
                                        <a href={whatsapp} target="_blank" rel="noopener noreferrer" className="inline-flex h-[52px] items-center justify-center gap-2 rounded-full border border-[rgba(26,22,18,0.2)] bg-white px-5 text-[15px] font-semibold transition-colors hover:border-[var(--site-ink)]">
                                            <MessageCircle className="size-4" /> {t('مشاركة عبر واتساب')}
                                        </a>
                                    )}
                                </div>
                            )}
                            {link && (
                                <p className="m-0 text-center text-[13px] text-[#7A7060]" aria-live="polite">
                                    {copied ? <span className="font-semibold text-emerald-700">{t('نُسخ الرابط')}</span> : <span dir="ltr" className="break-all [unicode-bidi:isolate]">{link}</span>}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="rounded-[24px] bg-white p-5 ring-1 ring-[rgba(26,22,18,0.08)] sm:p-7">
                        <h2 className="m-0 mb-3 text-base font-bold">{t('شروط الهدية')}</h2>
                        <GiftTermsList terms={terms} />
                    </div>
                </section>
            </div>
        </SiteLayout>
    );
}
