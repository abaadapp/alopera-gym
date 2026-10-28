import { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { invoiceStatus, label, paymentMethod } from '@/lib/labels';

interface Props {
    invoice: {
        number: string;
        status: string;
        customer_name: string;
        customer_phone: string | null;
        subtotal: string;
        discount_total: string;
        tax_total: string;
        total: string;
        credited_total: string;
        net_total: string;
        paid_total: string;
        balance: string;
        refund_due: string;
        currency: string;
        issue_date: string;
        due_date: string | null;
        notes: string | null;
        items: { description: string; quantity: string; unit_price: string; discount: string; line_total: string }[];
        payments: { receipt_number: string | null; kind: string; method: string; reference: string | null; amount: string; paid_at: string | null }[];
        credit_notes: { number: string; amount: string; reason: string; issued_at: string }[];
    };
    club: { name: string; legal_name: string | null; phone: string | null; email: string | null; address: string | null; logo_url: string | null; vat_number: string | null; footer: string | null };
}

/** A4 printable invoice — no panel chrome. */
export default function InvoicePrint({ invoice, club }: Props) {
    const currency = useCurrency() || invoice.currency;
    const [statusLabel] = label(invoiceStatus, invoice.status);

    useEffect(() => {
        const id = setTimeout(() => window.print(), 400);
        return () => clearTimeout(id);
    }, []);

    return (
        <>
            <Head title={`فاتورة ${invoice.number}`} />
            <style>{'@page { size: A4; margin: 14mm; }'}</style>
            <div className="min-h-screen bg-stone-100 py-8 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex max-w-[210mm] justify-end gap-2 px-4">
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm text-white">
                        <Printer className="size-4" /> طباعة / حفظ PDF
                    </button>
                    <button onClick={() => window.close()} className="rounded-xl border border-line bg-white px-4 py-2 text-sm">إغلاق</button>
                </div>
                <article className="mx-auto max-w-[210mm] bg-white p-10 text-sm shadow-sm print:p-0 print:shadow-none">
                    <header className="flex items-start justify-between gap-6 border-b-2 border-brand pb-6">
                        <div className="flex items-center gap-4">
                            {club.logo_url && <img src={club.logo_url} alt="" className="size-16 rounded-xl object-cover" />}
                            <div>
                                <h1 className="text-xl font-semibold">{club.legal_name || club.name}</h1>
                                {club.address && <p className="text-muted">{club.address}</p>}
                                <p className="text-muted">
                                    {club.phone && <span className="num">{club.phone}</span>}
                                    {club.phone && club.email && ' · '}
                                    {club.email}
                                </p>
                                {club.vat_number && <p className="text-muted">الرقم الضريبي: <span className="num">{club.vat_number}</span></p>}
                            </div>
                        </div>
                        <div className="text-end">
                            <p className="font-display text-2xl font-semibold text-brand">فاتورة</p>
                            <p className="mt-1 num">{invoice.number}</p>
                            <p className="mt-1 text-xs text-muted">{statusLabel}</p>
                        </div>
                    </header>

                    <section className="mt-6 grid grid-cols-2 gap-6">
                        <div>
                            <p className="text-xs text-muted">فاتورة إلى</p>
                            <p className="mt-1 font-semibold">{invoice.customer_name}</p>
                            {invoice.customer_phone && <p><span className="num">{invoice.customer_phone}</span></p>}
                        </div>
                        <div className="text-end">
                            <p><span className="text-muted">تاريخ الإصدار: </span>{date(invoice.issue_date)}</p>
                            {invoice.due_date && <p><span className="text-muted">تاريخ الاستحقاق: </span>{date(invoice.due_date)}</p>}
                        </div>
                    </section>

                    <table className="mt-8 w-full border-collapse">
                        <thead>
                            <tr className="bg-brand-50 text-xs text-brand-900">
                                <th className="p-2 text-start">#</th>
                                <th className="p-2 text-start">البند</th>
                                <th className="p-2 text-start">الكمية</th>
                                <th className="p-2 text-start">السعر</th>
                                <th className="p-2 text-start">الخصم</th>
                                <th className="p-2 text-start">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.items.map((item, i) => (
                                <tr key={i} className="border-b border-line">
                                    <td className="p-2 num">{i + 1}</td>
                                    <td className="p-2">{item.description}</td>
                                    <td className="p-2 num">{Number(item.quantity)}</td>
                                    <td className="p-2 num">{money(item.unit_price, currency)}</td>
                                    <td className="p-2 num">{Number(item.discount) > 0 ? money(item.discount, currency) : '—'}</td>
                                    <td className="p-2 num">{money(item.line_total, currency)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <section className="mt-6 flex justify-end">
                        <dl className="w-72 space-y-1.5">
                            <PrintRow label="المجموع" value={money(invoice.subtotal, currency)} />
                            {Number(invoice.discount_total) > 0 && <PrintRow label="الخصومات" value={`- ${money(invoice.discount_total, currency)}`} />}
                            {Number(invoice.tax_total) > 0 && <PrintRow label="ضريبة القيمة المضافة" value={money(invoice.tax_total, currency)} />}
                            <PrintRow label="الإجمالي" value={money(invoice.total, currency)} strong />
                            {Number(invoice.credited_total) > 0 && (
                                <>
                                    <PrintRow label="إشعارات دائنة" value={`- ${money(invoice.credited_total, currency)}`} />
                                    <PrintRow label="صافي الفاتورة" value={money(invoice.net_total, currency)} strong />
                                </>
                            )}
                            <PrintRow label="المدفوع" value={money(invoice.paid_total, currency)} />
                            {invoice.status !== 'void' && <PrintRow label="المتبقي" value={money(invoice.balance, currency)} strong />}
                            {Number(invoice.refund_due) > 0 && <PrintRow label="مستحق للعميلة" value={money(invoice.refund_due, currency)} />}
                        </dl>
                    </section>

                    {invoice.credit_notes.length > 0 && (
                        <section className="mt-8">
                            <p className="mb-2 font-semibold">الإشعارات الدائنة</p>
                            <table className="w-full text-xs">
                                <tbody>
                                    {invoice.credit_notes.map((n) => (
                                        <tr key={n.number} className="border-b border-line">
                                            <td className="py-1.5 num">{n.number}</td>
                                            <td className="py-1.5">{n.reason}</td>
                                            <td className="py-1.5">{date(n.issued_at)}</td>
                                            <td className="py-1.5 text-end num">- {money(n.amount, currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    )}

                    {invoice.payments.length > 0 && (
                        <section className="mt-8">
                            <p className="mb-2 font-semibold">الدفعات</p>
                            <table className="w-full text-xs">
                                <tbody>
                                    {invoice.payments.map((p, i) => (
                                        <tr key={i} className="border-b border-line">
                                            <td className="px-2 py-1.5 num">{p.receipt_number}</td>
                                            <td className="px-2 py-1.5">{p.kind === 'refund' ? 'رد مبلغ' : paymentMethod[p.method] ?? p.method}{p.reference && <> · <span className="num">{p.reference}</span></>}</td>
                                            <td className="px-2 py-1.5 num">{p.paid_at}</td>
                                            <td className="px-2 py-1.5 text-end num">{p.kind === 'refund' ? '- ' : ''}{money(p.amount, currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    )}

                    {(invoice.notes || club.footer) && (
                        <footer className="mt-10 border-t border-line pt-4 text-xs text-muted">
                            {invoice.notes && <p>{invoice.notes}</p>}
                            {club.footer && <p className="mt-1">{club.footer}</p>}
                        </footer>
                    )}
                </article>
            </div>
        </>
    );
}

function PrintRow({ label: text, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex justify-between ${strong ? 'border-t border-ink/20 pt-1.5 font-semibold' : ''}`}>
            <dt className={strong ? '' : 'text-muted'}>{text}</dt>
            <dd className="num">{value}</dd>
        </div>
    );
}
