import { Link } from '@inertiajs/react';
import { BellRing, Building2, ChevronLeft, Database, Globe, KeyRound, Receipt, ScrollText, Settings, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { PageHeader } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';

type Entry = { title: string; description: string; route: string; permission: string; icon: LucideIcon };

/** Every settings screen, grouped. A card shows only when the user may open it (the server checks again). */
const SECTIONS: { title: string; items: Entry[] }[] = [
    {
        title: 'النادي',
        items: [
            { title: 'إعدادات النادي', description: 'الاسم والشعار والألوان، قواعد الحجز والحضور، الفواتير والضريبة', route: 'club.settings.edit', permission: 'settings.manage', icon: Settings },
            { title: 'الفروع', description: 'فروع النادي وعناوينها وأرقام التواصل', route: 'club.branches.index', permission: 'branches.manage', icon: Building2 },
            { title: 'إعدادات الموقع الإلكتروني', description: 'نصوص الموقع وصوره وبيانات التواصل والظهور في محركات البحث', route: 'club.website.edit', permission: 'website.manage', icon: Globe },
        ],
    },
    {
        title: 'الفريق والصلاحيات',
        items: [
            { title: 'المستخدمون', description: 'حسابات الموظفات وأدوارهن وتفعيل الدخول', route: 'club.users.index', permission: 'users.manage', icon: Users },
            { title: 'الأدوار والصلاحيات', description: 'ما يراه كل دور وما يستطيع تنفيذه', route: 'club.roles.index', permission: 'roles.manage', icon: ShieldCheck },
        ],
    },
    {
        title: 'الربط والإشعارات',
        items: [
            { title: 'الربط والتكاملات', description: 'بوابة الدفع الإلكتروني وواتساب للأعمال', route: 'club.integrations.index', permission: 'integrations.manage', icon: KeyRound },
            { title: 'الإشعارات والقوالب', description: 'تذكيرات انتهاء العضوية والمتابعة وإشعارات الحصص', route: 'club.settings.notifications', permission: 'settings.manage', icon: BellRing },
        ],
    },
    {
        title: 'البيانات والاشتراك',
        items: [
            { title: 'النسخ الاحتياطي', description: 'نسخة كاملة من بيانات النادي، تنزيلها واستعادتها', route: 'club.backups.index', permission: 'backups.manage', icon: Database },
            { title: 'سجل العمليات', description: 'من فعل ماذا ومتى، في سجل لا يُعدّل', route: 'club.audit.index', permission: 'audit.view', icon: ScrollText },
            { title: 'اشتراك المنصة', description: 'باقة النادي في المنصة وفواتيرها', route: 'club.billing', permission: 'billing.view', icon: Receipt },
        ],
    },
];

export default function SettingsIndex() {
    const can = useCan();
    const sections = SECTIONS.map((s) => ({ ...s, items: s.items.filter((e) => can(e.permission)) })).filter((s) => s.items.length > 0);

    return (
        <ClubLayout title="الإعدادات">
            <PageHeader title="الإعدادات" subtitle="هوية النادي والفريق والربط والبيانات — اختاري القسم الذي تريدين تعديله" />

            <div className="space-y-8">
                {sections.map((section) => (
                    <section key={section.title} aria-labelledby={`settings-${section.title}`}>
                        <h2 id={`settings-${section.title}`} className="mb-3 text-[13px] font-semibold text-muted">{section.title}</h2>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {section.items.map(({ title, description, route: name, icon: Icon }) => (
                                <Link
                                    key={name}
                                    href={route(name)}
                                    className="group flex items-center gap-4 rounded-2xl border border-line bg-white p-5 shadow-[0_1px_2px_rgba(42,31,45,0.04)] transition hover:border-brand-200 hover:bg-brand-50/40 hover:shadow-md hover:shadow-brand/5 focus-visible:ring-4 focus-visible:ring-brand/15 focus-visible:outline-none"
                                >
                                    <span className="grid size-13 shrink-0 place-items-center rounded-[14px] bg-brand-50 text-brand transition-colors group-hover:bg-brand group-hover:text-white">
                                        <Icon className="size-6" />
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
