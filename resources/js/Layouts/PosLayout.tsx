import type { ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { LayoutDashboard, LogOut, Receipt, Store, UserRound } from 'lucide-react';
import { AdminScope, Button, Flash } from '@/Components/ui';
import { Dropdown, DropdownItem, DropdownLabel, DropdownSeparator } from '@/Components/ui/Dropdown';
import { useCan, useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';

type Device = { name: string; branch: string | null } | null;

const NAV = [
    { label: 'نقطة البيع', icon: Store, route: 'club.pos.index' },
    { label: 'إيصالات اليوم', icon: Receipt, route: 'club.pos.receipts' },
];

/**
 * The point of sale's own frame, full screen: a 64px white bar with the black store tile, the pill navigation, then
 * where the till sells (branch • device), the way back to the panel, and the user's menu. The selling screen fills the
 * window and scrolls inside its columns from tablets up (`fill`); the other screens, and phones, scroll as a page.
 */
export default function PosLayout({ title, device, branch, fill = false, children }: { title: string; device?: Device; branch?: string | null; fill?: boolean; children: ReactNode }) {
    const { auth, tenant } = useShared();
    const can = useCan();
    // Back to the panel: the dashboard, or reception, or — for a clerk who only sells — their account.
    const panel = can('dashboard.view') ? { href: route('club.dashboard'), label: 'لوحة النشاط' } : can('checkin.perform') ? { href: route('club.reception'), label: 'الاستقبال' } : { href: route('club.account.show'), label: 'حسابي' };
    const where = device ? `${device.branch ?? branch ?? ''} • ${device.name}` : branch;

    return (
        // Filling the window (the selling screen) from tablets up; on a phone the page scrolls, the basket under the products.
        <AdminScope className={cn('flex min-h-dvh flex-col bg-[#f7f8f9] pb-[env(safe-area-inset-bottom)]', fill && 'md:h-dvh md:overflow-hidden')}>
            <Head title={`${title} · ${tenant?.name ?? ''}`} />
            <header className="no-print sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-[#ececed] bg-white px-4">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[12px] bg-[#111] text-white">
                    <Store className="size-[18px]" />
                </span>
                <nav className="flex min-w-0 items-center gap-1 overflow-x-auto" aria-label="نقطة البيع">
                    {NAV.map((item) => {
                        const active = route().current(item.route);
                        return (
                            <Link key={item.route} href={route(item.route)} aria-current={active ? 'page' : undefined} className={cn('flex items-center gap-2 whitespace-nowrap rounded-full px-3.5 py-2 text-sm font-medium transition-colors', active ? 'bg-[#111] text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-[#111]')}>
                                <item.icon className="size-4" />
                                <span className="max-sm:sr-only">{item.label}</span>
                            </Link>
                        );
                    })}
                </nav>

                <div className="ms-auto flex shrink-0 items-center gap-1.5">
                    {where && (
                        <span className="me-1 hidden items-center gap-1.5 rounded-full bg-[#f7f7f5] px-3 py-1 text-[12px] text-[#4b4b4b] md:flex">
                            <Store className="size-3.5 text-gray-400" />
                            <span>{where}</span>
                        </span>
                    )}
                    <Button variant="ghost" size="sm" href={panel.href} icon={<LayoutDashboard />}>
                        <span className="hidden sm:inline">{panel.label}</span>
                    </Button>
                    <Dropdown
                        label="حسابي"
                        panelClassName="w-52"
                        trigger={() => (
                            <span className="flex items-center gap-2 rounded-full px-1.5 py-1 transition-colors hover:bg-gray-100">
                                <span className="grid size-8 place-items-center rounded-full bg-[#f2f2f0] text-[13px] font-semibold text-[#111]">{auth?.user.name?.[0] ?? '؟'}</span>
                                <span className="hidden text-start sm:block">
                                    <span className="block text-[13px] font-medium leading-tight text-[#111]">{auth?.user.name}</span>
                                    <span className="block text-[11px] leading-tight text-gray-400">{auth?.user.role}</span>
                                </span>
                            </span>
                        )}
                    >
                        {() => (
                            <>
                                <DropdownLabel>{auth?.user.email}</DropdownLabel>
                                <DropdownItem href={route('club.account.show')}><UserRound /> حسابي</DropdownItem>
                                <DropdownSeparator />
                                <DropdownItem destructive onClick={() => router.post(route('logout'))}><LogOut /> تسجيل الخروج</DropdownItem>
                            </>
                        )}
                    </Dropdown>
                </div>
            </header>
            <main className={cn('flex-1', fill && 'md:min-h-0')}>{children}</main>
            <Flash />
        </AdminScope>
    );
}
