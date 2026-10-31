import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';
import { cardActive, cardHover, statTones } from './Card';

/*
 * The panel's card types, one measure each. All of them are the content card underneath (white, 16px corners, a
 * #e8e8e8 hairline, the faintest shadow); what differs is what they hold and how they answer a pointer:
 *
 *   Card / CardHeader  content and information                      20px inside
 *   StatCard           one figure with its label, icon, comparison  16px inside, 20px bold figure
 *   SummaryCard        a money or admin total, its lines, an action 20px inside, 22px bold figure
 *   NavCard            a section to open (settings)                 52px icon, title, description, chevron
 *   ReportCard         a report to open (and export)                44px icon at the top, description, «فتح» + actions
 *   IntegrationCard    a connected service (Components/Integrations)
 *   ItemCard           one of a list: a class, a booking, a note    12px corners, 12px inside
 *   ChoiceCard         one option of a choice (a plan, a method)    12px corners, a dark edge when chosen
 *   Notice             a note across a page (a warning, a hint)
 */

type Tone = keyof typeof statTones;

/** A money or admin total: the label, the figure (red when negative), a note, its lines and an action at the foot. */
export function SummaryCard({ label, value, icon, tone = 'gray', negative, hint, action, children, className }: {
    label: ReactNode; value: ReactNode; icon?: ReactNode; tone?: Tone; negative?: boolean; hint?: ReactNode; action?: ReactNode; children?: ReactNode; className?: string;
}) {
    return (
        <div className={cn('card flex h-full flex-col p-5', className)}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[12px] text-[#9ca3af]">{label}</p>
                    <p className={cn('mt-1 text-[22px] font-bold leading-8 tracking-tight break-words', negative ? 'text-[#b91c1c]' : 'text-[#111]')}><span className="num">{value}</span></p>
                    {hint && <p className="mt-1 text-[12px] text-[#9ca3af]">{hint}</p>}
                </div>
                {icon && <span className={cn('grid size-10 shrink-0 place-items-center rounded-[12px] [&_svg]:size-5', statTones[tone])}>{icon}</span>}
            </div>
            {children && <div className="mt-4">{children}</div>}
            {action && <div className="mt-auto flex flex-wrap items-center gap-2 pt-4">{action}</div>}
        </div>
    );
}

/** One line of a summary: the label at the start, the amount at the end. */
export function SummaryRow({ label, value, strong, negative }: { label: ReactNode; value: ReactNode; strong?: boolean; negative?: boolean }) {
    return (
        <div className={cn('flex items-center justify-between gap-3 border-t border-[#f0f0f0] py-2 text-[13px] first:border-t-0 first:pt-0', strong ? 'font-semibold text-[#111]' : 'text-[#6b7280]')}>
            <span className="min-w-0">{label}</span>
            <span className={cn('num shrink-0', negative ? 'text-[#b91c1c]' : strong ? 'text-[#111]' : 'text-[#111]')}>{value}</span>
        </div>
    );
}

/** A section to open: a 52px icon tile that turns dark on hover, the title, a quiet description and a chevron. */
export function NavCard({ href, icon: Icon, title, description }: { href: string; icon: LucideIcon; title: ReactNode; description?: ReactNode }) {
    return (
        <Link href={href} className="group flex h-full items-center gap-4 rounded-2xl border border-line bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:bg-[#fafafa] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#111]/10">
            <span className="grid size-[52px] shrink-0 place-items-center rounded-[14px] bg-[#f5f5f4] text-[#111] transition-colors group-hover:bg-[#111] group-hover:text-white">
                <Icon className="size-6" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-[15px] font-semibold text-[#111]">{title}</span>
                {description && <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{description}</span>}
            </span>
            <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280]" />
        </Link>
    );
}

/**
 * A report to open: the title with a 44px icon tile at the top, the description filling the middle (so the feet of a
 * row line up) and, at the foot, «فتح» and the card's own actions (its export). «فتح» is the link and stretches over
 * the whole card, so a tap anywhere opens the report; the actions sit above it as separate controls — never a
 * button inside a link — each reached with the keyboard in turn.
 */
export function ReportCard({ href, icon: Icon, title, description, actions }: { href: string; icon: LucideIcon; title: string; description: ReactNode; actions?: ReactNode }) {
    return (
        <div className={cn('group relative flex h-full flex-col rounded-2xl border border-line bg-white p-5 text-start has-[a:focus-visible]:ring-4 has-[a:focus-visible]:ring-[#111]/10', cardHover)}>
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-[15px] font-semibold text-[#111]">{title}</h3>
                <span className="grid size-11 shrink-0 place-items-center rounded-[13px] bg-[#f5f5f4] text-[#111] transition-colors group-hover:bg-[#111] group-hover:text-white">
                    <Icon className="size-5" />
                </span>
            </div>
            <p className="mt-2 flex-1 text-[13px] leading-relaxed text-[#9ca3af]">{description}</p>
            <div className="mt-4 flex items-center justify-between gap-3">
                <Link href={href} className="flex items-center gap-1.5 text-[13px] font-medium text-[#111] outline-none after:absolute after:inset-0 after:rounded-2xl after:content-['']">
                    فتح<span className="sr-only"> تقرير {title}</span>
                    <ArrowLeft className="size-4 transition-transform group-hover:-translate-x-1" />
                </Link>
                {actions && <div className="relative z-10">{actions}</div>}
            </div>
        </div>
    );
}

/**
 * One item of a list — a class today, a session, a booking, a note: 12px corners, 12px inside, an optional colour
 * bar at the start, the title and a line under it, and something at the end. A link or a button when it opens.
 */
export function ItemCard({ href, onClick, accent, leading, title, meta, trailing, active, muted, className, children }: {
    href?: string; onClick?: () => void; accent?: string | null; leading?: ReactNode; title?: ReactNode; meta?: ReactNode; trailing?: ReactNode;
    active?: boolean; muted?: boolean; className?: string; children?: ReactNode;
}) {
    const cls = cn(
        'flex w-full items-center gap-3 rounded-[12px] border border-line bg-white p-3 text-start',
        (href || onClick) && 'transition hover:border-[#d4d4d4] hover:bg-[#fafafa] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#111]/10',
        active && cardActive,
        muted && 'opacity-60',
        className,
    );
    const body = children ?? (
        <>
            {accent !== undefined && <span className="h-9 w-1 shrink-0 rounded-full" style={{ background: accent ?? '#d1d5db' }} />}
            {leading}
            <span className="min-w-0 flex-1">
                {title && <span className="block truncate text-[14px] font-medium text-[#111]">{title}</span>}
                {meta && <span className="mt-0.5 block truncate text-[12px] text-[#6b7280]">{meta}</span>}
            </span>
            {trailing && <span className="shrink-0">{trailing}</span>}
        </>
    );
    if (href) return <Link href={href} className={cls}>{body}</Link>;
    if (onClick) return <button type="button" onClick={onClick} className={cls}>{body}</button>;
    return <div className={cls}>{body}</div>;
}

/** The look of an option of a choice: 12px corners, a dark edge when chosen. For a label around a radio as well. */
export function choiceClass(selected: boolean, disabled = false): string {
    return cn(
        'rounded-[12px] border bg-white p-3 text-start text-[14px] text-[#111] transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[#111]/10',
        selected ? cn('bg-[#fafafa]', cardActive) : 'border-line hover:border-[#d4d4d4] hover:bg-[#fafafa]',
        disabled && 'cursor-not-allowed opacity-50 hover:border-line hover:bg-white',
    );
}

/** One option of a choice (a plan, a payment method, new or renewal): a dark edge when chosen. */
export function ChoiceCard({ selected, onClick, disabled, className, children }: { selected: boolean; onClick: () => void; disabled?: boolean; className?: string; children: ReactNode }) {
    return (
        <button type="button" onClick={onClick} disabled={disabled} aria-pressed={selected} className={cn(choiceClass(selected, disabled), className)}>
            {children}
        </button>
    );
}

const noticeTones = {
    amber: 'border-[#fde68a] bg-[#fffbeb] text-[#92400e]',
    red: 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]',
    blue: 'border-[#bfdbfe] bg-[#eff6ff] text-[#1d4ed8]',
    green: 'border-[#a7f3d0] bg-[#ecfdf5] text-[#047857]',
    gray: 'border-line bg-[#fafafa] text-[#4b4b4b]',
};

/** A note across a page: a warning, a hint, a count waiting for action. A link or a button when it leads somewhere. */
export function Notice({ tone = 'amber', icon, href, onClick, className, children }: { tone?: keyof typeof noticeTones; icon?: ReactNode; href?: string; onClick?: () => void; className?: string; children: ReactNode }) {
    const cls = cn('flex w-full items-start gap-2.5 rounded-[12px] border px-4 py-3 text-start text-[13px] leading-6 [&>svg]:mt-1 [&>svg]:size-4 [&>svg]:shrink-0', noticeTones[tone], (href || onClick) && 'transition hover:brightness-[0.98]', className);
    if (href) return <Link href={href} className={cls}>{icon}<span className="min-w-0 flex-1">{children}</span></Link>;
    if (onClick) return <button type="button" onClick={onClick} className={cls}>{icon}<span className="min-w-0 flex-1">{children}</span></button>;
    return <div className={cls}>{icon}<div className="min-w-0 flex-1">{children}</div></div>;
}

/** A section of a full-page form: an 18px icon in a 36px grey square, a bold title and a grey hint, then its fields. */
export function FormSection({ icon: Icon, title, hint, children, locked }: { icon: LucideIcon; title: string; hint: string; children: ReactNode; locked?: ReactNode }) {
    return (
        <div className="card min-w-0 p-6">
            <div className="mb-5 flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-ink"><Icon className="size-[18px]" /></span>
                <div className="min-w-0">
                    <h3 className="font-bold text-ink">{title}</h3>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{hint}</p>
                </div>
            </div>
            {locked ? <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">{locked}</p> : children}
        </div>
    );
}

