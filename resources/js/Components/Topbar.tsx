import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Bell, Blocks, Building2, Check, CheckCheck, ChevronDown, Clock, CreditCard, FileText, Globe, LoaderCircle, Lock, LogOut, Mail, Menu, MessageCircle, Search, Settings, ShoppingBag, Store, Ticket, UserRound, X } from 'lucide-react';
import { Dropdown, DropdownItem, DropdownLabel, DropdownSeparator } from '@/Components/ui/Dropdown';
import { useCan, useShared } from '@/hooks/useShared';
import { NAV } from '@/lib/nav';
import { cn } from '@/lib/cn';
import { fold } from '@/lib/format';
import type { StaffNotification } from '@/types';

/* 40px square icon buttons with an 18px line icon, quiet grey that darkens on hover. */
const iconButton = 'relative grid size-10 shrink-0 place-items-center rounded-[10px] text-[#4b4b4b] transition-colors hover:bg-[rgba(17,17,17,0.045)] hover:text-[#111] [&_svg]:size-[18px]';

const SETTINGS_PERMISSIONS = ['settings.manage', 'branches.manage', 'users.manage', 'roles.manage', 'audit.view', 'backups.manage', 'billing.view', 'accounting.manage', 'documents.manage'];

export const NOTIFICATION_ICONS: Record<StaffNotification['kind'], LucideIcon> = {
    document: FileText, order: ShoppingBag, payment: CreditCard, membership: Ticket, integration: Blocks, contact: Mail, inbox: MessageCircle,
};

function initials(name?: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
    return (parts.length > 1 ? parts[0][0] + parts[1][0] : (parts[0] ?? '?').slice(0, 2)).toUpperCase();
}

export function Avatar({ name, size = 32 }: { name?: string | null; size?: number }) {
    return (
        <span className="grid shrink-0 place-items-center rounded-full bg-[#f2f2f0] font-semibold text-[#4b4b4b]" style={{ width: size, height: size, fontSize: size >= 40 ? 14 : 12 }} aria-hidden>
            {initials(name)}
        </span>
    );
}

/**
 * The club panel's top bar, 64px: on the start side the menu button (phones) and the search; on the end side the
 * branch, the quick icons (point of sale, the club's website, clock in/out), the bell and the user. Every element is
 * shown only to whoever may use it.
 */
export default function Topbar({ onMenu }: { onMenu: () => void }) {
    const { tenant, modules } = useShared();
    const can = useCan();
    const bell = useBellFeed();

    return (
        <header className="no-print sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-[#e8e8e8] bg-white/95 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-white/80 lg:px-6">
            <button className={cn(iconButton, 'lg:hidden')} onClick={onMenu} aria-label="القائمة">
                <Menu />
            </button>

            <TopSearch className="max-w-sm max-md:hidden" />
            <MobileSearch />

            <div className="ms-auto flex shrink-0 items-center gap-1.5">
                <BranchPicker />

                {tenant?.status === 'trial' && (
                    <span className="hidden rounded-full border border-[#e8e8e8] bg-[#f2f2f0] px-2.5 py-1 text-[12px] font-medium text-[#444] xl:inline">فترة تجريبية حتى {tenant.trial_ends_at}</span>
                )}

                {modules.sales && can('pos.sell') && (
                    <Link href={route('club.pos.index')} className={cn(iconButton, 'max-sm:hidden')} title="نقطة البيع">
                        <Store />
                        <span className="sr-only">نقطة البيع</span>
                    </Link>
                )}
                {tenant && can('website.manage') && (
                    <a href={route('site.home', { club: tenant.slug })} target="_blank" rel="noreferrer" className={cn(iconButton, 'max-sm:hidden')} title="موقع النادي">
                        <Globe />
                        <span className="sr-only">موقع النادي</span>
                    </a>
                )}
                <button onClick={() => router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true })} className={cn(iconButton, 'max-sm:hidden')} title="تسجيل حضور/انصراف">
                    <Clock />
                    <span className="sr-only">حضور/انصراف</span>
                </button>

                {bell && <NotificationBell bell={bell} />}
                <UserMenu unread={bell?.unread ?? 0} />
            </div>
        </header>
    );
}

/**
 * The branch: a club-wide user of a club with several branches picks one (or all of them) — the server checks the
 * branch belongs to the club and narrows the pages to it. A user tied to a branch sees it, locked.
 */
