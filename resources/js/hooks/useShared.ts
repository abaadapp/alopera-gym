import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

export function useShared() {
    return usePage<SharedProps>().props;
}

/** UI hint only — every action is authorised again on the server. */
export function useCan() {
    const { auth } = useShared();
    const permissions = auth?.permissions ?? [];
    return (permission: string) => permissions.includes(permission);
}

export function useCurrency() {
    return useShared().tenant?.currency ?? 'OMR';
}

/** The branch a new record starts in: the one the top bar shows (the user's own when tied to one), else none. */
export function useBranchDefault(): string {
    const id = useShared().auth?.branch?.current?.id;
    return id ? String(id) : '';
}
