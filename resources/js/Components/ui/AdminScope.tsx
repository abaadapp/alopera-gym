import { createContext, useContext, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

const AdminContext = createContext(false);

/**
 * The panel and the point of sale share one calm, neutral look: grey lines and canvas, near-black text,
 * 10px-radius buttons and fields, quiet tables, and one icon style — Lucide as it comes, as the reference's, sized by
 * the slot it sits in (18px in the menu, 16/18/20px in small/medium/large buttons, 20px in stat tiles, 14px in
 * badges). Everything inside this scope gets it — CSS through the `.admin-ui` class (app.css), components
 * through useAdmin(). The public website, the member portal, the sign-in pages and the marketing page sit outside it
 * and keep their own look.
 */
export default function AdminScope({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <AdminContext.Provider value={true}>
            <div className={cn('admin-ui', className)}>{children}</div>
        </AdminContext.Provider>
    );
}

/** Inside a control panel? (Portalled layers such as modals use it to carry the panel's look along.) */
export function useAdmin(): boolean {
    return useContext(AdminContext);
}
