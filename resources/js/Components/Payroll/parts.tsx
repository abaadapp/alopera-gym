import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';

/** A person's initials in a soft circle (no photos are kept for staff). */
export function Initials({ name, size = 'md' }: { name: string; size?: 'md' | 'lg' }) {
    const letters = name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('');

    return (
        <span className={cn('flex shrink-0 items-center justify-center rounded-full bg-brand-50 font-bold text-brand-700', size === 'lg' ? 'size-24 text-[28px] ring-4 ring-brand-50/60' : 'size-9 text-[13px]')}>
            {letters}
        </span>
    );
}

/** One section of the employee form: an icon, a title and what it covers, then its fields. */
export { FormSection } from '@/Components/ui';

/** Label/value lines in a profile card. */
export function FactList({ facts }: { facts: { label: string; value: ReactNode; ltr?: boolean }[] }) {
    return (
        <ul className="space-y-3 text-sm">
            {facts.map((f) => (
                <li key={f.label} className="flex items-center justify-between gap-3">
                    <span className="text-[#6b7280]">{f.label}</span>
                    <span className="min-w-0 truncate text-ink" dir={f.ltr ? 'ltr' : undefined}>{f.value}</span>
                </li>
            ))}
        </ul>
    );
}
