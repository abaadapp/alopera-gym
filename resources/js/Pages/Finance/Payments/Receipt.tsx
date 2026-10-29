import { useEffect, type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useCurrency } from '@/hooks/useShared';
import { money } from '@/lib/format';
import { paymentMethod } from '@/lib/labels';

interface Props {
    payment: {
        receipt_number: string;
        kind: 'payment' | 'refund';
        method: string;
        amount: string;
        currency: string;
        reference: string | null;
        paid_at: string | null;
        receiver: string | null;
        invoice: { id: number; number: string; customer_name: string; total: string; paid_total: string; balance: string } | null;
    };
    club: { name: string; phone: string | null; address: string | null; logo_url: string | null; vat_number: string | null };
}

/** Receipt sized for 80mm thermal printers; also prints fine on A4. */
export default function Receipt({ payment, club }: Props) {
    const currency = useCurrency() || payment.currency;
    const refund = payment.kind === 'refund';

    useEffect(() => {
        const id = setTimeout(() => window.print(), 400);
        return () => clearTimeout(id);
    }, []);

    return (
        <>
            <Head title={`إيصال ${payment.receipt_number}`} />
            <style>{'@page { size: 80mm auto; margin: 4mm; } @media print { body { width: 72mm; } }'}</style>
            <div className="min-h-screen bg-stone-100 py-8 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex w-[80mm] justify-center gap-2">
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm text-white"><Printer className="size-4" /> طباعة</button>
                    <button onClick={() => window.close()} className="rounded-xl border border-line bg-white px-4 py-2 text-sm">إغلاق</button>
                </div>
                <article className="mx-auto w-[80mm] bg-white px-4 py-5 text-[12px] leading-relaxed shadow-sm print:w-full print:p-0 print:shadow-none">
                    <header className="border-b border-dashed border-ink/30 pb-3 text-center">
                        {club.logo_url && <img src={club.logo_url} alt="" className="mx-auto mb-2 size-12 rounded-lg object-cover grayscale" />}
                        <p className="text-sm font-semibold">{club.name}</p>
                        {club.address && <p>{club.address}</p>}
                        {club.phone && <p className="num">{club.phone}</p>}
                        {club.vat_number && <p>الرقم الضريبي: <span className="num">{club.vat_number}</span></p>}
                    </header>
                    <p className="my-3 text-center text-sm font-semibold">{refund ? 'إيصال رد مبلغ' : 'إيصال استلام'}</p>
                    <dl className="space-y-1">
                        <Line label="رقم الإيصال" value={<span className="num">{payment.receipt_number}</span>} />
                        <Line label="التاريخ" value={<span className="num">{payment.paid_at}</span>} />
                        {payment.invoice && <Line label="الفاتورة" value={<span className="num">{payment.invoice.number}</span>} />}
                        {payment.invoice && <Line label="العميل" value={payment.invoice.customer_name} />}
                        <Line label="الطريقة" value={paymentMethod[payment.method] ?? payment.method} />
                        {payment.reference && <Line label="المرجع" value={<span className="num">{payment.reference}</span>} />}
                    </dl>
                    <div className="my-3 border-y border-dashed border-ink/30 py-3 text-center">
                        <p>{refund ? 'المبلغ المردود' : 'المبلغ المستلم'}</p>
                        <p className="text-lg font-bold num">{money(payment.amount, currency)}</p>
                    </div>
                    {payment.invoice && (
                        <dl className="space-y-1">
                            <Line label="إجمالي الفاتورة" value={<span className="num">{money(payment.invoice.total, currency)}</span>} />
                            <Line label="المدفوع" value={<span className="num">{money(payment.invoice.paid_total, currency)}</span>} />
                            <Line label="المتبقي" value={<span className="num">{money(payment.invoice.balance, currency)}</span>} />
                        </dl>
                    )}
                    {payment.receiver && <p className="mt-3 text-center">استلمه: {payment.receiver}</p>}
                    <p className="mt-3 text-center">شكرًا لك</p>
                </article>
            </div>
        </>
    );
}

function Line({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-ink/70">{label}</dt>
            <dd className="text-end">{value}</dd>
        </div>
    );
}
