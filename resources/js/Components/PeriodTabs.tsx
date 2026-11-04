import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarRange, X } from 'lucide-react';
import { Button, Input, Modal, Select, Tabs } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { date, MONTHS } from '@/lib/format';

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

const QUICK: Partial<Record<Range, string>> = { today: 'اليوم', week: 'هذا الأسبوع', month: 'هذا الشهر', year: 'هذه السنة' };

const pad = (n: number) => String(n).padStart(2, '0');
const lastDay = (y: number, m: number) => new Date(y, m, 0).getDate();
const monthStart = ([y, m]: [number, number]) => `${y}-${pad(m)}-01`;
const monthEnd = ([y, m]: [number, number]) => `${y}-${pad(m)}-${pad(lastDay(y, m))}`;
const monthName = ([y, m]: [number, number]) => `${MONTHS[m - 1]} ${y}`;
const ymOf = (d: string): [number, number] => [Number(d.slice(0, 4)), Number(d.slice(5, 7))];

/** Dates that are whole months: [first month, last month], else null. */
function wholeMonths(from: string | null, to: string | null): [[number, number], [number, number]] | null {
    if (!from || !to || from.slice(8) !== '01') return null;
    const end = ymOf(to);

    return to === monthEnd(end) ? [ymOf(from), end] : null;
}

/**
 * A period in words, as the reference words it over a report and in its file: a quick period by its name and dates
 * «هذا الشهر (2026-11-01 — 2026-11-03)», whole months «أكتوبر 2026», «سبتمبر 2026 – نوفمبر 2026», a whole year «2026»,
 * other dates «1 أكتوبر 2026 – 15 أكتوبر 2026», all of it «كل الفترات».
 */
export function periodLabel(range: Range, from: string | null, to: string | null): string {
    if (range === 'custom') {
        const months = wholeMonths(from, to);
        if (months) {
            const [[y1, m1], [y2, m2]] = months;
            if (y1 === y2 && m1 === 1 && m2 === 12) return String(y1);
            return y1 === y2 && m1 === m2 ? monthName(months[0]) : `${monthName(months[0])} – ${monthName(months[1])}`;
        }
        if (from && to) return from === to ? date(from) : `${date(from)} – ${date(to)}`;
        return from ? `من ${date(from)}` : to ? `حتى ${date(to)}` : 'كل الفترات';
    }
    return range === 'all' || !QUICK[range] ? 'كل الفترات' : `${QUICK[range]} (${from} — ${to})`;
}

type Kind = 'previous_month' | 'month' | 'month_range' | 'year' | 'custom' | 'all';

const KIND_LABELS: Record<Kind, string> = {
    previous_month: 'الشهر السابق',
    month: 'شهر محدد',
    month_range: 'نطاق أشهر',
    year: 'سنة كاملة',
    custom: 'فترة مخصصة',
    all: 'كل الفترات',
};

interface Draft {
    kind: Kind;
    month: [number, number];
    fromMonth: [number, number];
    toMonth: [number, number];
    year: number;
    from: string;
    to: string;
}

/** The dialog opens on what is shown: whole months as months or a year, other dates as dates. */
function draftOf(period: Period): Draft {
    const now = new Date();
    const current: [number, number] = [now.getFullYear(), now.getMonth() + 1];
    const months = period.range === 'custom' ? wholeMonths(period.from, period.to) : null;
    const prev: [number, number] = current[1] === 1 ? [current[0] - 1, 12] : [current[0], current[1] - 1];
    let kind: Kind = period.range === 'all' ? 'all' : period.range === 'custom' ? 'custom' : 'month';
    if (months) {
        const [[y1, m1], [y2, m2]] = months;
        kind = y1 === y2 && m1 === 1 && m2 === 12 ? 'year' : y1 === y2 && m1 === m2 ? (y1 === prev[0] && m1 === prev[1] ? 'previous_month' : 'month') : 'month_range';
    }

    return {
        kind,
        month: months?.[0] ?? current,
        fromMonth: months?.[0] ?? current,
        toMonth: months?.[1] ?? current,
        year: months?.[0][0] ?? current[0],
        from: period.from ?? '',
        to: period.to ?? '',
    };
}

