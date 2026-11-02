import { failure } from './bridge';
import { appleMobile, downloadReceipt, environment } from './page-print';
import type { PrintOutcome } from './types';

/**
 * «Print or share the PDF» — the share sheet on iPhone and iPad.
 *
 * Safari on iOS does not print a PDF from a frame (it prints the page beneath or a blank one, with no error), and
 * window.print() on the point of sale prints the point of sale. What Apple supports is sharing a file
 * (navigator.share({ files }), Safari 15+): the system sheet opens over the page, the page unchanged beneath, with
 * «Print» for a PDF, AirDrop and saving to Files. The file is the official receipt itself. It is not claimed that
 * «Print» is always there, nor that opening the sheet is printing.
 *
 * The sheet opens only in a live tap, and fetching the file after the tap spends it (Safari answers NotAllowedError
 * to a late share). So the file is made ready before the tap (prepareReceipt) and shared in it without waiting
 * (shareReceipt); one not ready yet is made ready by the first tap and shared by the second. The sheet never opens
 * by itself, not after a sale nor after preparing.
 */

/** The receipts made ready — the latest few only, keyed by their address. */
const PREPARED_LIMIT = 6;
const prepared = new Map<string, File>();
const preparing = new Map<string, Promise<File | PrintOutcome>>();
/** How many times an address was forgotten: a download begun before it does not keep what it brings. */
const forgotten = new Map<string, number>();

function probe(): File | null {
    try {
        return new File([new Blob(['%PDF-1.4'], { type: 'application/pdf' })], 'receipt.pdf', { type: 'application/pdf' });
    } catch {
        return null;
    }
}

/**
 * Does the share sheet open with a PDF here? iPhone and iPad only — their sheet offers «Print» for a PDF; Android's
 * and the Mac's do not, so the button is not called «Print» there. Asked with canShare on a file, not by presence
 * alone: iOS 14 shares links, not files.
 */
export function sharePrintSupported(env = environment()): boolean {
    if (!appleMobile(env)) return false;
    if (typeof navigator === 'undefined' || typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
        return false;
    }

    const file = probe();
    try {
        return !!file && navigator.canShare({ files: [file] });
    } catch {
        return false;
    }
}

/** The file made ready for this receipt — without waiting, to be shared in the tap itself. */
export function preparedReceipt(url: string): File | null {
    return prepared.get(url) ?? null;
}

/**
 * Fetches the receipt and keeps it as a file; opens nothing. Always from the server (a file made ready before may be
 * of a paper changed since). Two downloads of one receipt at once are one: the screen prepares it when it opens and
 * the cashier taps before it ends, so the tap joins the first.
 */
export function prepareReceipt(url: string, name: string): Promise<File | PrintOutcome> {
    const pending = preparing.get(url);
    if (pending) return pending;

    prepared.delete(url);
    const generation = forgotten.get(url) ?? 0;

    const job = downloadReceipt(url, 'share')
        .then((pdf): File | PrintOutcome => {
            if (!(pdf instanceof Blob)) return pdf;

            const file = new File([pdf], `${name}.pdf`, { type: 'application/pdf' });
            // Forgotten while it downloaded: what it brought may be from before the change, so it is not kept.
            if ((forgotten.get(url) ?? 0) === generation) prepared.set(url, file);
            while (prepared.size > PREPARED_LIMIT) prepared.delete(prepared.keys().next().value as string);

            return file;
        })
        .finally(() => {
            if (preparing.get(url) === job) preparing.delete(url);
        });

    preparing.set(url, job);

    return job;
}

/** Forgets what was made ready for papers whose data changed, so no copy from before the change is shared. */
export function forgetPreparedReceipts(match: (url: string) => boolean = () => true): void {
    for (const url of new Set([...prepared.keys(), ...preparing.keys()])) {
        if (!match(url)) continue;
        prepared.delete(url);
        preparing.delete(url);
        forgotten.set(url, (forgotten.get(url) ?? 0) + 1);
    }
}

/**
 * Opens the share sheet with the file — in the tap itself (navigator.share is called before any await). Finished →
 * ok via 'share', not «printed» (it may be a save or AirDrop); closed by the cashier → SHARE_CANCELLED, no error said;
 * a tap that expired → SHARE_BLOCKED, tap again; anything else → SHARE_FAILED, with «Open PDF».
 */
export function shareReceipt(file: File, title: string): Promise<PrintOutcome> {
    let shared: Promise<void>;
    try {
        shared = navigator.share({ files: [file], title });
    } catch (e) {
        return Promise.resolve(shareFailure(e));
    }

    return shared.then(
        (): PrintOutcome => ({ ok: true, via: 'share' }),
        (e: unknown) => shareFailure(e),
    );
}

function shareFailure(e: unknown): PrintOutcome {
    const name = e instanceof Error || e instanceof DOMException ? e.name : '';
    if (name === 'AbortError') return { ...failure('SHARE_CANCELLED'), via: 'share' };
    if (name === 'NotAllowedError') return { ...failure('SHARE_BLOCKED'), via: 'share' };

    return { ...failure('SHARE_FAILED'), via: 'share' };
}
