import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Toaster, toast } from 'sonner';
import { useShared } from '@/hooks/useShared';
import type { SharedProps } from '@/types';

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

    useEffect(() => {
        showFlash(flash);
        // Later responses arrive through the router event (first render only here).
        return router.on('success', (event) => showFlash((event.detail.page.props as Partial<SharedProps>).flash));
    }, []);

    useEffect(() => {
        const first = errors && Object.values(errors)[0];
        if (first && (errors.error || errors.booking || errors.starts_at)) toast.error(first);
    }, [errors]);

    return <Toaster position="top-center" dir="rtl" richColors closeButton toastOptions={{ style: { fontFamily: 'inherit' } }} />;
}
