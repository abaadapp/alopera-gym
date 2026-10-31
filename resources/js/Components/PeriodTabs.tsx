import { useEffect, useRef, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
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
];

type Params = Record<string, string | number | boolean | null | undefined>;

/** The address parameters of a period, for a list's other links (its filters, sort and pages keep the period). */
export function periodParams(period: Period): Record<string, string> {
    return period.range === 'custom'
        ? Object.fromEntries(Object.entries({ range: 'custom', from: period.from, to: period.to }).filter(([, v]) => v)) as Record<string, string>
        : { range: period.range };
}

/**
 * The period of a screen, the same on every screen that has one, drawn as the reference's period tabs: today, the week,
 * the month, the year, all of it — 14px underline tabs, the current one underlined in black. Dates of one's own are a
 * button at the row's end, «اختيار فترة», that opens the from–to dates beside it; while they are in force no tab is
 * current and the button shows them.
 *
 * The period lives in the address (?range=, and ?range=custom&from&to): it survives reload, back and a shared link, and
 * the server reads it for the list, its totals and its export alike. Changing it keeps the screen's other filters
 * (`params`) and starts the list from its first page. `ranges` drops periods a screen does not offer.
 */
export default function PeriodTabs({ period, params = {}, ranges, only, className }: { period: Period; params?: Params; ranges?: Range[]; only?: string[]; className?: string }) {
    const [dates, setDates] = useState({ from: period.from ?? '', to: period.to ?? '' });
    const [picking, setPicking] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);

    // A new period from the server (a tab, back, forward): the dates follow it and the picker closes.
    useEffect(() => {
        setDates({ from: period.from ?? '', to: period.to ?? '' });
        setPicking(false);
    }, [period.range, period.from, period.to]);

    // The picker takes the focus on its first date; Escape or a click elsewhere closes it, the focus back on its button.
    useEffect(() => {
        if (!picking) return;
        box.current?.querySelector<HTMLInputElement>('input[type="date"]')?.focus();
        const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && setPicking(false);
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') return;
            setPicking(false);
            button.current?.focus();
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [picking]);

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
    const custom = period.range === 'custom';
    const day = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '…');
    const picker = (!ranges || ranges.includes('custom')) && (
        <div ref={box} className="relative py-1.5">
            <button
                ref={button}
                type="button"
                aria-haspopup="dialog"
                aria-expanded={picking}
                aria-label={custom ? `الفترة من ${period.from ?? '…'} إلى ${period.to ?? '…'} — اختيار فترة` : 'اختيار فترة'}
                title={custom ? `${period.from ?? '…'} – ${period.to ?? '…'}` : undefined}
                onClick={() => setPicking((v) => !v)}
                className={cn(
                    'inline-flex h-8 items-center gap-1.5 rounded-[10px] border px-2.5 text-[13px] font-medium transition-colors [&_svg]:size-4 [&_svg]:shrink-0',
                    custom ? 'border-[#111] bg-[#111] text-white' : 'border-line bg-white text-[#4b4b4b] hover:bg-[#fafafa] hover:text-ink',
                )}
            >
                <CalendarRange />
                {custom ? <span className="num" dir="ltr">{day(period.from)} – {day(period.to)}</span> : <span className="hidden sm:inline">اختيار فترة</span>}
            </button>
            {picking && (
                <form role="dialog" aria-label="اختيار فترة" onSubmit={submit} className="absolute end-0 top-full z-40 mt-1 w-[min(17rem,calc(100vw-2rem))] animate-[menu-in_.14s_ease-out] rounded-[12px] border border-[#e8e8e8] bg-white p-3 shadow-[0_8px_30px_rgba(0,0,0,0.10)]">
                    <div className="grid gap-2">
                        <label className="block text-[12px] text-[#6b7280]">
                            من
                            <Input type="date" className="mt-1 h-9 text-[13px]" value={dates.from} max={dates.to || undefined} onChange={(e) => setDates({ ...dates, from: e.target.value })} aria-label="من تاريخ" />
                        </label>
                        <label className="block text-[12px] text-[#6b7280]">
                            إلى
                            <Input type="date" className="mt-1 h-9 text-[13px]" value={dates.to} min={dates.from || undefined} onChange={(e) => setDates({ ...dates, to: e.target.value })} aria-label="إلى تاريخ" />
                        </label>
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={() => { setPicking(false); button.current?.focus(); }}>إلغاء</Button>
                        <Button type="submit" size="sm" disabled={!dates.from && !dates.to}>عرض</Button>
                    </div>
                </form>
            )}
        </div>
    );

    return (
        <div className={cn('mb-4', className)}>
            {/* Dates of one's own leave every tab unmarked: the button at the end says what is shown. */}
            <Tabs tabs={tabs} value={(custom ? '' : period.range) as Range} onChange={(key) => go({ range: key })} dense trailing={picker} />
        </div>
    );
}
