import { useState, type ReactNode } from 'react';
import { Download, Maximize2, Printer } from 'lucide-react';
import { Button, Modal } from '@/Components/ui';
import { cn } from '@/lib/cn';
import PaperFrame from './PaperFrame';
import PrintButton from '@/Components/Printing/PrintButton';
import { useCan } from '@/hooks/useShared';

export type PaperData = { html: string; size: string; type: string; filename: string };

/**
 * «الورقة كما تُطبع» beside a document: the paper itself, with enlarge, download (PDF) and print. Print opens the
 * print page — the same paper with the browser's print dialog; download is the PDF drawn from the same HTML.
 */
export default function PaperPanel({ paper, printUrl, pdfUrl, heading = 'الورقة كما تُطبع', note, className }: {
    paper: PaperData;
    printUrl: string;
    pdfUrl: string;
    heading?: string;
    note?: ReactNode;
    className?: string;
}) {
    const mayPrint = useCan()('papers.print');
    const [open, setOpen] = useState(false);

    return (
        <>
            <div className={cn('card overflow-hidden', className)}>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    <h2 className="text-[15px] font-semibold text-ink">{heading}</h2>
                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="sm" icon={<Maximize2 className="size-3.5" />} onClick={() => setOpen(true)}><span className="max-sm:sr-only">تكبير</span></Button>
                        {/* Printing and the PDF need «طباعة المستندات»; reading the paper here does not. */}
                        {mayPrint && <Button variant="outline" size="sm" href={pdfUrl + (pdfUrl.includes('?') ? '&' : '?') + 'download=1'} external icon={<Download className="size-3.5" />}><span className="max-sm:sr-only">PDF</span></Button>}
                        {mayPrint && <PrintButton variant="outline" size="sm" url={printUrl} title={paper.filename.replace(/\.pdf$/, '')} icon={<Printer className="size-3.5" />}><span className="max-sm:sr-only">طباعة</span></PrintButton>}
                    </div>
                </div>
                {note && <p className="border-b border-line bg-[#fafafa] px-4 py-2 text-[12px] leading-relaxed text-muted">{note}</p>}
                <PaperFrame html={paper.html} paper={paper.size} height="auto" className="max-h-[min(78svh,1000px)] rounded-none border-0" />
            </div>
            {/* The paper at full size, in the system's dialog (Escape, focus and the page behind held as everywhere). */}
            <Modal open={open} onClose={() => setOpen(false)} size="full" title={heading} footer={mayPrint ? <PrintButton variant="outline" url={printUrl} title={paper.filename.replace(/\.pdf$/, '')} icon={<Printer className="size-3.5" />}>طباعة</PrintButton> : undefined}>
                <PaperFrame html={paper.html} paper={paper.size} height="100%" className="h-full min-h-[60svh] rounded-[12px]" />
            </Modal>
        </>
    );
}

/** The paper column beside a document's details: sticky on wide screens. */
export function PaperAside({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <aside className={cn('min-w-0', className)}>
            <div className="xl:sticky xl:top-6">{children}</div>
        </aside>
    );
}

/** A document's facts as label/value cells (status, party, dates, references). */
export function DocumentMeta({ cells, className }: { cells: ({ label: string; value: ReactNode; ltr?: boolean; wide?: boolean } | false | null | undefined)[]; className?: string }) {
    const shown = cells.filter(Boolean) as { label: string; value: ReactNode; ltr?: boolean; wide?: boolean }[];
    if (shown.length === 0) return null;

    return (
        <dl className={cn('card grid grid-cols-2 gap-x-4 gap-y-4 p-4 sm:grid-cols-3 sm:p-5 lg:grid-cols-4', className)}>
            {shown.map((c, i) => (
                <div key={i} className={cn('min-w-0', c.wide && 'col-span-2 sm:col-span-3 lg:col-span-4')}>
                    <dt className="text-[11px] tracking-[0.06em] text-[#9ca3af]">{c.label}</dt>
                    <dd dir={c.ltr ? 'ltr' : undefined} className={cn('mt-1 text-[13px] leading-relaxed text-ink', c.ltr && 'text-start', c.wide ? 'whitespace-pre-line' : 'truncate')}>{c.value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** Text with every date or dashed number kept left-to-right («2027-01-31» after an Arabic word, not «31-01-2027»). */
export function LtrDates({ text }: { text: string }) {
    const parts = text.split(/(\d+(?:[-/:]\d+)+)/);

    return <>{parts.map((part, i) => (i % 2 === 1 ? <bdo key={i} dir="ltr">{part}</bdo> : part))}</>;
}
