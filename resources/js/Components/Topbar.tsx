import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Bell, Building2, ChevronDown, Clock, Globe, LoaderCircle, LogOut, Menu, Search, Settings, Store, User as UserIcon, X } from 'lucide-react';
import { Dropdown, DropdownItem, DropdownLabel, DropdownSeparator } from '@/Components/ui/Dropdown';
import { useCan, useShared } from '@/hooks/useShared';
import { NAV } from '@/lib/nav';
import { cn } from '@/lib/cn';

/* 40px square icon buttons with an 18px line icon, quiet grey that darkens on hover. */
const iconButton = 'relative grid size-10 shrink-0 place-items-center rounded-[10px] text-[#4b4b4b] transition-colors hover:bg-[rgba(17,17,17,0.045)] hover:text-[#111] [&_svg]:size-[18px]';

function initials(name?: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
    return (parts.length > 1 ? parts[0][0] + parts[1][0] : (parts[0] ?? '?').slice(0, 2)).toUpperCase();
}

export function Avatar({ name, size = 32 }: { name?: string | null; size?: number }) {
    return (
        <span className="grid shrink-0 place-items-center rounded-full bg-[#f2f2f0] font-semibold text-[#4b4b4b]" style={{ width: size, height: size, fontSize: size >= 40 ? 14 : 12 }}>
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
    const { auth, tenant, modules } = useShared();
    const can = useCan();
    const user = auth?.user;
    const branch = user?.branch ?? (user && user.branches > 1 ? 'كل الفروع' : null);

    return (
        <header className="no-print sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-[#e8e8e8] bg-white/95 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-white/80 lg:px-6">
            <button className={cn(iconButton, 'lg:hidden')} onClick={onMenu} aria-label="القائمة">
                <Menu />
            </button>

            <TopSearch />

            <div className="ms-auto flex shrink-0 items-center gap-1.5">
                {branch && (
                    // The user's branch (or all of them); there is no switching between branches in this system.
                    <span className="hidden h-9 max-w-[11rem] items-center gap-1.5 rounded-[10px] px-2 text-[13px] font-medium text-[#111] md:inline-flex" title="الفرع">
                        <Building2 className="size-4 shrink-0 text-[#6b7280]" />
                        <span className="truncate">{branch}</span>
                    </span>
                )}

                {tenant?.status === 'trial' && (
                    <span className="hidden rounded-full border border-[#e8e8e8] bg-[#f2f2f0] px-2.5 py-1 text-[12px] font-medium text-[#444] xl:inline">فترة تجريبية حتى {tenant.trial_ends_at}</span>
                )}

                {modules.sales && can('pos.sell') && (
                    <Link href={route('club.pos.index')} className={iconButton} title="نقطة البيع">
                        <Store />
                        <span className="sr-only">نقطة البيع</span>
                    </Link>
                )}
                {tenant && can('website.manage') && (
                    <a href={route('site.home', { club: tenant.slug })} target="_blank" rel="noreferrer" className={iconButton} title="موقع النادي">
                        <Globe />
                        <span className="sr-only">موقع النادي</span>
                    </a>
                )}
                <button onClick={() => router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true })} className={cn(iconButton, 'max-sm:hidden')} title="تسجيل حضور/انصراف">
                    <Clock />
                    <span className="sr-only">حضور/انصراف</span>
                </button>

                <NotificationBell />
                <UserMenu />
            </div>
        </header>
    );
}

/**
 * The bell: a red badge with the number of things waiting, and a list of them. Each entry opens its screen; a
 * document alert can be marked as seen from here (the documents section's own «acknowledge»).
 */
