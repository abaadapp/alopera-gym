import { callBridge, failure, nativeBridge } from './bridge';
import { inPagePrintSupported, printPdfInPage } from './page-print';
import { preparedReceipt, sharePrintSupported, shareReceipt } from './share-print';
import type { PrintErrorCode, PrinterLike, PrinterTarget, PrintOutcome, PrintStage } from './types';

/**
 * The panel's printing layer — the one door every «print» goes through. No screen knows where a printer is or how to
 * reach it; this file picks the way, by the device and the printer:
 *
 *   - The club's app + a thermal printer on the network: the app prints straight to it over TCP. It fetches the
 *     receipt's own PDF with the page's session, turns it into dots and sends ESC/POS raster commands, so the
 *     printer's Arabic code page never matters (mobile/PROTOCOL.md).
 *   - The club's app + any other printer (USB, Bluetooth, AirPrint, an office printer): the system's print dialog on
 *     the PDF (Android's PrintManager, iOS's print controller). Windows do not open inside the app.
 *   - A browser: the paper's print page in a new tab, as before — except where a screen asks to print inside the page
 *     (inPage, the point of sale), the cashier staying on their screen:
 *       - Chrome and Edge on a computer: the print dialog on the PDF in a hidden frame (page-print).
 *       - iPhone and iPad: the share sheet with the PDF, «Print» in it (share-print).
 *       - Any other browser: the print page in a new tab, the one way that prints everywhere.
 *
 * The paper is one in all of them: the print door of the document (its HTML for the browser, the same Paper as a PDF
 * with ?format=pdf). No total or line is computed or laid out again in any app.
 */

/** The port the app asks when none is set: the documented default for raw printing, not a rule for every printer. */
export const DEFAULT_RAW_PORT = 9100;

/** A print door's address as its PDF (the same paper): ?format=pdf, without the print-dialog switch. */
export function pdfUrl(url: string): string {
    const u = new URL(url, typeof window === 'undefined' ? 'http://localhost' : window.location.href);
    u.searchParams.delete('print');
    u.searchParams.set('format', 'pdf');

    return u.href;
}

/** An absolute address on the page's origin — the app refuses any other. */
export function absoluteUrl(url: string): string {
    return new URL(url, window.location.href).href;
}

function thermal(printer?: PrinterLike | null): boolean {
    return printer?.paper_width === 58 || printer?.paper_width === 80;
}

/** A thermal printer on the network with an address: what the app can print to directly. */
export function isNetworkPrinter(printer?: PrinterLike | null): boolean {
    return printer?.connection === 'network' && !!printer.address?.trim() && thermal(printer);
}

/** Is this device running inside the club's app? */
export function insideApp(): boolean {
    return nativeBridge() !== null;
}

/** A direct print possible on this printer from this device. */
export function nativePrintingAvailable(printer?: PrinterLike | null): boolean {
    return insideApp() && isNetworkPrinter(printer);
}

/** The port that will really be asked — shown as it is, not assumed. */
export function effectivePort(printer?: PrinterLike | null): number {
    return printer?.port && printer.port > 0 ? printer.port : DEFAULT_RAW_PORT;
}

function target(printer: PrinterLike): PrinterTarget {
    return {
        id: typeof printer.id === 'number' ? printer.id : null,
        name: printer.name ?? '',
        model: printer.model ?? null,
        connection: 'network',
        address: (printer.address ?? '').trim(),
        port: printer.port && printer.port > 0 ? printer.port : null,
        paperWidth: printer.paper_width ?? null,
    };
}

const STAGES: readonly PrintStage[] = ['connecting', 'downloading_receipt', 'rendering', 'sending'];

function relay(onProgress?: (stage: PrintStage) => void) {
    return (stage: string) => {
        if (onProgress && STAGES.includes(stage as PrintStage)) onProgress(stage as PrintStage);
    };
}

