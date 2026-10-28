import { useEffect } from 'react';
import { Toaster, toast } from 'sonner';
import { useShared } from '@/hooks/useShared';

/** Shows the server's flash messages and the first validation error as toasts. */
export default function Flash() {
    const { flash, errors } = useShared();

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    useEffect(() => {
        const first = errors && Object.values(errors)[0];
        if (first && (errors.error || errors.booking || errors.starts_at)) toast.error(first);
    }, [errors]);

    return <Toaster position="top-center" dir="rtl" richColors closeButton toastOptions={{ style: { fontFamily: 'inherit' } }} />;
}
