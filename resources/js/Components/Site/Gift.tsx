/**
 * Gift memberships (#192) on the website and the portal: the card as its secret link draws it, its conditions, and
 * what each state says. The server sends the card in the page's language (GiftSiteController::card): names, plan,
 * duration, message and the redemption deadline only, never a price or a phone.
 */
import { Check, Smartphone } from 'lucide-react';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, dayCount } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';

export type GiftStatus = 'pending' | 'paid' | 'redeemed' | 'expired' | 'cancelled' | 'refunded';

/** A typed number as the server stores it (Phone::normalize): digits only, 00 dropped, a local Omani number with 968. */
export function normalizePhone(value: string): string {
    let digits = value.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/\D+/g, '');
    if (digits.startsWith('00')) digits = digits.slice(2);
    return digits.length === 8 ? `968${digits}` : digits;
}

/** The shape of a mobile WhatsApp can reach (Phone::isMobile): an Omani 7/9 mobile or an international number. Not proof of an account. */
export function isWhatsAppNumber(normalized: string): boolean {
    return normalized.startsWith('968') ? /^968[79]\d{7}$/.test(normalized) : /^[1-9]\d{7,14}$/.test(normalized);
}

/** +968 9XXX XXXX, or +digits for another country (Phone::display). */
export function displayPhone(normalized: string): string {
    return normalized.startsWith('968') && normalized.length === 11 ? `+968 ${normalized.slice(3, 7)} ${normalized.slice(7)}` : normalized ? `+${normalized}` : '';
}

export interface GiftCardData {
    status: GiftStatus | string;
    recipient_name: string;
    giver_name: string;
    message: string | null;
    plan_name: string;
    duration_label: string;
    /** The last day to redeem it; null until it is paid. */
    expires_on: string | null;
    club: string;
    /** Only an account with the recipient's number takes it from the link; the number itself is never sent. */
    for_recipient_number: boolean;
}

export interface GiftTermsData {
    redeem_days: number;
    max_start_days: number;
    /** The last day to redeem it if it is paid today (Y-m-d): the policy states it before payment. */
    expires_if_paid_today: string;
}

/** Why a gift cannot be used now (its card says so instead of offering to claim it); null for a usable one. */
export function giftStatusText(status: string, t: ReturnType<typeof useT>): string | null {
    switch (status) {
        case 'pending': return t('الهدية بانتظار تأكيد الدفع. تظهر صالحة هنا حين يصل تأكيد الدفع من البوابة.');
        case 'redeemed': return t('استُخدمت هذه الهدية.');
        case 'expired': return t('انتهت مهلة استرداد هذه الهدية.');
        case 'cancelled': return t('هذه الهدية ملغاة.');
        case 'refunded': return t('أُلغيت هذه الهدية بإشعار دائن، فلا يمكن استخدامها. للاستفسار عن مبلغها تواصل مع النادي.');
        default: return null;
    }
}

/** The gift's conditions, as every gift page states them. */
export function giftTerms(terms: GiftTermsData, t: ReturnType<typeof useT>): string[] {
    return [
        t('تُستلم الهدية خلال :days من تاريخ الدفع.', { days: dayCount(terms.redeem_days) }),
        t('يبدأ الاشتراك في يوم تختاره المستلمة، حتى :max بعد استلام الهدية.', { max: dayCount(terms.max_start_days) }),
        t('تُستخدم الهدية مرة واحدة.'),
        t('لا تُردّ قيمة الهدية بعد انتهاء مهلتها أو بعد استلامها، ولا يُردّ جزء منها.'),
        t('لا رسوم إضافية على المستلمة.'),
        t('لا يلزم حساب لشراء الهدية.'),
    ];
}

/** «تُستلم هذه الهدية بحساب رقم جوال المستلمة.»: on a gift tied to her number (never the number itself). */
export function GiftForNumberNote({ gift, className }: { gift: Pick<GiftCardData, 'for_recipient_number'>; className?: string }) {
    const t = useT();
    if (!gift.for_recipient_number) return null;
    return (
        <p className={cn('m-0 flex items-start gap-2 text-[13px] leading-6 text-[#5E5648]', className)}>
            <Smartphone className="mt-1 size-4 shrink-0 text-[#8A6A1F]" />
            {t('تُستلم هذه الهدية بحساب رقم جوال المستلمة.')}
        </p>
    );
}

