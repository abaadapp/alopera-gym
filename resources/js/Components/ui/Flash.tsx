import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Toaster, toast } from 'sonner';
import { useShared } from '@/hooks/useShared';
import type { SharedProps } from '@/types';
import { useAdmin } from './AdminScope';

type FlashBag = SharedProps['flash'];

// Every response with a message has its own id: the same text twice in a row
// ("تم الحجز." for two members) shows twice, while one response never shows twice.
let lastShown: string | null = null;

function showFlash(flash: FlashBag | null | undefined) {
    if (!flash?.id || flash.id === lastShown) return;
    lastShown = flash.id;
    if (flash.success) toast.success(flash.success);
    if (flash.error) toast.error(flash.error);
}

/** Shows the server's flash messages and the first validation error as toasts. */
export default function Flash() {
    const { flash, errors } = useShared();
    const admin = useAdmin();

    useEffect(() => {
        showFlash(flash);
        // Later responses arrive through the router event (first render only here).
        return router.on('success', (event) => showFlash((event.detail.page.props as Partial<SharedProps>).flash));
    }, []);

    useEffect(() => {
        const messages = Object.values(errors ?? {}).filter(Boolean) as string[];
        if (!messages.length) return;
        if (errors.error || errors.booking || errors.starts_at) {
            toast.error(messages[0]);
            return;
        }
        // A refusal the page shows nowhere (a key none of its fields renders: «items», «lines.2.account_id»…) would leave
        // the person with a form that silently did nothing: it is said here instead. Read once the page has drawn it.
        const frame = requestAnimationFrame(() => {
            const page = document.body.textContent ?? '';
            if (!messages.some((m) => page.includes(m))) toast.error(messages[0]);
        });
        return () => cancelAnimationFrame(frame);
    }, [errors]);

    // The panel's toasts rise from the bottom centre, above an on-screen keyboard; the public pages keep theirs on top.
    return admin ? (
        <Toaster position="bottom-center" dir="rtl" richColors offset={{ bottom: 'calc(24px + var(--kb, 0px))' }} mobileOffset={{ bottom: 'calc(16px + var(--kb, 0px))' }} toastOptions={{ style: { fontFamily: 'inherit' } }} />
    ) : (
        <Toaster position="top-center" dir="rtl" richColors closeButton toastOptions={{ style: { fontFamily: 'inherit' } }} />
    );
}
