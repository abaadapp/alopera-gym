import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CheckCircle2, Clock, Loader2, RefreshCw, XCircle } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { useShared } from '@/hooks/useShared';
import { useCart } from '@/lib/cart';
import { money } from '@/lib/format';
import { useT } from '@/lib/i18n';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    reference: string;
    status: 'pending' | 'paid' | 'failed' | 'expired' | string;
    /** Paymob confirmed the money, and the club reviews it before the membership or order goes ahead. */
    held?: boolean;
    amount: string;
    currency: string;
    invoice: { number?: string; token?: string | null };
    /** The link of the website order this payment is for, if any. */
    order?: string | null;
}

const POLL_MS = 3000;
const MAX_POLLS = 40; // ~2 minutes

/**
 * Landing page after the payment gateway. The query string from the gateway is
 * ignored on purpose: only the status recorded by the verified webhook is shown.
 */
export default function PaymentReturn({ site, seo, reference, status: initial, held: heldAtFirst = false, amount, currency, invoice, order }: Props) {
    const { member } = useShared();
    const t = useT();
    const cart = useCart();

    // Back from paying for a shop order: the order exists, so the cart that made it is emptied.
    useEffect(() => {
        try {
            if (order && sessionStorage.getItem('shop:placing')) {
                sessionStorage.removeItem('shop:placing');
                cart.clear();
            }
        } catch {
            // ignore
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order]);
    const [status, setStatus] = useState(initial);
    const [held, setHeld] = useState(heldAtFirst);
    const [timedOut, setTimedOut] = useState(false);
    const polls = useRef(0);

    useEffect(() => {
        if (status !== 'pending') return;
        const id = setInterval(async () => {
            polls.current += 1;
            try {
                const res = await fetch(route('site.pay.status', { reference }), { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = (await res.json()) as { status: string; held?: boolean };
                    if (data.status !== 'pending') {
                        setHeld(Boolean(data.held));
                        setStatus(data.status);
                    }
                }
            } catch {
                /* network hiccup — keep polling */
            }
            if (polls.current >= MAX_POLLS) {
                setTimedOut(true);
                clearInterval(id);
            }
        }, POLL_MS);
        return () => clearInterval(id);
    }, [status, reference]);

    const view = (() => {
        // Confirmed and in the books, but held for the club's review: neither a failure nor «all done».
        if (status === 'paid' && held) {
            return {
                icon: <CheckCircle2 className="size-14 text-amber-500" />,
                title: t('تم استلام تأكيد الدفع'),
                text: t('لكن الاشتراك أو الطلب قيد المراجعة لدى النادي، وسيتواصل معك. لا داعي للدفع مرة أخرى.'),
                tone: 'bg-amber-50',
            };
        }
        if (status === 'paid') {
            return {
                icon: <CheckCircle2 className="size-14 text-emerald-500" />,
                title: t('تم الدفع بنجاح'),
                text: t('استلمنا دفعتك وتم تحديث فاتورتك. ستصلك رسالة تأكيد على واتساب.'),
                tone: 'bg-emerald-50',
            };
        }
        if (status === 'failed' || status === 'expired') {
            return {
                icon: <XCircle className="size-14 text-red-500" />,
                title: status === 'expired' ? t('انتهت مهلة الدفع') : t('لم تكتمل عملية الدفع'),
                text: t('لم يُخصم أي مبلغ مقابل هذه المحاولة. يمكنك المحاولة مرة أخرى.'),
                tone: 'bg-red-50',
            };
        }
        if (timedOut) {
            return {
                icon: <Clock className="size-14 text-amber-500" />,
                title: t('جاري تأكيد الدفع'),
                text: t('لم يصلنا تأكيد البنك بعد. لا داعي للدفع مرة أخرى — سنفعّل اشتراكك تلقائيًا فور وصول التأكيد وستصلك رسالة على واتساب.'),
                tone: 'bg-amber-50',
            };
        }
        return {
            icon: <Loader2 className="size-14 animate-spin text-brand" />,
            title: t('نتحقق من الدفع…'),
            text: t('ننتظر تأكيد البنك الآمن. قد يستغرق ذلك لحظات، يُرجى عدم إغلاق الصفحة.'),
            tone: 'bg-brand-50',
        };
    })();

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="mx-auto max-w-lg px-4 py-16">
                <div className="rounded-[2rem] border border-line bg-white p-8 text-center shadow-xl shadow-brand/5">
                    <div className={`mx-auto grid size-24 place-items-center rounded-full ${view.tone}`}>{view.icon}</div>
                    <h1 className="mt-6 font-display text-2xl font-semibold">{view.title}</h1>
                    <p className="mt-3 leading-7 text-muted">{view.text}</p>

                    <dl className="mt-6 space-y-2 rounded-2xl bg-canvas p-4 text-sm">
                        <div className="flex justify-between"><dt className="text-muted">{t('المبلغ')}</dt><dd className="num font-medium">{money(amount, currency)}</dd></div>
                        {invoice.number && <div className="flex justify-between"><dt className="text-muted">{t('الفاتورة')}</dt><dd className="num">{invoice.number}</dd></div>}
                        <div className="flex justify-between"><dt className="text-muted">{t('المرجع')}</dt><dd className="num text-xs">{reference}</dd></div>
                    </dl>

                    <div className="mt-6 flex flex-col gap-2">
                        {order && (
                            <Link href={route('site.shop.order', { token: order })} className="inline-flex items-center justify-center gap-2 rounded-full bg-brand py-3 text-sm font-medium text-white hover:bg-brand-600">
                                {t('عرض الطلب وحالته')}
                            </Link>
                        )}
                        {!order && (status === 'failed' || status === 'expired') && invoice.token && (
                            <Link href={route('site.pay.show', { token: invoice.token })} className="inline-flex items-center justify-center gap-2 rounded-full bg-brand py-3 text-sm font-medium text-white hover:bg-brand-600">
                                <RefreshCw className="size-4" /> {t('المحاولة مرة أخرى')}
                            </Link>
                        )}
                        {status === 'paid' && invoice.token && (
                            <Link href={route('site.invoice', { token: invoice.token })} className="rounded-full border border-line py-3 text-sm hover:bg-brand-50">{t('عرض الفاتورة والإيصال')}</Link>
                        )}
                        <Link href={route(member ? 'portal.dashboard' : 'site.home')} className="rounded-full py-3 text-sm text-brand hover:underline">
                            {member ? t('الذهاب إلى حسابي') : t('العودة للرئيسية')}
                        </Link>
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
