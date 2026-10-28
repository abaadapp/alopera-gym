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

/** Search members by name, phone or number (uses club.members.search). */
export default function MemberPicker({ onSelect, autoFocus, placeholder = 'ابحثي بالاسم أو الهاتف أو رقم العضوية…' }: { onSelect: (m: MemberHit) => void; autoFocus?: boolean; placeholder?: string }) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<MemberHit[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (q.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const id = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(route('club.members.search', { q }), { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (res.ok) setHits(await res.json());
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
            <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} autoFocus={autoFocus} />
            {(hits.length > 0 || (loading && q.length >= 2)) && (
                <div className="absolute inset-x-0 top-full z-30 mt-1 max-h-80 overflow-y-auto rounded-xl border border-line bg-white p-1 shadow-xl">
                    {loading && hits.length === 0 && <p className="px-3 py-2 text-sm text-muted">جارٍ البحث…</p>}
                    {hits.map((m) => {
                        const [statusLabel, tone] = m.membership ? label(membershipStatus, m.membership.status) : ['بدون عضوية', 'gray' as const];
                        return (
                            <button
                                key={m.id}
                                type="button"
                                onClick={() => {
                                    onSelect(m);
                                    setQ('');
                                    setHits([]);
                                }}
                                className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start hover:bg-brand-50"
                            >
                                {m.photo_url ? <img src={m.photo_url} alt="" className="size-9 rounded-full object-cover" /> : <span className="grid size-9 place-items-center rounded-full bg-brand-50 text-brand"><UserRound className="size-4" /></span>}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">{m.full_name}</span>
                                    <span className="block text-xs text-muted"><span className="num">{m.member_no}</span> · <span className="num">{m.phone_display}</span></span>
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