function BranchPicker() {
    const { auth } = useShared();
    const branch = auth?.branch;
    if (!branch) return null;
    const label = branch.current?.name ?? 'كل الفروع';

    if (branch.options.length === 0) {
        if (!branch.current) return null;
        return (
            <span className="hidden h-9 max-w-[11rem] items-center gap-1.5 rounded-[10px] px-2 text-[13px] font-medium text-[#111] md:inline-flex" title={branch.locked ? 'فرعك — يحدده مدير المستخدمين' : 'الفرع'}>
                <Building2 className="size-4 shrink-0 text-[#6b7280]" />
                <span className="truncate">{label}</span>
                {branch.locked && <Lock className="size-3 shrink-0 text-[#9ca3af]" />}
            </span>
        );
    }

    const pick = (id: number | null) => router.post(route('club.branch.switch'), { branch_id: id }, { preserveScroll: true });

    return (
        <Dropdown
            label="الفرع"
            className="max-md:hidden"
            panelClassName="w-60"
            trigger={() => (
                <span className="inline-flex h-9 max-w-[11rem] items-center gap-1.5 rounded-[10px] px-2 text-[13px] font-medium text-[#111] transition-colors hover:bg-[rgba(17,17,17,0.045)]">
                    <Building2 className="size-4 shrink-0 text-[#6b7280]" />
                    <span className="truncate">{label}</span>
                    <ChevronDown className="size-3.5 shrink-0 opacity-50" />
                </span>
            )}
        >
            {(close) => (
                <>
                    <DropdownLabel className="font-normal">الفرع</DropdownLabel>
                    <DropdownItem onClick={() => { close(); pick(null); }}>
                        <span className="flex-1">كل الفروع</span>
                        {!branch.current && <Check />}
                    </DropdownItem>
                    {branch.options.map((b) => (
                        <DropdownItem key={b.id} onClick={() => { close(); pick(b.id); }}>
                            <span className="flex-1 truncate">{b.name}</span>
                            {branch.current?.id === b.id && <Check />}
                        </DropdownItem>
                    ))}
                </>
            )}
        </Dropdown>
    );
}

/** One notification row: kind icon, text and time; opening it marks it read and leads to its screen. */
export function NotificationRow({ n, onOpen, compact = false }: { n: StaffNotification; onOpen?: () => void; compact?: boolean }) {
    const Icon = NOTIFICATION_ICONS[n.kind] ?? Bell;
    return (
        <div className={cn('group flex items-start gap-1 rounded-[8px] px-1 transition-colors hover:bg-[#f5f5f4]', !n.read && 'bg-[#fafaf9]')}>
            <Link href={route('club.notifications.open', n.id)} onClick={onOpen} className={cn('flex min-w-0 flex-1 items-start gap-2.5 px-2', compact ? 'py-2' : 'py-3')}>
                <span className={cn('relative mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-[#f2f2f0]', n.read ? 'text-[#9ca3af]' : 'text-[#111]')}>
                    <Icon className="size-4" />
                    {!n.read && <span className="absolute -end-0.5 -top-0.5 size-2.5 rounded-full border-2 border-white bg-[#dc2626]" />}
                </span>
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className={cn('text-[13px] leading-5', n.read ? 'text-[#6b7280]' : 'font-medium text-[#111]')}>{n.text}</span>
                    <span className="text-[12px] text-[#9ca3af]">{n.kind_label} · {n.time}</span>
                    {!n.read && <span className="sr-only">غير مقروء</span>}
                </span>
            </Link>
            {!n.read && (
                <button
                    type="button"
                    onClick={() => router.post(route('club.notifications.read', n.id), {}, { preserveScroll: true })}
                    aria-label="تعليم كمقروء"
                    title="تعليم كمقروء"
                    className={cn('flex size-6 shrink-0 items-center justify-center rounded-full text-[#9ca3af] transition-colors hover:bg-[#e8e8e6] hover:text-[#111]', compact ? 'mt-2' : 'mt-3')}
                >
                    <Check className="size-3.5" />
                </button>
            )}
        </div>
    );
}

/** The bell: a red badge with the unread count, the latest notifications, «mark all read» and the full list. */
type Bell = { unread: number; items: StaffNotification[] };

const BELL_POLL_MS = 60_000;

/**
 * The bell's state: what the page brought, refreshed about once a minute while the tab is visible and at once when
 * it becomes visible again (one light request; the items come only when they changed). A page visit's fresh props
 * replace it. Signed out or forbidden stops the polling; a network error waits for the next turn.
 */
