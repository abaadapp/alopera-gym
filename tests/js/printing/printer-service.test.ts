import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    abandonAutoPrint,
    commitAutoPrint,
    isNetworkPrinter,
    mayHavePrinted,
    pdfUrl,
    prepareAutoPrint,
    printDocument,
    printReceipt,
    quietOutcome,
    testPrinter,
} from '@/lib/printing/printer-service';
import type { AloperaPrinterNative, PrinterLike } from '@/lib/printing/types';

const network: PrinterLike = { id: 7, name: 'طابعة الكاشير', model: 'TM-T20', connection: 'network', address: ' 192.168.1.50 ', port: null, paper: '80mm', paper_width: 80, auto_print: true };
const dialogPrinter: PrinterLike = { id: 8, name: 'USB', connection: 'browser', paper: '80mm', paper_width: 80, auto_print: true };
const sheetOnNetwork: PrinterLike = { id: 9, name: 'Office', connection: 'network', address: '192.168.1.70', paper: 'A4', paper_width: null, auto_print: false };

function fakeBridge(overrides: Partial<AloperaPrinterNative> = {}) {
    const bridge = {
        version: 1,
        platform: 'android',
        isAvailable: () => true,
        printReceipt: vi.fn(async () => ({ ok: true, jobId: 'j1', printed: true, connected: true, effectivePort: 9100 })),
        testPrinter: vi.fn(async () => ({ ok: true, printed: true, connected: true, effectivePort: 9100 })),
        openReceipt: vi.fn(async () => ({ ok: true, jobId: 'o1' })),
        ...overrides,
    };
    window.AloperaPrinter = bridge as unknown as AloperaPrinterNative;

    return bridge;
}

const ua = (value: string) => Object.defineProperty(window.navigator, 'userAgent', { value, configurable: true });
const DESKTOP_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

beforeEach(() => {
    delete window.AloperaPrinter;
    ua(ANDROID_CHROME);
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
});

afterEach(() => {
    vi.restoreAllMocks();
    delete window.AloperaPrinter;
});

describe('the print door and its PDF', () => {
    it('is the same door with format=pdf, absolute, without the print-dialog switch', () => {
        expect(pdfUrl('/app/sales/12/receipt?print=1')).toBe(`${window.location.origin}/app/sales/12/receipt?format=pdf`);
        expect(pdfUrl('/app/payments/3/receipt?face=strip')).toBe(`${window.location.origin}/app/payments/3/receipt?face=strip&format=pdf`);
    });

    it('prints directly only to a thermal printer on the network with an address', () => {
        expect(isNetworkPrinter(network)).toBe(true);
        expect(isNetworkPrinter(dialogPrinter)).toBe(false);
        expect(isNetworkPrinter(sheetOnNetwork)).toBe(false);
        expect(isNetworkPrinter({ ...network, address: '  ' })).toBe(false);
    });
});