/** The address of a draft, or what is wrong with it. Months and years are dates of one's own, which every screen reads. */
function draftParams(d: Draft): { params: Params } | { error: string } {
    const now = new Date();
    switch (d.kind) {
        case 'previous_month': {
            const prev: [number, number] = now.getMonth() === 0 ? [now.getFullYear() - 1, 12] : [now.getFullYear(), now.getMonth()];
            return { params: { range: 'custom', from: monthStart(prev), to: monthEnd(prev) } };
        }
        case 'month':
            return { params: { range: 'custom', from: monthStart(d.month), to: monthEnd(d.month) } };
        case 'month_range':
            if (monthStart(d.fromMonth) > monthStart(d.toMonth)) return { error: 'شهر البداية بعد شهر النهاية.' };
            return { params: { range: 'custom', from: monthStart(d.fromMonth), to: monthEnd(d.toMonth) } };
        case 'year':
            return { params: { range: 'custom', from: `${d.year}-01-01`, to: `${d.year}-12-31` } };
        case 'custom':
            if (!/^\d{4}-\d{2}-\d{2}$/.test(d.from) || !/^\d{4}-\d{2}-\d{2}$/.test(d.to)) return { error: 'اختر تاريخي البداية والنهاية.' };
            if (d.from > d.to) return { error: 'تاريخ البداية بعد تاريخ النهاية.' };
            return { params: { range: 'custom', from: d.from, to: d.to } };
        default:
            return { params: { range: 'all' } };
    }
}

/**
 * A report's period in its header, beside «تصدير» (the file holds what the screen shows), as the reference's: an
 * outline button that says the period in words — «الفترة: هذا الشهر (2026-11-01 — 2026-11-03)» — and opens the same
 * dates dialog as the tabs under it (`PeriodTabs` with `open`/`onOpenChange`).
 */
export function PeriodButton({ label, onClick }: { label: string; onClick: () => void }) {
    const text = `الفترة: ${label}`;
    return (
        <Button type="button" variant="outline" onClick={onClick} className="max-w-full sm:max-w-[22rem]" aria-label={`تغيير فترة التقرير — ${text}`} title="تغيير فترة التقرير" icon={<CalendarRange className="size-4" />}>
            <span className="min-w-0 truncate">{text}</span>
        </Button>
    );
}

/**
 * The period of a screen, the same on every screen that has one, drawn as the reference's period tabs: today, the week,
 * the month, the year, all of it — 14px underline tabs, the current one underlined in black. Dates of one's own are a
 * button at the row's end, «اختيار فترة», that opens the from–to dates in a dialog (as the reference's period picker);
 * while they are in force no tab is current and the button shows them. A report holds the dialog's `open` itself and
 * opens it from its header (`PeriodButton`): the row then has the tabs only, and dates of one's own are said under
 * them with a way back to the default period («مسح الفترة»), as the reference's report screen.
 *
 * The period lives in the address (?range=, and ?range=custom&from&to): it survives reload, back and a shared link, and
 * the server reads it for the list, its totals and its export alike. Changing it keeps the screen's other filters
 * (`params`) and starts the list from its first page. `ranges` drops periods a screen does not offer.
 */
