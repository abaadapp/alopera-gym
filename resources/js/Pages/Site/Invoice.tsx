import { Head, Link } from '@inertiajs/react';
import { CreditCard, Printer } from 'lucide-react';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { useCurrency, useShared } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { invoiceStatus, label, paymentMethod } from '@/lib/labels';
import { InvoiceTotals, type PublicInvoice } from './Pay';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    invoice: PublicInvoice & {
        payments: { receipt_number: string | null; kind: string; method: string; amount: string; paid_at: string | null }[];
        pay_url: string | null;
    };
    club: { name: string; legal_name: string | null; phone: string | null; address: string | null; vat_number: string | null; footer: string | null };
}

/** Public, printable invoice (link sent with receipts on WhatsApp). Standalone so it prints cleanly. */
export default function Invoice({ site, seo, invoice, club }: Props) {
    const currency = useCurrency();
    const { tenant } = useShared();
    const [statusLabel] = label(invoiceStatus, invoice.status);

    return (
        <>
            <Head title={seo.title} />
            <div className="min-h-screen bg-canvas px-4 py-8 print:bg-white print:p-0">
                <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-2">
                    <Link href={route('site.home')} className="text-sm text-brand hover:underline">← {club.name}</Link>
                    <div className="flex gap-2">
                        {invoice.pay_url && (
                            <a href={invoice.pay_url} className="inline-flex items-center gap-2 rounded-full bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                                <CreditCard className="size-4" /> ادفعي المتبقي
                            </a>
                        )}
                        <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-sm hover:bg-brand-50">
                            <Printer className="size-4" /> طباعة
                        </button>
                    </div>
                </div>

                <article className="mx-auto max-w-3xl rounded-3xl border border-line bg-white p-6 shadow-sm sm:p-10 print:rounded-none print:border-0 print:shadow-none">
                    <header className="flex flex-wrap items-start justify-between gap-6 border-b border-line pb-6">
                        <div className="flex items-center gap-3">
                            {site.logo_url ? (
                                <img src={site.logo_url} alt={club.name} width={56} height={56} className="size-14 rounded-2xl object-cover" />
                            ) : (
                                <span className="grid size-14 place-items-center rounded-2xl bg-brand font-display text-xl text-white">{tenant?.name?.[0]}</span>
                            )}
                            <div>
                                <p className="font-display text-lg font-semibold">{club.legal_name || club.name}</p>
                                {club.address && <p className="text-xs text-muted">{club.address}</p>}
                                {club.phone && <p className="text-xs text-muted"><span className="num">{club.phone}</span></p>}
                                {club.vat_number && <p className="text-xs text-muted">الرقم الضريبي: <span className="num">{club.vat_number}</span></p>}
                            </div>
                        </div>
                        <div className="text-end">
                            <p className="font-display text-2xl font-semibold">فاتورة</p>
                            <p className="mt-1 text-sm num">{invoice.number}</p>
                            <p className="text-xs text-muted">{date(invoice.issue_date)}</p>
                            <span className="mt-2 inline-block rounded-full bg-brand-50 px-3 py-0.5 text-xs font-medium text-brand-700">{statusLabel}</span>
                        </div>
                    </header>

                    <p className="mt-6 text-sm"><span className="text-muted">إلى: </span><span className="font-medium">{invoice.customer_name}</span></p>

                    <div className="mt-6 overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>البند</th><th>الكمية</th><th>السعر</th><th>الخصم</th><th className="text-end">الإجمالي</th></tr></thead>
                            <tbody>
                                {invoice.items.map((it, i) => (
                                    <tr key={i}>
                                        <td>{it.description}</td>
                                        <td className="num">{Number(it.quantity)}</td>
                                        <td className="num">{money(it.unit_price, currency)}</td>
                                        <td className="num">{Number(it.discount) > 0 ? money(it.discount, currency) : '—'}</td>
                                        <td className="num text-end">{money(it.line_total, currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-6 flex justify-end">
                        <div className="w-full max-w-xs"><InvoiceTotals invoice={invoice} currency={currency} /></div>
                    </div>

                    {invoice.payments.length > 0 && (
                        <section className="mt-8">
                            <h2 className="mb-2 text-sm font-semibold">الإيصالات</h2>
                            <div className="overflow-x-auto">
                                <table className="table-base">
                                    <thead><tr><th>رقم الإيصال</th><th>التاريخ</th><th>الطريقة</th><th className="text-end">المبلغ</th></tr></thead>
                                    <tbody>
                                        {invoice.payments.map((p, i) => (
                                            <tr key={i}>
                                                <td className="num">{p.receipt_number ?? '—'}</td>
                                                <td className="num">{p.paid_at}</td>
                                                <td>{p.kind === 'refund' ? 'استرداد — ' : ''}{paymentMethod[p.method] ?? p.method}</td>
                                                <td className="num text-end">{p.kind === 'refund' ? '− ' : ''}{money(p.amount, currency)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    {club.footer && <p className="mt-10 border-t border-line pt-4 text-center text-xs leading-6 text-muted">{club.footer}</p>}
                </article>
            </div>
        </>
    );
}
