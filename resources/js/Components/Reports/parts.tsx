import type { ReactNode } from 'react';
import {
    ArrowDownLeft, ArrowUpCircle, ArrowUpRight, Ban, BarChart3, Briefcase, CalendarCheck, CalendarDays, CalendarX, Clock, Copy, DoorOpen, Dumbbell, FileMinus, Fingerprint,
    History, LogIn, Percent, Receipt, RefreshCw, Scale, ShoppingBag, Snowflake, Ticket, TrendingDown, Undo2, UserCheck, UserPlus, UserRound, Users, Wallet, XCircle, type LucideIcon,
} from 'lucide-react';
import { Card, CardHeader, StatCard } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import { cn } from '@/lib/cn';

export type Metric = { current: number | string; previous: number | string; change: number | null };
export type Tone = 'brand' | 'green' | 'amber' | 'red' | 'gray' | 'blue' | 'violet';

/** Each figure's icon and colour, the same on every report. */
const KPI_ICONS: Record<string, [LucideIcon, Tone]> = {
    revenue: [Wallet, 'green'], expenses: [TrendingDown, 'red'], net: [Scale, 'brand'], collection_rate: [Percent, 'blue'], refunds: [Undo2, 'amber'],
    credit_notes: [FileMinus, 'gray'], product_sales: [ShoppingBag, 'violet'], total_revenue: [Wallet, 'green'], total_expenses: [TrendingDown, 'red'],
    new_memberships: [Ticket, 'brand'], renewals: [RefreshCw, 'green'], upgrades: [ArrowUpCircle, 'blue'], new_members: [UserPlus, 'violet'],
    cancellations: [XCircle, 'red'], freezes: [Snowflake, 'blue'], expirations: [CalendarX, 'gray'], checkins: [LogIn, 'blue'],
    sessions: [CalendarDays, 'brand'], bookings: [CalendarCheck, 'blue'], attendance_rate: [UserCheck, 'green'], occupancy_rate: [Percent, 'amber'],
    trainers: [Dumbbell, 'brand'], pt_completed: [UserRound, 'violet'],
    in: [ArrowDownLeft, 'green'], out: [ArrowUpRight, 'red'],
    total: [ShoppingBag, 'brand'], count: [Receipt, 'gray'], average: [Scale, 'blue'], voided_total: [Undo2, 'amber'], voided_count: [Ban, 'gray'],
    allowed: [DoorOpen, 'green'], members: [Users, 'brand'], denied: [XCircle, 'red'], duplicate: [Copy, 'amber'],
    staff: [Users, 'brand'], days: [CalendarCheck, 'blue'], hours: [Clock, 'green'], open: [Fingerprint, 'amber'],
    runs: [Briefcase, 'brand'], gross: [Wallet, 'blue'], deductions: [FileMinus, 'red'], paid: [Wallet, 'green'], unpaid: [Clock, 'amber'],
    payouts: [Receipt, 'gray'], employees: [Users, 'blue'], documents: [History, 'blue'],
};

/** Figures where a lower number is the good direction. */
export const lowerIsBetter = new Set(['cancellations', 'freezes', 'expirations', 'expenses', 'refunds', 'credit_notes', 'total_expenses', 'out', 'voided_total', 'voided_count', 'denied', 'duplicate', 'open', 'deductions', 'unpaid']);

export function useFormats() {
    const currency = useCurrency();
    return {
        money: (v: number | string) => money(v, currency),
        pct: (v: number | string) => `${number(v, 1)}%`,
        num: (v: number | string) => number(v),
        dec: (v: number | string) => number(v, 1),
    };
}

/** One figure against the comparison period (or alone, for a report of today's state). */
export function Kpi({ label, metric, format, metricKey, hint }: { label: string; metric?: Metric; format: (v: number | string) => string; metricKey: string; hint?: string }) {
    const m = metric ?? { current: 0, previous: 0, change: null };
    const comparison = `المقارنة: ${format(m.previous)}`;
    const [Icon, tone] = KPI_ICONS[metricKey] ?? [BarChart3, 'gray'];
    return (
        <StatCard
            icon={<Icon />}
            tone={tone}
            label={label}
            value={format(m.current)}
            trend={m.change === null || m.change === undefined ? null : { change: m.change, label: comparison, inverted: lowerIsBetter.has(metricKey) }}
            hint={[m.change === null || m.change === undefined ? comparison : null, hint].filter(Boolean).join(' · ') || undefined}
        />
    );
}

/** A figure with no comparison: a report of how things stand today. */
export function Figure({ label, value, metricKey, icon, tone, hint }: { label: string; value: ReactNode; metricKey?: string; icon?: LucideIcon; tone?: Tone; hint?: string }) {
    const [Icon, t] = icon ? [icon, tone ?? 'gray'] : KPI_ICONS[metricKey ?? ''] ?? [BarChart3, 'gray'];
    return <StatCard icon={<Icon />} tone={tone ?? t} label={label} value={value} hint={hint} />;
}

export function TableCard({ title, subtitle, head, empty, className, actions, footer, children }: {
    title: string; subtitle?: string; head: ReactNode[]; empty: string; className?: string; actions?: ReactNode; footer?: ReactNode; children: ReactNode[];
}) {
    return (
        <Card padded={false} className={cn('overflow-hidden', className)}>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                <div className="min-w-0">
                    <h3 className="font-bold text-[#111]">{title}</h3>
                    {subtitle && <p className="mt-0.5 text-[13px] text-[#6b7280]">{subtitle}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {children.length ? (
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr>{head.map((h, i) => <th key={i}>{h}</th>)}</tr></thead>
                        <tbody>{children}</tbody>
                    </table>
                </div>
            ) : (
                <p className="px-4 py-14 text-center text-sm text-[#9ca3af]">{empty}</p>
            )}
            {footer}
        </Card>
    );
}

/** A chart in a card, or a quiet line when there is nothing to draw. */
export function ChartCard({ title, className, empty = 'لا بيانات', has, children }: { title: string; className?: string; empty?: string; has: boolean; children: ReactNode }) {
    return (
        <Card className={className}>
            <CardHeader title={title} />
            {has ? children : <p className="py-12 text-center text-sm text-[#9ca3af]">{empty}</p>}
        </Card>
    );
}

export function Rate({ value }: { value: number }) {
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-[#f2f2f0]"><div className="h-full rounded-full bg-[#111]" style={{ width: `${Math.min(100, value)}%` }} /></div>
            <span className="num text-xs">{number(value, 1)}%</span>
        </div>
    );
}
