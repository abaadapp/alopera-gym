import { Link } from '@inertiajs/react';
import { BellRing, Building2, ChevronLeft, Clock, Database, FileClock, Landmark, Plug, ScrollText, ShoppingBag, SlidersHorizontal, UserCog, type LucideIcon } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { PageHeader } from '@/Components/ui';
import { useCan, useShared } from '@/hooks/useShared';

type Entry = { title: string; description: string; route: string; permission: string | string[]; icon: LucideIcon; module?: 'sales' };

/** Every settings screen, grouped. A card shows only when the user may open it (the server checks again). */
const SECTIONS: { title: string; items: Entry[] }[] = [
    {
        title: 'النادي',
        items: [
            { title: 'إعدادات النادي', description: 'الاسم والشعار والألوان، قواعد الحجز والحضور، الفواتير والضريبة', route: 'club.settings.edit', permission: 'settings.manage', icon: SlidersHorizontal },
            { title: 'الفروع', description: 'فروع النادي وعناوينها وأرقام التواصل', route: 'club.branches.index', permission: 'branches.manage', icon: Building2 },
            { title: 'المستخدمون والصلاحيات', description: 'حسابات الدخول وأدوارها، وما يراه كل دور وما يستطيع تنفيذه', route: 'club.users.index', permission: ['users.manage', 'roles.manage'], icon: UserCog },
        ],
    },
    {
        title: 'الربط والإشعارات',
        items: [
            { title: 'الربط مع واتساب وPaymob', description: 'واتساب للأعمال وبوابة الدفع الإلكتروني للأعضاء', route: 'club.integrations.index', permission: 'integrations.manage', icon: Plug },
            { title: 'الإشعارات', description: 'تذكيرات انتهاء العضوية والمتابعة وإشعارات الحصص', route: 'club.settings.notifications', permission: 'settings.manage', icon: BellRing },
        ],
    },
    {
        title: 'المالية والرواتب',
        items: [
            { title: 'الإعدادات المالية', description: 'الحساب الذي تذهب إليه كل طريقة دفع: الصندوق والبنك والبطاقات وPaymob', route: 'club.settings.finance', permission: 'accounting.manage', icon: Landmark },
            { title: 'إعدادات الرواتب والحضور', description: 'ساعات العمل والساعة الإضافية وحساب صرف الرواتب ومهلة البصمة المكررة', route: 'club.settings.payroll', permission: 'settings.manage', icon: Clock },
        ],
    },
    {
        title: 'المبيعات والمستندات',
        items: [
            { title: 'إعدادات المبيعات والمتجر', description: 'متجر الموقع، والدفع عند الاستلام أو عبر Paymob، ومهلة الدفع الإلكتروني', route: 'club.settings.sales', permission: 'settings.manage', icon: ShoppingBag, module: 'sales' },
            { title: 'تنبيهات المستندات', description: 'متى يُنبَّه قبل انتهاء المستندات، وإرسال التنبيه عبر واتساب', route: 'club.settings.documents', permission: 'documents.manage', icon: FileClock },
        ],
    },
    {
        title: 'البيانات',
        items: [
            { title: 'النسخ الاحتياطي', description: 'نسخة كاملة من بيانات النادي، تنزيلها واستعادتها', route: 'club.backups.index', permission: 'backups.manage', icon: Database },
            { title: 'سجل العمليات', description: 'من فعل ماذا ومتى، في سجل لا يُعدّل', route: 'club.audit.index', permission: 'audit.view', icon: ScrollText },
        ],
    },
];

export default function SettingsIndex() {
    const can = useCan();
    const { modules } = useShared();
    const allowed = (p: string | string[]) => (Array.isArray(p) ? p.some(can) : can(p));
    const sections = SECTIONS.map((s) => ({ ...s, items: s.items.filter((e) => allowed(e.permission) && (!e.module || modules[e.module])) })).filter((s) => s.items.length > 0);

    return (
        <ClubLayout title="الإعدادات">
            <PageHeader title="الإعدادات" subtitle="هوية النادي والفريق والربط والبيانات — اختر القسم الذي تريد تعديله" />

            <div className="space-y-8">
                {sections.map((section) => (
                    <section key={section.title} aria-labelledby={`settings-${section.title}`}>
                        <h2 id={`settings-${section.title}`} className="mb-3 text-[13px] font-semibold text-muted">{section.title}</h2>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {section.items.map(({ title, description, route: name, icon: Icon }) => (
                                <Link
                                    key={name}
                                    // Users & roles share a card: it opens the users page, or roles for whoever only manages roles.
                                    href={route(name === 'club.users.index' && !can('users.manage') ? 'club.roles.index' : name)}
                                    className="group flex items-center gap-4 rounded-2xl border border-line bg-white p-5 shadow-[0_1px_2px_rgba(0,0,0,0.04)] transition hover:border-[#dcdcdc] hover:shadow-[0_8px_30px_-12px_rgba(17,17,17,0.14)] focus-visible:ring-4 focus-visible:ring-brand/15 focus-visible:outline-none"
                                >
                                    <span className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[#f2f2f0] text-ink transition-colors group-hover:bg-brand-50 group-hover:text-brand">
                                        <Icon className="size-5" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-[15px] font-semibold text-ink">{title}</span>
                                        <span className="mt-1 block text-[13px] leading-snug text-muted">{description}</span>
                                    </span>
                                    <ChevronLeft className="size-4 shrink-0 text-muted/50 transition group-hover:-translate-x-0.5 group-hover:text-brand" />
                                </Link>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </ClubLayout>
    );
}
