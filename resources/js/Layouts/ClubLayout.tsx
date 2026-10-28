import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Clock, LogOut, Menu, X } from 'lucide-react';
import { Flash } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { useNavigation } from '@/lib/nav';

export default function ClubLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, tenant } = useShared();
    const { groups } = useNavigation();
    const [open, setOpen] = useState(false);

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
     * A compact menu: one link per section (its pages are tabs on the page), 40px rows two pixels apart,
     * quiet grey text, and for the current section a light grey rounded fill with a 3px bar in the club's
     * colour on its leading edge — no solid brand fill.
     */
    const row = 'relative flex h-10 w-full items-center gap-3 rounded-[10px] px-3 text-sm transition-colors duration-150';
    const idle = 'font-medium text-ink/70 hover:bg-ink/[0.045] hover:text-ink [&>svg]:text-ink/55';
    const current =
        'bg-[#f2f2f0] font-semibold text-ink [&>svg]:text-brand before:absolute before:-start-3 before:top-1/2 before:h-[18px] before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-brand';

    const sidebar = (
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
                        {group.sections.map((section) => (
                            <li key={section.label}>
                                <Link href={section.href} onClick={() => setOpen(false)} aria-current={section.active ? 'true' : undefined} className={cn(row, section.active ? current : idle)}>
                                    <section.icon className="size-[18px] shrink-0" />
                                    <span className="truncate">{section.label}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                ))}
            </ScrollIntoActive>
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
                        <aside className="absolute inset-y-0 start-0 w-72 max-w-[85vw] bg-white shadow-xl" role="dialog" aria-modal="true" aria-label="القائمة">
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

/** The menu scrolls on its own; when a page opens, its section is brought into view. */
function ScrollIntoActive({ className, children }: { className: string; children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        ref.current?.querySelector('[aria-current]')?.scrollIntoView({ block: 'nearest' });
    }, []);
    return <div ref={ref} className={className}>{children}</div>;
}
