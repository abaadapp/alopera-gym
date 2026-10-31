import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { ArrowUpDown, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ListFilter, Search, X } from 'lucide-react';
import { Dropdown, DropdownItem, DropdownLabel, DropdownSeparator } from './Dropdown';
import Tabs from './Tabs';
import { cn } from '@/lib/cn';

/*
 * A list page's table, driven by the address: the search, every filter, the sort and the page are query parameters the
 * server reads, so what the table says ("no overdue invoices") is true of the whole list, not of the page in view.
 *
 *   [🔍 search……]  [≡ أضف فلتر]  [from ▢] [to ▢]  [⇅ ترتيب]                 [toolbar]
 *   (chips of the filters in use · مسح الكل)
 *   الكل · غير مدفوعة · مدفوعة جزئيًا …                       (a filter shown as tabs)
 *   ─────────────────────────────────────────────────────────────────────────────
 *   column headers (12px semibold grey, 44px)
 *   rows (14px, 12px padding, a hairline between them)
 *   1–20 من 96                                                   [السابق] [التالي]
 *
 * It draws no card of its own: a page sets it on the canvas or inside a <Card padded={false}>.
 */

export interface Column<T> {
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    align?: 'start' | 'end' | 'center';
    className?: string;
}

export interface TableFilter {
    /** The query parameter it sets. */
    param: string;
    label: string;
    type?: 'select' | 'date';
    /** `hidden`: not offered, only named — an older link's value, so its chip still reads in words. */
    options?: { value: string; label: string; dot?: string; hidden?: boolean }[];
    /** Shown as a row of tabs (with «الكل» first) instead of in the «أضف فلتر» menu. */
    tabs?: boolean;
}

export interface TableSort {
    key: string;
    label: string;
}

export interface PageMeta {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

type Params = Record<string, string | number | null | undefined>;

const ghost = 'inline-flex h-8 items-center gap-1.5 rounded-[10px] px-3 text-[13px] font-medium text-[#4b4b4b] transition-colors hover:bg-ink/[0.045] hover:text-ink [&_svg]:size-4 [&_svg]:shrink-0';
const pageButton = 'inline-flex h-8 items-center rounded-[8px] border border-line px-3 text-[13px] text-ink transition-colors hover:bg-[#fafafa] disabled:pointer-events-none disabled:opacity-50';

export default function DataTable<T>({ rows, columns, rowKey, params, pagination, search, filters = [], sorts = [], toolbar, empty = 'لا توجد بيانات بعد', className }: {
    rows: T[];
    columns: Column<T>[];
    rowKey: (row: T) => string | number;
    params: Params;
    pagination?: PageMeta;
    search?: { placeholder: string; param?: string };
    filters?: TableFilter[];
    sorts?: TableSort[];
    toolbar?: ReactNode;
    empty?: ReactNode;
    className?: string;
}) {
    const searchParam = search?.param ?? 'q';
    const [query, setQuery] = useState(String(params[searchParam] ?? ''));
    const value = (param: string) => String(params[param] ?? '');

    // Every change goes to the server; the page number starts over unless it is the change.
    const go = (patch: Params) => {
        const next: Record<string, string> = {};
        Object.entries({ ...params, [searchParam]: query, page: undefined, ...patch }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') next[k] = String(v);
        });
        router.get(window.location.pathname, next, { preserveState: true, preserveScroll: true, replace: true });
    };