describe('inside the club app', () => {
    it('sends a structured job to the network printer: the receipt PDF on this origin, the trimmed address, port null for the default', async () => {
        const bridge = fakeBridge();
        const progress: string[] = [];
        bridge.printReceipt.mockImplementation(async (_job: unknown, onProgress?: (s: string) => void) => {
            onProgress?.('downloading_receipt');
            onProgress?.('sending');
            onProgress?.('not-a-stage');

            return { ok: true, jobId: 'j1', printed: true, connected: true, effectivePort: 9100 };
        });

        const outcome = await printReceipt({ title: 'POS-2026-000012', url: '/app/sales/12/receipt', printer: network, onProgress: (s) => progress.push(s) });

        expect(outcome).toMatchObject({ ok: true, via: 'native', printed: true, effectivePort: 9100 });
        expect(bridge.printReceipt).toHaveBeenCalledWith(
            {
                invoice: 'POS-2026-000012',
                receiptUrl: `${window.location.origin}/app/sales/12/receipt?format=pdf`,
                printer: { id: 7, name: 'طابعة الكاشير', model: 'TM-T20', connection: 'network', address: '192.168.1.50', port: null, paperWidth: 80 },
            },
            expect.any(Function),
        );
        expect(progress).toEqual(['downloading_receipt', 'sending']);
    });

    it('never calls an unknown fault printed, and marks it as may have printed so it is not repeated by itself', async () => {
        fakeBridge({ printReceipt: vi.fn(async () => { throw new Error('boom'); }) as never });
        const outcome = await printReceipt({ title: 'X', url: '/app/sales/1/receipt', printer: network });
        expect(outcome).toMatchObject({ ok: false, code: 'INTERNAL', mayHavePrinted: true, via: 'native' });
        expect(mayHavePrinted(outcome)).toBe(true);
    });

    it('passes the app’s failure codes through and refuses an answer off the contract', async () => {
        fakeBridge({ printReceipt: vi.fn(async () => ({ ok: false, code: 'SEND_FAILED', message: '', connected: true, mayHavePrinted: true })) as never });
        expect(await printReceipt({ title: 'X', url: '/r', printer: network })).toMatchObject({ code: 'SEND_FAILED', connected: true, mayHavePrinted: true });

        fakeBridge({ printReceipt: vi.fn(async () => ({ ok: 'yes' })) as never });
        expect(await printReceipt({ title: 'X', url: '/r', printer: network })).toMatchObject({ ok: false, code: 'INTERNAL' });
    });

    it('uses the system print dialog for any other printer, and for every sheet document', async () => {
        const bridge = fakeBridge();
        expect(await printReceipt({ title: 'X', url: '/app/sales/1/receipt', printer: dialogPrinter })).toMatchObject({ ok: true, via: 'system' });
        expect(await printReceipt({ title: 'X', url: '/app/sales/1/receipt', printer: sheetOnNetwork })).toMatchObject({ ok: true, via: 'system' });
        expect(await printDocument({ title: 'INV-1', url: '/app/invoices/1/print' })).toMatchObject({ ok: true, via: 'system' });
        expect(bridge.openReceipt).toHaveBeenLastCalledWith({ invoice: 'INV-1', receiptUrl: `${window.location.origin}/app/invoices/1/print?format=pdf` });
        expect(bridge.printReceipt).not.toHaveBeenCalled();
    });

    it('tests a network printer for real, and says plainly when that cannot happen', async () => {
        const bridge = fakeBridge();
        expect(await testPrinter(network, 'الصندوق 1')).toMatchObject({ ok: true, via: 'native', connected: true });
        expect(bridge.testPrinter).toHaveBeenCalledWith(expect.objectContaining({ deviceName: 'الصندوق 1', printerName: 'طابعة الكاشير' }), expect.any(Function));
        expect(await testPrinter(dialogPrinter, 'x')).toMatchObject({ ok: false, code: 'UNSUPPORTED_CONNECTION', effectivePort: 9100 });
    });

    it('ignores a bridge of another version or one that is not available on this page', async () => {
        fakeBridge({ version: 2 } as never);
        const open = vi.spyOn(window, 'open').mockReturnValue({} as Window);
        expect(await printReceipt({ title: 'X', url: '/r', printer: network })).toMatchObject({ via: 'browser' });

        fakeBridge({ isAvailable: () => false });
        expect(await printReceipt({ title: 'X', url: '/r', printer: network })).toMatchObject({ via: 'browser' });
        expect(open).toHaveBeenCalledTimes(2);
    });
});

