import { failure } from './bridge';
import type { PrintOutcome } from './types';

/**
 * Printing a receipt from inside the page: the browser's print dialog, the cashier staying on their screen.
 *
 * A receipt opened in a new tab hides the point of sale on a tablet or phone (the PDF viewer fills the screen with no
 * tab bar), while the customer waits. So the official PDF itself is fetched with the page's session, put in a hidden
 * frame, and the print dialog is asked for on that frame: what prints is what the tab printed, line for line, at its
 * width and length — not a paper written again in HTML.
 *
 * Printing a PDF from a frame is not in any standard: it is what the PDF viewer built into Chrome and Edge on a
 * computer does. Safari, Firefox, Android and iOS guarantee nothing (a blank frame or the page beneath may print, with
 * no error to read), so it is not tried there; iPhone and iPad have the share sheet (share-print).
 *
 * Opening the dialog is not printing: the browser does not say whether the cashier printed or cancelled. Success here
 * is via 'page' — «the dialog was asked for» — and the screen never says «printed» of it.
 */

/** Until the frame loads; after it, the dialog is said not to have opened. */
export const FRAME_TIMEOUT_MS = 20000;

/** The viewer's settling time after load: Chrome's PDF viewer receives the file after the frame loads. */
export const VIEWER_SETTLE_MS = 600;

/** The download of the receipt: a network that hangs does not answer with an error, so it is cut and said. */
export const DOWNLOAD_TIMEOUT_MS = 30000;

const timing = { timeoutMs: FRAME_TIMEOUT_MS, settleMs: VIEWER_SETTLE_MS, downloadMs: DOWNLOAD_TIMEOUT_MS };

/** For tests only: delays in milliseconds rather than seconds. */
export function setPagePrintTiming(next: Partial<typeof timing>): void {
    Object.assign(timing, next);
}

interface Environment {
    userAgent: string;
    pdfViewerEnabled?: boolean;
    maxTouchPoints?: number;
}

export function environment(): Environment {
    if (typeof navigator === 'undefined') return { userAgent: '' };

    return {
        userAgent: navigator.userAgent ?? '',
        pdfViewerEnabled: (navigator as Navigator & { pdfViewerEnabled?: boolean }).pdfViewerEnabled,
        maxTouchPoints: navigator.maxTouchPoints,
    };
}

/** An iPhone or iPad — an iPad in «desktop mode» says it is a Mac, and is told by its touch screen. */
export function appleMobile(env: Environment = environment()): boolean {
    if (/iPhone|iPad|iPod/i.test(env.userAgent)) return true;

    return /Macintosh/.test(env.userAgent) && (env.maxTouchPoints ?? 0) > 1;
}

/**
 * Does this browser print a PDF from a frame in the page? Chromium on a computer (Chrome, Edge) with its viewer on —
 * only that. Phones and tablets are outside it whatever the browser: Chrome on iOS is WebKit, and Android has no PDF
 * viewer in the page.
 */
export function inPagePrintSupported(env: Environment = environment()): boolean {
    const ua = env.userAgent;

    if (/Android|Mobile|CriOS|FxiOS|EdgiOS/i.test(ua) || appleMobile(env)) return false;
    if (!/Chrome\/|Chromium\/|Edg\//.test(ua)) return false;

    return env.pdfViewerEnabled !== false;
}

/* ═══════════════════════════ The download ═══════════════════════════ */

/**
 * The official receipt as a file, or a reason to say. The answer is read before it is used: an error page or the
 * sign-in page would print as they are and say nothing. So a PDF or not, nothing in between.
 */
export async function downloadReceipt(url: string, via: PrintOutcome['via']): Promise<Blob | PrintOutcome> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timing.downloadMs);

    try {
        return await download(url, via, controller.signal);
    } finally {
        clearTimeout(timer);
    }
}

async function download(url: string, via: PrintOutcome['via'], signal: AbortSignal): Promise<Blob | PrintOutcome> {
    let response: Response;
    try {
        response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' }, signal });
    } catch {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via };
    }

    const type = response.headers.get('content-type') ?? '';
    if (response.status === 401 || response.status === 419 || (response.ok && type.includes('text/html'))) {
        // The sign-in page answers 200 after the redirect: the type says what the status does not.
        return { ...failure('SESSION_EXPIRED'), via };
    }
    if (!response.ok || !type.includes('application/pdf')) {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via };
    }

    try {
        return await response.blob();
    } catch {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via };
    }
}

