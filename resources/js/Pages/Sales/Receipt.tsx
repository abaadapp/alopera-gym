import { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { money, number } from '@/lib/format';
import type { SaleRow } from '@/Pages/Inventory/Sales';

interface Props {
    sale: Omit<SaleRow, 'items'> & { subtotal: string; discount: string; tax: string; invoice: string | null; phone: string | null; items: { name: string; quantity: string; unit_price: string; line_total: string; addons: { name: string; quantity: string; unit_price: string; line_total: string }[] }[] };
    club: { name: string; phone: string | null; logo: string | null; currency: string };
    paper: number;
}

/** A sale's receipt, sized for the device's receipt printer (58 or 80 mm) and readable on A4; opens the print dialog. Printing it again creates nothing. */
export default function SaleReceipt({ sale, club, paper }: Props) {
    const m = (v: string) => money(v, club.currency);

    useEffect(() => {
        const id = setTimeout(() => window.print(), 400);
        return () => clearTimeout(id);
    }, []);

    return (
        <>
            <Head title={`إيصال ${sale.number}`} />
            <style>{`@page { size: ${paper}mm auto; margin: ${paper === 58 ? 2 : 4}mm; }`}</style>
            <div className="min-h-screen bg-stone-100 py-8 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex max-w-sm justify-end gap-2 px-4">
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm text-white"><Printer className="size-4" /> طباعة</button>
                    <button onClick={() => window.close()} className="rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm">إغلاق</button>
                </div>
                <article className={`mx-auto max-w-sm bg-white p-6 leading-relaxed shadow-sm print:max-w-none print:p-0 print:shadow-none ${paper === 58 ? 'text-[11px]' : 'text-[12px]'}`}>
                    <header className="border-b border-dashed border-stone-300 pb-3 text-center">
                        {club.logo && <img src={club.logo} alt="" className="mx-auto mb-2 size-12 rounded-lg object-cover" />}
                        <h1 className="text-sm font-semibold">{club.name}</h1>
                        {club.phone && <p className="text-stone-500" dir="ltr">{club.phone}</p>}
                        <p className="mt-2 font-semibold">إيصال بيع</p>
                    </header>
                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 border-b border-dashed border-stone-300 py-3">
                        <dt className="text-stone-500">الرقم</dt><dd className="text-end num">{sale.number}</dd>
                        <dt className="text-stone-500">التاريخ</dt><dd className="text-end num">{sale.at}</dd>
                        {sale.invoice && <><dt className="text-stone-500">الفاتورة</dt><dd className="text-end num">{sale.invoice}</dd></>}
                        <dt className="text-stone-500">العميل</dt><dd className="text-end">{sale.buyer}</dd>
                        <dt className="text-stone-500">المصدر</dt><dd className="text-end">{sale.source_label}</dd>
                        {sale.cashier && <><dt className="text-stone-500">الموظف</dt><dd className="text-end">{sale.cashier}</dd></>}
                    </dl>
                    <table className="w-full border-b border-dashed border-stone-300">
                        <thead><tr className="text-stone-500"><th className="py-2 text-start font-medium">الصنف</th><th className="py-2 text-center font-medium">الكمية</th><th className="py-2 text-end font-medium">المبلغ</th></tr></thead>
                        <tbody>
                            {sale.items.map((i, idx) => (
                                <tr key={idx} className="align-top">
                                    <td className="py-1">
                                        {i.name}
                                        {i.addons.map((a, j) => <span key={j} className="block ps-3 text-[11px] text-stone-500">+ {a.name}{Number(a.quantity) > 1 && <span className="num"> ×{number(a.quantity)}</span>} <span className="num">{m(a.unit_price)}</span></span>)}
                                    </td>
                                    <td className="py-1 text-center num">{number(i.quantity)}</td>
                                    <td className="py-1 text-end num">{m(i.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <dl className="space-y-1 py-3">
                        <div className="flex justify-between"><dt className="text-stone-500">المجموع</dt><dd className="num">{m(sale.subtotal)}</dd></div>
                        {Number(sale.discount) > 0 && <div className="flex justify-between"><dt className="text-stone-500">الخصم</dt><dd className="num">- {m(sale.discount)}</dd></div>}
                        {Number(sale.tax) > 0 && <div className="flex justify-between"><dt className="text-stone-500">الضريبة</dt><dd className="num">{m(sale.tax)}</dd></div>}
                        <div className="flex justify-between border-t border-stone-300 pt-2 text-sm font-semibold"><dt>الإجمالي</dt><dd className="num">{m(sale.total)}</dd></div>
                        <div className="flex justify-between"><dt className="text-stone-500">الدفع</dt><dd>{sale.method_label}{!sale.paid && ' — غير مدفوع'}</dd></div>
                        {sale.status !== 'completed' && <div className="flex justify-between"><dt className="text-stone-500">الحالة</dt><dd>{sale.status_label}</dd></div>}
                    </dl>
                    <p className="border-t border-dashed border-stone-300 pt-3 text-center text-stone-500">شكرًا لزيارتكم</p>
                </article>
            </div>
        </>
    );
}
