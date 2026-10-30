import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Check, LayoutGrid, ListFilter, Minus, MoreHorizontal, Package, Plus, Rows3, Search, X } from 'lucide-react';
import { Badge, Button, Modal } from '@/Components/ui';
import { Dropdown, DropdownItem, DropdownLabel, DropdownSeparator } from '@/Components/ui/Dropdown';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';

/*
 * The pieces of the products section's lists (products, add-ons): a toolbar row of a borderless search, status tabs,
 * an «أضف فلتر» menu whose choices become chips, and a table/cards switch; a row's «⋯» menu; the product thumbnail,
 * the stock badge and the − n + stepper the selling screens share.
 */

type Query = Record<string, string | number | undefined | null>;

/** Go to this list with some filters changed (empty values drop out of the address). */
export function visit(routeName: string, filters: Query, change: Query) {
    const next: Record<string, string> = {};
    Object.entries({ ...filters, ...change }).forEach(([key, value]) => {
        if (key !== 'page' && value !== '' && value !== null && value !== undefined) next[key] = String(value);
    });
    router.get(route(routeName), next, { preserveState: true, preserveScroll: true, replace: true });
}

/** The toolbar row along the top of a list card. */
export function Toolbar({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-line px-4 py-2', className)}>{children}</div>;
}

/** A search box without a frame, as the first thing in the toolbar; updates `q` a moment after typing stops. */
export function ToolbarSearch({ value, placeholder, onSearch }: { value: string; placeholder: string; onSearch: (q: string) => void }) {
    const [term, setTerm] = useState(value);
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = setTimeout(() => onSearch(term.trim()), 350);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <label className="flex w-full items-center gap-2 sm:w-auto sm:min-w-[16rem] sm:max-w-[18rem] sm:flex-1">
            <Search className="size-4 shrink-0 text-[#9ca3af]" />
            <input type="search" value={term} onChange={(e) => setTerm(e.target.value)} placeholder={placeholder} aria-label={placeholder} className="h-9 w-full border-0 bg-transparent p-0 text-sm text-ink placeholder:text-[#9ca3af] focus:outline-none focus:ring-0" />
        </label>
    );
}