/* ═══════════════════════════ The frame ═══════════════════════════ */

let frame: HTMLIFrameElement | null = null;
let objectUrl: string | null = null;
let inFlight = false;

/**
 * The hidden frame — one per page, used again. Not display:none: a frame that is not drawn does not load Chrome's
 * PDF viewer and prints blank. So it is shrunk and hidden from the eye and from screen readers.
 */
function printFrame(): HTMLIFrameElement {
    if (frame && frame.isConnected) return frame;

    frame = document.createElement('iframe');
    frame.setAttribute('data-print-frame', '');
    frame.setAttribute('aria-hidden', 'true');
    frame.setAttribute('tabindex', '-1');
    frame.title = 'print';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(frame);

    return frame;
}

/** The previous receipt is freed when another comes or the page is left — not before: its dialog may still be open. */
function swap(next: string | null): void {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = next;
}

if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => resetPrintFrame());
}

/** What the frame holds after loading — null when it cannot be read. */
function frameType(target: HTMLIFrameElement): string | null {
    try {
        return target.contentDocument?.contentType ?? null;
    } catch {
        return null;
    }
}

/**
 * Fetches the receipt and asks for the print dialog on it; the page does not move. One print in the page at a time:
 * two buttons racing on one frame would print a receipt nobody meant, so the second is refused while the first loads.
 */
export async function printPdfInPage(url: string, timeoutMs = timing.timeoutMs): Promise<PrintOutcome> {
    if (!inPagePrintSupported()) return { ...failure('IN_PAGE_UNSUPPORTED'), via: 'page' };
    if (inFlight) return { ...failure('QUEUE_FULL'), via: 'page' };
    inFlight = true;

    try {
        const pdf = await downloadReceipt(url, 'page');
        if (!(pdf instanceof Blob)) return pdf;

        return await showDialog(pdf, timeoutMs);
    } finally {
        inFlight = false;
    }
}

function showDialog(pdf: Blob, timeoutMs: number): Promise<PrintOutcome> {
    const target = printFrame();
    const src = URL.createObjectURL(pdf);

    return new Promise<PrintOutcome>((resolve) => {
        let done = false;
        const finish = (outcome: PrintOutcome) => {
            if (done) return;
            done = true;
            window.clearTimeout(timer);
            target.onload = null;
            resolve(outcome);
        };

        const timer = window.setTimeout(() => finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' }), timeoutMs);

        target.onload = () => {
            // What loaded is asked: Chrome with its viewer off downloads the file and leaves the frame blank.
            const type = frameType(target);
            if (type !== null && type !== 'application/pdf') {
                finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' });

                return;
            }

            window.setTimeout(() => {
                if (done) return;
                try {
                    const win = target.contentWindow;
                    if (!win) throw new Error('no window');
                    const before = document.activeElement;
                    try {
                        win.focus();
                    } catch {
                        // Focus helps, it is not required: the system dialog opens without it.
                    }
                    win.print();
                    giveFocusBack(target, before);
                    finish({ ok: true, via: 'page' });
                } catch {
                    finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' });
                }
            }, timing.settleMs);
        };

        swap(src);
        target.src = src;
    });
}

/**
 * Focus back on the page after the dialog: left in the hidden frame, the keyboard and the barcode scanner would type
 * into a frame nobody sees.
 */
function giveFocusBack(target: HTMLIFrameElement, before: Element | null): void {
    try {
        if (before instanceof HTMLElement && before !== document.body && before.isConnected) {
            before.focus({ preventScroll: true });
        }
        if (document.activeElement === target) target.blur();
    } catch {
        // Nothing here stops a dialog that opened.
    }
}

/** For tests and for leaving the page: a new frame and a freed file. */
export function resetPrintFrame(): void {
    swap(null);
    frame?.remove();
    frame = null;
    inFlight = false;
}
