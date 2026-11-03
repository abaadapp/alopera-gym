import { useState } from 'react';
import { router } from '@inertiajs/react';

type Method = 'post' | 'put' | 'patch' | 'delete';

/**
 * A request sent from a dialog (a deletion, a stop, a revoke): busy while it is on its way, the first error kept for the
 * dialog to show inside itself, and `done` (closing it) on success only — a refusal leaves the dialog open with its
 * reason, never closed with the reason lost.
 */
export default function useDialogRequest() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const send = (method: Method, url: string, done: () => void, data: Record<string, unknown> = {}) => {
        if (busy) return;
        router.visit(url, {
            method,
            data: data as Record<string, string>,
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                setError(undefined);
            },
            onSuccess: () => done(),
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onFinish: () => setBusy(false),
        });
    };

    return { busy, error, send, clear: () => setError(undefined) };
}
