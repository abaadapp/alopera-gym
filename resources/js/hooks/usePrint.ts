import { useCallback, useEffect, useRef, useState } from 'react';
import { recordPrint, type PrintSubject, type Trigger } from '@/lib/printing/print-log';
import { insideApp, openPdf, pagePrintMode, pdfUrl, printReceipt, quietOutcome } from '@/lib/printing/printer-service';
import { preparedReceipt, prepareReceipt } from '@/lib/printing/share-print';
import type { PrinterLike, PrintOutcome, PrintStage } from '@/lib/printing/types';

/** A receipt to print: its number, its print door and what the log calls it. */
export interface PrintJob {
    title: string;
    url: string;
    subject?: PrintSubject;
}

export interface PrintState {
    stage: PrintStage;
    outcome: PrintOutcome | null;
    job: PrintJob | null;
}

const IDLE: PrintState = { stage: 'idle', outcome: null, job: null };

/**
 * Printing a receipt from a screen, and its state until it ends. Every screen with «print» goes through here, so none
 * knows where the printer is or how to reach it: lib/printing/printer-service picks the way, and the outcome goes to
 * the print log.
 *
 * One print at a time: two quick taps opened two tabs, and in the app they mean two receipts on paper, so the second
 * is ignored while the first works. A failure that may have reached the paper is never retried by itself; «print
 * again» is a new request of the cashier's (trigger reprint).
 *
 * inPage (the point of sale): in a browser the print dialog opens on the PDF in a hidden frame, or the share sheet on
 * iPhone and iPad, whose file is made ready before the tap (prepare) because the sheet opens only in a live tap.
 */
export default function usePrint(printer: PrinterLike | null | undefined, { inPage = false }: { inPage?: boolean } = {}) {
    const [state, setState] = useState<PrintState>(IDLE);
    const [, setPrepared] = useState(0);
    const mode = inPage && !insideApp() ? pagePrintMode() : null;
    const working = useRef(false);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;

        return () => {
            alive.current = false;
        };
    }, []);

    /** Settles a print's outcome on the screen and in the log. */
    const settle = useCallback(
        (job: PrintJob, outcome: PrintOutcome | null, trigger: Trigger, log?: number) => {
            if (outcome && (job.subject || log)) void recordPrint(outcome, { log, subject: job.subject, trigger, printer: printer?.id ?? null });
            if (alive.current) setState(quietOutcome(outcome) || !outcome ? IDLE : { stage: outcome.ok ? 'success' : 'failed', outcome, job });

            return outcome;
        },
        [printer?.id],
    );

    /**
     * Runs a print and follows it. The job is called before any wait (in a browser it opens a tab, which only a live
     * gesture may). The state is shown before the answer in the app and when printing in the page (a download waits).
     */
    const run = useCallback(
        async (job: PrintJob, trigger: Trigger, task: (onProgress: (stage: PrintStage) => void) => Promise<PrintOutcome | null>, shows: boolean, log?: number) => {
            if (working.current) return null;
            working.current = true;

            const onProgress = (stage: PrintStage) => {
                if (alive.current) setState((s) => ({ ...s, stage }));
            };

            try {
                const pending = task(onProgress);
                if (shows) setState({ stage: 'downloading_receipt', outcome: null, job });

                return settle(job, await pending, trigger, log);
            } finally {
                working.current = false;
            }
        },
        [settle],
    );

    /** Makes a receipt ready for the share sheet — opens nothing. quietly: when the screen opens, with no state line. */
    const prepare = useCallback(
        (job: PrintJob, quietly = false): Promise<PrintOutcome | null> => {
            if (mode !== 'share') return Promise.resolve(null);
            if (!quietly) setState({ stage: 'downloading_receipt', outcome: null, job });

            return prepareReceipt(pdfUrl(job.url), job.title).then((result) => {
                if (!alive.current) return null;
                setPrepared((n) => n + 1);
                if (result instanceof File) {
                    if (!quietly) setState({ stage: 'ready', outcome: null, job });

                    return null;
                }
                if (!quietly) setState({ stage: 'failed', outcome: result, job });

                return result;
            });
        },
        [mode],
    );

    const isReady = useCallback((job: PrintJob) => mode === 'share' && preparedReceipt(pdfUrl(job.url)) !== null, [mode]);

    const print = useCallback(
        (job: PrintJob, trigger: Trigger = 'manual') => {
            // Not ready yet: this tap makes it ready, the next one opens the sheet.
            if (mode === 'share' && preparedReceipt(pdfUrl(job.url)) === null) return prepare(job);

            return run(job, trigger, (onProgress) => printReceipt({ title: job.title, url: job.url, printer: printer ?? null, onProgress, inPage }), insideApp() || (inPage && mode === 'frame'));
        },
        [run, printer, inPage, mode, prepare],
    );

    /** «Print again» — a new, explicit request of the cashier's, never an automatic retry. */
    const retry = useCallback(() => (state.job ? print(state.job, 'reprint') : Promise.resolve(null)), [print, state.job]);

    /** «Open PDF» — the same file, in the system's dialog in the app and in a new tab in a browser. */
    const open = useCallback((job: PrintJob | null = state.job) => (job ? openPdf({ title: job.title, url: job.url }) : Promise.resolve(null)), [state.job]);

    const reset = useCallback(() => setState(IDLE), []);

    return {
        state,
        busy: state.stage !== 'idle' && state.stage !== 'success' && state.stage !== 'failed' && state.stage !== 'ready',
        /** The way to print in the page — null in the app and outside the point of sale. */
        mode,
        run,
        settle,
        print,
        prepare,
        isReady,
        retry,
        open,
        reset,
    };
}
