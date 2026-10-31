import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import { number } from '@/lib/format';

/** The categorical colours of a chart's series and bars, in order. */
export const CHART_COLORS = ['#7c3aed', '#059669', '#2563eb', '#d97706', '#ec4899', '#0891b2'];

const PAD = { top: 12, bottom: 26, start: 56, end: 8 };

/**
 * A trend over time as a filled line: four light gridlines with their values, a label under every point, and the
 * point under the pointer (or the finger) read out in a line beneath. Drawn left to right in time order even on an
 * Arabic page (time axes read that way). Scrolls sideways on a narrow screen instead of squeezing its labels.
 */
export function AreaChart({ data, height = 260, valueLabel = (v: number) => number(v, 3), hint = 'مرّر المؤشر على الرسم لقراءة كل نقطة', color = '#7c3aed' }: {
    data: { label: string; value: number }[];
    height?: number;
    valueLabel?: (v: number) => string;
    hint?: string;
    color?: string;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(720);
    const [hover, setHover] = useState<number | null>(null);
    const gradient = useId();

    useLayoutEffect(() => {
        const el = box.current;
        if (!el) return;
        const measure = () => setWidth(Math.max(520, el.clientWidth));
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    if (data.length === 0) return <p className="py-12 text-center text-sm text-[#9ca3af]">لا توجد بيانات بعد</p>;

    const max = Math.max(1, ...data.map((d) => d.value));
    const innerW = width - PAD.start - PAD.end;
    const innerH = height - PAD.top - PAD.bottom;
    const x = (i: number) => PAD.start + (data.length === 1 ? innerW / 2 : (i / (data.length - 1)) * innerW);
    const y = (v: number) => PAD.top + innerH - (v / max) * innerH;
    const line = data.map((d, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(d.value).toFixed(1)}`).join(' ');
    const area = `${line} L${x(data.length - 1).toFixed(1)},${PAD.top + innerH} L${x(0).toFixed(1)},${PAD.top + innerH} Z`;
    // Every point gets its label while they fit; on a long series every few points, so they never overlap.
    const step = Math.max(1, Math.ceil(data.length / Math.floor(innerW / 34)));
    const pick = (clientX: number, rect: DOMRect) => {
        const at = ((clientX - rect.left) / rect.width) * width;
        setHover(Math.max(0, Math.min(data.length - 1, Math.round(((at - PAD.start) / innerW) * (data.length - 1)))));
    };

    return (
        <div>
            <div ref={box} dir="ltr" className="overflow-x-auto">
                <svg
                    width={width}
                    height={height}
                    className="block min-w-[520px] touch-pan-x select-none"
                    onMouseMove={(e) => pick(e.clientX, e.currentTarget.getBoundingClientRect())}
                    onMouseLeave={() => setHover(null)}
                    onTouchStart={(e) => pick(e.touches[0].clientX, e.currentTarget.getBoundingClientRect())}
                    onTouchMove={(e) => pick(e.touches[0].clientX, e.currentTarget.getBoundingClientRect())}
                    role="img"
                    aria-label="رسم بياني"
                >
                    <defs>
                        <linearGradient id={gradient} x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stopColor={color} stopOpacity="0.16" />
                            <stop offset="100%" stopColor={color} stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    {[0, 1, 2, 3].map((t) => {
                        const gy = PAD.top + (innerH * t) / 3;
                        return (
                            <g key={t}>
                                <line x1={PAD.start} x2={width - PAD.end} y1={gy} y2={gy} stroke="#eeeeee" strokeWidth="1" />
                                <text x={PAD.start - 8} y={gy + 4} textAnchor="end" className="fill-[#9ca3af] text-[10px]">{number(Math.round(max * (1 - t / 3)))}</text>
                            </g>
                        );
                    })}
                    <path d={area} fill={`url(#${gradient})`} />
                    <path d={line} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="animate-[chart-draw_0.7s_ease-out]" />
                    {data.map((d, i) => (i % step === 0 || i === hover) && (
                        <text key={i} x={x(i)} y={height - 8} textAnchor="middle" className={i === hover ? 'text-[10px] font-bold' : 'fill-[#9ca3af] text-[10px]'} fill={i === hover ? color : undefined}>{d.label}</text>
                    ))}
                    {hover !== null && (
                        <g>
                            <line x1={x(hover)} x2={x(hover)} y1={PAD.top} y2={PAD.top + innerH} stroke="#d1d5db" strokeDasharray="3 3" />
                            <circle cx={x(hover)} cy={y(data[hover].value)} r="5" fill={color} stroke="#fff" strokeWidth="2" />
                        </g>
                    )}
                </svg>
            </div>
            <p className="mt-1 h-5 text-center text-[12px] text-[#6b7280]">
                {hover !== null ? <><span className="font-medium text-[#111]">{data[hover].label}</span> · <span className="num">{valueLabel(data[hover].value)}</span></> : <span className="text-[#c7c7c7]">{hint}</span>}
            </p>
        </div>
    );
}

/** A daily series: the same filled line as AreaChart (the name is kept for the screens that use it). */
export const BarTrend = AreaChart;

/** Shares of a whole as horizontal bars: a colour dot, the label, the value and its share, a 8px track. */
export function HBars({ data, format = (v: number) => number(v) }: { data: { label: string; value: number; color?: string | null }[]; format?: (v: number) => string }) {
    if (data.length === 0) return <p className="py-12 text-center text-sm text-[#9ca3af]">لا توجد بيانات بعد</p>;
    const max = Math.max(1, ...data.map((d) => d.value));
    const sum = data.reduce((n, d) => n + d.value, 0) || 1;
    return (
        <div className="flex flex-col gap-3">
            {data.map((d, i) => {
                const color = d.color || CHART_COLORS[i % CHART_COLORS.length];
                return (
                    <div key={d.label}>
                        <div className="mb-1.5 flex items-baseline justify-between gap-3">
                            <span className="flex min-w-0 items-center gap-2 text-[13px] text-[#111]">
                                <span className="size-2.5 shrink-0 rounded-full" style={{ background: color }} />
                                <span className="truncate">{d.label}</span>
                            </span>
                            <span className="shrink-0 text-[12px] tabular-nums text-[#6b7280]">
                                <span className="num">{format(d.value)}</span>
                                <span className="ms-1.5 text-[#9ca3af] num">({Math.round((d.value / sum) * 100)}%)</span>
                            </span>
                        </div>
                        <div className="h-2 overflow-hidden rounded-full bg-[#f2f2f0]">
                            <div className="h-full rounded-full transition-[width] duration-[600ms]" style={{ width: `${(d.value / max) * 100}%`, background: color }} />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/** A change in percent with an arrow (the direction) and a colour (good or bad; `inverted` where less is better). */
export function Delta({ change, inverted = false }: { change: number | null; inverted?: boolean }) {
    if (change === null || change === undefined) return <span className="text-xs text-muted">—</span>;
    const up = change >= 0;
    const good = up !== inverted;
    return (
        <span className={`inline-flex items-center gap-0.5 text-xs font-medium ${good ? 'text-[#047857]' : 'text-[#b91c1c]'}`}>
            {up ? <ArrowUpRight className="size-3.5" /> : <ArrowDownRight className="size-3.5" />}
            <span className="num" dir="ltr">{up ? '+' : ''}{change}%</span>
        </span>
    );
}
