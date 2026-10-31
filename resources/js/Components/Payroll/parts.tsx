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
export function FormSection({ icon: Icon, title, hint, children, locked }: { icon: LucideIcon; title: string; hint: string; children: ReactNode; locked?: ReactNode }) {
    return (
        <div className="card p-6">
            <div className="mb-5 flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-ink"><Icon className="size-[18px]" /></span>
                <div className="min-w-0">
                    <h3 className="font-bold text-ink">{title}</h3>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{hint}</p>
                </div>
            </div>
            {locked ? <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">{locked}</p> : children}
        </div>
    );
}

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
