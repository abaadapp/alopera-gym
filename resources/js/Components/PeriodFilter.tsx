import { useState, type FormEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { Button, Input } from '@/Components/ui';
import { cn } from '@/lib/cn';

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Quick periods for the finance screens; «هذا الشهر» is the default the server uses. */
function presets(): { key: string; label: string; from: string; to: string }[] {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    return [
        { key: 'month', label: 'هذا الشهر', from: iso(new Date(y, m, 1)), to: iso(now) },
        { key: 'last', label: 'الشهر الماضي', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
        { key: 'quarter', label: 'هذا الربع', from: iso(new Date(y, Math.floor(m / 3) * 3, 1)), to: iso(now) },
        { key: 'year', label: 'هذه السنة', from: iso(new Date(y, 0, 1)), to: iso(now) },
    ];
}

/**
 * A period picker that reloads the page with ?from&to (other filters kept), so the period is in the address and
 * survives back, forward and reload.
 */
export default function PeriodFilter({ routeName, from, to, extra = {}, children, className }: { routeName: string; from: string; to: string; extra?: Record<string, string | number | null | undefined>; children?: ReactNode; className?: string }) {
    const [range, setRange] = useState({ from, to });
    const go = (next: { from: string; to: string }) => {
        const params = Object.fromEntries(Object.entries({ ...extra, ...next }).filter(([, v]) => v !== null && v !== undefined && v !== '')) as Record<string, string>;
        router.get(route(routeName), params, { preserveState: true, preserveScroll: true, replace: true });
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        go(range);
    };
    const active = presets().find((p) => p.from === from && p.to === to)?.key;

    return (
        <div className={cn('card mb-6 flex flex-wrap items-center justify-between gap-3 p-4', className)}>
            <div className="flex flex-wrap items-center gap-1.5">
                {presets().map((p) => (
                    <button
                        key={p.key}
                        type="button"
                        onClick={() => go({ from: p.from, to: p.to })}
                        className={cn('h-8 rounded-lg px-3 text-[13px] transition', active === p.key ? 'bg-[#f2f2f0] font-semibold text-ink' : 'text-muted hover:bg-ink/[0.045] hover:text-ink')}
                    >
                        {p.label}
                    </button>
                ))}
                {children}
            </div>
            <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                <CalendarRange className="size-4 text-muted" />
                <Input type="date" className="h-9 w-36 py-1.5" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} aria-label="من" />
                <span className="text-muted">—</span>
                <Input type="date" className="h-9 w-36 py-1.5" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} aria-label="إلى" />
                <Button type="submit" variant="outline" size="sm">عرض</Button>
            </form>
        </div>
    );
}
