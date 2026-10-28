import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export default function Tabs<T extends string>({ tabs, value, onChange, className }: { tabs: { key: T; label: ReactNode; count?: number | null }[]; value: T; onChange: (key: T) => void; className?: string }) {
    return (
        <div className={cn('-mx-1 flex gap-1 overflow-x-auto border-b border-line px-1', className)}>
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    onClick={() => onChange(tab.key)}
                    className={cn(
                        'relative -mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                        value === tab.key ? 'border-brand text-ink' : 'border-transparent text-muted hover:text-ink',
                    )}
                >
                    {tab.label}
                    {tab.count != null && <span className="rounded-full bg-[#f2f2f0] px-1.5 text-xs text-[#4b4b4b] num">{tab.count}</span>}
                </button>
            ))}
        </div>
    );
}
