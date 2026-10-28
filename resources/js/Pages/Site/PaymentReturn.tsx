import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CheckCircle2, Clock, Loader2, RefreshCw, XCircle } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { useShared } from '@/hooks/useShared';
import { money } from '@/lib/format';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    reference: string;
    status: 'pending' | 'paid' | 'failed' | 'expired' | string;
    amount: string;
    currency: string;
    invoice: { number?: string; token?: string | null };
}

const POLL_MS = 3000;
const MAX_POLLS = 40; // ~2 minutes

/**
 * Landing page after the payment gateway. The query string from the gateway is
 * ignored on purpose: only the status recorded by the verified webhook is shown.
 */
export default function PaymentReturn({ site, seo, reference, status: initial, amount, currency, invoice }: Props) {
    const { member } = useShared();
    const [status, setStatus] = useState(initial);
    const [timedOut, setTimedOut] = useState(false);
    const polls = useRef(0);

    useEffect(() => {
        if (status !== 'pending') return;
        const id = setInterval(async () => {
            polls.current += 1;
            try {
                const res = await fetch(route('site.pay.status', { reference }), { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = (await res.json()) as { status: string };
                    if (data.status !== 'pending') setStatus(data.status);
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
        if (status === 'paid') {
            return {
                icon: <CheckCircle2 className="size-14 text-emerald-500" />,
                title: 'تم الدفع بنجاح',
                text: 'استلمنا دفعتك وتم تحديث فاتورتك. ستصلك رسالة تأكيد على واتساب.',
                tone: 'bg-emerald-50',
            };
        }
        if (status === 'failed' || status === 'expired') {
            return {
                icon: <XCircle className="size-14 text-red-500" />,
                title: status === 'expired' ? 'انتهت مهلة الدفع' : 'لم تكتمل عملية الدفع',
                text: 'لم يُخصم أي مبلغ مقابل هذه المحاولة. يمكنك المحاولة مرة أخرى.',
                tone: 'bg-red-50',
            };
        }
        if (timedOut) {
            return {
                icon: <Clock className="size-14 text-amber-500" />,
                title: 'جاري تأكيد الدفع',
                text: 'لم يصلنا تأكيد البنك بعد. لا داعي للدفع مرة أخرى — سنفعّل اشتراكك تلقائيًا فور وصول التأكيد وستصلك رسالة على واتساب.',
                tone: 'bg-amber-50',
            };
        }
        return {
            icon: <Loader2 className="size-14 animate-spin text-brand" />,
            title: 'نتحقق من الدفع…',
            text: 'ننتظر تأكيد البنك الآمن. قد يستغرق ذلك لحظات، يُرجى عدم إغلاق الصفحة.',
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
                        <div className="flex justify-between"><dt className="text-muted">المبلغ</dt><dd className="num font-medium">{money(amount, currency)}</dd></div>
                        {invoice.number && <div className="flex justify-between"><dt className="text-muted">الفاتورة</dt><dd className="num">{invoice.number}</dd></div>}
                        <div className="flex justify-between"><dt className="text-muted">المرجع</dt><dd className="num text-xs">{reference}</dd></div>
                    </dl>

                    <div className="mt-6 flex flex-col gap-2">
                        {(status === 'failed' || status === 'expired') && invoice.token && (
                            <Link href={route('site.pay.show', { token: invoice.token })} className="inline-flex items-center justify-center gap-2 rounded-full bg-brand py-3 text-sm font-medium text-white hover:bg-brand-600">
                                <RefreshCw className="size-4" /> المحاولة مرة أخرى
                            </Link>
                        )}
                        {status === 'paid' && invoice.token && (
                            <Link href={route('site.invoice', { token: invoice.token })} className="rounded-full border border-line py-3 text-sm hover:bg-brand-50">عرض الفاتورة والإيصال</Link>
                        )}
                        <Link href={route(member ? 'portal.dashboard' : 'site.home')} className="rounded-full py-3 text-sm text-brand hover:underline">
                            {member ? 'الذهاب إلى حسابي' : 'العودة للرئيسية'}
                        </Link>
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
