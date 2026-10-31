import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Building2 } from 'lucide-react';
import { useShared } from '@/hooks/useShared';
import SectionTabs from '@/Components/SectionTabs';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

/**
 * The content card: white, 16px corners, a hairline edge and the faintest shadow; 20px inside. `fill` makes it take
 * the height of its row as a column, so a CardFooter sits at the same height in every card of the row.
 */
export function Card({ children, className, padded = true, fill = false }: { children: ReactNode; className?: string; padded?: boolean; fill?: boolean }) {
    return <div className={cn('card', padded && 'p-5', fill && 'flex h-full flex-col', className)}>{children}</div>;
}

/** The foot of a card: its actions or a closing line, under a hairline, pushed to the bottom of a `fill` card. */
export function CardFooter({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div className="mt-auto pt-4">
            <div className={cn('flex flex-wrap items-center gap-2 border-t border-line pt-3', className)}>{children}</div>
        </div>
    );
}

export function CardHeader({ title, subtitle, actions, className }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; className?: string }) {
    return (
        <div className={cn('mb-4 flex flex-wrap items-start justify-between gap-3', className)}>
            <div>
                <h3 className="text-[15px] font-semibold text-ink">{title}</h3>
                {subtitle && <p className="mt-0.5 text-[13px] text-muted">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

/* Pages that always show the whole club, whatever branch the top bar is on (the books, payroll, reports…). */
const CLUB_WIDE = ['club.finance.*', 'club.reports.*', 'club.payroll.*', 'club.schedule.*', 'club.trainers.*', 'club.pt.*', 'club.pt-packages.*', 'club.products.*', 'club.product-categories.*', 'club.product-addons.*'];

function BranchNote() {
    const { auth } = useShared();
    const branch = auth?.branch?.current;
    if (!branch || !CLUB_WIDE.some((pattern) => route().current(pattern))) return null;
    return (
        <p className="mt-3 inline-flex items-center gap-1.5 rounded-full bg-[#f2f2f0] px-3 py-1 text-[12px] text-[#4b4b4b]">
            <Building2 className="size-3.5" /> تعرض هذه الصفحة كل الفروع؛ اختيار «{branch.name}» لا يغيّرها.
        </p>
    );
}

/** A page's title, description and actions; on a page that is one tab of a section, the section's tabs follow. */
export function PageHeader({ title, subtitle, actions, back }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; back?: ReactNode }) {
    return (
        <div className="mb-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="min-w-0">
                    {back}
                    <h1 className="truncate text-[22px] font-bold tracking-tight text-ink">{title}</h1>
                    {subtitle && <p className="mt-0.5 text-[13px] text-muted">{subtitle}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            <BranchNote />
            <SectionTabs className="mt-5" />
        </div>
    );
}

const tones = {
    brand: 'bg-brand-50 text-brand-700',
    green: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
    gray: 'bg-stone-100 text-stone-600',
    blue: 'bg-sky-50 text-sky-700',
    violet: 'bg-violet-50 text-violet-700',
};

export function Badge({ children, tone = 'gray', className }: { children: ReactNode; tone?: keyof typeof tones; className?: string }) {
    // In a control panel the neutral badge is grey, not warm stone.
    const admin = useAdmin();
    // In a control panel the status colours are the stat tiles' (green success, amber warning, red danger, blue info).
    return <span className={cn('inline-flex items-center rounded-full font-medium whitespace-nowrap', admin ? 'gap-1.5 px-2.5 py-1 text-[12px] [&_svg]:size-3.5 [&_svg]:shrink-0' : 'gap-1 px-2.5 py-0.5 text-xs', admin ? statTones[tone] : tones[tone], className)}>{children}</span>;
}

/* Stat tiles take the status colours: a pale fill with a deeper icon. */
export const statTones = {
    brand: 'bg-brand-50 text-brand-700',
    green: 'bg-[#ecfdf5] text-[#047857]',
    amber: 'bg-[#fffbeb] text-[#d97706]',
    red: 'bg-[#fef2f2] text-[#b91c1c]',
    gray: 'bg-[#f2f2f0] text-[#4b4b4b]',
    blue: 'bg-[#eff6ff] text-[#2563eb]',
    violet: 'bg-[#f5f3ff] text-[#6d28d9]',
};

/**
 * A stat tile: the label (13px grey), the figure (20px bold) under it, and a secondary line (12px) at the foot; the
 * icon sits in a 40px, 12px-rounded square of its status colour on the far side. Padding 16px. In a narrow tile
 * (two to a row on a phone) the icon steps aside so the figure keeps its room.
 */
export type StatTrend = { change: number; label: string; inverted?: boolean };

/** The change against an earlier period: the arrow shows the direction; the colour whether that is good (for a figure
 *  where less is better — expenses, cancellations — a drop is green). */
export function TrendLine({ trend, className }: { trend: StatTrend; className?: string }) {
    const good = (trend.change >= 0) !== Boolean(trend.inverted);
    return (
        <p className={cn('flex items-center gap-1 text-[12px] font-medium', good ? 'text-[#047857]' : 'text-[#b91c1c]', className)}>
            {trend.change >= 0 ? <ArrowUpRight className="size-3.5 shrink-0" /> : <ArrowDownRight className="size-3.5 shrink-0" />}
            <span className="num" dir="ltr">{trend.change > 0 ? '+' : ''}{trend.change}%</span>
            <span className="truncate font-normal text-[#6b7280]">{trend.label}</span>
        </p>
    );
}

/**
 * The grid for stat tiles, everywhere: one per row on phones, two on tablets, three or four on wide screens, 16px
 * apart. Every row takes the height of the tallest tile, so tiles of one grid are the same size whether or not
 * one of them has a comparison or a note.
 */
export function StatGrid({ cols = 4, className, children }: { cols?: 3 | 4; className?: string; children: ReactNode }) {
    return <div className={cn('grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2', cols === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3', className)}>{children}</div>;
}

/** The hover of every card that opens something: a darker edge and a soft lift. */
export const cardHover = 'transition hover:border-[#d4d4d4] hover:shadow-[0_8px_30px_-12px_rgba(17,17,17,0.14)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#111]/10';
/** A card or tile that is the filter or choice in use: a dark edge. */
export const cardActive = 'border-[#111] shadow-[0_0_0_1px_#111] hover:border-[#111]';

/**
 * A stat tile. The label, the figure and the icon keep their place at the top; the comparison and the note sit at
 * the foot, so in a row of tiles the figures line up whatever lines follow them. With `href` it is a link (a filter
 * tile when `active` marks the filter in use).
 */
export function StatCard({ label, value, hint, icon, tone = 'brand', href, trend, active }: { label: ReactNode; value: ReactNode; hint?: ReactNode; icon?: ReactNode; tone?: keyof typeof tones; href?: string; trend?: StatTrend | null; active?: boolean }) {
    const body = (
        <div className={cn('card @container flex h-full flex-col p-4', href && cardHover, active && cardActive)}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate text-[13px] text-[#6b7280]">{label}</p>
                    <p className="mt-1.5 text-[20px] font-bold leading-7 tracking-tight break-words text-[#111]"><span className="num">{value}</span></p>
                </div>
                {icon && <span className={cn('hidden size-10 shrink-0 place-items-center rounded-[12px] @[13rem]:grid [&_svg]:size-5', statTones[tone])}>{icon}</span>}
            </div>
            {(trend || hint) && (
                <div className="mt-auto pt-3">
                    {trend && <TrendLine trend={trend} />}
                    {hint && <p className={cn('text-[12px] leading-5 text-[#6b7280]', trend && 'mt-1')}>{hint}</p>}
                </div>
            )}
        </div>
    );
    return href ? <Link href={href} preserveScroll className="block h-full rounded-2xl" aria-current={active ? 'true' : undefined}>{body}</Link> : body;
}

export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: ReactNode; description?: ReactNode; action?: ReactNode }) {
    // In a control panel the empty state is quiet grey; the club's colour stays for what needs attention.
    const admin = useAdmin();
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            {icon && <div className={cn('mb-3 grid size-14 place-items-center rounded-2xl [&_svg]:size-6', admin ? 'bg-[#f2f2f0] text-[#4b4b4b]' : 'bg-brand-50 text-brand')}>{icon}</div>}
            <p className="font-semibold text-ink">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Stat({ label, value }: { label: ReactNode; value: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="mt-0.5 text-sm font-medium text-ink">{value ?? '—'}</dd>
        </div>
    );
}
