import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent, type KeyboardEvent, type ReactNode } from 'react';
import {
    AlertTriangle, ArrowRight, Check, CheckCheck, Clock, Download, FileText, Image as ImageIcon, Info, Link2, Lock, MessageCircle, Mic, Paperclip,
    Search, Send, Smartphone, StickyNote, Unlink, UserRound, Users, Video, X, Zap,
} from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, EmptyState, MemberPicker, Notice } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { postJson, xsrf } from '@/lib/http';
import { label, membershipStatus } from '@/lib/labels';

/*
 * «واتساب ← المحادثات», laid out like WhatsApp Web in the panel's own look: the chats on one side, the open chat on
 * the other, its details beside it on a wide screen; one at a time on a phone. The screen asks the server what changed
 * every few seconds (slower in a background tab) — no page reload. Everything here is checked again on the server;
 * a button missing for someone is a refusal already made there.
 */

type Status = 'new' | 'open' | 'pending' | 'closed';
type Source = 'platform' | 'automation' | 'business_app' | 'history' | 'customer';

interface Row {
    id: number;
    name: string;
    phone: string;
    status: Status;
    unread: number;
    preview: string | null;
    last_direction: 'inbound' | 'outbound' | null;
    last_at: string | null;
    assignee: { id: number; name: string } | null;
    is_member: boolean;
    is_demo: boolean;
    window_open: boolean;
}

interface FileItem { id: number; kind: string; mime: string | null; filename: string | null; size: number | null; status: 'pending' | 'stored' | 'failed'; error: string | null; url: string | null }

interface Msg {
    id: number;
    direction: 'inbound' | 'outbound';
    source: Source;
    type: string;
    body: string | null;
    status: string;
    error: string | null;
    template: string | null;
    sender: string | null;
    at: string;
    logged: boolean;
    attachments: FileItem[];
}

interface Ev { id: number; type: 'note' | 'assigned' | 'status' | 'linked' | 'unlinked'; body: string | null; text: string; user: string | null; at: string }

interface MemberCard {
    restricted: boolean;
    id?: number;
    name?: string;
    member_no?: string;
    phone?: string;
    status?: string;
    url?: string;
    deleted?: boolean;
    membership?: { plan: string; kind: string; status: string; starts_on: string; ends_on: string; days_left: number } | null;
    owed?: string;
    open_invoices?: number;
}

interface Thread {
    id: number;
    row: Row;
    phone_raw: string;
    contact_name: string | null;
    profile_name: string | null;
    window_closes_at: string | null;
    messages: Msg[];
    events: Ev[];
    has_older: boolean;
    member: MemberCard | null;
    members_sharing: number | null;
    can: { reply: boolean; media: boolean; assign: boolean; link: boolean; member: boolean; membership: boolean; finance: boolean };
    templates: { key: string; label: string; body: string }[];
}

interface Props {
    conversations: Row[];
    active: Thread | null;
    unread: number;
    filters: { status?: string; q?: string; assignee?: string };
    assignees: { id: number; name: string }[];
    scope: 'all' | 'assigned';
    connection: { demo: boolean; enabled: boolean; failing: boolean; error: string | null };
    now: string;
}

const STATUSES: Record<Status, [string, 'blue' | 'green' | 'amber' | 'gray']> = {
    new: ['جديدة', 'blue'],
    open: ['مفتوحة', 'green'],
    pending: ['قيد المعالجة', 'amber'],
    closed: ['مغلقة', 'gray'],
};

const SOURCE_LABEL: Partial<Record<Source, string>> = {
    automation: 'رسالة تلقائية',
    business_app: 'من تطبيق WhatsApp Business',
    history: 'من السجل السابق',
};

const POLL_MS = 4000;
const POLL_HIDDEN_MS = 20000;

