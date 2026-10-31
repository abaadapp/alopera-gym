import { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';

/**
 * A test receipt at the printer's paper width, through the browser's print dialog. Whether it reached paper is for
 * the person at the printer to see: a browser cannot report it.
 */
export default function PosTestPrint({ printer, device, club, at }: { printer: { name: string; brand: string | null; model: string | null; connection: string; paper_width: number | null }; device: { name: string; branch: string | null }; club: { name: string; phone: string | null; logo: string | null }; at: string }) {
    const width = printer.paper_width ?? 80;
    useEffect(() => {
        const id = setTimeout(() => window.print(), 400);
        return () => clearTimeout(id);
    }, []);

    return (
        <>
            <Head title="طباعة تجريبية" />
            <style>{`@page { size: ${width}mm auto; margin: 3mm; }`}</style>
            <div className="min-h-screen bg-stone-100 py-8 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex max-w-sm justify-end gap-2 px-4">
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-[10px] bg-[#111] px-4 py-2 text-sm text-white"><Printer className="size-4" /> طباعة</button>
                    <button onClick={() => window.close()} className="rounded-[10px] border border-stone-300 bg-white px-4 py-2 text-sm">إغلاق</button>
                </div>
                <article className="mx-auto bg-white p-4 text-[12px] leading-relaxed shadow-sm print:p-0 print:shadow-none" style={{ width: `${width}mm` }}>
                    <header className="border-b border-dashed border-stone-400 pb-2 text-center">
                        {club.logo && <img src={club.logo} alt="" className="mx-auto mb-1 size-10 rounded-lg object-cover" />}
                        <p className="font-bold">{club.name}</p>
                        <p className="mt-1 font-semibold">صفحة اختبار الطابعة</p>
                    </header>
                    <dl className="grid grid-cols-2 gap-x-2 gap-y-0.5 border-b border-dashed border-stone-400 py-2">
                        <dt className="text-stone-500">الطابعة</dt><dd className="text-end">{printer.name}</dd>
                        {(printer.brand || printer.model) && <><dt className="text-stone-500">الطراز</dt><dd className="text-end">{[printer.brand, printer.model].filter(Boolean).join(' ')}</dd></>}
                        <dt className="text-stone-500">عرض الورق</dt><dd className="text-end num">{width} مم</dd>
                        <dt className="text-stone-500">الجهاز</dt><dd className="text-end">{device.name}</dd>
                        <dt className="text-stone-500">الفرع</dt><dd className="text-end">{device.branch}</dd>
                        <dt className="text-stone-500">الوقت</dt><dd className="text-end num">{at}</dd>
                    </dl>
                    <p className="py-2 text-center">١٢٣٤٥٦٧٨٩٠ — 1234567890</p>
                    <p className="border-t border-dashed border-stone-400 pt-2 text-center text-stone-500">إذا ظهرت هذه الصفحة كاملة على الورق فالطابعة تعمل.</p>
                </article>
            </div>
        </>
    );
}