export interface ReceiptRequest {
    /** The receipt's number, the file's name in a dialog or the share sheet. */
    title: string;
    /** The receipt's print door (its HTML page); the PDF is the same door with ?format=pdf. */
    url: string;
    printer: PrinterLike | null;
    onProgress?: (stage: PrintStage) => void;
    /** In a browser: print inside the page rather than in a new tab (the point of sale). No effect in the app. */
    inPage?: boolean;
}

/**
 * «Print» in one tap. In a browser the window opens before any wait: nothing is awaited before window.open, so it
 * stays in the cashier's gesture and no popup blocker swallows it.
 */
export async function printReceipt(request: ReceiptRequest): Promise<PrintOutcome> {
    const bridge = nativeBridge();

    if (bridge && isNetworkPrinter(request.printer)) {
        const receiptUrl = absoluteUrl(pdfUrl(request.url));
        const result = await callBridge(() =>
            bridge.printReceipt({ invoice: request.title, receiptUrl, printer: target(request.printer!) }, relay(request.onProgress)),
        );

        /*
         * A fault whose place is unknown (INTERNAL: the bridge threw, or answered off the contract) may come after bytes
         * reached the printer: it is told «may have printed», and never printed again by itself. Two receipts are worse
         * than one asked about.
         */
        if (!result.ok && result.code === 'INTERNAL') return { ...result, mayHavePrinted: true, via: 'native' };

        return { ...result, via: 'native' };
    }

    if (bridge) return openPdf(request);
    if (request.inPage) return printInPage(request);

    return openTab(request.url);
}

/** The print page in a new tab — the browser's own print dialog on the paper, the way that works in every browser. */
function openTab(url: string): Promise<PrintOutcome> {
    const tab = window.open(url, '_blank');

    return Promise.resolve(tab ? { ok: true, via: 'browser' } : { ...failure('POPUP_BLOCKED'), via: 'browser' });
}

/**
 * The way to print inside the page on this device, asked outside the app only:
 *   - `frame` the print dialog on a hidden frame (Chrome · Edge on a computer).
 *   - `share` the share sheet (iPhone and iPad with files).
 *   - `tab` neither: the print page in a new tab.
 */
export type PageMode = 'frame' | 'share' | 'tab';

export function pagePrintMode(): PageMode {
    if (inPagePrintSupported()) return 'frame';
    if (sharePrintSupported()) return 'share';

    return 'tab';
}

/**
 * Printing inside the page — and sharing opens in the same tap. Nothing is awaited before shareReceipt or
 * window.open: the tap reaches them synchronously. A file not ready yet gives SHARE_FAILED here, not a download: it is
 * made ready before the tap (usePrint).
 */
function printInPage(request: ReceiptRequest): Promise<PrintOutcome> {
    switch (pagePrintMode()) {
        case 'frame':
            return printPdfInPage(pdfUrl(request.url));
        case 'share': {
            const file = preparedReceipt(pdfUrl(request.url));

            return file ? shareReceipt(file, request.title) : Promise.resolve({ ...failure('SHARE_FAILED'), via: 'share' });
        }
        default:
            return openTab(request.url);
    }
}

/**
 * «Open PDF», and any sheet paper in the app — the same file, in the system's print dialog in the app (where AirPrint,
 * Mopria and installed printers are), and in a new tab in a browser.
 */
export async function openPdf(request: Pick<ReceiptRequest, 'title' | 'url'>): Promise<PrintOutcome> {
    const bridge = nativeBridge();

    if (bridge) {
        const receiptUrl = absoluteUrl(pdfUrl(request.url));

        return { ...(await callBridge(() => bridge.openReceipt({ invoice: request.title, receiptUrl }))), via: 'system' };
    }

    const tab = window.open(pdfUrl(request.url), '_blank');

    return tab ? { ok: true, via: 'browser' } : { ...failure('POPUP_BLOCKED'), via: 'browser' };
}

/**
 * A document's «Print» (an invoice, a payslip, a purchase order, a report): the system's print dialog on its PDF in the
 * app, its print page in a new tab in a browser. Called in the click itself.
 */
export function printDocument(request: Pick<ReceiptRequest, 'title' | 'url'>): Promise<PrintOutcome> {
    return insideApp() ? openPdf(request) : openTab(request.url);
}

