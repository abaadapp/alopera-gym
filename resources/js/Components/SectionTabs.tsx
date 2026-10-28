import { useEffect, useRef } from 'react';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { useSectionTabs, type NavTab } from '@/lib/nav';

/**
 * The section's pages as a row of tabs under the page header (rendered by PageHeader on every page that is a
 * tab of a section in lib/nav). Each tab is a real link, so an address opens it directly and back, forward and
 * reload work. One visible tab is not a bar: nothing is drawn. On phones the row scrolls sideways.
 */
export default function SectionTabs({ className }: { className?: string }) {
    const { tabs, active, query } = useSectionTabs();
    const ref = useRef<HTMLDivElement>(null);

    // On a narrow screen the current tab may sit past the edge: bring it into view.
    useEffect(() => {
        ref.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [active?.label]);

    if (tabs.length < 2) return null;

    // A view of the current route keeps the page's other filters (a report's period, say).
    const href = (tab: NavTab) => (tab.params && route().current(tab.route) ? route(tab.route, { ...Object.fromEntries(query), ...tab.params }) : route(tab.route, tab.params));

    return (
        <nav aria-label="صفحات القسم" className={cn('no-print', className)}>
            <div ref={ref} className="-mx-4 overflow-x-auto px-4 [scrollbar-width:none] lg:mx-0 lg:px-0 [&::-webkit-scrollbar]:hidden">
                <div className="flex min-w-max gap-1 border-b border-line">
                    {tabs.map((tab) => {
                        const current = tab === active;
                        return (
                            <Link
                                key={tab.label}
                                href={href(tab)}
                                aria-current={current ? 'page' : undefined}
                                className={cn(
                                    // -mb-px lays the tab's line over the row's, so the active one is not doubled.
                                    '-mb-px border-b-2 px-4 py-3 text-sm font-medium whitespace-nowrap transition-colors',
                                    // Colour, not weight, marks the current tab — the row does not shift.
                                    current ? 'border-brand text-ink' : 'border-transparent text-muted hover:border-line hover:text-ink',
                                )}
                            >
                                {tab.label}
                            </Link>
                        );
                    })}
                </div>
            </div>
        </nav>
    );
}
