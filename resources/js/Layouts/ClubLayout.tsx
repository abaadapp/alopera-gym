import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3, ChevronDown, Clock, DoorOpen, Dumbbell, Globe, LayoutDashboard, LogOut, Menu, MessageCircle, ScanLine, Settings, Ticket, Users, Wallet, X,
} from 'lucide-react';
import { Flash } from '@/Components/ui';
import { useCan, useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';

/** A page link. `permission` may list several (any of them is enough); `match` lists route patterns that count as this page. */
type Leaf = { label: string; route: string; permission?: string | string[]; match?: string | string[]; roleKey?: string };
/** A main-menu entry: a page of its own, or a section that opens to one level of pages. */
type Section = { label: string; icon: ReactNode } & ({ route: string; permission?: string | string[]; match?: string | string[] } | { children: Leaf[] });

const ic = 'size-[18px] shrink-0';

/** Settings screens live behind the settings hub; none of them appears in the main menu. */
const SETTINGS_PERMISSIONS = ['settings.manage', 'branches.manage', 'users.manage', 'roles.manage', 'integrations.manage', 'audit.view', 'backups.manage', 'billing.view', 'website.manage'];

export const NAV: Section[] = [
    { label: 'لوحة التحكم', route: 'club.dashboard', icon: <LayoutDashboard className={ic} />, permission: 'dashboard.view' },
    { label: 'الاستقبال', route: 'club.reception', icon: <ScanLine className={ic} />, permission: 'checkin.perform' },
    { label: 'المشتركات', route: 'club.members.index', icon: <Users className={ic} />, permission: 'members.view', match: 'club.members.*' },
    {
        label: 'العضويات والباقات',
        icon: <Ticket className={ic} />,
        children: [
            { label: 'العضويات', route: 'club.memberships.index', permission: 'memberships.view', match: 'club.memberships.*' },
            { label: 'الباقات والأسعار', route: 'club.plans.index', permission: 'plans.manage', match: 'club.plans.*' },
            { label: 'الخصومات والعروض', route: 'club.coupons.index', permission: 'plans.manage', match: 'club.coupons.*' },
        ],
    },
    {
        label: 'الحصص والتدريب',
        icon: <Dumbbell className={ic} />,
        children: [
            { label: 'شاشة المدربة', route: 'club.trainer', permission: 'classes.view', roleKey: 'trainer' },
            { label: 'الجدول الأسبوعي', route: 'club.schedule.index', permission: 'classes.view', match: 'club.schedule.*' },
            { label: 'الحصص والحجوزات', route: 'club.sessions.index', permission: 'classes.view', match: ['club.sessions.*', 'club.bookings.*'] },
            { label: 'البرامج', route: 'club.programs.index', permission: 'classes.manage', match: 'club.programs.*' },
            { label: 'المدربات', route: 'club.trainers.index', permission: 'trainers.manage', match: 'club.trainers.*' },
            { label: 'التدريب الشخصي', route: 'club.pt.index', permission: 'pt.manage', match: 'club.pt.*' },
            { label: 'باقات التدريب الشخصي', route: 'club.pt-packages.index', permission: 'pt.manage', match: 'club.pt-packages.*' },
        ],
    },
    {
        label: 'الدخول والحضور',
        icon: <DoorOpen className={ic} />,
        children: [
            { label: 'سجل الدخول', route: 'club.checkins.index', permission: 'checkin.view', match: 'club.checkins.*' },
            { label: 'أجهزة الدخول', route: 'club.devices.index', permission: 'devices.manage', match: 'club.devices.*' },
            { label: 'حضور الموظفات', route: 'club.staff-attendance.index', permission: 'staff_attendance.view', match: 'club.staff-attendance.*' },
        ],
    },
    {
        label: 'المالية',
        icon: <Wallet className={ic} />,
        children: [
            { label: 'الفواتير', route: 'club.invoices.index', permission: 'invoices.view', match: ['club.invoices.*', 'club.credit-notes.*'] },
            { label: 'المدفوعات والإيصالات', route: 'club.payments.index', permission: 'invoices.view', match: 'club.payments.*' },
            { label: 'المستحقات', route: 'club.dues', permission: 'dues.view' },
            { label: 'المصروفات', route: 'club.expenses.index', permission: 'expenses.manage', match: 'club.expenses.*' },
        ],
    },
    {
        label: 'واتساب',
        icon: <MessageCircle className={ic} />,
        children: [
            { label: 'المحادثات', route: 'club.inbox.index', permission: 'whatsapp.inbox', match: 'club.inbox.*' },
            { label: 'الحملات', route: 'club.campaigns.index', permission: 'campaigns.manage', match: 'club.campaigns.*' },
            { label: 'القوالب', route: 'club.templates.index', permission: 'templates.manage', match: 'club.templates.*' },
            { label: 'الأتمتة', route: 'club.automation.index', permission: 'templates.manage' },
            { label: 'سجل الرسائل', route: 'club.messages.index', permission: 'whatsapp.inbox' },
        ],
    },
    { label: 'التقارير', route: 'club.reports.index', icon: <BarChart3 className={ic} />, permission: 'reports.view', match: 'club.reports.*' },
    {
        label: 'الموقع الإلكتروني',
        icon: <Globe className={ic} />,
        children: [
            { label: 'المحتوى', route: 'club.website.edit', permission: 'website.manage' },
            { label: 'الأخبار والعروض', route: 'club.website.posts.index', permission: 'website.manage', match: 'club.website.posts.*' },
            { label: 'رسائل التواصل', route: 'club.website.messages', permission: 'website.manage' },
        ],
    },
    {
        label: 'الإعدادات',
        route: 'club.settings.index',
        icon: <Settings className={ic} />,
        permission: SETTINGS_PERMISSIONS,
        match: ['club.settings.*', 'club.branches.*', 'club.users.*', 'club.roles.*', 'club.integrations.*', 'club.audit.*', 'club.backups.*', 'club.billing'],
    },
];

const patterns = (item: { route: string; match?: string | string[] }) => (item.match === undefined ? [item.route] : Array.isArray(item.match) ? item.match : [item.match]);
const isActive = (item: { route: string; match?: string | string[] }) => patterns(item).some((p) => route().current(p));

export default function ClubLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, tenant } = useShared();
    const can = useCan();
    const [open, setOpen] = useState(false);

    const allowed = (permission?: string | string[]) => !permission || (Array.isArray(permission) ? permission.some(can) : can(permission));
    const visible = (leaf: Leaf) => allowed(leaf.permission) && (!leaf.roleKey || auth?.user.role_key === leaf.roleKey || can('classes.manage'));

    const sections = NAV.flatMap((section): Section[] => {
        if (!('children' in section)) return allowed(section.permission) ? [section] : [];
        const items = section.children.filter(visible);
        return items.length ? [{ ...section, children: items }] : [];
    });

    // The section holding the current page starts open; the others open on demand.
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set(sections.filter((s) => 'children' in s && s.children.some(isActive)).map((s) => s.label)));
    const toggle = (label: string) => setExpanded((prev) => {
        const next = new Set(prev);
        if (next.has(label)) next.delete(label);
        else next.add(label);
        return next;
    });

    // Phones: Esc closes the drawer, and the page behind it does not scroll.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open]);

    const linkClass = (active: boolean) =>
        cn('flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition', active ? 'bg-brand font-medium text-white shadow-sm shadow-brand/20' : 'text-ink/80 hover:bg-brand-50 hover:text-ink');

    const sidebar = (id: string) => (
        <nav className="flex h-full flex-col" aria-label="القائمة الرئيسية">
            <div className="flex items-center gap-3 px-5 py-5">
                {tenant?.logo_url ? (
                    <img src={tenant.logo_url} alt="" className="size-10 rounded-xl object-cover" />
                ) : (
                    <div className="grid size-10 place-items-center rounded-xl bg-brand font-display text-lg text-white">{tenant?.name?.[0] ?? 'A'}</div>
                )}
                <div className="min-w-0">
                    <p className="truncate font-display text-sm font-semibold text-ink">{tenant?.name}</p>
                    <p className="truncate text-xs text-muted">{auth?.user.role}</p>
                </div>
            </div>
            <ScrollIntoActive className="flex-1 overflow-y-auto overscroll-contain px-3 pb-6">
                <ul className="space-y-1">
                    {sections.map((section, i) => {
                        if (!('children' in section)) {
                            const active = isActive(section);
                            return (
                                <li key={section.label}>
                                    <Link href={route(section.route)} onClick={() => setOpen(false)} aria-current={active ? 'page' : undefined} className={linkClass(active)}>
                                        {section.icon}
                                        <span className="truncate">{section.label}</span>
                                    </Link>
                                </li>
                            );
                        }
                        const isOpen = expanded.has(section.label);
                        const holdsActive = section.children.some(isActive);
                        const panel = `${id}-section-${i}`;
                        return (
                            <li key={section.label}>
                                <button
                                    type="button"
                                    onClick={() => toggle(section.label)}
                                    aria-expanded={isOpen}
                                    aria-controls={panel}
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm transition',
                                        holdsActive ? 'font-semibold text-brand-700' : 'text-ink/80 hover:bg-brand-50 hover:text-ink',
                                        holdsActive && !isOpen && 'bg-brand-50',
                                    )}
                                >
                                    {section.icon}
                                    <span className="flex-1 truncate text-start">{section.label}</span>
                                    <ChevronDown className={cn('size-4 shrink-0 text-muted transition-transform duration-200', isOpen && 'rotate-180')} />
                                </button>
                                {/* Closed sections stay in the page (inert) so they can animate open. */}
                                <div id={panel} inert={!isOpen} className={cn('grid transition-[grid-template-rows] duration-200', isOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                                    <div className="min-h-0 overflow-hidden">
                                        <ul className="ms-[1.15rem] mt-0.5 mb-1 space-y-0.5 border-s border-line ps-3">
                                            {section.children.map((leaf) => {
                                                const active = isActive(leaf);
                                                return (
                                                    <li key={leaf.route}>
                                                        <Link
                                                            href={route(leaf.route)}
                                                            onClick={() => setOpen(false)}
                                                            aria-current={active ? 'page' : undefined}
                                                            className={cn(
                                                                'block truncate rounded-lg px-3 py-1.5 text-[13px] transition',
                                                                active ? 'bg-brand font-medium text-white shadow-sm shadow-brand/20' : 'text-ink/70 hover:bg-brand-50 hover:text-ink',
                                                            )}
                                                        >
                                                            {leaf.label}
                                                        </Link>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </ScrollIntoActive>
        </nav>
    );

    return (
        <>
            <Head title={`${title} · ${tenant?.name ?? ''}`} />
            <div className="min-h-screen lg:ps-64">
                <aside className="fixed inset-y-0 start-0 z-30 hidden w-64 border-e border-line bg-white lg:block">{sidebar('side')}</aside>

                {open && (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <div className="absolute inset-0 bg-ink/40" onClick={() => setOpen(false)} />
                        <aside className="absolute inset-y-0 start-0 w-72 max-w-[85vw] bg-white shadow-xl" role="dialog" aria-modal="true" aria-label="القائمة">
                            <button className="absolute top-4 end-3 grid size-8 place-items-center rounded-lg hover:bg-brand-50" onClick={() => setOpen(false)} aria-label="إغلاق">
                                <X className="size-4" />
                            </button>
                            {sidebar('drawer')}
                        </aside>
                    </div>
                )}

                <header className="no-print sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-line bg-white/85 px-4 backdrop-blur lg:px-8">
                    <div className="flex items-center gap-2">
                        <button className="grid size-10 place-items-center rounded-xl hover:bg-brand-50 lg:hidden" onClick={() => setOpen(true)} aria-label="القائمة">
                            <Menu className="size-5" />
                        </button>
                        <h2 className="truncate text-sm font-medium text-muted">{title}</h2>
                    </div>
                    <div className="flex items-center gap-2">
                        {tenant?.status === 'trial' && <span className="hidden rounded-full bg-sky-50 px-3 py-1 text-xs text-sky-700 sm:inline">فترة تجريبية حتى {tenant.trial_ends_at}</span>}
                        <button
                            onClick={() => router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true })}
                            className="hidden items-center gap-1.5 rounded-xl border border-line px-3 py-1.5 text-xs text-ink hover:bg-brand-50 sm:inline-flex"
                            title="تسجيل حضور/انصراف"
                        >
                            <Clock className="size-3.5" /> حضور/انصراف
                        </button>
                        <span className="hidden text-sm text-ink md:inline">{auth?.user.name}</span>
                        <button onClick={() => router.post(route('logout'))} className="grid size-10 place-items-center rounded-xl text-muted hover:bg-brand-50 hover:text-ink" title="تسجيل الخروج">
                            <LogOut className="size-4" />
                        </button>
                    </div>
                </header>

                <main className="mx-auto max-w-7xl px-4 py-6 lg:px-8">{children}</main>
            </div>
            <Flash />
        </>
    );
}

/** The menu scrolls on its own; when a page opens, its link is brought into view. */
function ScrollIntoActive({ className, children }: { className: string; children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        ref.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, []);
    return <div ref={ref} className={className}>{children}</div>;
}
