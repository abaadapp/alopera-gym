import { Head, Link } from '@inertiajs/react';
import { CreditCard, Download, Printer } from 'lucide-react';
import PaperFrame from '@/Components/Papers/PaperFrame';
import type { PaperData } from '@/Components/Papers/PaperPanel';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { useCurrency, useShared } from '@/hooks/useShared';
import { money } from '@/lib/format';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    invoice: { number: string; balance: string; status: string };
    paper: PaperData;
    pay_url: string | null;
    print_url: string;
    pdf_url: string;
}

/**
 * The invoice link sent to the member: the invoice paper itself — the same paper the club prints and downloads as a
 * PDF — with print, download and (when something is owed and online payment is on) pay.
 */
export default function Invoice({ seo, invoice, paper, pay_url, print_url, pdf_url }: Props) {
    const currency = useCurrency();
    const { tenant } = useShared();

    return (
        <>
            <Head title={seo.title} />
            <div className="min-h-screen bg-canvas px-3 py-6 sm:px-4 sm:py-8">
                <div className="mx-auto mb-4 flex max-w-4xl flex-wrap items-center justify-between gap-2">
                    <Link href={route('site.home')} className="text-sm text-brand hover:underline">← {tenant?.name}</Link>
                    <div className="flex flex-wrap gap-2">
                        {pay_url && (
                            <a href={pay_url} className="inline-flex items-center gap-2 rounded-full bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                                <CreditCard className="size-4" /> ادفع المتبقي <span className="num">{money(invoice.balance, currency)}</span>
                            </a>
                        )}
                        <a href={pdf_url + '?download=1'} className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-sm hover:bg-brand-50">
                            <Download className="size-4" /> تنزيل PDF
                        </a>
                        <a href={print_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-sm hover:bg-brand-50">
                            <Printer className="size-4" /> طباعة
                        </a>
                    </div>
                </div>
                <div className="mx-auto max-w-4xl">
                    <PaperFrame html={paper.html} paper={paper.size} height="auto" title={`فاتورة ${invoice.number}`} className="border-0 bg-transparent p-0" />
                </div>
            </div>
        </>
    );
}
