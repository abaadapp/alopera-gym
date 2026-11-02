import type { AloperaPrinterNative, BridgeResult, PrintErrorCode } from './types';

/**
 * The club app's bridge — there or not, nothing in between.
 *
 * In an ordinary browser there is no window.AloperaPrinter, and printing stays as it was. In the app, the native side
 * injects it when the document starts, on trusted origins only. What comes from it is normalised here before the
 * screen sees it: a partial answer or an exception never reads as «printed».
 */

const VERSION = 1;

const CODES: readonly PrintErrorCode[] = [
    'UNTRUSTED_ORIGIN',
    'INVALID_JOB',
    'RECEIPT_URL_REJECTED',
    'UNSUPPORTED_CONNECTION',
    'INVALID_PRINTER_ADDRESS',
    'INVALID_PORT',
    'SESSION_EXPIRED',
    'RECEIPT_DOWNLOAD_FAILED',
    'RECEIPT_RENDER_FAILED',
    'PRINTER_UNREACHABLE',
    'PORT_REFUSED',
    'CONNECT_TIMEOUT',
    'SEND_FAILED',
    'QUEUE_FULL',
    'INTERNAL',
    'BRIDGE_TIMEOUT',
    'BRIDGE_UNAVAILABLE',
    'NATIVE_REQUIRED',
    'POPUP_BLOCKED',
];

/** The bridge if it is valid and available to this page, else null. */
export function nativeBridge(): AloperaPrinterNative | null {
    if (typeof window === 'undefined') return null;

    const bridge = window.AloperaPrinter;
    if (
        !bridge ||
        bridge.version !== VERSION ||
        typeof bridge.isAvailable !== 'function' ||
        typeof bridge.printReceipt !== 'function' ||
        typeof bridge.testPrinter !== 'function' ||
        typeof bridge.openReceipt !== 'function'
    ) {
        return null;
    }

    try {
        return bridge.isAvailable() === true ? bridge : null;
    } catch {
        return null;
    }
}

export function failure(code: PrintErrorCode, extra: Partial<Extract<BridgeResult, { ok: false }>> = {}): BridgeResult {
    return { ok: false, code, message: '', ...extra };
}

/** The bridge's answer normalised; success only when said outright. */
export function normalizeResult(raw: unknown): BridgeResult {
    if (!raw || typeof raw !== 'object') return failure('INTERNAL');

    const r = raw as Record<string, unknown>;
    const port = typeof r.effectivePort === 'number' ? r.effectivePort : undefined;
    const jobId = typeof r.jobId === 'string' ? r.jobId : undefined;

    if (r.ok === true) {
        return {
            ok: true,
            jobId,
            printed: r.printed === true,
            connected: r.connected === true,
            effectivePort: port,
            message: typeof r.message === 'string' ? r.message : undefined,
        };
    }

    const code = CODES.includes(r.code as PrintErrorCode) ? (r.code as PrintErrorCode) : 'INTERNAL';

    return failure(code, {
        message: typeof r.message === 'string' ? r.message : '',
        jobId,
        connected: r.connected === true,
        mayHavePrinted: r.mayHavePrinted === true,
        effectivePort: port,
    });
}

/** A call to the bridge that never throws: every failure comes back as a result to show. */
export async function callBridge(run: () => Promise<unknown>): Promise<BridgeResult> {
    try {
        const result = normalizeResult(await run());
        if (!result.ok) {
            // For diagnosis only: the code and the port — no address of a receipt, nothing of its content.
            console.warn('[AloperaPrinter]', result.code, result.effectivePort ?? '');
        }

        return result;
    } catch {
        console.warn('[AloperaPrinter]', 'INTERNAL');

        return failure('INTERNAL');
    }
}