export function GiftTermsList({ terms, className }: { terms: GiftTermsData; className?: string }) {
    const t = useT();
    return (
        <ul className={cn('m-0 flex list-none flex-col gap-2 p-0', className)}>
            {giftTerms(terms, t).map((line) => (
                <li key={line} className="flex items-start gap-2.5 text-sm leading-6 text-[#4A443A]">
                    <span className="mt-1 grid size-4 shrink-0 place-items-center rounded-full bg-[var(--site-gold)]/20 text-[#8A6A1F]"><Check className="size-3" strokeWidth={3} /></span>
                    {line}
                </li>
            ))}
        </ul>
    );
}

/** A message in the page's quotation marks. */
function quoted(text: string, english: boolean): string {
    return english ? `“${text}”` : `«${text}»`;
}

/**
 * The digital gift card: dark with gold, the club's official logo on a light plate as it is (never redrawn or
 * stretched), «هدية», to and from, the plan and its length, the message and the deadline. `compact` for the claim
 * and redemption pages.
 */
export function GiftArt({ gift, compact = false }: { gift: GiftCardData; compact?: boolean }) {
    const t = useT();
    const english = useLocale() === 'en';
    const { tenant } = useShared();
    const logo = tenant?.logo_url;
    const usable = gift.status === 'paid';

    return (
        <article
            aria-label={t('بطاقة الهدية')}
            className={cn(
                'relative isolate overflow-hidden rounded-[28px] bg-[#1A1612] text-white shadow-[0_28px_60px_rgba(26,22,18,0.28)]',
                compact ? 'p-5 sm:p-6' : 'p-6 sm:p-9',
            )}
        >
            {/* Gold light and a fine inner frame: decoration only. */}
            <div aria-hidden className="pointer-events-none absolute -top-24 end-[-5rem] -z-10 size-72 rounded-full bg-[#C9A042]/25 blur-3xl" />
            <div aria-hidden className="pointer-events-none absolute -bottom-28 start-[-4rem] -z-10 size-64 rounded-full bg-[#C9A042]/10 blur-3xl" />
            <div aria-hidden className="pointer-events-none absolute inset-2.5 -z-10 rounded-[22px] border border-[#C9A042]/35" />

            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="m-0 text-xs font-semibold tracking-[0.2em] text-[#C9A042] uppercase">{gift.club}</p>
                    <h2 className={cn('m-0 mt-1 font-bold leading-tight text-[#C9A042]', compact ? 'text-3xl' : 'text-[clamp(40px,9vw,56px)]')}>{t('هدية')}</h2>
                </div>
                {logo && (
                    <span className={cn('flex shrink-0 items-center justify-center rounded-2xl bg-[#FBF8F2] px-3 py-2 shadow-sm', compact ? 'h-14 max-w-[42%]' : 'h-16 max-w-[45%] sm:h-20')}>
                        <img src={logo} alt={gift.club} className="block h-full w-auto max-w-full object-contain" />
                    </span>
                )}
            </div>

            <dl className={cn('m-0 grid gap-4', compact ? 'mt-5' : 'mt-8 sm:grid-cols-2 sm:gap-6')}>
                <div className="min-w-0">
                    <dt className="text-xs font-medium text-[#C9A042]">{t('إلى')}</dt>
                    <dd className={cn('m-0 mt-0.5 font-bold break-words', compact ? 'text-xl' : 'text-2xl sm:text-[28px]')}>{gift.recipient_name}</dd>
                </div>
                <div className="min-w-0">
                    <dt className="text-xs font-medium text-[#C9A042]">{t('من')}</dt>
                    <dd className={cn('m-0 mt-0.5 font-semibold break-words text-[#E9DFC8]', compact ? 'text-lg' : 'text-xl sm:text-2xl')}>{gift.giver_name}</dd>
                </div>
            </dl>

            <div className={cn('flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-[#C9A042]/25', compact ? 'mt-5 pt-4' : 'mt-8 pt-6')}>
                <span className="text-lg font-bold break-words">{gift.plan_name}</span>
                {gift.duration_label && <span className="rounded-full border border-[#C9A042]/50 px-3 py-0.5 text-sm text-[#E9DFC8]">{gift.duration_label}</span>}
            </div>

            {gift.message && !compact && (
                <blockquote className="m-0 mt-6 text-[17px] leading-8 break-words whitespace-pre-line text-[#E9DFC8] italic">{quoted(gift.message, english)}</blockquote>
            )}

            {usable && gift.expires_on && (
                <p className={cn('m-0 text-sm text-[#CFC7B8]', compact ? 'mt-4' : 'mt-7')}>{t('صالحة للاستلام حتى :date', { date: date(gift.expires_on) })}</p>
            )}
        </article>
    );
}