/* ═══════════════════════════ Automatic printing ═══════════════════════════ */

/**
 * An automatic print in two steps — prepared in the tap, carried out after the answer. Browsers open a window only
 * from a live gesture, so the blank window opens in prepareAutoPrint before the server is waited for, and is pointed
 * in commitAutoPrint when the receipt is known. In the app, and in a browser that prints in the page, no window: the
 * receipt goes after the answer. The share sheet cannot open by itself, so on iPhone and iPad nothing is automatic.
 */
export interface AutoPrintAttempt {
    mode: 'none' | 'native' | 'system' | 'page' | 'browser';
    printer: PrinterLike | null;
    /** The blank window in a browser — null when blocked, or when none is needed. */
    sheet: Window | null;
    settled: boolean;
}

/** Called in the click handler itself — before any await. */
export function prepareAutoPrint(printer: PrinterLike | null | undefined, enabled = !!printer?.auto_print): AutoPrintAttempt {
    const base = { printer: printer ?? null, sheet: null, settled: false };

    if (!printer || !enabled) return { ...base, mode: 'none' };
    if (nativePrintingAvailable(printer)) return { ...base, mode: 'native' };
    if (insideApp()) return { ...base, mode: 'system' };
    if (inPagePrintSupported()) return { ...base, mode: 'page' };
    if (sharePrintSupported()) return { ...base, mode: 'none' };

    // Without noopener, by necessity: the standard makes open() return null with it, leaving no handle to point.
    return { ...base, mode: 'browser', sheet: window.open('', '_blank') };
}

/**
 * The attempt runs once, after the server answers. No receipt (a sale with nothing to print) closes the blank window
 * and returns null. settled is set before any wait, so abandonAutoPrint in a finally never closes a window just
 * pointed.
 */
export async function commitAutoPrint(
    attempt: AutoPrintAttempt,
    receipt: { title: string; url: string } | null,
    onProgress?: (stage: PrintStage) => void,
): Promise<PrintOutcome | null> {
    if (attempt.settled) return null;
    attempt.settled = true;

    if (attempt.mode === 'none') return null;

    if (!receipt) {
        attempt.sheet?.close();

        return null;
    }

    if (attempt.mode === 'browser') {
        if (!attempt.sheet) return { ...failure('POPUP_BLOCKED'), via: 'browser' };
        // replace: the blank page does not enter the tab's history.
        attempt.sheet.location.replace(receipt.url);

        return { ok: true, via: 'browser' };
    }

    if (attempt.mode === 'page') return printPdfInPage(pdfUrl(receipt.url));

    return printReceipt({ title: receipt.title, url: receipt.url, printer: attempt.printer, onProgress });
}

/** What was prepared and not carried out (a failed sale) is closed — no blank tab left behind. */
export function abandonAutoPrint(attempt: AutoPrintAttempt | null | undefined): void {
    if (!attempt || attempt.settled) return;
    attempt.settled = true;
    attempt.sheet?.close();
}

/* ═══════════════════════════ Testing a printer ═══════════════════════════ */

/**
 * «Test the printer» — a real connection and a page printed, or a plain statement that it did not happen. A browser
 * cannot reach a printer on the local network, so it never pretends to a test it did not run: NATIVE_REQUIRED, and the
 * screen says the direct test needs the app (the print dialog's test page stays available).
 */
export async function testPrinter(printer: PrinterLike, deviceName: string | null | undefined, onProgress?: (stage: PrintStage) => void): Promise<PrintOutcome> {
    const bridge = nativeBridge();
    if (!bridge) return { ...failure('NATIVE_REQUIRED'), via: 'browser' };
    if (!isNetworkPrinter(printer)) return { ...failure('UNSUPPORTED_CONNECTION', { effectivePort: effectivePort(printer) }), via: 'native' };

    const result = await callBridge(() =>
        bridge.testPrinter({ printer: target(printer), deviceName: deviceName ?? null, printerName: printer.name ?? '' }, relay(onProgress)),
    );

    return { ...result, via: 'native' };
}

