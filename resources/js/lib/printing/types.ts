/**
 * The printing layer's types, and what crosses the club app's bridge as mobile/PROTOCOL.md defines it.
 *
 * The bridge takes a structured request only: a receipt's number, the address of its PDF and the printer's settings.
 * No printer bytes and no socket for a web page.
 */

/** What the screen shows of a print, the stages the app reports included. */
export type PrintStage =
    | 'idle'
    | 'connecting'
    | 'downloading_receipt'
    | 'rendering'
    | 'sending'
    | 'success'
    | 'failed'
    // On the web: a receipt made ready for the share sheet, waiting for the tap that opens it (share-print).
    | 'ready';

/** Failure codes: PROTOCOL §8, then the ones the page adds. */
export type PrintErrorCode =
    | 'UNTRUSTED_ORIGIN'
    | 'INVALID_JOB'
    | 'RECEIPT_URL_REJECTED'
    | 'UNSUPPORTED_CONNECTION'
    | 'INVALID_PRINTER_ADDRESS'
    | 'INVALID_PORT'
    | 'SESSION_EXPIRED'
    | 'RECEIPT_DOWNLOAD_FAILED'
    | 'RECEIPT_RENDER_FAILED'
    | 'PRINTER_UNREACHABLE'
    | 'PORT_REFUSED'
    | 'CONNECT_TIMEOUT'
    | 'SEND_FAILED'
    | 'QUEUE_FULL'
    | 'INTERNAL'
    | 'BRIDGE_TIMEOUT'
    | 'BRIDGE_UNAVAILABLE'
    // The page: no app on this device, or the browser blocked the window.
    | 'NATIVE_REQUIRED'
    | 'POPUP_BLOCKED'
    // Printing inside the page (page-print): a browser that cannot print a PDF from a frame, or a dialog that never opened.
    | 'IN_PAGE_UNSUPPORTED'
    | 'PRINT_DIALOG_FAILED'
    // The share sheet on iPhone and iPad: closed by the cashier, the tap expired, or the file refused.
    | 'SHARE_CANCELLED'
    | 'SHARE_BLOCKED'
    | 'SHARE_FAILED';

/** A network printer as sent to the app — which checks all of it again. */
export interface PrinterTarget {
    id: number | null;
    name: string;
    model: string | null;
    connection: 'network';
    address: string;
    port: number | null;
    paperWidth: number | null;
}

export interface ReceiptJob {
    invoice: string;
    /** An absolute address on the page's own origin; the app refuses any other. */
    receiptUrl: string;
    printer: PrinterTarget;
}

export interface OpenJob {
    invoice: string;
    receiptUrl: string;
}

export interface TestJob {
    printer: PrinterTarget;
    deviceName?: string | null;
    printerName?: string;
}

export type BridgeResult =
    | {
          ok: true;
          jobId?: string;
          printed?: boolean;
          connected?: boolean;
          effectivePort?: number;
          message?: string;
      }
    | {
          ok: false;
          code: PrintErrorCode;
          message: string;
          jobId?: string;
          connected?: boolean;
          /** Part of the receipt may have reached the printer: printed again only when the cashier decides. */
          mayHavePrinted?: boolean;
          effectivePort?: number;
      };

/**
 * The way a print went:
 *  - `native` straight from the app to the network printer.
 *  - `system` the device's own print dialog (the app, and a printer that is not on the network).
 *  - `browser` the paper's print page in a new tab.
 *  - `page` the browser's print dialog on the PDF in a hidden frame, the cashier staying on their screen (page-print).
 *    Opening the dialog is not printing: «printed» is never said of it.
 *  - `share` the share sheet with the PDF on iPhone and iPad (share-print), «Print» usually in it. Finishing it is not
 *    printing either.
 */
export type PrintVia = 'native' | 'system' | 'browser' | 'page' | 'share';

export type PrintOutcome = BridgeResult & { via: PrintVia };

/** window.AloperaPrinter — injected by the app alone (mobile/shared/js/alopera-printer-shim.js). */
export interface AloperaPrinterNative {
    version: number;
    platform: string;
    isAvailable(): boolean;
    printReceipt(job: ReceiptJob, onProgress?: (stage: string) => void): Promise<unknown>;
    testPrinter(job: TestJob, onProgress?: (stage: string) => void): Promise<unknown>;
    openReceipt(job: OpenJob): Promise<unknown>;
}

/** A printer as the server describes it (PosPeripheral::forPrinting); it may come partial from an older page. */
export interface PrinterLike {
    id?: number | null;
    name?: string | null;
    model?: string | null;
    connection?: string | null;
    address?: string | null;
    port?: number | null;
    paper?: string | null;
    paper_width?: number | null;
    auto_print?: boolean | null;
}

declare global {
    interface Window {
        AloperaPrinter?: AloperaPrinterNative;
    }
}
