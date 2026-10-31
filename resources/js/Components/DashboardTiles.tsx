import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, EyeOff, Plus, RotateCcw, SlidersHorizontal, X } from 'lucide-react';
import { Button, StatGrid } from '@/Components/ui';
import { cn } from '@/lib/cn';

export type TilePreferences = { order: string[]; hidden: string[] };

/**
 * The dashboard's stat tiles in the user's own order, without the ones they hid. «تخصيص» turns on editing: each tile
 * can move earlier or later or be hidden, hidden ones come back from the chips above, and «حفظ» keeps the choice on
 * the server for this user (another user keeps theirs). A tile the user may not see (money, without the financial
 * reports permission) is null and stays out of both the grid and the chips.
 *
 * The grid is the stat grid: one tile per row on phones, two on tablets, four on wide screens, rows of one height.
 */
export default function DashboardTiles({ tiles, preferences }: { tiles: Record<string, { label: string; node: ReactNode } | null>; preferences: TilePreferences }) {
    const [editing, setEditing] = useState(false);
    const [order, setOrder] = useState(preferences.order);
    const [hidden, setHidden] = useState(preferences.hidden);
    const [saving, setSaving] = useState(false);

    const available = (key: string) => tiles[key] != null;
    const shown = order.filter((k) => available(k) && !hidden.includes(k));
    const away = order.filter((k) => available(k) && hidden.includes(k));

    const move = (key: string, by: -1 | 1) => {
        const visible = shown;
        const at = visible.indexOf(key);
        const swap = visible[at + by];
        if (!swap) return;
        const next = [...order];
        const i = next.indexOf(key);
        const j = next.indexOf(swap);
        [next[i], next[j]] = [next[j], next[i]];
        setOrder(next);
    };

    const cancel = () => {
        setOrder(preferences.order);
        setHidden(preferences.hidden);
        setEditing(false);
    };

    const save = () => router.put(route('club.dashboard.tiles'), { order, hidden }, {
        preserveScroll: true,
        onStart: () => setSaving(true),
        onFinish: () => setSaving(false),
        onSuccess: () => setEditing(false),
    });

    const reset = () => {
        setOrder(Object.keys(tiles));
        setHidden([]);
    };

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-end gap-2">
                {editing && (
                    <div className="me-auto flex flex-wrap items-center gap-1.5">
                        {away.length === 0 ? (
                            <span className="text-[12px] text-[#9ca3af]">كل البطاقات ظاهرة. أخفِ ما لا تحتاجه، أو غيّر ترتيبه بالأسهم.</span>
                        ) : (
                            <>
                                <span className="text-[12px] text-[#9ca3af]">أضف:</span>
                                {away.map((k) => (
                                    <button key={k} type="button" onClick={() => setHidden(hidden.filter((h) => h !== k))}
                                        className="inline-flex items-center gap-1 rounded-full bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#6b7280] transition-colors hover:bg-[#e8e8e6]">
                                        <Plus className="size-3" /> {tiles[k]!.label}
                                    </button>
                                ))}
                            </>
                        )}
                    </div>
                )}
                {editing ? (
                    <>
                        <Button size="sm" variant="outline" icon={<RotateCcw />} onClick={reset}>إعادة الافتراضي</Button>
                        <Button size="sm" variant="ghost" icon={<X />} onClick={cancel}>إلغاء</Button>
                        <Button size="sm" icon={<Check />} loading={saving} onClick={save}>تم</Button>
                    </>
                ) : (
                    <Button size="sm" variant="outline" icon={<SlidersHorizontal />} onClick={() => setEditing(true)}>تخصيص</Button>
                )}
            </div>

            <StatGrid>
                {shown.map((key, i) => (
                    <div key={key} className={cn('relative h-full', editing && 'rounded-[16px] ring-2 ring-[#111]/10 ring-offset-2 ring-offset-[#f7f8f9]')}>
                        <div className={cn('h-full', editing && 'pointer-events-none select-none')}>{tiles[key]!.node}</div>
                        {editing && (
                            <div className="absolute end-2 top-2 flex gap-1 rounded-[10px] border border-[#e8e8e8] bg-white p-0.5 shadow-sm" role="group" aria-label={`ترتيب: ${tiles[key]!.label}`}>
                                <TileButton label="قبلها" disabled={i === 0} onClick={() => move(key, -1)}><ArrowRight /></TileButton>
                                <TileButton label="بعدها" disabled={i === shown.length - 1} onClick={() => move(key, 1)}><ArrowLeft /></TileButton>
                                <TileButton label="إخفاء" onClick={() => setHidden([...hidden, key])}><EyeOff /></TileButton>
                            </div>
                        )}
                    </div>
                ))}
            </StatGrid>
            {shown.length === 0 && <p className="rounded-[14px] border border-dashed border-[#e8e8e8] py-10 text-center text-sm text-[#9ca3af]">أخفيت كل البطاقات. أعد ما تحتاجه من «تخصيص».</p>}
        </div>
    );
}

function TileButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
    return (
        <button type="button" onClick={onClick} disabled={disabled} aria-label={label} title={label}
            className="grid size-7 place-items-center rounded-[8px] text-[#4b4b4b] transition-colors hover:bg-[#f2f2f0] hover:text-[#111] disabled:opacity-30 [&_svg]:size-3.5">
            {children}
        </button>
    );
}