function useBellFeed(): Bell | null {
    const { notifications } = useShared();
    const [bell, setBell] = useState<Bell | null>(notifications ?? null);
    const shown = useRef({ since: 0, unread: 0 });
    shown.current = { since: bell?.items[0]?.id ?? 0, unread: bell?.unread ?? 0 };

    useEffect(() => setBell(notifications ?? null), [notifications]);

    const enabled = notifications != null;
    useEffect(() => {
        if (!enabled) return;
        let stopped = false;
        const poll = async () => {
            if (stopped || document.visibilityState !== 'visible') return;
            try {
                const res = await fetch(route('club.notifications.feed', shown.current), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!res.ok) {
                    if ([401, 403, 419].includes(res.status)) stopped = true;
                    return;
                }
                const data = (await res.json()) as { unread: number; items: StaffNotification[] | null };
                if (!stopped) setBell((b) => b && { unread: data.unread, items: data.items ?? b.items });
            } catch {
                // Offline for a moment: the next turn tries again.
            }
        };
        const timer = window.setInterval(poll, BELL_POLL_MS);
        const onVisible = () => void poll();
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            stopped = true;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [enabled]);

    return bell;
}

function NotificationBell({ bell }: { bell: Bell }) {
    const { unread, items } = bell;

    return (
        <Dropdown
            label="الإشعارات"
            panelClassName="w-[21rem] max-w-[calc(100vw-1.5rem)] max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.25rem] max-sm:w-auto"
            trigger={() => (
                <span className={iconButton}>
                    <Bell />
                    {unread > 0 && (
                        <span className="absolute end-1.5 top-1.5 flex size-4 items-center justify-center rounded-full bg-[#dc2626] text-[10px] font-semibold text-white">
                            {unread > 9 ? '9+' : unread}
                        </span>
                    )}
                </span>
            )}
        >
            {(close) => (
                <>
                    <div className="flex items-center justify-between gap-2 px-3 py-1.5">
                        <span className="text-sm font-semibold text-[#111]">الإشعارات</span>
                        {unread > 0 && (
                            <button
                                type="button"
                                onClick={() => router.post(route('club.notifications.read-all'), {}, { preserveScroll: true })}
                                className="inline-flex items-center gap-1 text-[12px] text-[#6b7280] transition-colors hover:text-[#111]"
                            >
                                <CheckCheck className="size-3.5" /> تعليم الكل كمقروء
                            </button>
                        )}
                    </div>
                    <DropdownSeparator />
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-3 py-8 text-center">
                            <span className="grid size-10 place-items-center rounded-full bg-[#f2f2f0] text-[#9ca3af]"><Bell className="size-[18px]" /></span>
                            <p className="text-[13px] text-[#9ca3af]">لا توجد إشعارات</p>
                        </div>
                    ) : (
                        <div className="max-h-[55vh] overflow-y-auto">
                            {items.map((n) => <NotificationRow key={n.id} n={n} onOpen={close} compact />)}
                        </div>
                    )}
                    <DropdownSeparator />
                    <Link href={route('club.notifications.index')} onClick={close} className="block rounded-[8px] px-3 py-2 text-center text-[13px] font-medium text-[#111] transition-colors hover:bg-[#f2f2f0]">
                        عرض كل الإشعارات
                    </Link>
                </>
            )}
        </Dropdown>
    );
}