export default function PeriodTabs({ period, params = {}, ranges, only, className, open, onOpenChange }: {
    period: Period; params?: Params; ranges?: Range[]; only?: string[]; className?: string; open?: boolean; onOpenChange?: (open: boolean) => void;
}) {
    const [draft, setDraft] = useState<Draft>(() => draftOf(period));
    const [error, setError] = useState<string | null>(null);
    const [own, setOwn] = useState(false);
    const controlled = open !== undefined;
    const picking = controlled ? open : own;
    const setPicking = (next: boolean) => (controlled ? onOpenChange?.(next) : setOwn(next));

    // A new period from the server (a tab, back, forward): the picker closes.
    useEffect(() => {
        setPicking(false);
    }, [period.range, period.from, period.to]);
    // Opened: on the period shown, with no error left from before.
    useEffect(() => {
        if (picking) {
            setDraft(draftOf(period));
            setError(null);
        }
    }, [picking]);

    const go = (next: Params) => {
        const query: Record<string, string> = {};
        Object.entries({ ...params, page: null, range: null, from: null, to: null, ...next }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '' && v !== false) query[k] = String(v);
        });
        router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true, only });
    };

    const set = (patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch }));
    const apply = () => {
        const result = draftParams(draft);
        if ('error' in result) {
            setError(result.error);
            return;
        }
        setPicking(false);
        go(result.params);
    };

    const tabs = RANGES.filter((r) => !ranges || ranges.includes(r.key));
    const custom = period.range === 'custom';
    const day = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '…');
    const pickable = !ranges || ranges.includes('custom');
    const kinds = (Object.keys(KIND_LABELS) as Kind[]).filter((k) => k !== 'all' || !ranges || ranges.includes('all'));
    const thisYear = new Date().getFullYear();
    const years = Array.from({ length: 6 }, (_, i) => thisYear - i);
    const monthPicker = (label: string, value: [number, number], onChange: (v: [number, number]) => void) => (
        <fieldset className="flex flex-col gap-1">
            <legend className="mb-1 text-[12px] text-[#6b7280]">{label}</legend>
            <div className="grid grid-cols-2 gap-2">
                <Select aria-label={`${label} — الشهر`} value={String(value[1])} onChange={(e) => onChange([value[0], Number(e.target.value)])}>
                    {MONTHS.map((m, i) => <option key={m} value={String(i + 1)}>{m}</option>)}
                </Select>
                <Select aria-label={`${label} — السنة`} value={String(value[0])} onChange={(e) => onChange([Number(e.target.value), value[1]])}>
                    {years.map((y) => <option key={y} value={String(y)}>{y}</option>)}
                </Select>
            </div>
        </fieldset>
    );
    // As the reference's: the kind of period first, then only the fields that kind needs.
    const dialog = pickable && (
        <Modal
            open={picking}
            onClose={() => setPicking(false)}
            size="compact"
            title="اختيار فترة"
            // «تطبيق» on the reading side and «إلغاء» at the far end, as the reference's period dialog (its footer runs reversed).
            footer={
                <>
                    <Button type="button" onClick={apply}>تطبيق</Button>
                    <Button type="button" variant="outline" onClick={() => setPicking(false)}>إلغاء</Button>
                </>
            }
        >
            <div className="flex flex-col gap-4">
                <fieldset>
                    <legend className="mb-2 text-[12px] text-[#6b7280]">نوع الفترة</legend>
                    <div className="grid grid-cols-2 gap-2" role="radiogroup">
                        {kinds.map((k) => (
                            <label key={k} className="flex cursor-pointer items-center gap-2 rounded-[10px] border border-[#e8e8e8] px-3 py-2 text-[13px] has-[:checked]:border-[#6366f1] has-[:checked]:bg-[#eef2ff]">
                                <input type="radio" name="period-kind" value={k} checked={draft.kind === k} onChange={() => { set({ kind: k }); setError(null); }} className="size-4" />
                                {KIND_LABELS[k]}
                            </label>
                        ))}
                    </div>
                </fieldset>
                {draft.kind === 'month' && monthPicker('الشهر', draft.month, (v) => set({ month: v }))}
                {draft.kind === 'month_range' && (
                    <div className="flex flex-col gap-3">
                        {monthPicker('من شهر', draft.fromMonth, (v) => set({ fromMonth: v }))}
                        {monthPicker('إلى شهر', draft.toMonth, (v) => set({ toMonth: v }))}
                    </div>
                )}
                {draft.kind === 'year' && (
                    <label className="flex flex-col gap-1">
                        <span className="text-[12px] text-[#6b7280]">السنة</span>
                        <Select aria-label="السنة" value={String(draft.year)} onChange={(e) => set({ year: Number(e.target.value) })}>
                            {years.map((y) => <option key={y} value={String(y)}>{y}</option>)}
                        </Select>
                    </label>
                )}
                {draft.kind === 'custom' && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label className="flex flex-col gap-1">
                            <span className="text-[12px] text-[#6b7280]">من تاريخ</span>
                            <Input type="date" dir="ltr" value={draft.from} onChange={(e) => set({ from: e.target.value })} aria-label="من تاريخ" />
                        </label>
                        <label className="flex flex-col gap-1">
                            <span className="text-[12px] text-[#6b7280]">إلى تاريخ</span>
                            <Input type="date" dir="ltr" value={draft.to} onChange={(e) => set({ to: e.target.value })} aria-label="إلى تاريخ" />
                        </label>
                    </div>
                )}
                {error && <p role="alert" className="text-[12.5px] text-[#b91c1c]">{error}</p>}
            </div>
        </Modal>
    );
    const picker = pickable && !controlled && (
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
        </div>
    );

    return (
        <div className={cn('mb-4', className)}>
            {/* Dates of one's own leave every tab unmarked: the button at the end says what is shown. A report's tabs
                (its button in the header) stand alone, their line as wide as they are, as the reference's report screen. */}
            {controlled ? (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <Tabs tabs={tabs} value={(custom ? '' : period.range) as Range} onChange={(key) => go({ range: key })} />
                    </div>
                    {custom && (
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <span className="rounded-full bg-[#eef2ff] px-3 py-1 text-[12.5px] font-medium text-[#3730a3]">الفترة: {periodLabel('custom', period.from, period.to)}</span>
                            <button type="button" onClick={() => go({})} className="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[12px] text-[#6b7280] hover:bg-[#f3f4f6] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#6366f1]">
                                <X className="size-3.5" aria-hidden="true" />
                                مسح الفترة
                            </button>
                        </div>
                    )}
                </>
            ) : (
                <Tabs tabs={tabs} value={(custom ? '' : period.range) as Range} onChange={(key) => go({ range: key })} dense trailing={picker} />
            )}
            {dialog}
        </div>
    );
}
