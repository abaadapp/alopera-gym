import { useEffect, useRef, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { Button, Input, Tabs } from '@/Components/ui';
import { cn } from '@/lib/cn';

export type Range = 'today' | 'week' | 'month' | 'year' | 'all' | 'custom';

/** A screen's period as the server worked it out (App\Core\Support\Period::toArray). */
export interface Period {
    range: Range;
    from: string | null;
    to: string | null;
}

const RANGES: { key: Range; label: string }[] = [
    { key: 'today', label: 'اليوم' },
    { key: 'week', label: 'الأسبوع' },
    { key: 'month', label: 'الشهر' },
    { key: 'year', label: 'السنة' },
    { key: 'all', label: 'الكل' },
    { key: 'custom', label: 'مخصص' },
];

type Params = Record<string, string | number | boolean | null | undefined>;

/** The address parameters of a period, for a list's other links (its filters, sort and pages keep the period). */
export function periodParams(period: Period): Record<string, string> {
    return period.range === 'custom'
        ? Object.fromEntries(Object.entries({ range: 'custom', from: period.from, to: period.to }).filter(([, v]) => v)) as Record<string, string>
        : { range: period.range };
}

/**
 * The period of a screen, the same on every screen that has one: today, the week, the month, the year, all of it, or
 * dates of one's own — underline tabs with the from–to dates at the row's far end (under the row on a phone).
 *
 * The period lives in the address (?range=, and ?from&to for «مخصص»): it survives reload, back and a shared link, and
 * the server reads it for the list, its totals and its export alike. Changing it keeps the screen's other filters
 * (`params`) and starts the list from its first page. `ranges` drops periods a screen does not offer.
 */
export default function PeriodTabs({ period, params = {}, ranges, only, className }: { period: Period; params?: Params; ranges?: Range[]; only?: string[]; className?: string }) {
    const [dates, setDates] = useState({ from: period.from ?? '', to: period.to ?? '' });
    const [picking, setPicking] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    // A new period from the server (a tab, back, forward): the dates follow it.
    useEffect(() => {
        setDates({ from: period.from ?? '', to: period.to ?? '' });
        setPicking(false);
    }, [period.range, period.from, period.to]);

    const go = (next: Params) => {
        const query: Record<string, string> = {};
        Object.entries({ ...params, page: null, range: null, from: null, to: null, ...next }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '' && v !== false) query[k] = String(v);
        });
        router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true, only });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!dates.from && !dates.to) return;
        go({ range: 'custom', from: dates.from, to: dates.to });
    };

    const tabs = RANGES.filter((r) => !ranges || ranges.includes(r.key));
    const current: Range = picking ? 'custom' : period.range;
    const form = (
        <form onSubmit={submit} className="flex items-center gap-2 py-1.5">
            <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={dates.from} max={dates.to || undefined} onChange={(e) => setDates({ ...dates, from: e.target.value })} aria-label="من تاريخ" />
            <span className="text-[#9ca3af]">—</span>
            <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={dates.to} min={dates.from || undefined} onChange={(e) => setDates({ ...dates, to: e.target.value })} aria-label="إلى تاريخ" />
            <Button type="submit" variant="outline" size="sm" disabled={!dates.from && !dates.to}>عرض</Button>
        </form>
    );

    return (
        <div ref={root} className={cn('mb-4', className)}>
            <Tabs
                tabs={tabs}
                value={current}
                onChange={(key) => {
                    if (key === 'custom') {
                        setPicking(true);
                        // The from-date that is on screen (beside the tabs, or under them on a phone).
                        window.setTimeout(() => [...(root.current?.querySelectorAll<HTMLInputElement>('input[type="date"]') ?? [])].find((el) => el.offsetParent !== null)?.focus(), 0);
                        return;
                    }
                    go({ range: key });
                }}
                trailing={<div className="hidden md:block">{form}</div>}
            />
            <div className="md:hidden">{form}</div>
        </div>
    );
}
