import { useCallback, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/cn';

/**
 * A row of tabs over a line: 14px medium labels, 16px apart, the current one underlined. A tab may carry a count (grey,
 * after the label) or a status dot in its badge's colour, so a row of state tabs reads like the badges under it.
 *
 * The line is drawn under the row (an inset shadow), not as a border the tabs reach over, so the row never overflows
 * by a pixel and never shows a scrollbar for nothing. A row longer than its box (a member's file on a narrow card)
 * still scrolls — by finger, trackpad or wheel — with no grey bar under it: round arrows appear at the end that hides
 * tabs, and the current tab is brought into view. `dense` packs short labels (the periods) closer on a phone.
 */
export default function Tabs<T extends string>({ tabs, value, onChange, className, trailing, dense = false }: { tabs: { key: T; label: ReactNode; count?: number | null; dot?: string }[]; value: T; onChange: (key: T) => void; className?: string; trailing?: ReactNode; dense?: boolean }) {
    const row = useRef<HTMLDivElement>(null);
    const [more, setMore] = useState({ start: false, end: false });

    // Which ends hide tabs. Right-to-left rows scroll from 0 towards negative numbers.
    const measure = useCallback(() => {
        const el = row.current;
        if (!el) return;
        const max = el.scrollWidth - el.clientWidth;
        const at = Math.abs(el.scrollLeft);
        setMore((prev) => {
            const next = { start: max > 1 && at > 1, end: max > 1 && at < max - 1 };
            return prev.start === next.start && prev.end === next.end ? prev : next;
        });
    }, []);

    useLayoutEffect(() => {
        const el = row.current;
        if (!el) return;
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        el.addEventListener('scroll', measure, { passive: true });
        return () => {
            observer.disconnect();
            el.removeEventListener('scroll', measure);
        };
    }, [measure, tabs.length]);

    useLayoutEffect(() => {
        const el = row.current;
        const current = el?.querySelector<HTMLElement>('[aria-selected="true"]');
        if (!el || !current || el.scrollWidth <= el.clientWidth) return;
        const box = el.getBoundingClientRect();
        const tab = current.getBoundingClientRect();
        if (tab.left < box.left || tab.right > box.right) el.scrollBy({ left: tab.left < box.left ? tab.left - box.left - 32 : tab.right - box.right + 32 });
    }, [value]);

    const scroll = (towardsEnd: boolean) => {
        const el = row.current;
        if (!el) return;
        const rtl = getComputedStyle(el).direction === 'rtl';
        el.scrollBy({ left: (towardsEnd !== rtl ? 1 : -1) * el.clientWidth * 0.7, behavior: 'smooth' });
    };
    const arrow = 'absolute top-1/2 z-10 grid size-7 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-[#4b4b4b] shadow-[0_1px_4px_rgba(0,0,0,0.08)] transition-colors hover:text-ink [&_svg]:size-4';

    return (
        // min-w-0 / max-w-full: inside a row of filters the tabs shrink to the room there is (and scroll), never widen the page.
        <div className={cn('relative flex min-w-0 max-w-full items-center shadow-[inset_0_-1px_0_var(--color-line,#e8e8e8)]', className)}>
            <div className="relative min-w-0 flex-1">
                {more.start && (
                    <button type="button" tabIndex={-1} onClick={() => scroll(false)} className={cn(arrow, 'start-0')} aria-label="التبويبات السابقة">
                        <ChevronRight className="rtl:rotate-0 ltr:rotate-180" />
                    </button>
                )}
                <div ref={row} role="tablist" className={cn('no-scrollbar flex items-center overflow-x-auto overflow-y-hidden', dense ? 'sm:gap-1' : 'gap-1')}>
                    {tabs.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={value === tab.key}
                            onClick={() => onChange(tab.key)}
                            className={cn(
                                'relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap border-b-2 py-3 text-sm font-medium transition-colors [&_svg]:size-4 [&_svg]:shrink-0',
                                dense ? 'px-2.5 sm:px-4' : 'px-4',
                                value === tab.key ? 'border-brand text-ink' : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                            )}
                        >
                            {tab.dot && <span aria-hidden className="size-1.5 shrink-0 rounded-full" style={{ backgroundColor: tab.dot }} />}
                            {tab.label}
                            {tab.count != null && <span className="text-[12px] text-[#9ca3af] num">{tab.count}</span>}
                        </button>
                    ))}
                </div>
                {more.end && (
                    <button type="button" tabIndex={-1} onClick={() => scroll(true)} className={cn(arrow, 'end-0')} aria-label="التبويبات التالية">
                        <ChevronLeft className="rtl:rotate-0 ltr:rotate-180" />
                    </button>
                )}
            </div>
            {/* Whatever sits at the far end of the row (a date range beside period tabs). */}
            {trailing && <div className="ms-auto shrink-0 ps-4">{trailing}</div>}
        </div>
    );
}
