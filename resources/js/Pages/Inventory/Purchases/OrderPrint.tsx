import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { money, number } from '@/lib/format';

interface Props {
    order: {
        number: string; status_label: string; subtotal: string; discount: string; shipping: string; tax_rate: string; tax: string; total: string; notes: string | null; supplier_reference: string | null; payment_terms_days: number | null;
        supplier: { name: string; phone: string | null; email: string | null; tax_number: string | null; address: string | null } | null; branch: string | null; order_date: string; expected_on: string | null;
        items: { product: string | null; sku: string | null; quantity: string; unit: string | null; unit_factor: string; unit_cost: string; line_total: string }[];
    };
    club: { name: string; phone: string | null; logo: string | null; currency: string; tax_number: string | null };
}

/** A purchase order on A4, to preview and print or save as PDF. Prints the order only; writes nothing. */
export default function OrderPrint({ order, club }: Props) {
    const m = (v: string) => money(v, club.currency);
    const q = (v: string) => number(v, Number(v) % 1 ? 3 : 0);

    return (
        <>
            <Head title={`أمر الشراء ${order.number}`} />
            <style>{'@page { size: A4; margin: 14mm; }'}</style>
            <div className="min-h-screen bg-stone-100 py-8 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex max-w-3xl justify-end gap-2 px-4">
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-[10px] bg-[#111] px-4 py-2 text-sm text-white"><Printer className="size-4" /> طباعة</button>
                    <button onClick={() => window.close()} className="rounded-[10px] border border-stone-300 bg-white px-4 py-2 text-sm">إغلاق</button>
                </div>
                <article className="mx-auto max-w-3xl bg-white p-10 text-[13px] leading-relaxed shadow-sm print:max-w-none print:p-0 print:shadow-none">
                    <header className="flex items-start justify-between border-b border-stone-200 pb-5">
                        <div>
                            {club.logo && <img src={club.logo} alt="" className="mb-2 size-12 rounded-lg object-cover" />}
                            <h1 className="text-base font-bold">{club.name}</h1>
                            {club.phone && <p className="text-stone-500" dir="ltr">{club.phone}</p>}
                            {club.tax_number && <p className="text-stone-500">الرقم الضريبي: <span className="num">{club.tax_number}</span></p>}
                        </div>
                        <div className="text-end">
                            <p className="text-lg font-bold">أمر شراء</p>
                            <p className="num">{order.number}</p>
                            <p className="text-stone-500">{order.status_label}</p>
                        </div>
                    </header>
                    <section className="grid grid-cols-2 gap-6 border-b border-stone-200 py-5">
                        <div>
                            <p className="mb-1 text-stone-500">المورد</p>
                            <p className="font-semibold">{order.supplier?.name}</p>
                            {order.supplier?.phone && <p dir="ltr" className="text-end">{order.supplier.phone}</p>}
                            {order.supplier?.address && <p>{order.supplier.address}</p>}
                            {order.supplier?.tax_number && <p>الرقم الضريبي: <span className="num">{order.supplier.tax_number}</span></p>}
                        </div>
                        <dl className="grid grid-cols-2 gap-x-3 gap-y-1">
                            <dt className="text-stone-500">تاريخ الطلب</dt><dd className="num">{order.order_date}</dd>
                            {order.expected_on && <><dt className="text-stone-500">التسليم المتوقع</dt><dd className="num">{order.expected_on}</dd></>}
                            <dt className="text-stone-500">يُسلَّم في</dt><dd>{order.branch}</dd>
                            {order.supplier_reference && <><dt className="text-stone-500">مرجع العرض</dt><dd className="num">{order.supplier_reference}</dd></>}
                            {order.payment_terms_days != null && <><dt className="text-stone-500">مدة السداد</dt><dd><span className="num">{order.payment_terms_days}</span> يوم</dd></>}
                        </dl>
                    </section>
                    <table className="mt-5 w-full">
                        <thead><tr className="border-b border-stone-300 text-stone-500"><th className="py-2 text-start font-medium">#</th><th className="py-2 text-start font-medium">الصنف</th><th className="py-2 text-start font-medium">الوحدة</th><th className="py-2 text-center font-medium">الكمية</th><th className="py-2 text-end font-medium">السعر</th><th className="py-2 text-end font-medium">الإجمالي</th></tr></thead>
                        <tbody>
                            {order.items.map((i, idx) => (
                                <tr key={idx} className="border-b border-stone-100">
                                    <td className="py-2 num">{idx + 1}</td>
                                    <td className="py-2">{i.product}{i.sku && <span className="text-stone-400"> · {i.sku}</span>}</td>
                                    <td className="py-2">{i.unit ?? 'وحدة'}{Number(i.unit_factor) !== 1 && <span className="text-stone-400"> ({q(i.unit_factor)})</span>}</td>
                                    <td className="py-2 text-center num">{q(i.quantity)}</td>
                                    <td className="py-2 text-end num">{m(i.unit_cost)}</td>
                                    <td className="py-2 text-end num">{m(i.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <dl className="ms-auto mt-4 w-64 space-y-1">
                        <div className="flex justify-between"><dt className="text-stone-500">المجموع</dt><dd className="num">{m(order.subtotal)}</dd></div>
                        {Number(order.discount) > 0 && <div className="flex justify-between"><dt className="text-stone-500">الخصم</dt><dd className="num">- {m(order.discount)}</dd></div>}
                        {Number(order.shipping) > 0 && <div className="flex justify-between"><dt className="text-stone-500">الشحن</dt><dd className="num">{m(order.shipping)}</dd></div>}
                        {Number(order.tax) > 0 && <div className="flex justify-between"><dt className="text-stone-500">الضريبة <span className="num">{Number(order.tax_rate)}٪</span></dt><dd className="num">{m(order.tax)}</dd></div>}
                        <div className="flex justify-between border-t border-stone-300 pt-2 text-sm font-bold"><dt>الإجمالي</dt><dd className="num">{m(order.total)}</dd></div>
                    </dl>
                    {order.notes && <p className="mt-6 whitespace-pre-line rounded-lg bg-stone-50 p-3">{order.notes}</p>}
                </article>
            </div>
        </>
    );
}
