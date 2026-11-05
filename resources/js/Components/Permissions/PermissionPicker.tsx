import { useState, type ReactNode } from 'react';
import { Search, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/cn';
import { togglePermission } from '@/lib/permissions';

export type PermissionGroup = { key: string; label: string; permissions: { key: string; label: string; hint?: string | null }[] };
type Filter = 'all' | 'chosen' | 'sensitive';

/**
 * Every permission of the club in its section, a box before each: search, all / the chosen / the sensitive, a whole
 * section on or off, how many are chosen. A box the editor may not decide (not held, or sensitive for anyone but the
 * owner) stays as it is; the server keeps it so whatever is sent. The sensitive ones are listed apart, under their own
 * heading, each with its section. `tag` adds a mark of its own after a permission (the roles page's kind of power).
 */
export default function PermissionPicker({ groups, value, onChange, editable, mine, sensitive, requires, tag }: {
    groups: PermissionGroup[];
    value: string[];
    onChange: (next: string[]) => void;
    editable: boolean;
    mine: (permission: string) => boolean;
    sensitive: string[];
    requires: Record<string, string>;
    tag?: (permission: string) => ReactNode;
}) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const all = groups.flatMap((g) => g.permissions.map((p) => p.key));
    const chosen = all.filter((p) => value.includes(p)).length;
    const q = query.trim();
    const visible = groups
        .map((g) => ({ ...g, permissions: g.permissions.filter((p) =>
            (filter !== 'chosen' || value.includes(p.key)) && (filter !== 'sensitive' || sensitive.includes(p.key))
            && (q === '' || p.label.includes(q) || (p.hint ?? '').includes(q) || g.label.includes(q) || p.key.includes(q.toLowerCase()))) }))
        .filter((g) => g.permissions.length > 0);
    const may = (p: string) => editable && mine(p);
    const toggle = (p: string) => may(p) && onChange(togglePermission(value, p, requires, mine));
    const setSection = (permissions: { key: string }[], on: boolean) => {
        const keys = permissions.map((p) => p.key).filter(may);
        if (on) {
            onChange(Array.from(new Set([...value, ...keys, ...keys.map((k) => requires[k]).filter((n): n is string => !!n && mine(n))])));
            return;
        }
        const left = value.filter((p) => !keys.includes(p));
        onChange(left.filter((p) => !(requires[p] && !left.includes(requires[p]) && mine(p))));
    };
    // The ordinary ones stay in their sections; the sensitive ones are listed apart, under their own heading, each with its section.
    const ordinary = visible.map((g) => ({ ...g, permissions: g.permissions.filter((p) => !sensitive.includes(p.key)) })).filter((g) => g.permissions.length > 0);
    const sensitiveRows = visible.flatMap((g) => g.permissions.filter((p) => sensitive.includes(p.key)).map((p) => ({ p, section: g.label })));
    const head = (label: ReactNode, permissions: { key: string }[]) => (
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p className="font-medium">{label} <span className="num text-[12px] font-normal text-muted">{permissions.filter((p) => value.includes(p.key)).length}/{permissions.length}</span></p>
            {editable && (
                <span className="flex gap-3 text-[12px]">
                    <button type="button" className="text-[#111] underline-offset-2 hover:underline" onClick={() => setSection(permissions, true)}>تحديد الكل</button>
                    <button type="button" className="text-[#6b7280] underline-offset-2 hover:underline" onClick={() => setSection(permissions, false)}>إلغاء التحديد</button>
                </span>
            )}
        </div>
    );
    const row = (p: PermissionGroup['permissions'][number], section?: string) => {
        const isSensitive = sensitive.includes(p.key);
        return (
            <label key={p.key} title={editable && !mine(p.key) ? (isSensitive ? 'الصلاحيات الحساسة يمنحها صاحب النشاط وحده.' : 'لا تملك هذه الصلاحية — ولا تُمنح ما لا تملك.') : undefined}
                className={cn('flex items-start gap-2 text-sm text-ink/85', may(p.key) ? 'cursor-pointer' : 'cursor-not-allowed', editable && !may(p.key) && 'opacity-60')}>
                <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-[#111]" checked={value.includes(p.key)} onChange={() => toggle(p.key)} disabled={!may(p.key)} />
                <span className="min-w-0 flex-1">{p.label}{p.hint && <span className="mt-0.5 block text-[12px] leading-relaxed text-[#6b7280]">{p.hint}</span>}</span>
                {tag?.(p.key)}
                {isSensitive && (section
                    ? <span className="shrink-0 rounded-full bg-[#fef3c7] px-2 py-0.5 text-[11px] font-medium text-[#92400e]">{section}</span>
                    : <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#fffbeb] px-2 py-0.5 text-[11px] font-medium text-[#b45309]"><ShieldAlert className="size-3" />حساسة</span>)}
            </label>
        );
    };
    const filters: [Filter, string, number][] = [['all', 'الكل', all.length], ['chosen', 'المختارة', chosen], ['sensitive', 'الحساسة', sensitive.filter((p) => all.includes(p)).length]];

    return (
        <div className="space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <label className="relative block flex-1">
                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                    <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="ابحث عن صلاحية أو قسم"
                        className="h-10 w-full rounded-[10px] border border-[#e5e7eb] bg-white ps-9 pe-3 text-sm outline-none focus:border-[#111]" />
                </label>
                <div className="flex shrink-0 gap-1 rounded-[10px] bg-[#f2f2f0] p-1" role="group" aria-label="تصفية الصلاحيات">
                    {filters.map(([key, label, n]) => (
                        <button key={key} type="button" aria-pressed={filter === key} onClick={() => setFilter(key)}
                            className={cn('rounded-[8px] px-3 py-1.5 text-[12px] font-medium transition-colors', filter === key ? 'bg-white text-ink shadow-sm' : 'text-[#6b7280] hover:text-ink')}>
                            {label} <span className="num">{n}</span>
                        </button>
                    ))}
                </div>
            </div>
            <p className="text-[12px] text-muted" aria-live="polite">المختارة <span className="num font-medium text-ink">{chosen}</span> من <span className="num">{all.length}</span></p>
            {visible.length === 0 && <p className="rounded-[12px] border border-dashed border-line p-5 text-center text-[13px] text-[#9ca3af]">لا صلاحيات مطابقة.</p>}
            {ordinary.length > 0 && (
                <div className="grid gap-3 md:grid-cols-2">
                    {ordinary.map((g) => (
                        <div key={g.key} className="rounded-2xl border border-line p-4">
                            {head(g.label, g.permissions)}
                            <div className="space-y-1.5">{g.permissions.map((p) => row(p))}</div>
                        </div>
                    ))}
                </div>
            )}
            {sensitiveRows.length > 0 && (
                <div className="rounded-2xl border border-[#fde68a] bg-[#fffdf5] p-4">
                    {head(<span className="inline-flex items-center gap-1.5"><ShieldAlert className="size-4 text-[#b45309]" />الصلاحيات الحساسة</span>, sensitiveRows.map((r) => r.p))}
                    <p className="-mt-1 mb-3 text-[12px] leading-relaxed text-[#92400e]">تُمنح بالاسم، ويمنحها صاحب النشاط وحده: المال والرواتب والقيود والحسابات والتصدير والإعدادات.</p>
                    <div className="grid gap-x-4 gap-y-1.5 md:grid-cols-2">{sensitiveRows.map(({ p, section }) => row(p, section))}</div>
                </div>
            )}
        </div>
    );
}
