import { useEffect, useState } from 'react';
import { Search, UserRound } from 'lucide-react';
import { Badge } from './Card';
import { label, membershipStatus } from '@/lib/labels';

export interface MemberHit {
    id: number;
    member_no: string;
    full_name: string;
    phone: string;
    phone_display: string;
    status: string;
    photo_url: string | null;
    membership: { status: string; plan: string; ends_on: string; days_left: number } | null;
}

/**
 * Search members by name, phone or number (uses club.members.search). The hits open under the field as the reference's
 * customer picker: a 10px-rounded white list with a soft shadow, scrolling past 256px; it closes when the field loses
 * the focus or with Escape, and opens again on coming back to it, clicking it or typing.
 */
export default function MemberPicker({ onSelect, autoFocus, placeholder = 'ابحث بالاسم أو الهاتف أو رقم العضوية…' }: { onSelect: (m: MemberHit) => void; autoFocus?: boolean; placeholder?: string }) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<MemberHit[]>([]);
    const [loading, setLoading] = useState(false);
    const [searched, setSearched] = useState(''); // the query the current hits answer
    const [open, setOpen] = useState(Boolean(autoFocus));

    useEffect(() => {
        if (q.trim().length < 2) {
            setHits([]);
            setSearched('');
            return;
        }
        const controller = new AbortController();
        const id = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(route('club.members.search', { q }), { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (res.ok) {
                    setHits(await res.json());
                    setSearched(q);
                }
            } catch {
                /* aborted */
            } finally {
                setLoading(false);
            }
        }, 250);
        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [q]);

    return (
        <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-muted" />
            <input
                className="input ps-9"
                value={q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onClick={() => setOpen(true)}
                // A moment's grace: a hit pressed with the pointer is chosen before the list goes.
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                placeholder={placeholder}
                autoFocus={autoFocus}
            />
            {open && (hits.length > 0 || (loading && q.length >= 2) || (!loading && searched !== '' && searched === q)) && (
                <div className="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-[10px] border border-[#e8e8e8] bg-white shadow-[0_8px_30px_rgba(0,0,0,0.10)]">
                    {loading && hits.length === 0 && <p className="p-3 text-[12px] text-[#9ca3af]">جارٍ البحث…</p>}
                    {!loading && hits.length === 0 && searched === q && <p className="p-3 text-[12px] text-[#9ca3af]">لا توجد نتائج لـ «{q}».</p>}
                    {hits.map((m) => {
                        const [statusLabel, tone] = m.membership ? label(membershipStatus, m.membership.status) : ['بدون عضوية', 'gray' as const];
                        return (
                            <button
                                key={m.id}
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => {
                                    onSelect(m);
                                    setQ('');
                                    setHits([]);
                                }}
                                className="flex w-full items-center gap-3 px-3 py-2 text-start text-[13px] hover:bg-[#f7f7f5] focus-visible:bg-[#f7f7f5] focus-visible:outline-none"
                            >
                                {m.photo_url ? <img src={m.photo_url} alt="" className="size-9 rounded-full object-cover" /> : <span className="grid size-9 place-items-center rounded-full bg-brand-50 text-brand"><UserRound className="size-4" /></span>}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium text-[#111]">{m.full_name}</span>
                                    <span className="block text-[11px] text-[#6b7280]"><span className="num">{m.member_no}</span> · <span className="num">{m.phone_display}</span></span>
                                </span>
                                <Badge tone={tone}>{statusLabel}</Badge>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