    const first = useRef(true);
    useEffect(() => {
        if (!search) return;
        if (first.current) {
            first.current = false;
            return;
        }
        const id = setTimeout(() => go({}), 350);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const tabFilters = filters.filter((f) => f.tabs);
    const dateFilters = filters.filter((f) => !f.tabs && f.type === 'date');
    const menuFilters = filters.filter((f) => !f.tabs && f.type !== 'date');
    const applied = menuFilters.filter((f) => value(f.param) !== '');
    const unapplied = menuFilters.filter((f) => value(f.param) === '');
    const clearable = [...menuFilters, ...dateFilters].filter((f) => value(f.param) !== '');
    const filtering = query.trim() !== '' || filters.some((f) => value(f.param) !== '');
    const sort = sorts.find((s) => s.key === value('sort'));
    const dir = value('dir') === 'desc' ? 'desc' : 'asc';
    const hasToolbar = Boolean(search || unapplied.length || dateFilters.length || sorts.length || toolbar);

    return (
        <div className={className}>
            {hasToolbar && (
                <div className={cn('flex flex-wrap items-center gap-1 px-4 py-2', applied.length === 0 && 'border-b border-line')}>
                    {search && (
                        <label className="flex min-w-0 flex-1 items-center gap-2 sm:max-w-[18rem]">
                            <Search className="size-4 shrink-0 text-[#9ca3af]" />
                            <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={search.placeholder} aria-label={search.placeholder} className="h-9 w-full min-w-0 border-0 bg-transparent p-0 text-sm text-ink placeholder:text-[#9ca3af] focus:outline-none focus:ring-0" />
                        </label>
                    )}
                    {unapplied.length > 0 && (
                        <Dropdown label="أضف فلتر" panelClassName="min-w-52 max-h-80 overflow-y-auto" trigger={() => <span className={ghost}><ListFilter /> أضف فلتر</span>}>
                            {(close) => <FilterChoices filters={unapplied} onPick={(param, v) => { close(); go({ [param]: v }); }} />}
                        </Dropdown>
                    )}
                    {dateFilters.map((f) => (
                        <label key={f.param} className="flex h-8 items-center gap-1.5 rounded-[8px] px-2 text-[13px] transition-colors hover:bg-ink/[0.045] focus-within:bg-ink/[0.045]">
                            <span className="whitespace-nowrap text-[#6b7280]">{f.label}</span>
                            <input type="date" aria-label={f.label} value={value(f.param)} onChange={(e) => go({ [f.param]: e.target.value })} className="h-auto w-[8.5rem] min-w-0 border-0 bg-transparent p-0 text-[13px] text-ink focus:outline-none focus:ring-0" />
                        </label>
                    ))}
                    {sorts.length > 0 && (
                        <Dropdown label="ترتيب" panelClassName="min-w-48" trigger={() => <span className={ghost}><ArrowUpDown /> {sort?.label ?? 'ترتيب'}</span>}>
                            {(close) => (
                                <>
                                    {sorts.map((s) => (
                                        <DropdownItem key={s.key} onClick={() => { close(); go({ sort: s.key, dir: sort?.key === s.key && dir === 'asc' ? 'desc' : 'asc' }); }}>
                                            <span className="flex-1">{s.label}</span>
                                            {sort?.key === s.key && (dir === 'asc' ? <ChevronUp /> : <ChevronDown />)}
                                        </DropdownItem>
                                    ))}
                                    {sort && (
                                        <>
                                            <DropdownSeparator />
                                            <DropdownItem onClick={() => { close(); go({ sort: null, dir: null }); }}>بلا ترتيب</DropdownItem>
                                        </>
                                    )}
                                </>
                            )}
                        </Dropdown>
                    )}
                    {toolbar && <div className="ms-auto flex items-center gap-1">{toolbar}</div>}
                </div>
            )}

            {applied.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 pb-2">
                    {applied.map((f) => (
                        <span key={f.param} className="inline-flex items-center gap-1.5 rounded-full bg-[#f2f2f0] py-1 pe-1.5 ps-3 text-[13px] text-ink">
                            <span className="text-[#6b7280]">{f.label}:</span>
                            {f.options?.find((o) => o.value === value(f.param))?.label ?? value(f.param)}
                            <button type="button" onClick={() => go({ [f.param]: null })} aria-label={`إزالة ${f.label}`} className="rounded-full p-0.5 text-[#6b7280] transition-colors hover:bg-ink/[0.08] hover:text-ink"><X className="size-3.5" /></button>
                        </span>
                    ))}
                    {clearable.length > 1 && (
                        <button type="button" onClick={() => go(Object.fromEntries(clearable.map((f) => [f.param, null])))} className="text-[13px] text-[#6b7280] transition-colors hover:text-ink">مسح الكل</button>
                    )}
                </div>
            )}

            {tabFilters.map((f) => (
                <Tabs
                    key={f.param}
                    tabs={[{ key: '', label: 'الكل', dot: '#9ca3af' }, ...(f.options ?? []).filter((o) => !o.hidden).map((o) => ({ key: o.value, label: o.label, dot: o.dot }))]}
                    value={value(f.param)}
                    onChange={(k) => go({ [f.param]: k })}
                    className="mx-0 px-4"
                />
            ))}

            <div className="w-full overflow-x-auto">
                <table className="table-base [&_tbody_tr:last-child_td]:border-0">
                    <thead>
                        <tr>
                            {columns.map((c) => (
                                <th key={c.key} className={cn('h-11 py-0 align-middle text-[12px] text-[#6b7280]', c.align === 'end' && 'text-end', c.align === 'center' && 'text-center', c.className)}>{c.header}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-14 text-center text-sm whitespace-normal text-[#9ca3af]">{filtering ? 'لا نتائج مطابقة للبحث أو التصفية' : empty}</td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr key={rowKey(row)} className="transition-colors">
                                    {columns.map((c) => (
                                        <td key={c.key} className={cn('border-line text-ink', c.align === 'end' && 'text-end', c.align === 'center' && 'text-center', c.className)}>{c.cell(row)}</td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-3">
                    <p className="text-[12px] text-[#6b7280]"><span className="num">{pagination.from ?? 0}–{pagination.to ?? 0}</span> من <span className="num">{pagination.total}</span></p>
                    <div className="flex gap-1.5">
                        <button type="button" className={pageButton} disabled={pagination.current_page <= 1} onClick={() => go({ page: pagination.current_page - 1 })}>السابق</button>
                        <button type="button" className={pageButton} disabled={pagination.current_page >= pagination.last_page} onClick={() => go({ page: pagination.current_page + 1 })}>التالي</button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** «أضف فلتر»: the filters by name; one opens its choices in the same menu (and «رجوع» comes back). */
function FilterChoices({ filters, onPick }: { filters: TableFilter[]; onPick: (param: string, value: string) => void }) {
    const [open, setOpen] = useState<TableFilter | null>(filters.length === 1 ? filters[0] : null);
    if (!open) {
        return filters.map((f) => (
            <DropdownItem key={f.param} onClick={() => setOpen(f)}>
                <span className="flex-1">{f.label}</span>
                <ChevronLeft className="text-[#9ca3af]" />
            </DropdownItem>
        ));
    }

    return (
        <>
            {filters.length > 1 ? (
                <button type="button" onClick={() => setOpen(null)} className="flex w-full items-center gap-1 px-3 py-1.5 text-[12px] font-semibold text-[#9ca3af] hover:text-ink"><ChevronRight className="size-3.5" />{open.label}</button>
            ) : (
                <DropdownLabel>{open.label}</DropdownLabel>
            )}
            {(open.options ?? []).filter((o) => !o.hidden).map((o) => (
                <DropdownItem key={o.value} onClick={() => onPick(open.param, o.value)}>
                    {o.dot && <span aria-hidden className="size-1.5 shrink-0 rounded-full" style={{ backgroundColor: o.dot }} />}
                    <span className="flex-1">{o.label}</span>
                </DropdownItem>
            ))}
        </>
    );
}
