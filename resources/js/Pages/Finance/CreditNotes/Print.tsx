import { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { paymentMethod } from '@/lib/labels';

interface Props {
    note: {
        number: string;
        amount: string;
        refunded_total: string;
        currency: string;
        reason: string;
        issued_at: string;
        creator: string | null;
        refunds: { receipt_number: string | null; method: string; amount: string; reference: string | null; paid_at: string | null }[];
        invoice: { id: number; number: string; customer_name: string; customer_phone: string | null; total: string; credited_total: string; paid_total: string; issue_date: string; net_total: string; balance: string };
    };
    club: { name: string; legal_name: string | null; phone: string | null; address: string | null; logo_url: string | null; vat_number: string | null };
}

/** A4 printable credit note — it references the original invoice, which is never changed. */
export default function CreditNotePrint({ note, club }: Props) {
    const currency = useCurrency() || note.currency;

    useEffect(() => {
        const id = setTimeout(() => window.print(), 400);
        return () => clearTimeout(id);
    }, []);

    return (
        <>
            <Head title={`إشعار دائن ${note.number}`} />
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
                                {club.phone && <p className="text-muted num">{club.phone}</p>}
                                {club.vat_number && <p className="text-muted">الرقم الضريبي: <span className="num">{club.vat_number}</span></p>}
                            </div>
                        </div>
                        <div className="text-end">
                            <p className="font-display text-2xl font-semibold text-brand">إشعار دائن</p>
                            <p className="mt-1 num">{note.number}</p>
                            <p className="mt-1 text-xs text-muted">{note.issued_at}</p>
                        </div>
                    </header>

                    <section className="mt-6 grid grid-cols-2 gap-6">
                        <div>
                            <p className="text-xs text-muted">إلى</p>
                            <p className="mt-1 font-semibold">{note.invoice.customer_name}</p>
                            {note.invoice.customer_phone && <p className="num">{note.invoice.customer_phone}</p>}
                        </div>
                        <div className="text-end">
                            <p><span className="text-muted">الفاتورة الأصلية: </span><span className="num">{note.invoice.number}</span></p>
                            <p><span className="text-muted">تاريخها: </span>{date(note.invoice.issue_date)}</p>
                        </div>
                    </section>

                    <section className="mt-8 rounded-xl border border-line p-4">
                        <p className="text-xs text-muted">السبب</p>
                        <p className="mt-1">{note.reason}</p>
                    </section>

                    <section className="mt-6 flex justify-end">
                        <dl className="w-80 space-y-1.5">
                            <PrintRow label="إجمالي الفاتورة الأصلية" value={money(note.invoice.total, currency)} />
                            <PrintRow label="قيمة هذا الإشعار" value={`- ${money(note.amount, currency)}`} strong />
                            <PrintRow label="إجمالي الإشعارات على الفاتورة" value={`- ${money(note.invoice.credited_total, currency)}`} />
                            <PrintRow label="صافي الفاتورة" value={money(note.invoice.net_total, currency)} strong />
                            <PrintRow label="المردود للعميلة بهذا الإشعار" value={money(note.refunded_total, currency)} />
                        </dl>
                    </section>

                    {note.refunds.length > 0 && (
                        <section className="mt-8">
                            <p className="mb-2 font-semibold">المبالغ المردودة</p>
                            <table className="w-full text-xs">
                                <tbody>
                                    {note.refunds.map((r, i) => (
                                        <tr key={i} className="border-b border-line">
                                            <td className="py-1.5 num">{r.receipt_number}</td>
                                            <td className="py-1.5">{paymentMethod[r.method] ?? r.method}{r.reference && <> · <span className="num">{r.reference}</span></>}</td>
                                            <td className="py-1.5">{r.paid_at}</td>
                                            <td className="py-1.5 text-end num">{money(r.amount, currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    )}

                    <footer className="mt-10 border-t border-line pt-4 text-xs text-muted">
                        يُعدّل هذا الإشعار صافي الفاتورة {note.invoice.number} دون تغيير الفاتورة الأصلية.{note.creator && ` أصدرته: ${note.creator}.`}
                    </footer>
                </article>
            </div>
        </>
    );
}

function PrintRow({ label: text, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex justify-between gap-4 ${strong ? 'border-t border-ink/20 pt-1.5 font-semibold' : ''}`}>
            <dt className={strong ? '' : 'text-muted'}>{text}</dt>
            <dd className="num">{value}</dd>
        </div>
    );
}
