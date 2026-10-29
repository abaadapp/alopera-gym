import type { Config, RouteParams } from 'ziggy-js';

export interface StaffUser {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_key: string | null;
    is_platform_admin: boolean;
    /** The user's branch, and how many active branches the club has (top bar branch label). */
    branch: string | null;
    branches: number;
}

/** One entry of the top bar bell: a single alert (dismissable) or a count of things waiting in a screen. */
export interface StaffNotification {
    key: string;
    kind: 'document' | 'count';
    count: number;
    text: string;
    time: string | null;
    url: string;
    dismiss: string | null;
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
    auth: { user: StaffUser; permissions: string[] } | null;
    tenant: TenantInfo | null;
    /** Optional modules switched on for this installation (config gym.modules). */
    modules: { sales: boolean; inventory: boolean };
    member: { id: number; name: string; member_no: string } | null;
    /** The club panel's bell; null outside the club panel. */
    notifications: { count: number; items: StaffNotification[] } | null;
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
