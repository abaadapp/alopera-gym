import type { Config, RouteParams } from 'ziggy-js';

export interface StaffUser {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_key: string | null;
    is_platform_admin: boolean;
}

/** The branch the panel shows: the current one (null = all), whether the user is tied to it, the choices. */
export interface BranchState {
    current: { id: number; name: string } | null;
    locked: boolean;
    options: { id: number; name: string }[];
}

/** One notification of the bell / the notifications page (App\Http\Controllers\Club\NotificationController::row). */
export interface StaffNotification {
    id: number;
    kind: 'document' | 'order' | 'payment' | 'membership' | 'integration' | 'contact' | 'inbox';
    kind_label: string;
    text: string;
    read: boolean;
    time: string;
    at: string;
}

export interface TenantInfo {
    id: number;
    name: string;
    slug: string;
    status: string;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
    currency: string;
    phone: string | null;
    trial_ends_at: string | null;
}

export interface SharedProps {
    appName: string;
    locale: string;
    dir: 'rtl' | 'ltr';
    auth: { user: StaffUser; permissions: string[]; branch: BranchState | null } | null;
    tenant: TenantInfo | null;
    /** Optional modules switched on for this installation (config gym.modules). */
    modules: { sales: boolean; inventory: boolean };
    member: { id: number; name: string; member_no: string } | null;
    /** The club panel's bell; null outside the club panel. */
    notifications: { unread: number; items: StaffNotification[] } | null;
    flash: { success: string | null; error: string | null; token: string | null; id: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface Option {
    id: number;
    name: string;
}

declare global {
    function route(name?: string, params?: RouteParams<string> | unknown, absolute?: boolean, config?: Config): string & {
        current: (name?: string, params?: unknown) => boolean;
    };
}