/** الكل / مفعّل / غير مفعّل with their counts. */
export function StatusTabs({ value, counts, onChange, labels = ['مفعّل', 'غير مفعّل'] }: { value: string; counts: { all: number; active: number; inactive: number }; onChange: (v: string) => void; labels?: [string, string] }) {
    const tabs = [['', 'الكل', counts.all], ['active', labels[0], counts.active], ['inactive', labels[1], counts.inactive]] as const;
    return (
        <div className="flex items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="الحالة">
            {tabs.map(([key, label, count]) => (
                <button key={key || 'all'} type="button" onClick={() => onChange(key)} aria-pressed={value === key} className={cn('inline-flex h-8 items-center gap-1.5 rounded-[8px] px-3 text-[13px] font-medium transition', value === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>
                    {label}
                    <span className="text-[12px] text-[#9ca3af] num">{number(count)}</span>
                </button>
            ))}
        </div>
    );
}

export type FilterDef = { key: string; label: string; options: { value: string; label: string }[] };

/** «أضف فلتر»: a menu of each filter's choices; a chosen one shows as a chip with ✕. */
export function FilterMenu({ filters, values, onChange }: { filters: FilterDef[]; values: Record<string, string>; onChange: (key: string, value: string) => void }) {
    const chosen = filters.flatMap((f) => {
        const option = f.options.find((o) => o.value === values[f.key]);
        return values[f.key] && option ? [{ key: f.key, text: `${f.label}: ${option.label}` }] : [];
    });

    return (
        <>
            {chosen.map((c) => (
                <span key={c.key} className="inline-flex items-center gap-1.5 rounded-full bg-[#f2f2f0] py-1 ps-3 pe-1.5 text-[13px] text-ink">
                    {c.text}
                    <button type="button" onClick={() => onChange(c.key, '')} className="grid size-5 place-items-center rounded-full text-[#6b7280] hover:bg-white hover:text-ink" aria-label={`إزالة ${c.text}`}><X className="size-3.5" /></button>
                </span>
            ))}
            <Dropdown label="أضف فلتر" panelClassName="w-64 max-h-[70vh] overflow-y-auto" trigger={() => (
                <span className="inline-flex h-9 items-center gap-1.5 rounded-[10px] px-3 text-[13px] font-medium text-[#4b4b4b] hover:bg-ink/[0.045] hover:text-ink"><ListFilter className="size-4" /> أضف فلتر</span>
            )}>
                {(close) => filters.map((f, i) => (
                    <div key={f.key}>
                        {i > 0 && <DropdownSeparator />}
                        <DropdownLabel>{f.label}</DropdownLabel>
                        {f.options.map((o) => (
                            <DropdownItem key={o.value} onClick={() => { onChange(f.key, values[f.key] === o.value ? '' : o.value); close(); }}>
                                <span className="flex-1">{o.label}</span>
                                {values[f.key] === o.value && <Check className="text-ink" />}
                            </DropdownItem>
                        ))}
                    </div>
                ))}
            </Dropdown>
        </>
    );
}

/** Table or cards; the choice is remembered in this browser. */
export function useView(key: string): ['table' | 'grid', (v: 'table' | 'grid') => void] {
    const [view, setView] = useState<'table' | 'grid'>(() => {
        try {
            return localStorage.getItem(`view:${key}`) === 'grid' ? 'grid' : 'table';
        } catch {
            return 'table';
        }
    });
    const set = (v: 'table' | 'grid') => {
        setView(v);
        try {
            localStorage.setItem(`view:${key}`, v);
        } catch {
            // storage blocked: the choice lasts for this page only
        }
    };
    return [view, set];
}

export function ViewToggle({ value, onChange }: { value: 'table' | 'grid'; onChange: (v: 'table' | 'grid') => void }) {
    return (
        <div className="flex items-center gap-0.5" role="group" aria-label="طريقة العرض">
            {([['table', Rows3, 'جدول'], ['grid', LayoutGrid, 'بطاقات']] as const).map(([key, Icon, label]) => (
                <button key={key} type="button" onClick={() => onChange(key)} aria-pressed={value === key} aria-label={label} title={label} className={cn('grid size-8 place-items-center rounded-[8px] transition', value === key ? 'bg-[#f2f2f0] text-ink' : 'text-[#9ca3af] hover:text-ink')}>
                    <Icon className="size-4" />
                </button>
            ))}
        </div>
    );
}

/** A row's or a card's actions behind «⋯». */
export function RowMenu({ label, children }: { label: string; children: ReactNode | ((close: () => void) => ReactNode) }) {
    return (
        <Dropdown label={label} panelClassName="w-48" trigger={() => (
            <span className="grid size-8 place-items-center rounded-[8px] text-[#6b7280] hover:bg-ink/[0.045] hover:text-ink"><MoreHorizontal className="size-4" /></span>
        )}>
            {children}
        </Dropdown>
    );
}

export function ProductThumb({ url, className }: { url: string | null; className?: string }) {
    return url ? (
        <img src={url} alt="" loading="lazy" className={cn('size-11 shrink-0 rounded-[8px] border border-line object-cover', className)} />
    ) : (
        <span className={cn('grid size-11 shrink-0 place-items-center rounded-[8px] border border-line bg-[#f7f7f5] text-[#9ca3af]', className)}><Package className="size-4" /></span>
    );
}

/** متوفر / منخفض / نفد / غير متتبع. */
export function StockBadge({ track, out, low }: { track: boolean; out: boolean; low: boolean }) {
    if (!track) return <Badge tone="gray">غير متتبع</Badge>;
    if (out) return <Badge tone="red">نفد</Badge>;
    if (low) return <Badge tone="amber">منخفض</Badge>;
    return <Badge tone="green">متوفر</Badge>;
}

/** − n + with limits. */
export function Stepper({ value, onChange, min = 0, max = Infinity, label, size = 'md' }: { value: number; onChange: (v: number) => void; min?: number; max?: number; label: string; size?: 'sm' | 'md' }) {
    const box = size === 'sm' ? 'size-7' : 'size-9';
    return (
        <div className="flex items-center gap-1" role="group" aria-label={label}>
            <button type="button" disabled={value <= min} onClick={() => onChange(Math.max(min, value - 1))} className={cn('grid place-items-center rounded-[8px] border border-line bg-white text-ink transition hover:bg-[#fafafa] disabled:opacity-40', box)} aria-label={`إنقاص ${label}`}><Minus className="size-3.5" /></button>
            <span className="w-7 text-center text-sm font-semibold num" aria-live="polite">{value}</span>
            <button type="button" disabled={value >= max} onClick={() => onChange(Math.min(max, value + 1))} className={cn('grid place-items-center rounded-[8px] border border-line bg-white text-ink transition hover:bg-[#fafafa] disabled:opacity-40', box)} aria-label={`زيادة ${label}`}><Plus className="size-3.5" /></button>
        </div>
    );
}

/** A delete confirmation opened from a row's menu (the menu closes, so the dialog lives on the page). */
export function ConfirmDelete({ target, url, title, message, onClose }: { target: unknown; url: string | null; title: string; message: ReactNode; onClose: () => void }) {
    const [busy, setBusy] = useState(false);
    if (!target || !url) return null;
    const confirm = () => router.delete(url, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); onClose(); } });

    return (
        <Modal open onClose={onClose} size="sm" title={title} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button variant="danger" loading={busy} onClick={confirm}>حذف</Button></>}>
            <p className="text-sm leading-7 text-[#4b4b4b]">{message}</p>
        </Modal>
    );
}
