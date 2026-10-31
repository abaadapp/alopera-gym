import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';

const PX_PER_MM = 96 / 25.4;
const mm = (v: number) => Math.round(v * PX_PER_MM);

export const PAPERS: Record<string, { width: number; height: number | null }> = {
    A4: { width: 210, height: 297 },
    A5: { width: 148, height: 210 },
    '80mm': { width: 80, height: null },
    '58mm': { width: 58, height: null },
};

/**
 * The paper as the server drew it, at its real size and scaled to fit: the same HTML the print page and the PDF use,
 * in an isolated frame (no script, no panel styles). A sheet shows a dashed line where each new page starts.
 */
export default function PaperFrame({ html, paper = 'A4', height = 'min(78svh, 1000px)', title = 'معاينة الورقة', maxScale, className }: {
    html: string;
    paper?: string;
    height?: string;
    title?: string;
    maxScale?: number;
    className?: string;
}) {
    const box = useRef<HTMLDivElement>(null);
    const frame = useRef<HTMLIFrameElement>(null);
    const [avail, setAvail] = useState(0);
    const [content, setContent] = useState(0);
    const size = PAPERS[paper] ?? PAPERS.A4;
    const width = mm(size.width);
    const page = size.height ? mm(size.height) : 0;
    const strip = size.height === null;
    const full = page > 0 ? Math.max(1, Math.ceil((content || page) / page)) * page : Math.max(content, mm(60));
    const pages = page > 0 ? Math.round(full / page) : 1;
    const scale = avail > 0 ? Math.min(maxScale ?? (strip ? 1.6 : 1), avail / width) : 1;

    useLayoutEffect(() => {
        const el = box.current;
        if (!el) return;
        const read = () => {
            const style = window.getComputedStyle(el);
            setAvail(Math.max(0, el.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0)));
        };
        read();
        const observer = new ResizeObserver(read);
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const measure = useCallback(() => {
        const doc = frame.current?.contentDocument;
        if (!doc?.documentElement) return;
        const h = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight ?? 0);
        if (h > 0) setContent(h);
    }, []);

    useEffect(() => {
        const id = window.setTimeout(measure, 150);
        return () => window.clearTimeout(id);
    }, [html, measure]);

    return (
        <div ref={box} className={cn('overflow-y-auto overflow-x-hidden rounded-[16px] border border-line bg-[#f1f1f0] p-4', className)} style={{ height }}>
            <div className="relative mx-auto bg-white shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_24px_rgba(0,0,0,0.06)]" style={{ width: width * scale, height: full * scale }}>
                <iframe
                    ref={frame}
                    title={title}
                    srcDoc={html}
                    sandbox="allow-same-origin"
                    onLoad={measure}
                    scrolling="no"
                    className="absolute left-0 top-0 border-0 bg-white"
                    style={{ width, height: full, transform: `scale(${scale})`, transformOrigin: 'top left' }}
                />
                {pages > 1 && Array.from({ length: pages - 1 }, (_, i) => (
                    <div key={i} aria-hidden className="pointer-events-none absolute inset-x-0 border-t border-dashed border-[#cbd5e1]" style={{ top: (i + 1) * page * scale }}>
                        <span className="absolute -top-[9px] left-1/2 -translate-x-1/2 bg-[#f1f1f0] px-2 text-[9px] text-[#94a3b8]">صفحة <span className="num">{i + 2}</span></span>
                    </div>
                ))}
            </div>
        </div>
    );
}
