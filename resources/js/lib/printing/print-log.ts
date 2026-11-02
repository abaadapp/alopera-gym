import { postJson } from '@/lib/http';
import { mayHavePrinted } from './printer-service';
import type { PrintOutcome } from './types';

/**
 * The print log on the server: what was printed and how it ended, and the one claim of an automatic print. The server
 * never reaches a printer; it keeps the record and the printer's last state. A failed write here never prints again.
 */

/** The paper a print is of, as the server names it (a paper type and its record), or a printer's test page. */
export interface PrintSubject {
    document: string;
    id?: number | null;
}

export type Trigger = 'manual' | 'reprint' | 'test';

/**
 * The automatic print of a receipt: the number of the claim when this screen got it, false when another screen or a
 * reload took it first, and false too when the server could not be asked — an automatic print is skipped rather than
 * risked twice (the print button stays).
 */
export async function claimAutoPrint(subject: PrintSubject): Promise<number | false> {
    try {
        const res = await postJson(route('club.prints.claim'), { document: subject.document, document_id: subject.id });
        if (!res.ok) return false;
        const data = (await res.json()) as { claimed?: boolean; log?: number | null };

        return data.claimed === true && typeof data.log === 'number' ? data.log : false;
    } catch {
        return false;
    }
}

/** How a print ended: a new record (with its trigger), or the claimed automatic one completed. */
export async function recordPrint(outcome: PrintOutcome, how: { log?: number; subject?: PrintSubject; trigger?: Trigger; printer?: number | null }): Promise<void> {
    const body = {
        log: how.log ?? null,
        document: how.log ? null : how.subject?.document,
        document_id: how.log ? null : how.subject?.id ?? null,
        trigger: how.log ? null : how.trigger,
        peripheral_id: how.printer ?? null,
        via: outcome.via,
        ok: outcome.ok,
        printed: outcome.ok ? outcome.printed === true : false,
        code: outcome.ok ? null : outcome.code,
        may_have_printed: mayHavePrinted(outcome),
        connected: outcome.connected ?? null,
        effective_port: outcome.effectivePort ?? null,
    };

    try {
        await postJson(route('club.prints.store'), body);
    } catch {
        // The record is lost, not the print: nothing is printed again because of it.
    }
}