/** The user: a 32px circle with initials, the name and the role beneath it; the menu opens on hover or click. */
function UserMenu({ unread }: { unread: number }) {
    const { auth, tenant } = useShared();
    const can = useCan();
    const user = auth?.user;
    const branchOptions: { id: number | null; name: string }[] = auth?.branch?.options ?? [];
    if (!user) return null;

    return (
        <Dropdown
            hover
            label="حساب المستخدم"
            panelClassName="w-72 p-2"
            trigger={({ open }) => (
                <span className={cn('flex items-center gap-2 rounded-[10px] px-1.5 py-1 transition-colors hover:bg-[rgba(17,17,17,0.045)]', open && 'bg-[rgba(17,17,17,0.045)]')}>
                    <Avatar name={user.name} />
                    <span className="hidden text-start sm:block">
                        <span className="block max-w-[10rem] truncate text-[13px] font-medium leading-tight text-[#111]">{user.name}</span>
                        <span className="block max-w-[10rem] truncate text-[11px] leading-tight text-[#9ca3af]">{user.role}</span>
                    </span>
                    <ChevronDown className="hidden size-3.5 text-[#9ca3af] sm:block" />
                </span>
            )}
        >
            {(close) => (
                <>
                    <div className="flex items-center gap-3 px-1.5 pb-2 pt-1">
                        <Avatar name={user.name} size={40} />
                        <div className="min-w-0 text-start">
                            <p className="truncate text-[14px] font-bold text-[#111]">{user.name}</p>
                            <p className="truncate text-[12px] text-[#6b7280]" dir="ltr">{user.email}</p>
                            <p className="truncate text-[12px] text-[#9ca3af]">{[user.role, auth?.branch?.current?.name, tenant?.name].filter(Boolean).join(' · ')}</p>
                        </div>
                    </div>
                    {/* On a phone the top bar has no room for the branch picker: the choice sits here. */}
                    {branchOptions.length > 0 && (
                        <div className="md:hidden">
                            <DropdownSeparator />
                            <DropdownLabel className="font-normal">الفرع</DropdownLabel>
                            {[{ id: null, name: 'كل الفروع' }, ...branchOptions].map((b) => (
                                <DropdownItem key={b.id ?? 'all'} onClick={() => { close(); router.post(route('club.branch.switch'), { branch_id: b.id }, { preserveScroll: true }); }}>
                                    <Building2 /> <span className="flex-1 truncate">{b.name}</span>
                                    {(auth?.branch?.current?.id ?? null) === b.id && <Check />}
                                </DropdownItem>
                            ))}
                        </div>
                    )}
                    <DropdownSeparator />
                    <DropdownItem href={route('club.account.show')} onClick={close}>
                        <UserRound /> حسابي
                    </DropdownItem>
                    <DropdownItem href={route('club.notifications.index')} onClick={close}>
                        <Bell /> <span className="flex-1">الإشعارات</span>
                        {unread > 0 && <span className="rounded-full bg-[#dc2626] px-1.5 text-[10px] font-semibold leading-4 text-white">{unread > 99 ? '99+' : unread}</span>}
                    </DropdownItem>
                    <DropdownItem onClick={() => { close(); router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true }); }}>
                        <Clock /> تسجيل حضور/انصراف
                    </DropdownItem>
                    {SETTINGS_PERMISSIONS.some(can) && (
                        <DropdownItem href={route('club.settings.index')} onClick={close}>
                            <Settings /> الإعدادات
                        </DropdownItem>
                    )}
                    <DropdownSeparator />
                    <DropdownItem destructive className="justify-center font-semibold" onClick={() => router.post(route('logout'))}>
                        <LogOut /> تسجيل الخروج
                    </DropdownItem>
                </>
            )}
        </Dropdown>
    );
}

type Hit = { key: string; label: string; hint?: string; href: string };
type Group = { key: string; label: string; items: { label: string; hint?: string; url: string }[] };

/**
 * Search in the top bar: the panel's pages this user may open (instantly, in the browser) and, from the server,
 * members, products, sales and website orders, invoices, payments, employees and documents — each group only for
 * whoever may open it. Arrow keys
 * move, Enter opens, Escape closes; on a computer «/» jumps into it.
 */
