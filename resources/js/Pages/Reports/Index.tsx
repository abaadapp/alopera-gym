import { useMemo, useState } from 'react';
import {
    ArrowLeftRight, Banknote, BarChart3, Boxes, Briefcase, Building2, CalendarDays, ClipboardCheck, Clock, DoorOpen, Dumbbell, FileClock, HandCoins, History, Hourglass, Landmark,
    PackageCheck, PackageX, Search, ShoppingBag, ShoppingCart, Ticket, Truck, Users, Wallet, X, type LucideIcon,
} from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { PageHeader, ReportCard } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { fold } from '@/lib/format';

interface Report {
    key: string;
    category: string;
    title: string;
    desc: string;
    icon: string;
    href: string;
    /** The report's export address, or null when this user may not export. */
    export: string | null;
    /** What the card's export covers: the period the report opens with, or its state today. */
    scope: string;
}

/* The icon names the server gives (App\Modules\Reports\Services\ReportCatalog), mapped one by one. */
const ICONS: Record<string, LucideIcon> = {
    wallet: Wallet, landmark: Landmark, 'arrow-left-right': ArrowLeftRight, 'hand-coins': HandCoins, ticket: Ticket, hourglass: Hourglass,
    'calendar-days': CalendarDays, dumbbell: Dumbbell, 'shopping-bag': ShoppingBag, briefcase: Briefcase, banknote: Banknote, 'door-open': DoorOpen,
    clock: Clock, 'file-clock': FileClock, history: History, 'building-2': Building2,
    boxes: Boxes, 'package-x': PackageX, 'clipboard-check': ClipboardCheck, truck: Truck, 'shopping-cart': ShoppingCart, users: Users, 'package-check': PackageCheck,
};

/**
 * The reports page — the one place for the club's reports: a large search, the categories with how many reports each
 * holds, and the reports in groups of equal cards, three to a row on a wide screen. Each card opens its report or
 * exports it (Excel or CSV, for the period it opens with). Only the reports this user may open arrive here, and the
 * counts are of what arrived, so a category never promises more than it shows. The search reads the title and the
 * description, whatever form of alef, yaa or taa marbuta was typed.
 */
export default function ReportsIndex({ reports, categories }: { reports: Report[]; categories: Record<string, string> }) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string | null>(null);

    const matched = useMemo(() => {
        const q = fold(query);
        return q ? reports.filter((r) => fold(r.title).includes(q) || fold(r.desc).includes(q)) : reports;
    }, [reports, query]);
    const visible = category ? matched.filter((r) => r.category === category) : matched;

    const chips = Object.entries(categories)
        .map(([key, label]) => ({ key, label, count: matched.filter((r) => r.category === key).length }))
        .filter((c) => c.count > 0);
    const groups = Object.entries(categories)
        .map(([key, label]) => ({ key, label, items: visible.filter((r) => r.category === key) }))
        .filter((g) => g.items.length > 0);

    return (
        <ClubLayout title="التقارير">
            <PageHeader title="التقارير" subtitle="كل تقارير النادي في مكان واحد: افتح التقرير لتحليله بالفترة والفرع، أو صدّره مباشرة إلى Excel أو CSV." />

            <div className="relative mb-4">
                <Search className="pointer-events-none absolute start-4 top-1/2 size-[18px] -translate-y-1/2 text-[#9ca3af]" />
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="ابحث عن تقرير بالاسم أو الوصف"
                    aria-label="ابحث عن تقرير بالاسم أو الوصف"
                    className="h-[52px] w-full rounded-2xl border border-line bg-white pe-11 ps-12 text-[14px] text-[#111] outline-none transition-[border-color,box-shadow] placeholder:text-[#9ca3af] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] [&::-webkit-search-cancel-button]:appearance-none"
                />
                {query && (
                    <button type="button" onClick={() => setQuery('')} aria-label="مسح البحث" className="absolute end-3 top-1/2 -translate-y-1/2 rounded-full p-1.5 text-[#9ca3af] transition-colors hover:bg-[#f2f2f0] hover:text-[#111]">
                        <X className="size-4" />
                    </button>
                )}
            </div>

            <div className="mb-7 flex flex-wrap items-center gap-2" role="group" aria-label="تصنيفات التقارير">
                {[{ key: null as string | null, label: 'الكل', count: matched.length }, ...chips].map((c) => {
                    const active = category === c.key;
                    return (
                        <button
                            key={c.key ?? 'all'}
                            type="button"
                            onClick={() => setCategory(c.key)}
                            aria-pressed={active}
                            className={cn(
                                'flex h-9 items-center gap-2 rounded-full px-4 text-[13px] font-medium transition-colors',
                                active ? 'bg-[#111] text-white' : 'border border-line bg-white text-[#6b7280] hover:border-[#d4d4d4] hover:text-[#111]',
                            )}
                        >
                            {c.label}
                            <span className={cn('num text-[12px]', active ? 'text-white/60' : 'text-[#9ca3af]')}>{c.count}</span>
                        </button>
                    );
                })}
            </div>

            {groups.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-line bg-white py-16 text-center">
                    <BarChart3 className="mx-auto mb-3 size-6 text-[#9ca3af]" />
                    <p className="text-[14px] font-medium text-[#111]">{reports.length ? 'لا تقرير بهذا الاسم' : 'لا تقارير متاحة لحسابك'}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">{reports.length ? 'جرّب كلمة أخرى، أو اعرض الكل.' : 'تظهر هنا التقارير التي تسمح بها صلاحياتك.'}</p>
                </div>
            ) : (
                <div className="space-y-9">
                    {groups.map((g) => (
                        <section key={g.key} aria-labelledby={`reports-${g.key}`}>
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <h2 id={`reports-${g.key}`} className="flex items-center gap-2.5 text-[15px] font-bold text-[#111]">
                                    <span className="size-2 rounded-[3px] bg-[#111]" aria-hidden />
                                    {g.label}
                                </h2>
                                <span className="num flex size-7 items-center justify-center rounded-full border border-line bg-white text-[12px] text-[#9ca3af]">{g.items.length}</span>
                            </div>
                            <div className="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {g.items.map((r) => (
                                    <ReportCard
                                        key={r.key}
                                        href={r.href}
                                        icon={ICONS[r.icon] ?? BarChart3}
                                        title={r.title}
                                        description={r.desc}
                                        actions={<ExportMenu href={r.export} title={r.title} scope={r.scope} />}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </ClubLayout>
    );
}
