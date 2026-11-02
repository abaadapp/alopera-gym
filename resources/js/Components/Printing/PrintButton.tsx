import type { ComponentProps, MouseEvent, ReactNode } from 'react';
import { Printer } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui';
import { failureMessage, insideApp, printDocument } from '@/lib/printing/printer-service';

/**
 * A document's «Print» (an invoice, a payslip, a purchase order, a receipt on a sheet): its print page in a new tab in
 * a browser, as every print button did, and the system's print dialog on its PDF inside the club's app, where windows
 * do not open (AirPrint, Mopria and the printers installed on the device are there). The address is the document's
 * print door; the PDF is the same door with ?format=pdf.
 */
export default function PrintButton({ url, title, children, icon = <Printer />, ...rest }: Omit<ComponentProps<typeof Button>, 'href' | 'external' | 'onClick'> & { url: string; title: string }) {
    const click = (e: MouseEvent) => {
        e.preventDefault();
        void printDocument({ title, url }).then((outcome) => {
            if (!outcome.ok && outcome.code !== 'SHARE_CANCELLED') toast.error(failureMessage(outcome.code));
        });
    };

    return (
        <Button {...rest} icon={icon} onClick={click}>
            {children}
        </Button>
    );
}

/** The same for a link in a line of text or a table cell. */
export function PrintLink({ url, title, className, children }: { url: string; title: string; className?: string; children: ReactNode }) {
    return (
        <a
            href={url}
            target="_blank"
            rel="noreferrer"
            className={className}
            onClick={(e) => {
                if (!insideApp()) return;
                e.preventDefault();
                void printDocument({ title, url }).then((outcome) => {
                    if (!outcome.ok) toast.error(failureMessage(outcome.code));
                });
            }}
        >
            {children}
        </a>
    );
}
