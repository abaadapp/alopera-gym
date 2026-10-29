import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Menu, X } from 'lucide-react';
import { AdminScope, Flash } from '@/Components/ui';
import { cn } from '@/lib/cn';

export type PanelLink = { label: string; href: string; icon: LucideIcon; active: boolean };
/** A run of links between two thin separators; the `footer` group sits at the bottom of the menu. */
export type PanelGroup = { links: PanelLink[]; footer?: boolean };

/**
 * The frame of a control panel (club and platform): a compact sidebar — one link per section, groups split by
 * thin rules, no dropdowns — that becomes a drawer on phones, a top bar with the page title and the panel's
 * actions, and the page. Everything inside is in the control-panel look (AdminScope).
 */
export default function PanelShell({ title, identity, groups, actions, children }: { title: string; identity: ReactNode; groups: PanelGroup[]; actions: ReactNode; children: ReactNode }) {
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
     * 40px rows two pixels apart, quiet grey text, and for the current section a light grey rounded fill with a
     * 3px bar in the accent colour on its leading edge — no solid fill.
     */
    const row = 'relative flex h-10 w-full items-center gap-3 rounded-[10px] px-3 text-sm transition-colors duration-150';
    const idle = 'font-medium text-ink/70 hover:bg-ink/[0.045] hover:text-ink [&>svg]:text-ink/55';
    const current =
        'bg-[#f2f2f0] font-semibold text-ink [&>svg]:text-brand before:absolute before:-start-3 before:top-1/2 before:h-[18px] before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-brand';

    const sidebar = (
        <nav className="flex h-full flex-col" aria-label="القائمة الرئيسية">
            {/* Who this panel belongs to, in one compact line — the page title carries the rest. */}
            <div className="flex h-16 shrink-0 items-center gap-2.5 px-4">{identity}</div>
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
                        {group.links.map((link) => (
                            <li key={link.label}>
                                <Link href={link.href} onClick={() => setOpen(false)} aria-current={link.active ? 'true' : undefined} className={cn(row, link.active ? current : idle)}>
                                    <link.icon className="size-[18px] shrink-0" />
                                    <span className="truncate">{link.label}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                ))}
            </ScrollIntoActive>
        </nav>
    );

    return (
        <AdminScope className="min-h-screen bg-canvas lg:ps-64">
            <aside className="fixed inset-y-0 start-0 z-30 hidden w-64 border-e border-line bg-white lg:block">{sidebar}</aside>

            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/20 backdrop-blur-sm" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 start-0 w-72 max-w-[85vw] bg-white shadow-xl" role="dialog" aria-modal="true" aria-label="القائمة">
                        <button className="absolute top-4 end-3 grid size-8 place-items-center rounded-lg text-muted hover:bg-[#f2f2f0] hover:text-ink" onClick={() => setOpen(false)} aria-label="إغلاق">
                            <X className="size-4" />
                        </button>
                        {sidebar}
                    </aside>
                </div>
            )}

            <header className="no-print sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-line bg-white/85 px-4 backdrop-blur lg:px-8">
                <div className="flex min-w-0 items-center gap-2">
                    <button className="grid size-10 shrink-0 place-items-center rounded-[10px] hover:bg-ink/[0.045] lg:hidden" onClick={() => setOpen(true)} aria-label="القائمة">
                        <Menu className="size-[18px]" />
                    </button>
                    <h2 className="truncate text-sm font-medium text-muted">{title}</h2>
                </div>
                <div className="flex items-center gap-2">{actions}</div>
            </header>

            <main className="mx-auto max-w-7xl px-4 py-6 lg:px-8">{children}</main>
            <Flash />
        </AdminScope>
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
