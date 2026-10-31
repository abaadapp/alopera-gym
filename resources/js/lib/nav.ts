import { usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { BarChart3, Blocks, Boxes, Briefcase, ClipboardList, DoorOpen, Dumbbell, FileText, FolderOpen, Globe, LayoutGrid, MessageCircle, Package, ScanLine, Settings, ShoppingBag, Ticket, Users, Wallet } from 'lucide-react';
import { useCan, useShared } from '@/hooks/useShared';

/**
 * The club panel's navigation, in one place. The sidebar shows one link per section (no dropdowns); a
 * section's pages appear as tabs under the page header (SectionTabs). A section with one page has no tab bar.
 */

/** A page of a section. `permission` may list several (any one is enough); `match` lists the route patterns
 *  of its detail pages, which keep the section lit; `params` marks a tab that is a view of a shared route;
 *  `multiBranch` shows a tab only in a club with more than one open branch. */
export type NavTab = { label: string; route: string; params?: Record<string, string>; permission?: string | string[]; match?: string | string[]; roleKey?: string; multiBranch?: boolean };
export type NavSection = { label: string; icon: LucideIcon; tabs: NavTab[]; match?: string[] };
/** A run of sections between two thin separators; the `footer` group sits at the bottom of the menu. */
export type NavGroup = { sections: NavSection[]; footer?: boolean };

/** Each report's own permission (ReportCatalog::PERMISSIONS): holding any one of them shows the reports page. */
const REPORT_PERMISSIONS = ['reports.view', 'reports.financial', 'accounting.view', 'dues.view', 'sales.view', 'memberships.view', 'checkin.view', 'staff_attendance.view', 'payroll.reports', 'documents.view'];

/** Settings screens live behind the settings hub; none of them appears in the main menu. */
const SETTINGS_PERMISSIONS = ['settings.manage', 'branches.manage', 'users.manage', 'roles.manage', 'audit.view', 'backups.manage', 'accounting.manage', 'documents.manage', 'pos_devices.manage', 'papers.manage'];

/*
 * The groups follow the reference panel's rhythm, split by thin rules: the day (dashboard, reception); the members
 * and what they hold; selling (products, sales, customer invoices, purchases); money and people (finance, payroll,
 * business documents); stock; reports; WhatsApp; the website; and, at the foot, integrations and settings.
 */
export const NAV: NavGroup[] = [
    {
        sections: [
            { label: 'لوحة التحكم', icon: LayoutGrid, tabs: [{ label: 'لوحة التحكم', route: 'club.dashboard', permission: 'dashboard.view' }] },
            { label: 'الاستقبال', icon: ScanLine, tabs: [{ label: 'الاستقبال', route: 'club.reception', permission: 'checkin.perform' }] },
        ],
    },
    {
        sections: [
            { label: 'الأعضاء', icon: Users, tabs: [{ label: 'الأعضاء', route: 'club.members.index', permission: 'members.view', match: 'club.members.*' }] },
            {
                label: 'العضويات والباقات',
                icon: Ticket,
                tabs: [
                    { label: 'العضويات', route: 'club.memberships.index', permission: 'memberships.view', match: 'club.memberships.*' },
                    { label: 'الباقات والأسعار', route: 'club.plans.index', permission: 'plans.manage', match: 'club.plans.*' },
                    { label: 'الخصومات والعروض', route: 'club.coupons.index', permission: 'plans.manage', match: 'club.coupons.*' },
                ],
            },
            {
                label: 'الحصص والتدريب',
                icon: Dumbbell,
                tabs: [
                    { label: 'لوحة المدرب', route: 'club.trainer', permission: 'classes.view', roleKey: 'trainer', match: 'club.trainer.*' },
                    { label: 'الجدول الأسبوعي', route: 'club.schedule.index', permission: 'classes.view', match: 'club.schedule.*' },
                    { label: 'الحصص والحجوزات', route: 'club.sessions.index', permission: 'classes.view', match: ['club.sessions.*', 'club.bookings.*'] },
                    { label: 'البرامج', route: 'club.programs.index', permission: 'classes.manage', match: 'club.programs.*' },
                    { label: 'فريق التدريب', route: 'club.trainers.index', permission: 'trainers.manage', match: 'club.trainers.*' },
                    { label: 'التدريب الشخصي', route: 'club.pt.index', permission: 'pt.manage', match: 'club.pt.*' },
                    { label: 'باقات التدريب الشخصي', route: 'club.pt-packages.index', permission: 'pt.manage', match: 'club.pt-packages.*' },
                ],
            },
            {
                label: 'دخول الأعضاء والحضور',
                icon: DoorOpen,
                tabs: [
                    { label: 'سجل الدخول', route: 'club.checkins.index', permission: 'checkin.view', match: 'club.checkins.*' },
                ],
            },
        ],
    },
    {
        sections: [
            {
                label: 'المنتجات',
                icon: Package,
                // The one place products are created and changed, with their categories and add-ons. The point of sale,
                // the website shop, orders, invoices and reports use them but do not manage them.
                tabs: [
                    { label: 'المنتجات', route: 'club.products.index', permission: 'products.manage', match: 'club.products.*' },
                    { label: 'التصنيفات', route: 'club.product-categories.index', permission: 'products.manage', match: 'club.product-categories.*' },
                    { label: 'الإضافات', route: 'club.product-addons.index', permission: 'products.manage', match: 'club.product-addons.*' },
                ],
            },
            {
                label: 'المبيعات',
                icon: ShoppingBag,
                // Selling and its records only: products are managed in their own section, reports on the reports page.
                tabs: [
                    { label: 'ملخص المبيعات', route: 'club.sales.summary', permission: 'sales.view' },
                    { label: 'نقطة البيع', route: 'club.pos.index', permission: 'pos.sell' },
                    { label: 'عمليات البيع', route: 'club.sales.index', permission: 'sales.view' },
                    { label: 'طلبات الموقع الإلكتروني', route: 'club.sales.orders.index', permission: 'orders.manage' },
                ],
            },
            {
                // One entry of its own, not a tab of finance: one place to issue, read and collect a customer's invoice.
                label: 'فواتير العملاء',
                icon: FileText,
                tabs: [{ label: 'فواتير العملاء', route: 'club.invoices.index', permission: 'invoices.view', match: ['club.invoices.*', 'club.credit-notes.*'] }],
            },
            {
                label: 'المشتريات',
                icon: ClipboardList,
                tabs: [
                    { label: 'قائمة المشتريات', route: 'club.purchases.register', permission: 'purchases.view' },
                    { label: 'سندات الموردين', route: 'club.purchases.bills', permission: 'purchases.view', match: 'club.purchases.bills.*' },
                    { label: 'أوامر الشراء', route: 'club.purchases.index', permission: 'purchases.view', match: ['club.purchases.show', 'club.purchases.create', 'club.purchases.edit', 'club.purchases.print'] },
                    { label: 'الموردون', route: 'club.purchases.suppliers', permission: 'suppliers.view', match: 'club.purchases.suppliers.*' },
                ],
            },
        ],
    },
    {
        sections: [
            {
                label: 'المالية',
                icon: Wallet,
                tabs: [
                    { label: 'الملخص المالي', route: 'club.finance.summary', permission: 'accounting.view' },
                    { label: 'المدفوعات والتحصيل', route: 'club.payments.index', permission: 'invoices.view', match: 'club.payments.*' },
                    { label: 'المستحقات', route: 'club.dues', permission: 'dues.view' },
                    { label: 'المصروفات', route: 'club.expenses.index', permission: 'expenses.manage', match: 'club.expenses.*' },
                    { label: 'الحسابات البنكية', route: 'club.finance.banks', permission: 'accounting.view' },
                    { label: 'القيود اليومية', route: 'club.finance.journal', permission: 'accounting.view' },
                    { label: 'شجرة الحسابات', route: 'club.finance.accounts', permission: 'accounting.view' },
                ],
            },
            {
                label: 'الرواتب والموظفون',
                icon: Briefcase,
                tabs: [
                    // Staff attendance opens from the employees page (and each employee's file); it keeps that tab lit.
                    { label: 'الموظفون', route: 'club.employees.index', permission: 'employees.view', match: ['club.employees.*', 'club.staff-attendance.*'] },
                    { label: 'مسيرات الرواتب', route: 'club.payroll.index', permission: ['payroll.prepare', 'payroll.edit', 'payroll.approve', 'payroll.pay', 'payroll.payslips', 'payroll.reports'], match: 'club.payroll.print' },
                    { label: 'صرف الرواتب', route: 'club.payroll.payouts', permission: ['payroll.pay', 'payroll.payslips', 'payroll.reports'] },
                    { label: 'المسميات الوظيفية', route: 'club.job-titles.index', permission: 'employees.view', match: 'club.job-titles.*' },
                ],
            },
            {
                label: 'المستندات',
                icon: FolderOpen,
                // One list page with views (the `view` query parameter), the renewal log and the document types.
                tabs: [
                    { label: 'جميع المستندات', route: 'club.business-documents.index', params: { view: 'all' }, permission: 'documents.view' },
                    { label: 'قريبة الانتهاء', route: 'club.business-documents.index', params: { view: 'expiring' }, permission: 'documents.view' },
                    { label: 'منتهية', route: 'club.business-documents.index', params: { view: 'expired' }, permission: 'documents.view' },
                    { label: 'سجل التجديدات', route: 'club.business-documents.renewals', permission: 'documents.view' },
                    { label: 'أنواع المستندات', route: 'club.document-types.index', permission: 'documents.manage' },
                ],
                match: ['club.business-documents.*', 'club.document-types.*'],
            },
        ],
    },
    {
        sections: [
            {
                label: 'المخزون',
                icon: Boxes,
                // Stock per branch and its documents; exports and stock reports are on the reports page.
                tabs: [
                    { label: 'المخزون', route: 'club.inventory.index', permission: 'inventory.view' },
                    { label: 'الجرد الفعلي', route: 'club.inventory.counts', permission: 'inventory.view', match: 'club.inventory.counts.*' },
                    { label: 'سجل المخزون', route: 'club.inventory.movements', permission: 'inventory.view' },
                    { label: 'إشعارات استلام البضاعة', route: 'club.inventory.receipts', permission: 'purchases.view', match: 'club.inventory.receipts.*' },
                    { label: 'نقل المخزون بين الفروع', route: 'club.inventory.transfers', permission: 'inventory.view', match: 'club.inventory.transfers.*', multiBranch: true },
                    { label: 'طلبات تزويد الفروع', route: 'club.inventory.supply', permission: 'inventory.view', match: 'club.inventory.supply.*', multiBranch: true },
                ],
            },
        ],
    },
    {
        sections: [
            {
                label: 'التقارير',
                icon: BarChart3,
                // The one place for reports: every report in categories; each card opens its page (club.reports.show) or
                // exports it. The sections keep their working screens and have no report tabs of their own.
                // Any one report's permission opens the page (App\Modules\Reports\Services\ReportCatalog::PERMISSIONS).
                tabs: [{ label: 'التقارير', route: 'club.reports.index', permission: REPORT_PERMISSIONS, match: 'club.reports.*' }],
            },
        ],
    },
    {
        sections: [
            {
                label: 'محادثات واتساب',
                icon: MessageCircle,
                tabs: [
                    { label: 'المحادثات', route: 'club.inbox.index', permission: 'whatsapp.inbox', match: 'club.inbox.*' },
                    { label: 'الحملات', route: 'club.campaigns.index', permission: 'campaigns.manage', match: 'club.campaigns.*' },
                    { label: 'القوالب', route: 'club.templates.index', permission: 'templates.manage', match: 'club.templates.*' },
                    { label: 'الأتمتة', route: 'club.automation.index', permission: 'templates.manage' },
                    { label: 'سجل الرسائل', route: 'club.messages.index', permission: 'whatsapp.inbox' },
                ],
            },
        ],
    },
    {
        sections: [
            {
                label: 'الموقع الإلكتروني',
                icon: Globe,
                // The owner's, or whoever the owner grants website.manage to by name (no role holds it by default).
                tabs: [
                    { label: 'المحتوى', route: 'club.website.edit', permission: 'website.manage' },
                    { label: 'أقسام الموقع', route: 'club.website.sections', permission: 'website.manage' },
                    { label: 'الهوية والألوان', route: 'club.website.branding', permission: 'website.manage' },
                    { label: 'الأخبار والعروض', route: 'club.website.posts.index', permission: 'website.manage', match: 'club.website.posts.*' },
                    { label: 'رسائل التواصل', route: 'club.website.messages', permission: 'website.manage' },
                ],
            },
        ],
    },
    {
        footer: true,
        sections: [
            {
                // One page of three cards (Paymob, access devices, WhatsApp Business); each card opens its own settings.
                label: 'التطبيقات التكاملية',
                icon: Blocks,
                tabs: [{ label: 'التطبيقات التكاملية', route: 'club.integrations.index', permission: ['integrations.manage', 'devices.manage'] }],
                match: ['club.integrations.*', 'club.devices.*'],
            },
            {
                label: 'الإعدادات',
                icon: Settings,
                tabs: [{ label: 'الإعدادات', route: 'club.settings.index', permission: SETTINGS_PERMISSIONS }],
                match: ['club.settings.*', 'club.branches.*', 'club.users.*', 'club.roles.*', 'club.audit.*', 'club.backups.*'],
            },
        ],
    },
];

/** Pages that are not sections of the menu but sit side by side as tabs (inside settings). */
export const PAGE_GROUPS: NavTab[][] = [
    [
        { label: 'المستخدمون', route: 'club.users.index', permission: 'users.manage' },
        { label: 'الأدوار والصلاحيات', route: 'club.roles.index', permission: 'roles.manage' },
    ],
];

const list = (value?: string | string[]) => (value === undefined ? [] : Array.isArray(value) ? value : [value]);

/** The query string of the current page (tabs that are views of one route are told apart by it). */
function useQuery(): URLSearchParams {
    const { url } = usePage();
    return new URLSearchParams(url.split('?')[1] ?? '');
}

/**
 * The menu as this user may see it: tabs they lack the permission for are dropped, and a section or group with
 * nothing left disappears (with its separator). Each section links to its landing tab and knows if it is current.
 */
export function useNavigation() {
    const { auth } = useShared();
    const can = useCan();
    const query = useQuery();
    const roleKey = auth?.user.role_key;

    const allowed = (permission?: string | string[]) => !permission || list(permission).some(can);
    // The trainer screen is for trainers (and whoever manages classes); a trainer lands on it.
    const visible = (tab: NavTab) => allowed(tab.permission) && (!tab.roleKey || roleKey === tab.roleKey || can('classes.manage')) && (!tab.multiBranch || Boolean(auth?.branch?.multi));
    const onTab = (tab: NavTab) => route().current(tab.route) && Object.entries(tab.params ?? {}).every(([key, value]) => query.get(key) === value);

    const groups = NAV.map((group) => ({
        ...group,
        sections: group.sections.flatMap((section) => {
            const tabs = section.tabs.filter(visible);
            if (tabs.length === 0) return [];
            const landing = tabs.find((tab) => !tab.roleKey || tab.roleKey === roleKey) ?? tabs[0];
            const patterns = [...tabs.flatMap((tab) => [tab.route, ...list(tab.match)]), ...(section.match ?? [])];
            return [{ ...section, tabs, href: route(landing.route, landing.params), active: patterns.some((p) => route().current(p)) }];
        }),
    })).filter((group) => group.sections.length > 0);

    return { groups, query, onTab, visible };
}

/**
 * The tab bar of the current page: the visible tabs of the section one of whose tabs this page is, and the
 * active one. Detail pages (an invoice, a session) are not tabs and get no bar. A view-tab route opened without
 * its parameter shows its first visible view.
 */
export function useSectionTabs(): { tabs: NavTab[]; active: NavTab | undefined; query: URLSearchParams } {
    const { groups, query, onTab, visible } = useNavigation();
    const section = groups.flatMap((group) => group.sections).find((s) => s.tabs.some((tab) => route().current(tab.route)));
    const tabs = section?.tabs ?? PAGE_GROUPS.map((g) => g.filter(visible)).find((g) => g.some((tab) => route().current(tab.route))) ?? [];
    return { tabs, active: tabs.find(onTab) ?? tabs.find((tab) => route().current(tab.route)), query };
}
