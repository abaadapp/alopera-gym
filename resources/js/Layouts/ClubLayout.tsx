import { useState, type ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3, Bell, CalendarDays, ClipboardList, Clock, CreditCard, Dumbbell, FileText, Globe, Fingerprint, LayoutDashboard,
    LogOut, Menu, MessageCircle, Megaphone, Package, Receipt, Settings, ShoppingBag, Sparkles, Tags, Truck, UserCog, Users,
    Wallet, X, ScanLine, Layers, ShieldCheck, Database, Building2, KeyRound, ScrollText, Newspaper, Mail, UserRoundCheck, HandCoins, Ticket, BadgePercent,
} from 'lucide-react';
import { Flash } from '@/Components/ui';
import { useCan, useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';

type Item = { label: string; route: string; icon: ReactNode; permission?: string; match?: string; roleKey?: string };
type Group = { label?: string; items: Item[] };

const ic = 'size-[18px]';

export const NAV: Group[] = [
    {
        items: [
            { label: 'لوحة التحكم', route: 'club.dashboard', icon: <LayoutDashboard className={ic} />, permission: 'dashboard.view' },
            { label: 'الاستقبال', route: 'club.reception', icon: <ScanLine className={ic} />, permission: 'checkin.perform' },
            { label: 'شاشة المدربة', route: 'club.trainer', icon: <UserRoundCheck className={ic} />, permission: 'classes.view', roleKey: 'trainer' },
        ],
    },
    {
        label: 'المشتركات',
        items: [
            { label: 'المشتركات', route: 'club.members.index', icon: <Users className={ic} />, permission: 'members.view', match: 'club.members.*' },
            { label: 'العضويات', route: 'club.memberships.index', icon: <Ticket className={ic} />, permission: 'memberships.view' },
            { label: 'الباقات والأسعار', route: 'club.plans.index', icon: <Layers className={ic} />, permission: 'plans.manage' },
            { label: 'الخصومات والعروض', route: 'club.coupons.index', icon: <BadgePercent className={ic} />, permission: 'plans.manage' },
        ],
    },
    {
        label: 'التدريب',
        items: [
            { label: 'الجدول الأسبوعي', route: 'club.schedule.index', icon: <CalendarDays className={ic} />, permission: 'classes.view' },
            { label: 'الحصص والحجوزات', route: 'club.sessions.index', icon: <ClipboardList className={ic} />, permission: 'classes.view', match: 'club.sessions.*' },
            { label: 'البرامج', route: 'club.programs.index', icon: <Dumbbell className={ic} />, permission: 'classes.manage' },
            { label: 'المدربات', route: 'club.trainers.index', icon: <Sparkles className={ic} />, permission: 'trainers.manage' },
            { label: 'التدريب الشخصي', route: 'club.pt.index', icon: <UserCog className={ic} />, permission: 'pt.manage' },
            { label: 'باقات التدريب الشخصي', route: 'club.pt-packages.index', icon: <Tags className={ic} />, permission: 'pt.manage' },
        ],
    },
    {
        label: 'الدخول والحضور',
        items: [
            { label: 'سجل الدخول', route: 'club.checkins.index', icon: <Clock className={ic} />, permission: 'checkin.view' },
            { label: 'أجهزة الدخول', route: 'club.devices.index', icon: <Fingerprint className={ic} />, permission: 'devices.manage' },
            { label: 'حضور الموظفات', route: 'club.staff-attendance.index', icon: <UserRoundCheck className={ic} />, permission: 'staff_attendance.view' },
        ],
    },
    {
        label: 'المالية',
        items: [
            { label: 'الفواتير', route: 'club.invoices.index', icon: <FileText className={ic} />, permission: 'invoices.view', match: 'club.invoices.*' },
            { label: 'الدفعات والإيصالات', route: 'club.payments.index', icon: <Receipt className={ic} />, permission: 'invoices.view' },
            { label: 'المستحقات', route: 'club.dues', icon: <HandCoins className={ic} />, permission: 'dues.view' },
            { label: 'المصروفات', route: 'club.expenses.index', icon: <Wallet className={ic} />, permission: 'expenses.manage' },
        ],
    },
    {
        label: 'المبيعات والمخزون',
        items: [
            { label: 'نقطة البيع', route: 'club.pos.index', icon: <ShoppingBag className={ic} />, permission: 'pos.sell' },
            { label: 'عمليات البيع', route: 'club.pos.sales', icon: <CreditCard className={ic} />, permission: 'pos.sell' },
            { label: 'المنتجات والمخزون', route: 'club.products.index', icon: <Package className={ic} />, permission: 'products.manage' },
            { label: 'الموردون', route: 'club.suppliers.index', icon: <Building2 className={ic} />, permission: 'suppliers.manage' },
            { label: 'المشتريات', route: 'club.purchases.index', icon: <Truck className={ic} />, permission: 'purchases.manage', match: 'club.purchases.*' },
        ],
    },
    {
        label: 'التقارير',
        items: [{ label: 'التقارير', route: 'club.reports.index', icon: <BarChart3 className={ic} />, permission: 'reports.view' }],
    },
    {
        label: 'واتساب',
        items: [
            { label: 'المحادثات', route: 'club.inbox.index', icon: <MessageCircle className={ic} />, permission: 'whatsapp.inbox', match: 'club.inbox.*' },
            { label: 'الحملات', route: 'club.campaigns.index', icon: <Megaphone className={ic} />, permission: 'campaigns.manage', match: 'club.campaigns.*' },
            { label: 'القوالب والأتمتة', route: 'club.templates.index', icon: <Bell className={ic} />, permission: 'templates.manage' },
            { label: 'سجل الرسائل', route: 'club.messages.index', icon: <ScrollText className={ic} />, permission: 'whatsapp.inbox' },
        ],
    },
    {
        label: 'الموقع الإلكتروني',
        items: [
            { label: 'محتوى الموقع', route: 'club.website.edit', icon: <Globe className={ic} />, permission: 'website.manage' },
            { label: 'الأخبار والعروض', route: 'club.website.posts.index', icon: <Newspaper className={ic} />, permission: 'website.manage' },
            { label: 'رسائل التواصل', route: 'club.website.messages', icon: <Mail className={ic} />, permission: 'website.manage' },
        ],
    },
    {
        label: 'الإعدادات',
        items: [
            { label: 'إعدادات النادي', route: 'club.settings.edit', icon: <Settings className={ic} />, permission: 'settings.manage' },
            { label: 'الفروع', route: 'club.branches.index', icon: <Building2 className={ic} />, permission: 'branches.manage' },
            { label: 'المستخدمون', route: 'club.users.index', icon: <Users className={ic} />, permission: 'users.manage' },
            { label: 'الأدوار والصلاحيات', route: 'club.roles.index', icon: <ShieldCheck className={ic} />, permission: 'roles.manage' },
            { label: 'الربط (الدفع وواتساب)', route: 'club.integrations.index', icon: <KeyRound className={ic} />, permission: 'integrations.manage' },
            { label: 'سجل العمليات', route: 'club.audit.index', icon: <ScrollText className={ic} />, permission: 'audit.view' },
            { label: 'النسخ الاحتياطي', route: 'club.backups.index', icon: <Database className={ic} />, permission: 'backups.manage' },
            { label: 'اشتراك المنصة', route: 'club.billing', icon: <Receipt className={ic} />, permission: 'billing.view' },
        ],
    },
];

export default function ClubLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, tenant } = useShared();
    const can = useCan();
    const [open, setOpen] = useState(false);

    const groups = NAV.map((g) => ({
        ...g,
        items: g.items.filter((i) => (!i.permission || can(i.permission)) && (!i.roleKey || auth?.user.role_key === i.roleKey || can('classes.manage'))),
    })).filter((g) => g.items.length > 0);

    const isActive = (item: Item) => route().current(item.match ?? item.route);

    const sidebar = (
        <nav className="flex h-full flex-col">
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
            <div className="flex-1 space-y-5 overflow-y-auto px-3 pb-6">
                {groups.map((group, gi) => (
                    <div key={gi}>
                        {group.label && <p className="mb-1.5 px-3 text-[11px] font-semibold tracking-wide text-muted/80">{group.label}</p>}
                        <ul className="space-y-0.5">
                            {group.items.map((item) => (
                                <li key={item.route}>
                                    <Link
                                        href={route(item.route)}
                                        onClick={() => setOpen(false)}
                                        className={cn(
                                            'flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition',
                                            isActive(item) ? 'bg-brand text-white shadow-sm shadow-brand/20' : 'text-ink/80 hover:bg-brand-50 hover:text-ink',
                                        )}
                                    >
                                        {item.icon}
                                        <span className="truncate">{item.label}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </nav>
    );

    return (
        <>
            <Head title={`${title} · ${tenant?.name ?? ''}`} />
            <div className="min-h-screen lg:ps-64">
                <aside className="fixed inset-y-0 start-0 z-30 hidden w-64 border-e border-line bg-white lg:block">{sidebar}</aside>

                {open && (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <div className="absolute inset-0 bg-ink/40" onClick={() => setOpen(false)} />
                        <aside className="absolute inset-y-0 start-0 w-72 bg-white shadow-xl">
                            <button className="absolute top-4 end-3 grid size-8 place-items-center rounded-lg hover:bg-brand-50" onClick={() => setOpen(false)} aria-label="إغلاق">
                                <X className="size-4" />
                            </button>
                            {sidebar}
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
