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
/** A run of sections between two thin separators; the `footer` group sits at the bottom of the menu. */
type Group = { sections: Section[]; footer?: boolean };

const ic = 'size-[18px] shrink-0';

/** Settings screens live behind the settings hub; none of them appears in the main menu. */
const SETTINGS_PERMISSIONS = ['settings.manage', 'branches.manage', 'users.manage', 'roles.manage', 'integrations.manage', 'audit.view', 'backups.manage', 'billing.view', 'website.manage'];

export const NAV: Group[] = [
  { sections: [
    { label: 'لوحة التحكم', route: 'club.dashboard', icon: <LayoutDashboard className={ic} />, permission: 'dashboard.view' },
    { label: 'الاستقبال', route: 'club.reception', icon: <ScanLine className={ic} />, permission: 'checkin.perform' },
  ] },
  { sections: [
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
  ] },
  { sections: [
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
    { label: 'التقارير', route: 'club.reports.index', icon: <BarChart3 className={ic} />, permission: 'reports.view', match: 'club.reports.*' },
  ] },
  { sections: [
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
    {
        label: 'الموقع الإلكتروني',
        icon: <Globe className={ic} />,
        children: [
            { label: 'المحتوى', route: 'club.website.edit', permission: 'website.manage' },
            { label: 'الأخبار والعروض', route: 'club.website.posts.index', permission: 'website.manage', match: 'club.website.posts.*' },
            { label: 'رسائل التواصل', route: 'club.website.messages', permission: 'website.manage' },
        ],
    },
  ] },
  { footer: true, sections: [
    {
        label: 'الإعدادات',
        route: 'club.settings.index',
        icon: <Settings className={ic} />,
        permission: SETTINGS_PERMISSIONS,
        match: ['club.settings.*', 'club.branches.*', 'club.users.*', 'club.roles.*', 'club.integrations.*', 'club.audit.*', 'club.backups.*', 'club.billing'],
    },
  ] },
];

const patterns = (item: { route: string; match?: string | string[] }) => (item.match === undefined ? [item.route] : Array.isArray(item.match) ? item.match : [item.match]);
const isActive = (item: { route: string; match?: string | string[] }) => patterns(item).some((p) => route().current(p));

export default function ClubLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, tenant } = useShared();
    const can = useCan();
    const [open, setOpen] = useState(false);

    const allowed = (permission?: string | string[]) => !permission || (Array.isArray(permission) ? permission.some(can) : can(permission));
    const visible = (leaf: Leaf) => allowed(leaf.permission) && (!leaf.roleKey || auth?.user.role_key === leaf.roleKey || can('classes.manage'));

    const keep = (section: Section): Section[] => {
        if (!('children' in section)) return allowed(section.permission) ? [section] : [];
        const items = section.children.filter(visible);
        return items.length ? [{ ...section, children: items }] : [];
    };
    // A group none of whose sections this user may open disappears with its separator.
    const groups = NAV.map((g) => ({ ...g, sections: g.sections.flatMap(keep) })).filter((g) => g.sections.length > 0);
    const sections = groups.flatMap((g) => g.sections);

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

    /*
     * A compact menu: 40px rows two pixels apart, quiet grey text, a light grey
     * rounded fill for the current page and a 3px bar in the club's colour on its
     * leading edge — no solid brand fill.
     */
    const row = 'relative flex w-full items-center gap-3 rounded-[10px] px-3 text-sm transition-colors duration-150';
    const idle = 'font-medium text-ink/70 hover:bg-ink/[0.045] hover:text-ink';
    const current =
        'bg-[#f2f2f0] font-semibold text-ink before:absolute before:-start-3 before:top-1/2 before:h-[18px] before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-brand';

    const renderSection = (section: Section, key: string) => {
        if (!('children' in section)) {
            const active = isActive(section);
            return (
                <li key={section.label}>
                    <Link href={route(section.route)} onClick={() => setOpen(false)} aria-current={active ? 'page' : undefined} className={cn(row, 'h-10', active ? current : idle)}>
                        <span className={cn('contents', active ? '[&>svg]:text-brand' : '[&>svg]:text-ink/55')}>{section.icon}</span>
                        <span className="truncate">{section.label}</span>
                    </Link>
                </li>
            );
        }
        const isOpen = expanded.has(section.label);
        const holdsActive = section.children.some(isActive);
        return (
            <li key={section.label}>
                <button
                    type="button"
                    onClick={() => toggle(section.label)}
                    aria-expanded={isOpen}
                    aria-controls={key}
                    className={cn(row, 'h-10', holdsActive ? 'font-semibold text-ink hover:bg-ink/[0.045]' : idle)}
                >
                    <span className={cn('contents', holdsActive ? '[&>svg]:text-brand' : '[&>svg]:text-ink/55')}>{section.icon}</span>
                    <span className="flex-1 truncate text-start">{section.label}</span>
                    <ChevronDown className={cn('size-4 shrink-0 text-muted/80 transition-transform duration-200', isOpen && 'rotate-180')} />
                </button>
                {/* Closed sections stay in the page (inert) so they can animate open. */}
                <div id={key} inert={!isOpen} className={cn('grid transition-[grid-template-rows] duration-200', isOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                    {/* The clip reaches into the menu's padding, so a page's colour bar is not cut off. */}
                    <div className="-mx-3 min-h-0 overflow-hidden px-3">
                        {/* Pages sit under their section's label: 12px padding + 18px icon + 12px gap. */}
                        <ul className="space-y-0.5 pt-0.5 pb-1">
                            {section.children.map((leaf) => {
                                const active = isActive(leaf);
                                return (
                                    <li key={leaf.route}>
                                        <Link
                                            href={route(leaf.route)}
                                            onClick={() => setOpen(false)}
                                            aria-current={active ? 'page' : undefined}
                                            className={cn(row, 'h-9 ps-[2.625rem] text-[13px]', active ? current : idle)}
                                        >
                                            <span className="truncate">{leaf.label}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>
            </li>
        );
    };

    const sidebar = (id: string) => (
        <nav className="flex h-full flex-col" aria-label="القائمة الرئيسية">
            {/* The club's logo and name, one compact line — the page title carries the rest. */}
            <div className="flex h-16 shrink-0 items-center gap-2.5 px-4">
                {tenant?.logo_url ? (
                    <img src={tenant.logo_url} alt="" className="size-8 shrink-0 rounded-lg object-cover" />
                ) : (
                    <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-brand font-display text-sm text-white">{tenant?.name?.[0] ?? 'A'}</div>
                )}
                <div className="min-w-0 leading-tight">
                    <p className="truncate font-display text-sm font-semibold text-ink">{tenant?.name}</p>
                    <p className="truncate text-[11px] text-muted">{auth?.user.role}</p>
                </div>
            </div>
            <ScrollIntoActive className="flex flex-1 flex-col overflow-y-auto overscroll-contain px-3 pt-2 pb-3">
                {groups.map((group, gi) => (
                    <ul
                        key={gi}
                        className={cn(
                            'space-y-0.5',
                            // A thin rule between groups — no headings that add height.
                            gi > 0 && 'mt-2 border-t border-line pt-2',
                            group.footer && 'mt-auto',
                        )}
                    >
                        {group.sections.map((section, si) => renderSection(section, `${id}-section-${gi}-${si}`))}
                    </ul>
                ))}
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
