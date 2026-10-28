import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CheckCircle2, CreditCard, FileText, Lock, Store, XCircle } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';

export interface PublicInvoice {
    number: string;
    status: string;
    customer_name: string;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    tax_rate: string;
    total: string;
    credited_total: string;
    paid_total: string;
    currency: string;
    balance: string;
    issue_date: string;
    items: { description: string; quantity: string; unit_price: string; discount: string; line_total: string }[];
}

export function InvoiceTotals({ invoice, currency }: { invoice: PublicInvoice; currency: string }) {
    return (
        <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-muted">المجموع</dt><dd className="num">{money(invoice.subtotal, currency)}</dd></div>
            {Number(invoice.discount_total) > 0 && <div className="flex justify-between"><dt className="text-muted">الخصم</dt><dd className="num text-emerald-700">− {money(invoice.discount_total, currency)}</dd></div>}
            {Number(invoice.tax_total) > 0 && <div className="flex justify-between"><dt className="text-muted">الضريبة (<span className="num">{Number(invoice.tax_rate)}%</span>)</dt><dd className="num">{money(invoice.tax_total, currency)}</dd></div>}
            <div className="flex justify-between border-t border-line pt-2 font-semibold"><dt>الإجمالي</dt><dd className="num">{money(invoice.total, currency)}</dd></div>
            {Number(invoice.credited_total) > 0 && <div className="flex justify-between"><dt className="text-muted">إشعارات دائنة</dt><dd className="num text-emerald-700">− {money(invoice.credited_total, currency)}</dd></div>}
            {Number(invoice.paid_total) > 0 && <div className="flex justify-between"><dt className="text-muted">المدفوع</dt><dd className="num">{money(invoice.paid_total, currency)}</dd></div>}
            <div className="flex justify-between text-base font-semibold text-brand-700"><dt>المتبقي</dt><dd className="num">{money(invoice.balance, currency)}</dd></div>
        </dl>
    );
}

export default function Pay({ site, seo, invoice, token, onlinePayments }: { site: SiteInfo; seo: { title: string }; invoice: PublicInvoice; token: string; onlinePayments: boolean }) {
    const currency = useCurrency();
    const [busy, setBusy] = useState(false);
    const open = ['issued', 'partially_paid'].includes(invoice.status) && Number(invoice.balance) > 0;

    const pay = () => {
        setBusy(true);
        router.post(route('site.pay.start', { token }), {}, { onFinish: () => setBusy(false) });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="mx-auto max-w-xl px-4 py-12">
                <div className="overflow-hidden rounded-[2rem] border border-line bg-white shadow-xl shadow-brand/5">
                    <div className="bg-gradient-to-br from-brand to-brand-700 px-6 py-8 text-white">
                        <p className="text-sm text-white/80">فاتورة رقم</p>
                        <p className="mt-1 font-display text-2xl font-semibold num">{invoice.number}</p>
                        <p className="mt-3 text-sm text-white/85">{invoice.customer_name} · {date(invoice.issue_date)}</p>
                        <p className="mt-6 text-sm text-white/80">المبلغ المستحق</p>
                        <p className="font-display text-4xl font-semibold num">{money(invoice.balance, currency)}</p>
                    </div>

                    <div className="p-6">
                        <ul className="divide-y divide-line text-sm">
                            {invoice.items.map((item, i) => (
                                <li key={i} className="flex justify-between gap-4 py-3">
                                    <span className="text-ink/85">{item.description}{Number(item.quantity) !== 1 && <span className="text-muted"> × <span className="num">{Number(item.quantity)}</span></span>}</span>
                                    <span className="num shrink-0">{money(item.line_total, currency)}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-4 rounded-2xl bg-brand-50/50 p-4">
                            <InvoiceTotals invoice={invoice} currency={currency} />
                        </div>

                        <div className="mt-6">
                            {invoice.status === 'paid' && (
                                <p className="flex items-center justify-center gap-2 rounded-2xl bg-emerald-50 p-4 text-sm font-medium text-emerald-700"><CheckCircle2 className="size-5" /> تم سداد هذه الفاتورة بالكامل. شكرًا لكِ!</p>
                            )}
                            {invoice.status === 'void' && (
                                <p className="flex items-center justify-center gap-2 rounded-2xl bg-red-50 p-4 text-sm font-medium text-red-700"><XCircle className="size-5" /> هذه الفاتورة ملغاة.</p>
                            )}
                            {open && onlinePayments && (
                                <>
                                    <Button size="lg" className="w-full rounded-full" loading={busy} onClick={pay} icon={<CreditCard className="size-5" />}>
                                        ادفعي الآن <span className="num">{money(invoice.balance, currency)}</span>
                                    </Button>
                                    <p className="mt-3 flex items-center justify-center gap-1.5 text-xs text-muted"><Lock className="size-3.5" /> دفع آمن عبر بوابة الدفع. يتم التفعيل تلقائيًا بعد تأكيد البنك.</p>
                                </>
                            )}
                            {open && !onlinePayments && (
                                <p className="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm text-amber-800">
                                    <Store className="mt-0.5 size-5 shrink-0" /> الدفع الإلكتروني غير متاح حاليًا. يمكنك السداد في استقبال النادي نقدًا أو بالبطاقة أو بالتحويل البنكي.
                                </p>
                            )}
                        </div>

                        <Link href={route('site.invoice', { token })} className="mt-5 flex items-center justify-center gap-1.5 text-sm text-brand hover:underline">
                            <FileText className="size-4" /> عرض الفاتورة الكاملة وطباعتها
                        </Link>
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
