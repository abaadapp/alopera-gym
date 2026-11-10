import { useState, type ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/cn';

/**
 * A record's words for the English website, folded under its Arabic fields in the same form. It opens by itself when
 * something English is already written, and stays closed otherwise; it is read once, as the form opens, so emptying a
 * field while typing does not fold it away.
 */
export default function EnglishFields({ filled, className, children }: { filled: boolean; className?: string; children: ReactNode }) {
    const [open] = useState(filled);
    return (
        <details open={open} className={cn('group/en rounded-xl border border-line p-3', className)}>
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 text-sm font-medium text-ink [&::-webkit-details-marker]:hidden">
                <span>النسخة الإنجليزية من الموقع (اختياري)</span>
                <ChevronDown className="size-4 shrink-0 text-[#9ca3af] transition-transform group-open/en:rotate-180" />
            </summary>
            <p className="mt-1 text-[13px] text-muted">يظهر في الموقع الإنجليزي. ما تتركه فارغًا يظهر بالعربية.</p>
            <div className="mt-3 space-y-4">{children}</div>
        </details>
    );
}

/** The lines of a «one per line» list as the server takes it: trimmed, the empty ones dropped, at most `max`. */
export function linesOf(text: string, max: number): string[] {
    return text.split('\n').map((line) => line.trim()).filter(Boolean).slice(0, max);
}
