import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { AdminScope, Flash } from '@/Components/ui';
import { cn } from '@/lib/cn';

export type PanelLink = { label: string; href: string; icon: LucideIcon; active: boolean };
/** A run of links between two thin separators; the `footer` group sits at the bottom of the menu. */
export type PanelGroup = { links: PanelLink[]; footer?: boolean };

/*
 * A menu row: 36px high (8px above and below a 20px line), 10px-rounded, 12px between the 18px icon and the label,
 * #4b4b4b medium text that turns #111 on a faint grey on hover. The current section: a #f2f2f0 fill, #111 text at
 * weight 650, and a 3px × 18px bar on the row's leading edge, in the gutter outside it.
 */
const row = 'relative flex w-full items-center gap-3 rounded-[10px] px-3 py-2 text-sm leading-5 transition-colors duration-150 [&>svg]:size-[18px] [&>svg]:shrink-0';
const idle = 'font-medium text-[#4b4b4b] hover:bg-[rgba(17,17,17,0.045)] hover:text-[#111]';
const current = "bg-[#f2f2f0] font-[650] text-[#111] before:absolute before:-start-3 before:top-1/2 before:h-[18px] before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-[#111] before:content-['']";

/**
 * The frame of the gym's panel: a 256px sidebar — one link per section, groups split by thin
 * rules, the footer group (integrations, settings) pinned to the bottom, no dropdowns — that slides in as a drawer
 * on phones; a 64px top bar; and the page, up to 1600px wide with 16/24px gutters. Everything inside is in the
 * control-panel look (AdminScope).
 *
 * `topbar` draws the bar (search, quick icons, bell, user).
 */
export default function PanelShell({ identity, groups, topbar, children }: {
    identity: ReactNode;
    groups: PanelGroup[];
    topbar: (openMenu: () => void) => ReactNode;
    children: ReactNode;
}) {
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

    return (
        <AdminScope className="min-h-dvh bg-canvas">
            {open && <div className="fixed inset-0 z-30 bg-black/20 backdrop-blur-sm lg:hidden" onClick={() => setOpen(false)} aria-hidden />}

            <aside
                className={cn(
                    'fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-[#e8e8e8] bg-white transition-transform duration-300 lg:translate-x-0',
                    open ? 'translate-x-0' : 'max-lg:rtl:translate-x-full max-lg:ltr:-translate-x-full',
                )}
                role={open ? 'dialog' : undefined}
                aria-modal={open ? true : undefined}
                aria-label={open ? 'القائمة' : undefined}
            >
                {/* The club's mark and name, centred in a 64px head — who the user is sits in the top bar. */}
                <div className="flex h-16 shrink-0 items-center justify-center gap-2.5 px-5">{identity}</div>

                <ScrollIntoActive className="flex flex-1 flex-col overflow-y-auto overscroll-contain px-3 pb-4" label="القائمة الرئيسية">
                    {groups.map((group, gi) => (
                        <ul key={gi} className={cn('mb-1', gi > 0 && 'border-t border-[#e8e8e8] pt-2', group.footer && 'mt-auto')}>
                            {group.links.map((link) => (
                                <li key={link.label}>
                                    <Link href={link.href} onClick={() => setOpen(false)} aria-current={link.active ? 'true' : undefined} className={cn(row, link.active ? current : idle)}>
                                        <link.icon />
                                        <span className="truncate">{link.label}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ))}
                </ScrollIntoActive>
            </aside>

            <div className="lg:ms-64">
                {topbar(() => setOpen(true))}

                <main className="mx-auto w-full max-w-[1600px] p-4 lg:p-6">{children}</main>
            </div>
            <Flash />
        </AdminScope>
    );
}

/** The menu scrolls on its own; when a page opens, its section is brought into view. */
function ScrollIntoActive({ className, label, children }: { className: string; label: string; children: ReactNode }) {
    const ref = useRef<HTMLElement>(null);
    useEffect(() => {
        ref.current?.querySelector('[aria-current]')?.scrollIntoView({ block: 'nearest' });
    }, []);
    return <nav ref={ref} className={className} aria-label={label}>{children}</nav>;
}
