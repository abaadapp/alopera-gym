import { useEffect, useId, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { Button, Field, Input, Modal, Tabs } from '@/Components/ui';
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
 * button at the row's end, «اختيار فترة», that opens the from–to dates in a dialog (as the reference's period picker);
 * while they are in force no tab is current and the button shows them.
 *
 * The period lives in the address (?range=, and ?range=custom&from&to): it survives reload, back and a shared link, and
 * the server reads it for the list, its totals and its export alike. Changing it keeps the screen's other filters
 * (`params`) and starts the list from its first page. `ranges` drops periods a screen does not offer.
 */
export default function PeriodTabs({ period, params = {}, ranges, only, className }: { period: Period; params?: Params; ranges?: Range[]; only?: string[]; className?: string }) {
    const [dates, setDates] = useState({ from: period.from ?? '', to: period.to ?? '' });
    const [picking, setPicking] = useState(false);
    const formId = useId();

    // A new period from the server (a tab, back, forward): the dates follow it and the picker closes.
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
        setPicking(false);
        go({ range: 'custom', from: dates.from, to: dates.to });
    };

    const tabs = RANGES.filter((r) => !ranges || ranges.includes(r.key));
    const custom = period.range === 'custom';
    const day = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '…');
    const picker = (!ranges || ranges.includes('custom')) && (
        <div className="py-1.5">
            <button
                type="button"
                aria-haspopup="dialog"
                aria-expanded={picking}
                aria-label={custom ? `الفترة من ${period.from ?? '…'} إلى ${period.to ?? '…'} — اختيار فترة` : 'اختيار فترة'}
                title={custom ? `${period.from ?? '…'} – ${period.to ?? '…'}` : undefined}
                onClick={() => setPicking(true)}
                className={cn(
                    'inline-flex h-8 items-center gap-1.5 rounded-[10px] border px-2.5 text-[13px] font-medium transition-colors [&_svg]:size-4 [&_svg]:shrink-0',
                    custom ? 'border-[#111] bg-[#111] text-white' : 'border-line bg-white text-[#4b4b4b] hover:bg-[#fafafa] hover:text-ink',
                )}
            >
                <CalendarRange />
                {custom ? <span className="num" dir="ltr">{day(period.from)} – {day(period.to)}</span> : <span className="hidden sm:inline">اختيار فترة</span>}
            </button>
            <Modal
                open={picking}
                onClose={() => setPicking(false)}
                size="compact"
                title="اختيار فترة"
                footer={
                    <>
                        <Button type="button" variant="outline" onClick={() => setPicking(false)}>إلغاء</Button>
                        <Button type="submit" form={formId} disabled={!dates.from && !dates.to}>عرض</Button>
                    </>
                }
            >
                <form id={formId} onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <Field label="من">
                        <Input type="date" dir="ltr" value={dates.from} max={dates.to || undefined} onChange={(e) => setDates({ ...dates, from: e.target.value })} aria-label="من تاريخ" />
                    </Field>
                    <Field label="إلى">
                        <Input type="date" dir="ltr" value={dates.to} min={dates.from || undefined} onChange={(e) => setDates({ ...dates, to: e.target.value })} aria-label="إلى تاريخ" />
                    </Field>
                </form>
            </Modal>
        </div>
    );

    return (
        <div className={cn('mb-4', className)}>
            {/* Dates of one's own leave every tab unmarked: the button at the end says what is shown. */}
            <Tabs tabs={tabs} value={(custom ? '' : period.range) as Range} onChange={(key) => go({ range: key })} dense trailing={picker} />
        </div>
    );
}