function NotificationBell() {
    const { notifications } = useShared();
    if (!notifications) return null;
    const { count, items } = notifications;

    return (
        <Dropdown
            label="الإشعارات"
            panelClassName="w-[21rem] max-w-[calc(100vw-1.5rem)] max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.25rem] max-sm:w-auto"
            trigger={() => (
                <span className={iconButton}>
                    <Bell />
                    {count > 0 && (
                        <span className="absolute end-1.5 top-1.5 flex size-4 items-center justify-center rounded-full bg-[#dc2626] text-[10px] font-semibold text-white">
                            {count > 9 ? '9+' : count}
                        </span>
                    )}
                </span>
            )}
        >
            {(close) => (
                <>
                    <div className="flex items-center justify-between gap-2 px-3 py-1.5">
                        <span className="text-sm font-semibold text-[#111]">الإشعارات</span>
                        {count > 0 && <span className="text-[12px] text-[#9ca3af]">{count > 99 ? '99+' : count} بانتظارك</span>}
                    </div>
                    <DropdownSeparator />
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-3 py-8 text-center">
                            <span className="grid size-10 place-items-center rounded-full bg-[#f2f2f0] text-[#9ca3af]"><Bell className="size-[18px]" /></span>
                            <p className="text-[13px] text-[#9ca3af]">لا توجد إشعارات</p>
                        </div>
                    ) : (
                        <div className="max-h-[55vh] overflow-y-auto">
                            {items.map((item) => (
                                <div key={item.key} className="group flex items-start gap-1 rounded-[8px] px-1 transition-colors hover:bg-[#f5f5f4]">
                                    <Link href={item.url} onClick={close} className="flex min-w-0 flex-1 flex-col gap-0.5 px-2 py-2" role="menuitem">
                                        <span className="text-[13px] font-medium leading-5 text-[#111]">{item.text}</span>
                                        {item.time && <span className="text-[12px] text-[#6b7280]">{item.time}</span>}
                                    </Link>
                                    {item.dismiss && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(item.dismiss!, {}, { preserveScroll: true })}
                                            aria-label="تمت المشاهدة"
                                            title="تمت المشاهدة"
                                            className="mt-2 flex size-6 shrink-0 items-center justify-center rounded-full text-[#9ca3af] transition-colors hover:bg-[#e8e8e6] hover:text-[#111]"
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </Dropdown>
    );
}

/** The user: a 32px circle with initials, the name and the role beneath it; the menu opens on hover or click. */
function UserMenu() {
    const { auth, tenant } = useShared();
    const can = useCan();
    const user = auth?.user;
    if (!user) return null;
    const settings = ['settings.manage', 'branches.manage', 'users.manage', 'roles.manage', 'audit.view', 'backups.manage', 'billing.view', 'accounting.manage', 'documents.manage'].some(can);

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
                        </div>
                    </div>
                    <DropdownLabel className="flex items-center gap-2 font-normal"><UserIcon className="size-3.5" />{user.role}{tenant ? ` · ${tenant.name}` : ''}</DropdownLabel>
                    <DropdownSeparator />
                    <DropdownItem onClick={() => { close(); router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true }); }}>
                        <Clock /> تسجيل حضور/انصراف
                    </DropdownItem>
                    {settings && (
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

type Hit = { key: string; label: string; hint?: string; href: string; group: 'pages' | 'members' };

/**
 * Search in the top bar: the panel's pages this user may open (instantly) and, for whoever sees members, members by
 * name, phone or number (the members search the panel already has). Arrow keys move, Enter opens.
 */
function TopSearch() {
    const can = useCan();
    const { auth } = useShared();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [members, setMembers] = useState<Hit[]>([]);
    const [loading, setLoading] = useState(false);
    const [index, setIndex] = useState(0);
    const box = useRef<HTMLDivElement>(null);

    const pages = useMemo((): Hit[] => {
        const allowed = (p?: string | string[]) => !p || (Array.isArray(p) ? p : [p]).some(can);
        return NAV.flatMap((g) => g.sections.flatMap((s) => s.tabs.filter((t) => allowed(t.permission) && (!t.roleKey || auth?.user.role_key === t.roleKey || can('classes.manage'))).map((t) => ({
            key: `${t.route}-${JSON.stringify(t.params ?? {})}`,
            label: t.label === s.label ? s.label : `${s.label} · ${t.label}`,
            href: String(route(t.route, t.params ?? {})),
            group: 'pages' as const,
        }))));
    }, [auth?.permissions]); // eslint-disable-line react-hooks/exhaustive-deps

    const term = q.trim();
    const pageHits = term ? pages.filter((p) => p.label.includes(term)).slice(0, 6) : [];

    useEffect(() => {
        if (term.length < 2 || !can('members.view')) {
            setMembers([]);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        const t = setTimeout(async () => {
            try {
                const res = await fetch(route('club.members.search', { q: term }), { headers: { Accept: 'application/json' }, signal: controller.signal });
                const rows = (await res.json()) as { id: number; full_name: string; member_no: string; phone_display?: string }[];
                setMembers(rows.slice(0, 6).map((m) => ({ key: `m${m.id}`, label: m.full_name, hint: [m.member_no, m.phone_display].filter(Boolean).join(' · '), href: String(route('club.members.show', m.id)), group: 'members' as const })));
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

    const hits = [...pageHits, ...members];
    useEffect(() => setIndex(0), [term, members.length]);

    const go = (hit?: Hit) => {
        if (!hit) return;
        setOpen(false);
        setQ('');
        router.visit(hit.href);
    };

    return (
        <div ref={box} className="relative w-full min-w-0 max-w-sm max-md:hidden">
            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
            <input
                type="search"
                value={q}
                onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') { e.preventDefault(); setIndex((i) => Math.min(i + 1, hits.length - 1)); }
                    if (e.key === 'ArrowUp') { e.preventDefault(); setIndex((i) => Math.max(i - 1, 0)); }
                    if (e.key === 'Enter') { e.preventDefault(); go(hits[index]); }
                    if (e.key === 'Escape') setOpen(false);
                }}
                placeholder={can('members.view') ? 'ابحث عن عضو أو صفحة…' : 'ابحث عن صفحة…'}
                aria-label="بحث"
                className="h-10 w-full rounded-[12px] border border-[#dcdcdc] bg-white pe-9 ps-9 text-sm text-[#111] outline-none transition placeholder:text-[#9ca3af] focus:border-[#111] focus:shadow-[0_0_0_4px_rgba(17,17,17,0.08)] [&::-webkit-search-cancel-button]:hidden"
            />
            {loading && <LoaderCircle className="absolute end-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-[#9ca3af]" />}

            {open && term && (
                <div className="absolute start-0 top-full z-40 mt-2 max-h-[70vh] w-full overflow-y-auto rounded-[14px] border border-[#e8e8e8] bg-white shadow-lg" role="listbox">
                    {hits.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-[#9ca3af]">{loading ? 'جارٍ البحث…' : 'لا نتائج'}</p>
                    ) : (
                        (['pages', 'members'] as const).map((group) => {
                            const rows = hits.filter((h) => h.group === group);
                            if (rows.length === 0) return null;
                            return (
                                <div key={group}>
                                    <div className="border-b border-[#e8e8e8] bg-[#fbfbfa] px-4 py-2 text-[12px] font-semibold text-[#6b7280]">{group === 'pages' ? 'الصفحات' : 'الأعضاء'}</div>
                                    {rows.map((hit) => {
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
                                                {hit.hint && <span className="shrink-0 truncate text-[12px] text-[#9ca3af]" dir="ltr">{hit.hint}</span>}
                                            </button>
                                        );
                                    })}
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
