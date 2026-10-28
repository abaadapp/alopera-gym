import { useState } from 'react';
import { number } from '@/lib/format';

/**
 * Small dependency-free SVG charts. Values are drawn left-to-right in time
 * order even in RTL pages (time axes read LTR in Arabic finance UIs too).
 */
export function BarTrend({ data, height = 160, valueLabel = (v: number) => number(v, 3) }: { data: { label: string; value: number }[]; height?: number; valueLabel?: (v: number) => string }) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(1, ...data.map((d) => d.value));
    const w = 100 / Math.max(1, data.length);

    return (
        <div dir="ltr" className="relative">
            <svg viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" className="w-full" style={{ height }}>
                {[0.25, 0.5, 0.75].map((t) => (
                    <line key={t} x1="0" x2="100" y1={height * t} y2={height * t} stroke="#eee4ea" strokeWidth="0.3" vectorEffect="non-scaling-stroke" />
                ))}
                {data.map((d, i) => {
                    const h = (d.value / max) * (height - 8);
                    return (
                        <rect
                            key={i}
                            x={i * w + w * 0.18}
                            width={w * 0.64}
                            y={height - h}
                            height={Math.max(h, d.value > 0 ? 1.5 : 0)}
                            rx={0.6}
                            className="transition-opacity"
                            fill="var(--brand)"
                            opacity={hover === null || hover === i ? 0.9 : 0.35}
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                        />
                    );
                })}
            </svg>
            {hover !== null && data[hover] && (
                <div className="pointer-events-none absolute -top-2 rounded-lg bg-ink px-2 py-1 text-xs text-white shadow" style={{ left: `${Math.min(85, hover * w)}%` }}>
                    {data[hover].label}: {valueLabel(data[hover].value)}
                </div>
            )}
            <div className="mt-1 flex justify-between text-[10px] text-muted">
                <span>{data[0]?.label}</span>
                <span>{data[data.length - 1]?.label}</span>
            </div>
        </div>
    );
}

export function HBars({ data, format = (v: number) => number(v) }: { data: { label: string; value: number; color?: string | null }[]; format?: (v: number) => string }) {
    const max = Math.max(1, ...data.map((d) => d.value));
    return (
        <div className="space-y-2.5">
            {data.map((d) => (
                <div key={d.label}>
                    <div className="mb-1 flex justify-between gap-2 text-sm">
                        <span className="truncate text-ink">{d.label}</span>
                        <span className="num text-muted">{format(d.value)}</span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-brand-50">
                        <div className="h-full rounded-full" style={{ width: `${(d.value / max) * 100}%`, background: d.color || 'var(--brand)' }} />
                    </div>
                </div>
            ))}
        </div>
    );
}

export function Delta({ change }: { change: number | null }) {
    if (change === null || change === undefined) return <span className="text-xs text-muted">—</span>;
    const up = change >= 0;
    return (
        <span className={`text-xs font-medium num ${up ? 'text-emerald-600' : 'text-red-600'}`}>
            {up ? '▲' : '▼'} {Math.abs(change)}%
        </span>
    );
}
