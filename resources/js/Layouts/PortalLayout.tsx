import type { ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, FileText, Home, LogOut, RefreshCw, UserRound } from 'lucide-react';
import SiteLayout, { type SiteInfo } from './SiteLayout';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';

const tabs = [
    { label: 'الرئيسية', route: 'portal.dashboard', icon: Home },
    { label: 'حجز الحصص', route: 'portal.classes', icon: CalendarCheck },
    { label: 'التجديد', route: 'portal.renew', icon: RefreshCw },
    { label: 'الفواتير', route: 'portal.invoices', icon: FileText },
    { label: 'ملفي', route: 'portal.profile', icon: UserRound },
];

export default function PortalLayout({ site, title, children }: { site: SiteInfo; title: string; children: ReactNode }) {
    const { member } = useShared();

    return (
        <SiteLayout site={site} seoTitle={title}>
            <div className="mx-auto max-w-5xl px-4 py-8">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted">أهلًا بك</p>
                        <h1 className="text-2xl font-semibold">{member?.name}</h1>
                    </div>
                    <button onClick={() => router.post(route('portal.logout'))} className="inline-flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm hover:bg-brand-50">
                        <LogOut className="size-4" /> خروج
                    </button>
                </div>
                <nav className="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-line bg-white p-1">
                    {tabs.map(({ label, route: r, icon: Icon }) => (
                        <Link key={r} href={route(r)} className={cn('flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-3 py-2 text-sm', route().current(r) ? 'bg-brand text-white' : 'text-ink/80 hover:bg-brand-50')}>
                            <Icon className="size-4" /> {label}
                        </Link>
                    ))}
                </nav>
                {children}
            </div>
        </SiteLayout>
    );
}