describe('in a browser', () => {
    it('opens the print page in a new tab, and says so when the browser blocks it', async () => {
        const open = vi.spyOn(window, 'open').mockReturnValueOnce({} as Window).mockReturnValueOnce(null);
        expect(await printReceipt({ title: 'X', url: '/app/sales/1/receipt', printer: network })).toEqual({ ok: true, via: 'browser' });
        expect(open).toHaveBeenCalledWith('/app/sales/1/receipt', '_blank');
        expect(await printDocument({ title: 'X', url: '/app/invoices/1/print' })).toMatchObject({ ok: false, code: 'POPUP_BLOCKED' });
    });

    it('never pretends to test a network printer it cannot reach', async () => {
        expect(await testPrinter(network, 'x')).toMatchObject({ ok: false, code: 'NATIVE_REQUIRED', via: 'browser' });
    });

    it('prints inside the page on desktop Chrome from the receipt PDF, without opening a tab', async () => {
        ua(DESKTOP_CHROME);
        const open = vi.spyOn(window, 'open');
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('<html>login</html>', { status: 200, headers: { 'content-type': 'text/html' } }));
        // The sign-in page in place of the PDF reads as an expired session, never as a receipt to print.
        expect(await printReceipt({ title: 'X', url: '/app/sales/1/receipt', printer: network, inPage: true })).toMatchObject({ ok: false, code: 'SESSION_EXPIRED', via: 'page' });
        expect(fetchSpy).toHaveBeenCalledWith(`${window.location.origin}/app/sales/1/receipt?format=pdf`, expect.objectContaining({ credentials: 'same-origin' }));
        expect(open).not.toHaveBeenCalled();
    });
});

describe('automatic printing', () => {
    it('is prepared in the tap: none without a printer or the setting; direct, dialog, in the page or a blank tab', () => {
        expect(prepareAutoPrint(null).mode).toBe('none');
        expect(prepareAutoPrint({ ...network, auto_print: false }).mode).toBe('none');

        fakeBridge();
        expect(prepareAutoPrint(network).mode).toBe('native');
        expect(prepareAutoPrint(dialogPrinter).mode).toBe('system');
        delete window.AloperaPrinter;

        ua(DESKTOP_CHROME);
        expect(prepareAutoPrint(network).mode).toBe('page');

        ua(ANDROID_CHROME);
        const sheet = { close: vi.fn(), location: { replace: vi.fn() } } as unknown as Window;
        vi.spyOn(window, 'open').mockReturnValue(sheet);
        const attempt = prepareAutoPrint(network);
        expect(attempt).toMatchObject({ mode: 'browser', sheet });
    });

    it('runs once after the answer: the blank tab is pointed at the receipt, or closed when there is none', async () => {
        const sheet = { close: vi.fn(), location: { replace: vi.fn() } } as unknown as Window & { close: ReturnType<typeof vi.fn>; location: { replace: ReturnType<typeof vi.fn> } };
        vi.spyOn(window, 'open').mockReturnValue(sheet);

        const attempt = prepareAutoPrint(network);
        expect(await commitAutoPrint(attempt, { title: 'POS-1', url: '/app/sales/1/receipt' })).toEqual({ ok: true, via: 'browser' });
        expect(sheet.location.replace).toHaveBeenCalledWith('/app/sales/1/receipt');
        expect(await commitAutoPrint(attempt, { title: 'POS-1', url: '/app/sales/1/receipt' })).toBeNull();
        abandonAutoPrint(attempt);
        expect(sheet.close).not.toHaveBeenCalled();

        const empty = prepareAutoPrint(network);
        expect(await commitAutoPrint(empty, null)).toBeNull();
        expect(sheet.close).toHaveBeenCalledTimes(1);

        const failed = prepareAutoPrint(network);
        abandonAutoPrint(failed);
        expect(sheet.close).toHaveBeenCalledTimes(2);
    });

    it('in the app, prints straight to the network printer after the answer', async () => {
        const bridge = fakeBridge();
        const attempt = prepareAutoPrint(network);
        expect(await commitAutoPrint(attempt, { title: 'POS-1', url: '/app/sales/1/receipt' })).toMatchObject({ ok: true, via: 'native' });
        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
    });
});

describe('what the cashier is told', () => {
    it('stays quiet about a dialog opened or a share finished, never calling them printed', () => {
        expect(quietOutcome({ ok: true, via: 'page' })).toBe(true);
        expect(quietOutcome({ ok: true, via: 'share' })).toBe(true);
        expect(quietOutcome({ ok: true, via: 'system' })).toBe(true);
        expect(quietOutcome({ ok: true, via: 'native', printed: true })).toBe(false);
        expect(quietOutcome({ ok: false, code: 'SHARE_CANCELLED', message: '', via: 'share' })).toBe(true);
        expect(quietOutcome({ ok: false, code: 'POPUP_BLOCKED', message: '', via: 'browser' })).toBe(false);
    });
});
