import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Info, LoaderCircle, TriangleAlert } from 'lucide-react';
import { Toaster, toast } from 'sonner';
import { useShared } from '@/hooks/useShared';
import type { SharedProps } from '@/types';
import { useAdmin } from './AdminScope';

type FlashBag = SharedProps['flash'];

// Inside a control panel the toasts carry the panel's own icons instead of the library's filled ones.
const panelIcons = {
    success: <CircleCheck className="size-[18px]" />,
    error: <CircleAlert className="size-[18px]" />,
    warning: <TriangleAlert className="size-[18px]" />,
    info: <Info className="size-[18px]" />,
    loading: <LoaderCircle className="size-[18px] animate-spin" />,
};

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
        const first = errors && Object.values(errors)[0];
        if (first && (errors.error || errors.booking || errors.starts_at)) toast.error(first);
    }, [errors]);

    return <Toaster position="top-center" dir="rtl" richColors closeButton icons={admin ? panelIcons : undefined} toastOptions={{ style: { fontFamily: 'inherit' } }} />;
}
