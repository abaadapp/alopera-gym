import { BellRing, Building2, Clock, Database, FileClock, Landmark, ScrollText, ShoppingBag, SlidersHorizontal, UserCog, type LucideIcon } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { NavCard, PageHeader } from '@/Components/ui';
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
        // Paymob, WhatsApp and the access devices are set up in their own section, «التطبيقات التكاملية».
        title: 'الإشعارات',
        items: [
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
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {section.items.map(({ title, description, route: name, icon }) => (
                                // Users & roles share a card: it opens the users page, or roles for whoever only manages roles.
                                <NavCard key={name} href={route(name === 'club.users.index' && !can('users.manage') ? 'club.roles.index' : name)} icon={icon} title={title} description={description} />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </ClubLayout>
    );
}
