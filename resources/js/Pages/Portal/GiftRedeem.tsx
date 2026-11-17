import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { CalendarDays, Gift } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { GiftArt, type GiftCardData } from '@/Components/Site/Gift';
import { date } from '@/lib/format';
import { useT } from '@/lib/i18n';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    gift: GiftCardData;
    token: string;
    /** The first and last day she may start on (today, and the furthest the gift allows). */
    min: string;
    max: string;
    /** The day after her current membership when it falls in the window, else today. */
    suggested: string;
    /** The last day her current membership covers, if she has one. */
    covered_until: string | null;
    member_name: string;
}

/** The recipient, signed in: the gift, the day her membership starts, a review, and her confirmation. */
export default function GiftRedeem({ site, seo, gift, token, min, max, suggested, covered_until }: Props) {
    const t = useT();
    const form = useForm({ starts_on: suggested });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('portal.gift.redeem.store', { token }), { preserveScroll: true });
    };

    return (
        <PortalLayout site={site} title={seo.title}>
            <form onSubmit={submit} className="mx-auto flex max-w-2xl flex-col gap-5">
                <h2 className="m-0 flex items-center gap-2 text-xl font-semibold"><Gift className="size-5 text-brand" /> {t('استلام الهدية')}</h2>

                <GiftArt gift={gift} compact />

                <section className="flex flex-col gap-3 rounded-3xl border border-line bg-white p-5 sm:p-6">
                    <label htmlFor="gift-starts-on" className="flex items-center gap-2 text-base font-semibold">
                        <CalendarDays className="size-5 text-brand" /> {t('اختاري تاريخ بدء الاشتراك')}
                    </label>
                    {covered_until && <p className="m-0 text-sm leading-6 text-muted">{t('اشتراككِ الحالي يغطي حتى :date، فاختاري يومًا بعده.', { date: date(covered_until) })}</p>}
                    <input
                        id="gift-starts-on"
                        type="date"
                        min={min}
                        max={max}
                        value={form.data.starts_on}
                        onChange={(e) => form.setData('starts_on', e.target.value)}
                        aria-invalid={!!errors.starts_on}
                        required
                        className="h-[52px] w-full min-w-0 rounded-2xl border border-line bg-white px-4 text-base outline-none focus:border-brand focus:ring-2 focus:ring-brand/20 aria-[invalid=true]:border-red-500"
                    />
                    <p className="m-0 text-[13px] text-muted">{t('من :min حتى :max', { min: date(min), max: date(max) })}</p>
                    {errors.starts_on && <p className="m-0 text-sm text-red-600">{errors.starts_on}</p>}
                </section>

                <section className="flex flex-col gap-2.5 rounded-3xl border border-line bg-white p-5 sm:p-6">
                    <h3 className="m-0 mb-1 text-base font-semibold">{t('مراجعة')}</h3>
                    <Row k={t('الباقة')} v={gift.plan_name} />
                    {gift.duration_label && <Row k={t('المدة')} v={gift.duration_label} />}
                    <Row k={t('تاريخ البدء')} v={form.data.starts_on ? date(form.data.starts_on) : '—'} />
                    <p className="m-0 mt-1 rounded-2xl bg-brand-50 px-4 py-2.5 text-center text-sm font-medium text-brand-700">{t('دون أي مبلغ إضافي')}</p>
                </section>

                {errors.error && <p role="alert" className="m-0 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{errors.error}</p>}

                <button
                    type="submit"
                    disabled={form.processing || !form.data.starts_on}
                    className="inline-flex h-[54px] items-center justify-center gap-2 rounded-full bg-[var(--site-gold)] px-6 text-base font-bold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Gift className="size-5" /> {form.processing ? t('جارٍ التأكيد…') : t('تأكيد استلام الهدية')}
                </button>
            </form>
        </PortalLayout>
    );
}

function Row({ k, v }: { k: ReactNode; v: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-[15px]">
            <span className="min-w-0 text-muted">{k}</span>
            <span className="min-w-0 text-end font-medium">{v}</span>
        </div>
    );
}
