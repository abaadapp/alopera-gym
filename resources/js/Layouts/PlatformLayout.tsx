import { useEffect, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Building2, FileText, Layers, LayoutGrid, LogOut, ScrollText, Webhook } from 'lucide-react';
import PanelShell from '@/Components/PanelShell';
import { useShared } from '@/hooks/useShared';

/** The platform owner's menu: the overview | clubs and their subscriptions | the logs. */
const groups = [
    [{ label: 'الرئيسية', route: 'platform.dashboard', icon: LayoutGrid }],
    [
        { label: 'الأندية', route: 'platform.tenants.index', match: 'platform.tenants.*', icon: Building2 },
        { label: 'باقات المنصة', route: 'platform.plans.index', icon: Layers },
        { label: 'فواتير المنصة', route: 'platform.invoices.index', icon: FileText },
    ],
    [
        { label: 'سجل بوابات الدفع', route: 'platform.webhooks', icon: Webhook },
        { label: 'سجل التدقيق', route: 'platform.audit', icon: ScrollText },
    ],
];

/** Super Admin (platform owner): the control-panel frame, told apart from a club panel by its own accent and name. */
export default function PlatformLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, appName } = useShared();

    // Set on <html> (not a wrapper) so portalled modals get the platform colour too.
    useEffect(() => {
        document.documentElement.style.setProperty('--brand', '#3b2a5a');
    }, []);

    return (
        <>
            <Head title={`${title} · المنصة`} />
            <PanelShell
                title={title}
                groups={groups.map((links) => ({ links: links.map(({ label, route: name, match, icon }) => ({ label, icon, href: route(name), active: route().current(match ?? name) })) }))}
                identity={
                    <>
                        <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-brand font-display text-sm text-white">A</div>
                        <div className="min-w-0 leading-tight">
                            <p className="truncate font-display text-sm font-semibold text-ink">{appName}</p>
                            <p className="truncate text-[11px] text-muted">إدارة المنصة</p>
                        </div>
                    </>
                }
                actions={
                    <>
                        <span className="hidden text-sm text-ink md:inline">{auth?.user.name}</span>
                        <button onClick={() => router.post(route('logout'))} className="grid size-10 place-items-center rounded-[10px] text-muted hover:bg-ink/[0.045] hover:text-ink" title="تسجيل الخروج">
                            <LogOut className="size-[18px]" />
                        </button>
                    </>
                }
            >
                {children}
            </PanelShell>
        </>
    );
}
