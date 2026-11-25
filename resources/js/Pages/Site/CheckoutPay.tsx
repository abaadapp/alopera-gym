import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircle2, CreditCard, Lock, XCircle } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button } from '@/Components/ui';
import { money } from '@/lib/format';
import { useT } from '@/lib/i18n';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    state: 'open' | 'completed' | 'abandoned';
    name: string;
    plan: string | null;
    lines: { description: string; line_total: string }[];
    total: string | null;
    currency: string | null;
    invoice_url: string | null;
    start_url: string;
}

/**
 * A payment link reception sent for a membership: what it pays for and how much, and one button to Paymob's secure
 * page. Nothing is sold until the money is confirmed; then the paid invoice and the activation reach her on WhatsApp.
 */
export default function CheckoutPay({ site, seo, state, name, plan, lines, total, currency, invoice_url, start_url }: Props) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const cur = currency ?? 'OMR';

    const pay = () => {
        setBusy(true);
        router.post(start_url, {}, { onFinish: () => setBusy(false) });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="mx-auto max-w-xl px-4 py-12">
                <div className="overflow-hidden rounded-[2rem] border border-line bg-white shadow-xl shadow-brand/5">
                    <div className="bg-[var(--site-ink)] px-6 py-8 text-white">
                        <p className="text-sm text-white/75">{name ? t('مرحبًا :name', { name }) : t('مرحبًا')}</p>
                        <h1 className="mt-1 text-2xl font-bold">{plan ? t('اشتراكك في :plan', { plan }) : t('اشتراكك')}</h1>
                        {total && state === 'open' && <p className="num mt-3 text-3xl font-bold text-[var(--site-gold)]">{money(total, cur)}</p>}
                    </div>

                    <div className="space-y-5 p-6">
                        {state === 'open' && (
                            <>
                                <ul className="divide-y divide-line text-sm">
                                    {lines.map((l, i) => (
                                        <li key={i} className="flex justify-between gap-3 py-2.5"><span>{l.description}</span><span className="num shrink-0">{money(l.line_total, cur)}</span></li>
                                    ))}
                                </ul>
                                <Button className="w-full rounded-full" size="lg" icon={<CreditCard />} loading={busy} onClick={pay}>{t('ادفعي الآن')}</Button>
                                <p className="flex items-center justify-center gap-1.5 text-center text-xs text-muted"><Lock className="size-3.5" />{t('يتفعّل اشتراكك فور تأكيد الدفع، وتصلك الفاتورة على واتساب.')}</p>
                            </>
                        )}
                        {state === 'completed' && (
                            <div className="space-y-4 text-center">
                                <CheckCircle2 className="mx-auto size-12 text-emerald-500" />
                                <p className="font-semibold">{t('تم الدفع وتفعيل اشتراكك. شكرًا لك!')}</p>
                                {invoice_url && <Button variant="outline" href={invoice_url} className="rounded-full">{t('عرض الفاتورة والإيصال')}</Button>}
                            </div>
                        )}
                        {state === 'abandoned' && (
                            <div className="space-y-3 text-center">
                                <XCircle className="mx-auto size-12 text-red-500" />
                                <p className="font-semibold">{t('لم يعد هذا الرابط صالحًا للدفع.')}</p>
                                <p className="text-sm text-muted">{t('تواصلي مع النادي ليرسل لك رابطًا جديدًا.')}</p>
                            </div>
                        )}
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
