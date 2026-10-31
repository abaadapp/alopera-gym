import { useState, type FormEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Button, Input, Tabs } from '@/Components/ui';
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
    const all = presets();
    const active = all.find((p) => p.from === from && p.to === to)?.key ?? 'custom';

    // The reference's period row: underline tabs, the from–to dates at its far end; a page's own filter (a source) under it.
    return (
        <div className={cn('mb-6', className)}>
            <Tabs
                tabs={all.map((p) => ({ key: p.key, label: p.label }))}
                value={active}
                onChange={(key) => {
                    const p = all.find((x) => x.key === key);
                    if (p) go({ from: p.from, to: p.to });
                }}
                trailing={
                    <form onSubmit={submit} className="flex items-center gap-2 py-1.5">
                        <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} aria-label="من" />
                        <span className="text-[#9ca3af]">—</span>
                        <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} aria-label="إلى" />
                        <Button type="submit" variant="outline" size="sm">عرض</Button>
                    </form>
                }
            />
            {children && <div className="mt-3 flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}
