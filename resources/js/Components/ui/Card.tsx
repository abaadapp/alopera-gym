import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Card({ children, className, padded = true }: { children: ReactNode; className?: string; padded?: boolean }) {
    return <div className={cn('card', padded && 'p-5', className)}>{children}</div>;
}

export function CardHeader({ title, subtitle, actions, className }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; className?: string }) {
    return (
        <div className={cn('mb-4 flex flex-wrap items-start justify-between gap-3', className)}>
            <div>
                <h3 className="text-base font-semibold text-ink">{title}</h3>
                {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export function PageHeader({ title, subtitle, actions, back }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; back?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                {back}
                <h1 className="truncate text-2xl font-semibold text-ink">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

const tones = {
    brand: 'bg-brand-50 text-brand-700',
    green: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
    gray: 'bg-stone-100 text-stone-600',
    blue: 'bg-sky-50 text-sky-700',
    violet: 'bg-violet-50 text-violet-700',
};

export function Badge({ children, tone = 'gray', className }: { children: ReactNode; tone?: keyof typeof tones; className?: string }) {
    return <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone], className)}>{children}</span>;
}

export function StatCard({ label, value, hint, icon, tone = 'brand', href }: { label: ReactNode; value: ReactNode; hint?: ReactNode; icon?: ReactNode; tone?: keyof typeof tones; href?: string }) {
    const body = (
        <div className="card flex items-start gap-4 p-5 transition hover:border-brand-200">
            {icon && <div className={cn('grid size-11 shrink-0 place-items-center rounded-xl', tones[tone])}>{icon}</div>}
            <div className="min-w-0">
                <p className="text-sm text-muted">{label}</p>
                <p className="mt-1 text-2xl font-semibold text-ink num">{value}</p>
                {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
            </div>
        </div>
    );
    return href ? <a href={href}>{body}</a> : body;
}

export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: ReactNode; description?: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            {icon && <div className="mb-3 grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand">{icon}</div>}
            <p className="font-semibold text-ink">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Stat({ label, value }: { label: ReactNode; value: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="mt-0.5 text-sm font-medium text-ink">{value ?? '—'}</dd>
        </div>
    );
}