function TopSearch({ className, autoFocus, onDone }: { className?: string; autoFocus?: boolean; onDone?: () => void }) {
    const can = useCan();
    const { auth } = useShared();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [groups, setGroups] = useState<Group[]>([]);
    const [loading, setLoading] = useState(false);
    const [index, setIndex] = useState(0);
    const box = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);

    const pages = useMemo((): Hit[] => {
        const allowed = (p?: string | string[]) => !p || (Array.isArray(p) ? p : [p]).some(can);
        return NAV.flatMap((g) => g.sections.flatMap((s) => s.tabs.filter((t) => allowed(t.permission) && (!t.roleKey || auth?.user.role_key === t.roleKey || can('classes.manage'))).map((t) => ({
            key: `page-${t.route}-${JSON.stringify(t.params ?? {})}`,
            label: t.label === s.label ? s.label : `${s.label} · ${t.label}`,
            href: String(route(t.route, t.params ?? {})),
        }))));
    }, [auth?.permissions]); // eslint-disable-line react-hooks/exhaustive-deps

    const term = fold(q);
    const pageHits = term ? pages.filter((p) => fold(p.label).includes(term)).slice(0, 5) : [];

    useEffect(() => {
        if (term.length < 2) {
            setGroups([]);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        const t = setTimeout(async () => {
            try {
                const res = await fetch(route('club.search', { q: q.trim() }), { headers: { Accept: 'application/json' }, signal: controller.signal });
                setGroups(((await res.json()) as { groups: Group[] }).groups);
            } catch {
                /* aborted or offline: keep the page results */
            } finally {
                setLoading(false);
            }
        }, 220);
        return () => {
            clearTimeout(t);
            controller.abort();
        };
    }, [term]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('pointerdown', onDown);
        return () => document.removeEventListener('pointerdown', onDown);
    }, [open]);

    // «/» focuses the search, unless the user is typing somewhere.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement;
            if (e.key === '/' && !e.metaKey && !e.ctrlKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) && !target.isContentEditable) {
                e.preventDefault();
                input.current?.focus();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const sections = [
        { key: 'pages', label: 'الصفحات', hits: pageHits },
        ...groups.map((g) => ({ key: g.key, label: g.label, hits: g.items.map((i, n): Hit => ({ key: `${g.key}-${n}`, label: i.label, hint: i.hint, href: i.url })) })),
    ].filter((s) => s.hits.length > 0);
    const hits = sections.flatMap((s) => s.hits);
    useEffect(() => setIndex(0), [term, groups]);

    const go = (hit?: Hit) => {
        if (!hit) return;
        setOpen(false);
        setQ('');
        onDone?.();
        router.visit(hit.href);
    };

    return (
        <div ref={box} className={cn('relative w-full min-w-0', className)}>
            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
            <input
                ref={input}
                autoFocus={autoFocus}
                type="search"
                value={q}
                onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') { e.preventDefault(); setIndex((i) => Math.min(i + 1, hits.length - 1)); }
                    if (e.key === 'ArrowUp') { e.preventDefault(); setIndex((i) => Math.max(i - 1, 0)); }
                    if (e.key === 'Enter') { e.preventDefault(); go(hits[index]); }
                    if (e.key === 'Escape') { setOpen(false); input.current?.blur(); onDone?.(); }
                }}
                placeholder="ابحث عن عضو، فاتورة، دفعة، منتج، موظف أو صفحة…"
                aria-label="بحث"
                className="h-10 w-full rounded-[12px] border border-[#dcdcdc] bg-white pe-9 ps-9 text-sm text-[#111] outline-none transition placeholder:text-[#9ca3af] focus:border-[#111] focus:shadow-[0_0_0_4px_rgba(17,17,17,0.08)] [&::-webkit-search-cancel-button]:hidden"
            />
            {loading ? (
                <LoaderCircle className="absolute end-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-[#9ca3af]" />
            ) : (
                !q && <kbd className="pointer-events-none absolute end-3 top-1/2 hidden -translate-y-1/2 rounded-[6px] border border-[#e8e8e8] px-1.5 text-[11px] text-[#9ca3af] lg:block">/</kbd>
            )}

            {open && term && (
                <div className="absolute start-0 top-full z-40 mt-2 max-h-[70vh] w-full overflow-y-auto rounded-[14px] border border-[#e8e8e8] bg-white shadow-lg" role="listbox" aria-label="نتائج البحث">
                    {hits.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-[#9ca3af]">{loading ? 'جارٍ البحث…' : 'لا نتائج'}</p>
                    ) : (
                        sections.map((section) => (
                            <div key={section.key}>
                                <div className="border-b border-[#e8e8e8] bg-[#fbfbfa] px-4 py-2 text-[12px] font-semibold text-[#6b7280]">{section.label}</div>
                                {section.hits.map((hit) => {
                                    const active = hits[index]?.key === hit.key;
                                    return (
                                        <button
                                            key={hit.key}
                                            type="button"
                                            role="option"
                                            aria-selected={active}
                                            onMouseEnter={() => setIndex(hits.indexOf(hit))}
                                            onClick={() => go(hit)}
                                            className={cn('flex w-full items-center justify-between gap-3 px-4 py-2.5 text-start', active ? 'bg-[#f5f5f4]' : 'hover:bg-[#fafafa]')}
                                        >
                                            <span className="truncate text-sm text-[#111]">{hit.label}</span>
                                            {hit.hint && <span className="shrink-0 truncate text-[12px] text-[#9ca3af]" dir="auto">{hit.hint}</span>}
                                        </button>
                                    );
                                })}
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

/** Phones: a search button that opens the same search across the top of the screen. */
function MobileSearch() {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button className={cn(iconButton, 'md:hidden')} onClick={() => setOpen(true)} aria-label="بحث">
                <Search />
            </button>
            {open && (
                <div className="fixed inset-x-0 top-0 z-40 flex items-center gap-2 border-b border-[#e8e8e8] bg-white px-3 py-3 shadow-sm md:hidden">
                    <TopSearch autoFocus onDone={() => setOpen(false)} />
                    <button className={cn(iconButton, 'shrink-0')} onClick={() => setOpen(false)} aria-label="إغلاق البحث">
                        <X />
                    </button>
                </div>
            )}
        </>
    );
}
