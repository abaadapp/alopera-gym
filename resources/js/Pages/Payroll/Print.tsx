import { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useCurrency } from '@/hooks/useShared';
import { date, money, number } from '@/lib/format';
import { runStatus, type Item, type RunSummary } from './Runs';

interface Props {
    mode: 'sheet' | 'payslip';
    run: RunSummary & { approved_at: string | null; approver: string | null };
    items: (Item & { iban: string | null; bank_name: string | null })[];
    club: { name: string; logo_url: string | null; phone: string | null; email: string | null };
}

/** The run sheet (all lines) or one payslip, laid out for A4 and printed on open. */
export default function PayrollPrint({ mode, run, items, club }: Props) {
    const currency = useCurrency();
    const m = (v: string) => money(v, currency);
    const sum = (key: keyof Item) => items.reduce((t, i) => t + Math.round(Number(i[key] ?? 0) * 1000), 0) / 1000;

    useEffect(() => {
        const id = setTimeout(() => window.print(), 400);
        return () => clearTimeout(id);
    }, []);

    const header = (title: string) => (
        <header className="flex items-start justify-between gap-6 border-b-2 border-brand pb-5">
            <div className="flex items-center gap-4">
                {club.logo_url && <img src={club.logo_url} alt="" className="size-14 rounded-xl object-cover" />}
                <div>
                    <h1 className="text-lg font-semibold">{club.name}</h1>
                    <p className="text-xs text-stone-500" dir="ltr">{[club.phone, club.email].filter(Boolean).join(' · ')}</p>
                </div>
            </div>
            <div className="text-end">
                <p className="text-base font-semibold">{title}</p>
                <p className="text-xs text-stone-500 num">{run.number} · {runStatus[run.status][0]}</p>
            </div>
        </header>
    );

    return (
        <>
            <Head title={mode === 'payslip' ? `قسيمة راتب — ${items[0]?.name}` : `كشف رواتب ${run.label}`} />
            <style>{`@page { size: A4 ${mode === 'sheet' ? 'landscape' : 'portrait'}; margin: 12mm; }`}</style>
            <div className="min-h-screen bg-stone-100 py-8 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex max-w-5xl justify-end gap-2 px-4">
                    <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm text-white"><Printer className="size-4" /> طباعة / حفظ PDF</button>
                    <button onClick={() => window.close()} className="rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm">إغلاق</button>
                </div>

                {mode === 'sheet' ? (
                    <article className="mx-auto max-w-5xl bg-white p-8 text-[12px] shadow-sm print:p-0 print:shadow-none">
                        {header(`كشف رواتب ${run.label}`)}
                        <table className="mt-5 w-full border-collapse">
                            <thead>
                                <tr className="border-b border-stone-300 text-stone-500">
                                    {['#', 'الموظفة', 'المسمى', 'الحضور', 'الأساسي', 'البدلات', 'الإضافي', 'الخصومات', 'الصافي', 'الصرف', 'التوقيع'].map((h) => <th key={h} className="py-2 text-start font-medium">{h}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((i, n) => (
                                    <tr key={i.id} className="border-b border-stone-200">
                                        <td className="py-2 num">{n + 1}</td>
                                        <td className="py-2 font-medium">{i.name}</td>
                                        <td className="py-2">{i.job_title ?? ''}</td>
                                        <td className="py-2">{i.attendance_days !== null ? `${i.attendance_days} يوم` : ''}</td>
                                        <td className="py-2 num">{m(i.basic)}</td>
                                        <td className="py-2 num">{m(i.allowances)}</td>
                                        <td className="py-2 num">{m(i.overtime)}</td>
                                        <td className="py-2 num">{m(i.deductions)}</td>
                                        <td className="py-2 font-semibold num">{m(i.net)}</td>
                                        <td className="py-2">{i.paid_at ? date(i.paid_at) : '—'}</td>
                                        <td className="w-24 py-2" />
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td colSpan={4} className="py-2">الإجمالي ({items.length})</td>
                                    <td className="py-2 num">{m(String(sum('basic')))}</td>
                                    <td className="py-2 num">{m(String(sum('allowances')))}</td>
                                    <td className="py-2 num">{m(String(sum('overtime')))}</td>
                                    <td className="py-2 num">{m(String(sum('deductions')))}</td>
                                    <td className="py-2 num">{m(String(sum('net')))}</td>
                                    <td colSpan={2} />
                                </tr>
                            </tbody>
                        </table>
                        <footer className="mt-10 grid grid-cols-3 gap-8 text-center text-stone-500">
                            <div className="border-t border-stone-300 pt-2">إعداد</div>
                            <div className="border-t border-stone-300 pt-2">اعتماد{run.approver ? ` — ${run.approver}` : ''}{run.approved_at ? ` (${date(run.approved_at)})` : ''}</div>
                            <div className="border-t border-stone-300 pt-2">المحاسبة</div>
                        </footer>
                    </article>
                ) : (
                    items.map((i) => (
                        <article key={i.id} className="mx-auto max-w-[210mm] bg-white p-10 text-sm shadow-sm print:p-0 print:shadow-none">
                            {header(`قسيمة راتب — ${run.label}`)}
                            <section className="mt-6 grid grid-cols-2 gap-4">
                                <div><p className="text-xs text-stone-500">الموظفة</p><p className="font-semibold">{i.name}</p>{i.job_title && <p className="text-xs">{i.job_title}</p>}</div>
                                <div><p className="text-xs text-stone-500">الحضور</p><p>{i.attendance_days !== null ? `${i.attendance_days} يومًا · ${number(i.attendance_hours, 1)} ساعة` : '—'}</p></div>
                                <div><p className="text-xs text-stone-500">طريقة الصرف</p><p>{i.payment_method === 'cash' ? 'نقدًا' : `تحويل بنكي ${i.bank_name ?? ''}`} <span dir="ltr">{i.iban ?? ''}</span></p></div>
                                <div><p className="text-xs text-stone-500">الصرف</p><p>{i.paid_at ? `${date(i.payout?.paid_on ?? i.paid_at)} · ${i.payout?.number ?? ''}` : 'لم يُصرف بعد'}</p></div>
                            </section>
                            <table className="mt-6 w-full border-collapse">
                                <tbody>
                                    <tr className="border-b border-stone-200"><td className="py-2">الراتب الأساسي</td><td className="py-2 text-end num">{m(i.basic)}</td></tr>
                                    {(i.allowance_lines.length ? i.allowance_lines : Number(i.allowances) ? [{ name: 'البدلات', amount: i.allowances }] : []).map((a, n) => (
                                        <tr key={n} className="border-b border-stone-200"><td className="py-2">{a.name}</td><td className="py-2 text-end num">{m(a.amount)}</td></tr>
                                    ))}
                                    {Number(i.overtime) > 0 && <tr className="border-b border-stone-200"><td className="py-2">العمل الإضافي ({number(i.overtime_hours, 2)} ساعة)</td><td className="py-2 text-end num">{m(i.overtime)}</td></tr>}
                                    <tr className="border-b border-stone-300 font-medium"><td className="py-2">إجمالي المستحقات</td><td className="py-2 text-end num">{m(i.earnings)}</td></tr>
                                    {Number(i.deductions) > 0 && <tr className="border-b border-stone-200 text-amber-800"><td className="py-2">الخصومات{i.deduction_notes ? ` — ${i.deduction_notes}` : ''}</td><td className="py-2 text-end num">− {m(i.deductions)}</td></tr>}
                                    <tr className="text-base font-bold"><td className="py-3">صافي الراتب</td><td className="py-3 text-end num">{m(i.net)}</td></tr>
                                </tbody>
                            </table>
                            <footer className="mt-12 grid grid-cols-2 gap-10 text-center text-stone-500">
                                <div className="border-t border-stone-300 pt-2">توقيع الموظفة</div>
                                <div className="border-t border-stone-300 pt-2">الإدارة</div>
                            </footer>
                        </article>
                    ))
                )}
            </div>
        </>
    );
}