const clock = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '');
const dayKey = (iso: string) => new Date(iso).toLocaleDateString('en-CA');
function dayLabel(iso: string): string {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date(Date.now() - 86400000);
    if (d.toDateString() === today.toDateString()) return 'اليوم';
    if (d.toDateString() === yesterday.toDateString()) return 'أمس';
    return d.toLocaleDateString('en-CA');
}
function listTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString() ? clock(iso) : d.toLocaleDateString('en-CA').slice(5).replace('-', '/');
}
const initials = (name: string) => name.replace(/^\+/, '').trim().slice(0, 1) || '؟';
const kb = (n: number | null) => (n ? (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`) : '');

async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const res = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) throw Object.assign(new Error(String(res.status)), { status: res.status });
    return res.json();
}

/** A server refusal in the words the server used. */
async function reason(res: Response): Promise<string> {
    try {
        const data = await res.json();
        return data.error ?? (data.errors ? (Object.values(data.errors).flat()[0] as string) : null) ?? data.message ?? 'تعذّر تنفيذ الطلب.';
    } catch {
        return res.status === 403 ? 'لا تملك صلاحية هذا الإجراء.' : 'تعذّر تنفيذ الطلب.';
    }
}

export default function Inbox(props: Props) {
    const can = useCan();
    const [rows, setRows] = useState<Row[]>(props.conversations);
    const [unread, setUnread] = useState(props.unread);
    const [thread, setThread] = useState<Thread | null>(props.active);
    const [filters, setFilters] = useState({ status: props.filters.status ?? '', q: props.filters.q ?? '', assignee: props.filters.assignee ?? '' });
    const [search, setSearch] = useState(filters.q);
    const [showDetails, setShowDetails] = useState(false);
    const [loading, setLoading] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const since = useRef(props.now);
    const threadRef = useRef<Thread | null>(props.active);
    threadRef.current = thread;
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    const query = useCallback((extra: Record<string, string | number | undefined> = {}) => {
        const f = filtersRef.current;
        return Object.fromEntries(Object.entries({ status: f.status || undefined, q: f.q || undefined, assignee: f.assignee || undefined, ...extra }).filter(([, v]) => v !== undefined && v !== ''));
    }, []);

    /** Keep the address in step (a reload or a shared link opens the same view), without a page visit. */
    const syncUrl = useCallback((id: number | null) => {
        const url = route('club.whatsapp.index', query({ c: id ?? undefined }));
        window.history.replaceState(window.history.state, '', url);
    }, [query]);

    const poll = useCallback(async (signal?: AbortSignal) => {
        const t = threadRef.current;
        const lastMsg = t?.messages.length ? t.messages[t.messages.length - 1].id : 0;
        const lastEv = t?.events.length ? t.events[t.events.length - 1].id : 0;
        const data = await getJson<{ conversations: Row[]; unread: number; now: string; thread: null | ({ gone?: boolean; id: number; row?: Row; window_closes_at?: string | null; messages?: Msg[]; events?: Ev[]; updated?: Msg[] }) }>(
            route('club.whatsapp.feed', query({ c: t?.id, after: t ? lastMsg : undefined, events_after: t ? lastEv : undefined, since: since.current })), signal);
        since.current = data.now;
        setRows(data.conversations);
        setUnread(data.unread);
        const delta = data.thread;
        if (delta && threadRef.current && delta.id === threadRef.current.id) {
            if (delta.gone) {
                // Transferred to someone else: it is no longer this person's to see.
                setThread(null);
                setNotice('لم تعد هذه المحادثة ضمن المحادثات التي تراها (حُوّلت لموظف آخر).');
                syncUrl(null);
                return;
            }
            setThread((cur) => {
                if (!cur || cur.id !== delta.id) return cur;
                const updated = new Map((delta.updated ?? []).map((m) => [m.id, m]));
                const known = new Set(cur.messages.map((m) => m.id));
                const knownEv = new Set(cur.events.map((e) => e.id));
                return {
                    ...cur,
                    row: delta.row ?? cur.row,
                    window_closes_at: delta.window_closes_at ?? null,
                    messages: [...cur.messages.map((m) => updated.get(m.id) ?? m), ...(delta.messages ?? []).filter((m) => !known.has(m.id))],
                    events: [...cur.events, ...(delta.events ?? []).filter((e) => !knownEv.has(e.id))],
                };
            });
        }
    }, [query, syncUrl]);

    // The live loop: every few seconds while the tab is in front, slower behind; one request at a time.
    useEffect(() => {
        let timer: number | undefined;
        let stopped = false;
        let controller: AbortController | null = null;
        const tick = async () => {
            controller?.abort();
            controller = new AbortController();
            try {
                await poll(controller.signal);
            } catch {
                /* a missed beat; the next one catches up */
            }
            if (!stopped) timer = window.setTimeout(tick, document.hidden ? POLL_HIDDEN_MS : POLL_MS);
        };
        timer = window.setTimeout(tick, POLL_MS);
        const wake = () => {
            if (!document.hidden) {
                window.clearTimeout(timer);
                tick();
            }
        };
        document.addEventListener('visibilitychange', wake);
        return () => {
            stopped = true;
            window.clearTimeout(timer);
            controller?.abort();
            document.removeEventListener('visibilitychange', wake);
        };
    }, [poll]);

    // A search typed in the box applies after a short pause.
    useEffect(() => {
        if (search === filters.q) return;
        const t = window.setTimeout(() => setFilters((f) => ({ ...f, q: search })), 350);
        return () => window.clearTimeout(t);
    }, [search, filters.q]);

    useEffect(() => {
        syncUrl(threadRef.current?.id ?? null);
        poll().catch(() => undefined);
    }, [filters, poll, syncUrl]);

    const open = async (id: number) => {
        setLoading(true);
        setNotice(null);
        try {
            const data = await getJson<Thread>(route('club.whatsapp.show', id));
            setThread(data);
            setShowDetails(false);
            setRows((rs) => rs.map((r) => (r.id === id ? { ...r, unread: 0 } : r)));
            syncUrl(id);
        } catch (e) {
            setNotice((e as { status?: number }).status === 404 ? 'هذه المحادثة غير متاحة لك.' : 'تعذّر فتح المحادثة.');
        } finally {
            setLoading(false);
        }
    };

    const close = () => {
        setThread(null);
        syncUrl(null);
    };

    const refreshThread = async () => {
        if (!thread) return;
        const data = await getJson<Thread>(route('club.whatsapp.show', thread.id));
        setThread(data);
        poll().catch(() => undefined);
    };

    const tabs: { key: '' | Status; label: string }[] = [{ key: '', label: 'الكل' }, ...(Object.keys(STATUSES) as Status[]).map((k) => ({ key: k, label: STATUSES[k][0] }))];
    const settingsAllowed = can('whatsapp.manage_settings') || can('integrations.manage');
    // Notices run as thin full-width strips above the inbox, which keeps the rest of the screen.
    const strip = 'shrink-0 rounded-none border-x-0 border-t-0';

    return (
        <ClubLayout title="واتساب" fullBleed>
            {props.connection.demo && (
                <Notice tone="blue" icon={<Info />} className={strip}>
                    وضع التجربة: الرسائل لا تُرسل إلى واتساب فعليًا ولا تصل من العملاء، وما يظهر بشارة «تجريبية» بيانات نموذجية فقط.
                </Notice>
            )}
            {!props.connection.demo && !props.connection.enabled && (
                <Notice tone="amber" icon={<AlertTriangle />} className={strip} href={settingsAllowed ? route('club.integrations.whatsapp') : undefined}>
                    ربط واتساب غير مفعّل، فلا تصل رسائل جديدة ولا تُرسل الردود.{settingsAllowed && ' افتح إعدادات واتساب.'}
                </Notice>
            )}
            {props.connection.failing && (
                <Notice tone="red" icon={<AlertTriangle />} className={strip} href={settingsAllowed ? route('club.integrations.whatsapp') : undefined}>
                    انقطع اتصال واتساب: {props.connection.error}
                </Notice>
            )}
            {notice && (
                <Notice tone="amber" className={strip} onClick={() => setNotice(null)}>
                    {notice}
                </Notice>
            )}

            <div className="min-h-0 flex-1 overflow-hidden bg-white">
                <div className={cn('grid h-full min-h-0', 'lg:grid-cols-[minmax(300px,360px)_1fr]', thread && showDetails && 'min-[87.5rem]:grid-cols-[minmax(300px,360px)_1fr_300px]')}>
                    {/* The chats */}
                    <aside className={cn('flex min-h-0 flex-col border-line lg:border-e', thread && 'hidden lg:flex')}>
                        <div className="space-y-2 border-b border-line bg-[#fafaf9] p-3">
                            <div className="flex items-center justify-between gap-2 px-0.5">
                                <h1 className="text-[18px] font-semibold text-ink">{props.scope === 'all' ? 'المحادثات' : 'محادثاتي'}</h1>
                                {unread > 0 && <span className="rounded-full bg-emerald-600 px-2 py-0.5 text-[11px] font-semibold text-white num">{unread} غير مقروءة</span>}
                            </div>
                            <label className="flex h-10 items-center gap-2 rounded-xl border border-line bg-white px-3">
                                <Search className="size-4 text-muted" />
                                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث بالاسم أو رقم الهاتف" className="h-full w-full bg-transparent text-sm outline-none" aria-label="بحث في المحادثات" />
                                {search && <button type="button" onClick={() => setSearch('')} aria-label="مسح البحث"><X className="size-4 text-muted" /></button>}
                            </label>
                            <div className="flex gap-1 overflow-x-auto pb-0.5" role="tablist" aria-label="تصفية حسب الحالة">
                                {tabs.map((t) => (
                                    <button key={t.key} type="button" role="tab" aria-selected={filters.status === t.key} onClick={() => setFilters((f) => ({ ...f, status: t.key }))}
                                        className={cn('shrink-0 rounded-full px-3 py-1 text-[12px] font-medium transition', filters.status === t.key ? 'bg-emerald-600 text-white' : 'bg-white text-muted ring-1 ring-line hover:text-ink')}>
                                        {t.label}
                                    </button>
                                ))}
                            </div>
                            {props.scope === 'all' && (
                                <select value={filters.assignee} onChange={(e) => setFilters((f) => ({ ...f, assignee: e.target.value }))} className="h-9 w-full rounded-lg border border-line bg-white px-2 text-[13px]" aria-label="تصفية حسب الموظف المسؤول">
                                    <option value="">كل الموظفين</option>
                                    <option value="me">المخصصة لي</option>
                                    <option value="none">غير مخصصة</option>
                                    {props.assignees.map((a) => <option key={a.id} value={String(a.id)}>{a.name}</option>)}
                                </select>
                            )}
                        </div>
                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {rows.length === 0 ? (
                                <EmptyState icon={<MessageCircle className="size-6" />} title="لا توجد محادثات" description={filters.q || filters.status || filters.assignee ? 'جرّب تصفية أخرى.' : 'تظهر هنا رسائل العملاء فور وصولها.'} />
                            ) : (
                                <ul>
                                    {rows.map((r) => (
                                        <li key={r.id}>
                                            <button type="button" onClick={() => open(r.id)} className={cn('flex w-full items-center gap-3 border-b border-line/60 px-3 py-3 text-start transition hover:bg-[#f5f5f4]', thread?.id === r.id && 'bg-emerald-50/70')}>
                                                <span className={cn('grid size-11 shrink-0 place-items-center rounded-full text-[15px] font-semibold', r.is_member ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-600')}>{initials(r.name)}</span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center justify-between gap-2">
                                                        <span className="truncate text-[14px] font-semibold text-ink">{r.name}</span>
                                                        <span className={cn('shrink-0 text-[11px] num', r.unread > 0 ? 'font-semibold text-emerald-700' : 'text-muted')}>{listTime(r.last_at)}</span>
                                                    </span>
                                                    <span className="mt-0.5 flex items-center justify-between gap-2">
                                                        <span className="flex min-w-0 items-center gap-1 text-[12.5px] text-muted">
                                                            {r.last_direction === 'outbound' && <CheckCheck className="size-3.5 shrink-0" />}
                                                            <span className="truncate">{r.preview ?? r.phone}</span>
                                                        </span>
                                                        {r.unread > 0 && <span className="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white num">{r.unread}</span>}
                                                    </span>
                                                    <span className="mt-1 flex flex-wrap items-center gap-1">
                                                        <Badge tone={STATUSES[r.status]?.[1] ?? 'gray'}>{STATUSES[r.status]?.[0] ?? r.status}</Badge>
                                                        {r.assignee && <span className="inline-flex items-center gap-1 text-[11px] text-muted"><UserRound className="size-3" />{r.assignee.name}</span>}
                                                        {r.is_demo && <Badge tone="violet">تجريبية</Badge>}
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </aside>

                    {/* The open chat */}
                    <section className={cn('flex min-h-0 flex-col bg-[#f4f1ec]', !thread && 'hidden lg:flex')}>
                        {!thread ? (
                            <div className="grid flex-1 place-items-center p-6">
                                <EmptyState icon={<MessageCircle className="size-6" />} title={loading ? 'جارٍ الفتح…' : 'اختر محادثة'} description="الرسائل الواردة والردود والملاحظات الداخلية تظهر هنا وتتحدث تلقائيًا." />
                            </div>
                        ) : (
                            <ChatView key={thread.id} thread={thread} onBack={close} onChanged={refreshThread} onDetails={() => setShowDetails((v) => !v)} showDetails={showDetails} setThread={setThread} />
                        )}
                    </section>

                    {/* A third column only where the chat keeps room for its buttons (1400px+); narrower, a drawer over it. */}
                    {thread && showDetails && (
                        <aside className="fixed inset-0 z-40 flex min-h-0 flex-col overflow-y-auto bg-white lg:start-auto lg:w-[340px] lg:border-s lg:border-line lg:shadow-xl min-[87.5rem]:static min-[87.5rem]:z-auto min-[87.5rem]:w-auto min-[87.5rem]:shadow-none">
                            <Details thread={thread} assignees={props.assignees} onClose={() => setShowDetails(false)} onChanged={refreshThread} />
                        </aside>
                    )}
                </div>
            </div>
        </ClubLayout>
    );
}

function ChatView({ thread, onBack, onChanged, onDetails, showDetails, setThread }: {
    thread: Thread;
    onBack: () => void;
    onChanged: () => void;
    onDetails: () => void;
    showDetails: boolean;
    setThread: (fn: (t: Thread | null) => Thread | null) => void;
}) {
    const bottom = useRef<HTMLDivElement>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const [olderLoading, setOlderLoading] = useState(false);
    const windowOpen = thread.row.window_open;

    // The thread in time order: messages by when WhatsApp says they happened, notes and events among them.
    const items = useMemo(() => {
        const list: ({ kind: 'msg'; at: string; m: Msg } | { kind: 'ev'; at: string; e: Ev })[] = [
            ...thread.messages.map((m) => ({ kind: 'msg' as const, at: m.at, m })),
            ...thread.events.map((e) => ({ kind: 'ev' as const, at: e.at, e })),
        ];
        return list.sort((a, b) => a.at.localeCompare(b.at));
    }, [thread.messages, thread.events]);

    const count = items.length;
    useEffect(() => {
        const el = scroller.current;
        // Follow new messages only when the reader is already at the bottom.
        if (!el || el.scrollHeight - el.scrollTop - el.clientHeight < 160) bottom.current?.scrollIntoView({ block: 'end' });
    }, [count]);
    useEffect(() => {
        bottom.current?.scrollIntoView({ block: 'end' });
    }, [thread.id]);

    const older = async () => {
        setOlderLoading(true);
        try {
            const data = await getJson<Thread>(route('club.whatsapp.show', { conversation: thread.id, before: thread.messages[0]?.id }));
            setThread((t) => (t && t.id === thread.id ? { ...t, messages: [...data.messages, ...t.messages], has_older: data.has_older } : t));
        } finally {
            setOlderLoading(false);
        }
    };

    let lastDay = '';

    return (
        <>
            <header className="flex items-center gap-2 border-b border-line bg-[#fafaf9] px-3 py-2">
                <button type="button" onClick={onBack} className="grid size-9 place-items-center rounded-lg hover:bg-stone-100 lg:hidden" aria-label="رجوع إلى المحادثات"><ArrowRight className="size-5" /></button>
                <button type="button" onClick={onDetails} className="flex min-w-0 flex-1 items-center gap-3 text-start" aria-expanded={showDetails}>
                    <span className={cn('grid size-10 shrink-0 place-items-center rounded-full text-[15px] font-semibold', thread.row.is_member ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-600')}>{initials(thread.row.name)}</span>
                    <span className="min-w-0">
                        <span className="block truncate text-[15px] font-semibold text-ink">{thread.row.name}</span>
                        <span className="block truncate text-[12px] text-muted"><span dir="ltr" className="num">{thread.row.phone}</span>{thread.row.assignee && ` · مسؤول: ${thread.row.assignee.name}`}</span>
                    </span>
                </button>
                <Badge tone={STATUSES[thread.row.status]?.[1] ?? 'gray'}>{STATUSES[thread.row.status]?.[0]}</Badge>
                <button type="button" onClick={onDetails} className={cn('grid size-9 place-items-center rounded-lg hover:bg-stone-100', showDetails && 'bg-stone-100')} aria-label="تفاصيل المحادثة"><Info className="size-5" /></button>
            </header>

            <div ref={scroller} className="min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-6" style={{ backgroundImage: 'radial-gradient(rgba(0,0,0,0.035) 1px, transparent 1px)', backgroundSize: '18px 18px' }}>
                {thread.has_older && (
                    <div className="mb-3 text-center">
                        <Button size="sm" variant="outline" loading={olderLoading} onClick={older}>رسائل أقدم</Button>
                    </div>
                )}
                <div className="flex w-full flex-col gap-1.5 lg:px-[3%]">
                    {items.map((it) => {
                        const day = dayKey(it.at);
                        const separator = day !== lastDay ? <div key={`d${day}`} className="my-2 self-center rounded-lg bg-white/90 px-3 py-1 text-[11.5px] text-muted shadow-sm num">{dayLabel(it.at)}</div> : null;
                        lastDay = day;
                        return (
                            <FragmentWith key={`${it.kind}${it.kind === 'msg' ? it.m.id : it.e.id}`} before={separator}>
                                {it.kind === 'msg' ? <Bubble m={it.m} /> : <EventLine e={it.e} />}
                            </FragmentWith>
                        );
                    })}
                    <div ref={bottom} />
                </div>
            </div>

            <Composer thread={thread} windowOpen={windowOpen} onSent={(m) => setThread((t) => (t && t.id === thread.id && !t.messages.some((x) => x.id === m.id) ? { ...t, messages: [...t.messages, m] } : t))} onChanged={onChanged} />
        </>
    );
}

function FragmentWith({ before, children }: { before: ReactNode; children: ReactNode }) {
    return (
        <>
            {before}
            {children}
        </>
    );
}

function Ticks({ m }: { m: Msg }) {
    if (m.direction !== 'outbound') return null;
    switch (m.status) {
        case 'queued':
        case 'scheduled':
            return <Clock className="size-3.5" aria-label="قيد الإرسال" />;
        case 'sent':
            return <Check className="size-3.5" aria-label="مرسلة" />;
        case 'delivered':
            return <CheckCheck className="size-3.5" aria-label="مستلمة" />;
        case 'read':
            return <CheckCheck className="size-3.5 text-sky-500" aria-label="مقروءة" />;
        case 'failed':
        case 'skipped':
            return <AlertTriangle className="size-3.5 text-red-600" aria-label="فشلت" />;
        default:
            return null;
    }
}

const STATUS_WORD: Record<string, string> = { queued: 'قيد الإرسال', scheduled: 'مجدولة', sent: 'مرسلة', delivered: 'مستلمة', read: 'مقروءة', failed: 'فشلت', skipped: 'لم تُرسل' };

function Bubble({ m }: { m: Msg }) {
    const out = m.direction === 'outbound';
    const who = out ? (m.source === 'platform' ? m.sender : SOURCE_LABEL[m.source]) : m.source === 'history' ? SOURCE_LABEL.history : null;
    return (
        <div className={cn('flex', out ? 'justify-end' : 'justify-start')}>
            <div className={cn('max-w-[85%] rounded-[10px] px-2.5 pt-1.5 pb-1 text-[14px] leading-6 shadow-[0_1px_0.5px_rgba(0,0,0,0.13)] sm:max-w-[70%]', out ? 'bg-[#d9fdd3]' : 'bg-white', (m.status === 'failed' || m.status === 'skipped') && 'ring-1 ring-red-300')}>
                {who && (
                    <div className={cn('mb-0.5 flex items-center gap-1 text-[11.5px] font-semibold', m.source === 'business_app' ? 'text-sky-700' : m.source === 'automation' ? 'text-violet-700' : 'text-emerald-800')}>
                        {m.source === 'business_app' && <Smartphone className="size-3" />}
                        {m.source === 'automation' && <Zap className="size-3" />}
                        {who}
                    </div>
                )}
                {m.template && <div className="mb-0.5 text-[11px] text-muted">قالب: {m.template}</div>}
                {m.attachments.map((a) => <FileBlock key={a.id} a={a} />)}
                {m.body && <div className="whitespace-pre-wrap break-words" dir="auto">{m.body}</div>}
                {m.error && <div className="mt-1 text-[12px] text-red-700">{m.error}</div>}
                <div className="mt-0.5 flex items-center justify-end gap-1 text-[11px] text-stone-500" title={STATUS_WORD[m.status] ?? m.status}>
                    {m.logged && <span className="text-violet-600">تجربة ·</span>}
                    <span className="num">{clock(m.at)}</span>
                    <Ticks m={m} />
                </div>
            </div>
        </div>
    );
}

function FileBlock({ a }: { a: FileItem }) {
    if (a.status === 'pending') return <div className="mb-1 flex items-center gap-2 rounded-lg bg-black/5 px-2 py-2 text-[12.5px] text-muted"><Clock className="size-4" />جارٍ تنزيل الملف…</div>;
    if (a.status === 'failed' || !a.url) return <div className="mb-1 flex items-center gap-2 rounded-lg bg-red-50 px-2 py-2 text-[12.5px] text-red-700"><AlertTriangle className="size-4" />{a.error ?? 'تعذّر تنزيل الملف.'}</div>;
    if (a.kind === 'image' || a.kind === 'sticker') {
        return (
            <a href={a.url} target="_blank" rel="noreferrer" className="mb-1 block overflow-hidden rounded-lg">
                <img src={a.url} alt={a.filename ?? 'صورة'} className="max-h-72 w-auto max-w-full object-contain" loading="lazy" decoding="async" />
            </a>
        );
    }
    if (a.kind === 'audio') return <audio controls src={a.url} className="mb-1 max-w-full" preload="none" />;
    if (a.kind === 'video') return <video controls src={a.url} className="mb-1 max-h-72 max-w-full rounded-lg" preload="metadata" />;
    return (
        <a href={`${a.url}?download=1`} className="mb-1 flex items-center gap-2 rounded-lg bg-black/5 px-2.5 py-2 transition hover:bg-black/10">
            <FileText className="size-6 shrink-0 text-red-600" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] font-medium">{a.filename ?? 'مستند'}</span>
                <span className="block text-[11px] text-muted num">{kb(a.size)}</span>
            </span>
            <Download className="size-4 shrink-0 text-muted" />
        </a>
    );
}

function EventLine({ e }: { e: Ev }) {
    if (e.type === 'note') {
        return (
            <div className="my-1 self-center w-full max-w-xl rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-[13.5px]">
                <div className="mb-0.5 flex items-center gap-1 text-[11.5px] font-semibold text-amber-800"><Lock className="size-3" />ملاحظة داخلية لا تُرسل للعميل · {e.user ?? 'النظام'}</div>
                <div className="whitespace-pre-wrap break-words" dir="auto">{e.body}</div>
                <div className="mt-0.5 text-end text-[11px] text-amber-700/80 num">{clock(e.at)}</div>
            </div>
        );
    }
    return <div className="my-1 self-center rounded-lg bg-white/80 px-3 py-1 text-[11.5px] text-muted shadow-sm">{e.text} <span className="num">· {clock(e.at)}</span></div>;
}

function Composer({ thread, windowOpen, onSent, onChanged }: { thread: Thread; windowOpen: boolean; onSent: (m: Msg) => void; onChanged: () => void }) {
    const [mode, setMode] = useState<'reply' | 'note'>(thread.can.reply && windowOpen ? 'reply' : 'note');
    const [body, setBody] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [template, setTemplate] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);
    const canNote = thread.can.reply || thread.can.assign;

    if (!thread.can.reply && !canNote) {
        return <div className="border-t border-line bg-[#fafaf9] px-4 py-3 text-center text-[13px] text-muted">تملك عرض هذه المحادثة فقط.</div>;
    }

    const submit = async (e?: FormEvent) => {
        e?.preventDefault();
        if (busy || (!body.trim() && !file)) return;
        setBusy(true);
        setError(null);
        try {
            let res: Response;
            if (mode === 'note') {
                res = await postJson(route('club.whatsapp.notes', thread.id), { body });
            } else if (file) {
                const data = new FormData();
                data.append('file', file);
                if (body.trim()) data.append('caption', body);
                res = await fetch(route('club.whatsapp.media', thread.id), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } });
            } else {
                res = await postJson(route('club.whatsapp.reply', thread.id), { body });
            }
            if (!res.ok) {
                setError(await reason(res));
            } else {
                const data = await res.json();
                if (data.message) onSent(data.message);
                setBody('');
                setFile(null);
                if (mode === 'note') onChanged();
            }
        } catch {
            setError('تعذّر الإرسال. تحقق من الاتصال بالإنترنت.');
        } finally {
            setBusy(false);
        }
    };

    const sendTemplate = async () => {
        if (!template) return;
        setBusy(true);
        setError(null);
        const res = await postJson(route('club.whatsapp.template', thread.id), { template_key: template });
        if (!res.ok) setError(await reason(res));
        else {
            const data = await res.json();
            if (data.message) onSent(data.message);
            setTemplate('');
        }
        setBusy(false);
    };

    const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            submit();
        }
    };

    const pick = (e: ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0] ?? null;
        setFile(f);
        e.target.value = '';
    };

    const replyBlocked = mode === 'reply' && !windowOpen;

    return (
        <div className="border-t border-line bg-[#f0f2f5] px-2 py-2 sm:px-3">
            <div className="mb-1.5 flex items-center gap-1">
                {thread.can.reply && (
                    <button type="button" onClick={() => setMode('reply')} className={cn('rounded-full px-3 py-1 text-[12px] font-medium', mode === 'reply' ? 'bg-emerald-600 text-white' : 'text-muted hover:bg-white')}>رد للعميل</button>
                )}
                {canNote && (
                    <button type="button" onClick={() => { setMode('note'); setFile(null); }} className={cn('inline-flex items-center gap-1 rounded-full px-3 py-1 text-[12px] font-medium', mode === 'note' ? 'bg-amber-500 text-white' : 'text-muted hover:bg-white')}>
                        <StickyNote className="size-3.5" />ملاحظة داخلية
                    </button>
                )}
                {mode === 'reply' && windowOpen && thread.window_closes_at && (
                    <span className="ms-auto text-[11.5px] text-muted">نافذة الرد مفتوحة حتى <span className="num">{clock(thread.window_closes_at)}</span></span>
                )}
            </div>

            {replyBlocked ? (
                <div className="rounded-xl bg-white p-3 text-[13px]">
                    <p className="mb-2 text-muted">انتهت نافذة الـ24 ساعة منذ آخر رسالة من العميل، فلا يسمح واتساب بالرد الحر. أرسل قالبًا معتمدًا، أو انتظر رسالة جديدة من العميل.</p>
                    {thread.templates.length > 0 ? (
                        <div className="flex flex-wrap gap-2">
                            <select value={template} onChange={(e) => setTemplate(e.target.value)} className="h-9 min-w-0 flex-1 rounded-lg border border-line px-2 text-[13px]" aria-label="قالب معتمد">
                                <option value="">اختر قالبًا معتمدًا…</option>
                                {thread.templates.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                            </select>
                            <Button size="sm" disabled={!template} loading={busy} onClick={sendTemplate} icon={<Send className="size-3.5" />}>إرسال القالب</Button>
                        </div>
                    ) : (
                        <p className="text-[12.5px] text-amber-700">{thread.member ? 'لا توجد قوالب معتمدة بعد.' : 'اربط المحادثة بمشترك لإرسال قالب (من التفاصيل).'}</p>
                    )}
                    {error && <p className="mt-2 text-[12.5px] text-red-700">{error}</p>}
                </div>
            ) : (
                <form onSubmit={submit}>
                    {file && (
                        <div className="mb-1.5 flex items-center gap-2 rounded-lg bg-white px-2.5 py-1.5 text-[12.5px]">
                            {file.type.startsWith('image/') ? <ImageIcon className="size-4" /> : file.type.startsWith('audio/') ? <Mic className="size-4" /> : file.type.startsWith('video/') ? <Video className="size-4" /> : <FileText className="size-4" />}
                            <span className="min-w-0 flex-1 truncate">{file.name}</span>
                            <span className="text-muted num">{kb(file.size)}</span>
                            <button type="button" onClick={() => setFile(null)} aria-label="إزالة الملف"><X className="size-4 text-muted" /></button>
                        </div>
                    )}
                    <div className="flex items-end gap-1.5">
                        {mode === 'reply' && thread.can.media && (
                            <>
                                <input ref={fileInput} type="file" hidden onChange={pick} accept="image/jpeg,image/png,video/mp4,video/3gpp,audio/*,application/pdf,text/plain,.doc,.docx,.xls,.xlsx,.ppt,.pptx" />
                                <button type="button" onClick={() => fileInput.current?.click()} className="grid size-10 shrink-0 place-items-center rounded-full text-stone-600 hover:bg-white" aria-label="إرفاق ملف"><Paperclip className="size-5" /></button>
                            </>
                        )}
                        <textarea
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            onKeyDown={onKey}
                            rows={1}
                            maxLength={mode === 'note' ? 2000 : 4000}
                            dir="auto"
                            placeholder={mode === 'note' ? 'ملاحظة للموظفين فقط…' : file ? 'وصف للملف (اختياري)…' : 'اكتب رسالة'}
                            className={cn('max-h-36 min-h-10 flex-1 resize-none rounded-[10px] border-0 px-3 py-2 text-[14px] outline-none', mode === 'note' ? 'bg-amber-50 ring-1 ring-amber-200' : 'bg-white')}
                            aria-label={mode === 'note' ? 'ملاحظة داخلية' : 'الرسالة'}
                        />
                        <button type="submit" disabled={busy || (!body.trim() && !file)} className={cn('grid size-10 shrink-0 place-items-center rounded-full text-white transition disabled:opacity-40', mode === 'note' ? 'bg-amber-500' : 'bg-emerald-600')} aria-label={mode === 'note' ? 'حفظ الملاحظة' : 'إرسال'}>
                            {mode === 'note' ? <StickyNote className="size-4" /> : <Send className="size-4 -scale-x-100" />}
                        </button>
                    </div>
                    {error && <p className="mt-1.5 px-1 text-[12.5px] text-red-700">{error}</p>}
                </form>
            )}
        </div>
    );
}

function Details({ thread, assignees, onClose, onChanged }: { thread: Thread; assignees: { id: number; name: string }[]; onClose: () => void; onChanged: () => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [linking, setLinking] = useState(false);
    const member = thread.member;

    const act = async (url: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);
        const res = await postJson(url, body);
        if (!res.ok) setError(await reason(res));
        else onChanged();
        setBusy(false);
    };

    return (
        <div className="flex flex-col">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="text-[15px] font-semibold">تفاصيل المحادثة</h2>
                <button type="button" onClick={onClose} className="grid size-8 place-items-center rounded-lg hover:bg-stone-100" aria-label="إغلاق التفاصيل"><X className="size-4" /></button>
            </div>

            <div className="space-y-4 p-4 text-[13.5px]">
                <section className="text-center">
                    <div className="mx-auto mb-2 grid size-16 place-items-center rounded-full bg-emerald-100 text-[22px] font-semibold text-emerald-800">{initials(thread.row.name)}</div>
                    <div className="font-semibold">{thread.row.name}</div>
                    <div dir="ltr" className="text-muted num">{thread.row.phone}</div>
                    {thread.profile_name && thread.profile_name !== thread.row.name && <div className="text-[12px] text-muted">اسمه في واتساب: {thread.profile_name}</div>}
                    {thread.row.is_demo && <Badge tone="violet" className="mt-1">محادثة تجريبية</Badge>}
                </section>

                <section className="rounded-xl border border-line p-3">
                    <h3 className="mb-2 flex items-center gap-1.5 text-[12.5px] font-semibold text-muted"><UserRound className="size-3.5" />المشترك</h3>
                    {!member ? (
                        <>
                            <p className="mb-1">عميل جديد — غير مرتبط بمشترك.</p>
                            {thread.members_sharing !== null && thread.members_sharing > 1 && (
                                <p className="mb-2 flex items-start gap-1 text-[12.5px] text-amber-700"><Users className="mt-0.5 size-3.5 shrink-0" />هذا الرقم مسجّل لـ {thread.members_sharing} مشتركين، فاختر المقصود منهم.</p>
                            )}
                            {thread.can.link && (linking ? (
                                <div className="space-y-2">
                                    <MemberPicker autoFocus onSelect={(m) => act(route('club.whatsapp.link', thread.id), { member_id: m.id }).then(() => setLinking(false))} />
                                    <Button size="sm" variant="ghost" onClick={() => setLinking(false)}>إلغاء</Button>
                                </div>
                            ) : (
                                <Button size="sm" variant="outline" icon={<Link2 className="size-3.5" />} onClick={() => setLinking(true)}>ربط بمشترك موجود</Button>
                            ))}
                        </>
                    ) : member.restricted ? (
                        <p className="text-muted">مشترك مسجّل. بياناته لمن يملك صلاحية عرض الأعضاء.</p>
                    ) : (
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-semibold">{member.name}</span>
                                <span className="text-[12px] text-muted num">{member.member_no}</span>
                            </div>
                            {member.membership !== undefined && (member.membership ? (
                                <div className="rounded-lg bg-stone-50 p-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <span>{member.membership.plan}</span>
                                        <Badge tone={label(membershipStatus, member.membership.status)[1]}>{label(membershipStatus, member.membership.status)[0]}</Badge>
                                    </div>
                                    <div className="mt-1 text-[12px] text-muted">من <span className="num">{member.membership.starts_on}</span> إلى <span className="num">{member.membership.ends_on}</span>{member.membership.status === 'active' && <> · باقٍ <span className="num">{member.membership.days_left}</span> يوم</>}</div>
                                </div>
                            ) : <p className="text-[12.5px] text-muted">لا اشتراك.</p>)}
                            {member.owed !== undefined && Number(member.owed) > 0 && <p className="text-[12.5px] text-red-700">مستحق: {money(member.owed)} ({member.open_invoices} فاتورة)</p>}
                            <div className="flex flex-wrap gap-2 pt-1">
                                {member.url && <Button size="sm" variant="outline" href={member.url}>ملف المشترك</Button>}
                                {thread.can.link && <Button size="sm" variant="ghost" icon={<Unlink className="size-3.5" />} loading={busy} onClick={() => act(route('club.whatsapp.link', thread.id), { member_id: null })}>إلغاء الربط</Button>}
                            </div>
                        </div>
                    )}
                </section>

                {thread.can.assign && (
                    <section className="rounded-xl border border-line p-3">
                        <h3 className="mb-2 text-[12.5px] font-semibold text-muted">الموظف المسؤول</h3>
                        <select value={thread.row.assignee?.id ?? ''} disabled={busy} onChange={(e) => act(route('club.whatsapp.assign', thread.id), { user_id: e.target.value ? Number(e.target.value) : null })} className="h-9 w-full rounded-lg border border-line px-2" aria-label="تعيين أو تحويل المحادثة">
                            <option value="">غير مخصصة</option>
                            {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                    </section>
                )}

                {(thread.can.reply || thread.can.assign) && (
                    <section className="rounded-xl border border-line p-3">
                        <h3 className="mb-2 text-[12.5px] font-semibold text-muted">الحالة</h3>
                        <div className="grid grid-cols-2 gap-1.5">
                            {(Object.keys(STATUSES) as Status[]).map((s) => (
                                <button key={s} type="button" disabled={busy || thread.row.status === s} onClick={() => act(route('club.whatsapp.status', thread.id), { status: s })}
                                    className={cn('rounded-lg border px-2 py-1.5 text-[12.5px]', thread.row.status === s ? 'border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : 'border-line hover:bg-stone-50')}>
                                    {STATUSES[s][0]}
                                </button>
                            ))}
                        </div>
                    </section>
                )}

                <section className="text-[12px] text-muted">
                    {thread.row.window_open ? 'نافذة الرد الحر مفتوحة (24 ساعة من آخر رسالة للعميل).' : 'نافذة الرد الحر مغلقة: الرسالة الأولى تكون بقالب معتمد.'}
                    {' '}الرسائل المرسلة من تطبيق WhatsApp Business على الهاتف تظهر بعلامة الهاتف ولا تُنسب لموظف.
                </section>
                {error && <p className="text-[12.5px] text-red-700">{error}</p>}
            </div>
        </div>
    );
}
