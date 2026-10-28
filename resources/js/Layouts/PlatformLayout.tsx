import type { ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Building2, FileText, LayoutDashboard, Layers, LogOut, ScrollText, Webhook } from 'lucide-react';
import { Flash } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';

const items = [
    { label: 'الرئيسية', route: 'platform.dashboard', icon: LayoutDashboard },
    { label: 'الأندية', route: 'platform.tenants.index', match: 'platform.tenants.*', icon: Building2 },
    { label: 'باقات المنصة', route: 'platform.plans.index', icon: Layers },
    { label: 'فواتير المنصة', route: 'platform.invoices.index', icon: FileText },
    { label: 'سجل بوابات الدفع', route: 'platform.webhooks', icon: Webhook },
    { label: 'سجل التدقيق', route: 'platform.audit', icon: ScrollText },
];

/** Super Admin (platform owner) — deliberately visually distinct from a club panel. */
export default function PlatformLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, appName } = useShared();

    return (
        <>
            <Head title={`${title} · المنصة`} />
            <div className="min-h-screen bg-[#f6f5f8]" style={{ ['--brand' as string]: '#3b2a5a' }}>
                <header className="bg-[#231a33] text-white">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 lg:px-8">
                        <div className="flex items-center gap-3">
                            <div className="grid size-9 place-items-center rounded-xl bg-white/10 font-display">A</div>
                            <div>
                                <p className="font-display text-sm font-semibold">{appName} — إدارة المنصة</p>
                                <p className="text-xs text-white/60">{auth?.user.name}</p>
                            </div>
                        </div>
                        <button onClick={() => router.post(route('logout'))} className="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-white/80 hover:bg-white/10">
                            <LogOut className="size-4" /> خروج
                        </button>
                    </div>
                    <nav className="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 lg:px-8">
                        {items.map(({ label, route: r, match, icon: Icon }) => (
                            <Link key={r} href={route(r)} className={cn('flex items-center gap-2 whitespace-nowrap rounded-t-xl px-4 py-2.5 text-sm', route().current(match ?? r) ? 'bg-[#f6f5f8] text-ink' : 'text-white/75 hover:text-white')}>
                                <Icon className="size-4" /> {label}
                            </Link>
                        ))}
                    </nav>
                </header>
                <main className="mx-auto max-w-7xl px-4 py-6 lg:px-8">{children}</main>
            </div>
            <Flash />
        </>
    );
}