/* ═══════════════════════════ What the cashier is told ═══════════════════════════ */

/** Why it failed, in one plain sentence — no code, no technical detail. */
export function failureMessage(code: PrintErrorCode): string {
    switch (code) {
        case 'PRINTER_UNREACHABLE':
            return 'تعذر الوصول إلى عنوان الطابعة';
        case 'PORT_REFUSED':
            return 'المنفذ لا يستجيب';
        case 'CONNECT_TIMEOUT':
            return 'انتهت مهلة الاتصال';
        case 'SEND_FAILED':
        case 'BRIDGE_TIMEOUT':
            return 'انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة';
        case 'SESSION_EXPIRED':
            return 'انتهت الجلسة — سجّل الدخول من جديد';
        case 'RECEIPT_DOWNLOAD_FAILED':
            return 'تعذر تنزيل الإيصال';
        case 'RECEIPT_RENDER_FAILED':
            return 'تعذر تجهيز الإيصال للطباعة';
        case 'INVALID_PRINTER_ADDRESS':
            return 'عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية';
        case 'INVALID_PORT':
            return 'رقم المنفذ غير صالح';
        case 'UNSUPPORTED_CONNECTION':
            return 'الطباعة المباشرة تدعم طابعات الشبكة الحرارية فقط';
        case 'QUEUE_FULL':
            return 'الطابعة مشغولة — حاول بعد لحظات';
        case 'NATIVE_REQUIRED':
            return 'الاختبار المباشر يحتاج تطبيق الجم على جهاز في شبكة الطابعة';
        case 'POPUP_BLOCKED':
            return 'منع المتصفح فتح نافذة الطباعة — اطبع من الزر أدناه.';
        case 'IN_PAGE_UNSUPPORTED':
            return 'هذا المتصفح لا يطبع من داخل الصفحة — افتح PDF واطبعه منه.';
        case 'PRINT_DIALOG_FAILED':
            return 'لم يُفتح حوار الطباعة — أعد المحاولة أو افتح PDF.';
        case 'SHARE_BLOCKED':
            return 'لم تُفتح قائمة المشاركة — اضغط «طباعة أو مشاركة PDF» مرة أخرى.';
        case 'SHARE_FAILED':
            return 'تعذرت مشاركة الإيصال — افتح PDF واطبعه منه.';
        case 'SHARE_CANCELLED':
            return 'أُغلقت قائمة المشاركة.';
        case 'BRIDGE_UNAVAILABLE':
            return 'تعذر الوصول إلى التطبيق';
        case 'UNTRUSTED_ORIGIN':
        case 'INVALID_JOB':
        case 'RECEIPT_URL_REJECTED':
            return 'طلب طباعة مرفوض';
        default:
            return 'تعذرت الطباعة';
    }
}

/** What is happening now — one line. */
export function stageMessage(stage: PrintStage): string {
    switch (stage) {
        case 'downloading_receipt':
        case 'rendering':
            return 'جارٍ تجهيز الإيصال…';
        case 'connecting':
            return 'جارٍ الاتصال بالطابعة…';
        case 'sending':
            return 'جارٍ الطباعة…';
        case 'success':
            return 'تمت طباعة الإيصال';
        case 'ready':
            return 'الإيصال جاهز — اختر «طباعة» من قائمة المشاركة.';
        default:
            return '';
    }
}

/**
 * Is it quiet? No «printed» of a dialog opened or a sheet finished (nothing says the paper came out), no error of a
 * sheet the cashier closed.
 */
export function quietOutcome(outcome: PrintOutcome | null | undefined): boolean {
    if (!outcome || outcome.via === 'browser') return !outcome || outcome.ok;
    if (outcome.ok) return outcome.via === 'page' || outcome.via === 'share' || outcome.via === 'system';

    return outcome.code === 'SHARE_CANCELLED';
}

/** May part of it have reached the paper? Then it is never printed again without the cashier deciding. */
export function mayHavePrinted(outcome: PrintOutcome | null | undefined): boolean {
    return !!outcome && !outcome.ok && outcome.mayHavePrinted === true;
}
