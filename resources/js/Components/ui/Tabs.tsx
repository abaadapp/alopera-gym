import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * A row of tabs under a line: 14px medium labels, 16px apart, the current one underlined. A tab may carry a count (grey,
 * after the label) or a status dot in its badge's colour, so a row of state tabs reads like the badges under it.
 */
export default function Tabs<T extends string>({ tabs, value, onChange, className }: { tabs: { key: T; label: ReactNode; count?: number | null; dot?: string }[]; value: T; onChange: (key: T) => void; className?: string }) {
    return (
        <div role="tablist" className={cn('-mx-1 flex items-center gap-1 overflow-x-auto border-b border-line px-1', className)}>
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    role="tab"
                    aria-selected={value === tab.key}
                    onClick={() => onChange(tab.key)}
                    className={cn(
                        'relative -mb-px inline-flex items-center justify-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors [&_svg]:size-4 [&_svg]:shrink-0',
                        value === tab.key ? 'border-brand text-ink' : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                    )}
                >
                    {tab.dot && <span aria-hidden className="size-1.5 shrink-0 rounded-full" style={{ backgroundColor: tab.dot }} />}
                    {tab.label}
                    {tab.count != null && <span className="text-[12px] text-[#9ca3af] num">{tab.count}</span>}
                </button>
            ))}
        </div>
    );
}
