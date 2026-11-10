import { Link } from '@inertiajs/react';
import type { Paginated } from '@/types';
import { cn } from '@/lib/cn';
import { currentLocale } from '@/lib/locale';

export default function Pagination({ data }: { data: Paginated<unknown> }) {
    if (data.last_page <= 1) return null;
    // The English website's news list (the panel is Arabic).
    const en = currentLocale() === 'en';

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm">
            <p className="text-muted">
                <span className="num">{data.from}</span>–<span className="num">{data.to}</span> {en ? 'of' : 'من'} <span className="num">{data.total}</span>
            </p>
            <div className="flex flex-wrap gap-1">
                {data.links.map((link, i) => {
                    const label = link.label.includes('Previous') || link.label.includes('&laquo;') ? (en ? 'Previous' : 'السابق') : link.label.includes('Next') || link.label.includes('&raquo;') ? (en ? 'Next' : 'التالي') : link.label;
                    return link.url ? (
                        <Link key={i} href={link.url} preserveScroll className={cn('min-w-8 rounded-lg px-2.5 py-1 text-center', link.active ? 'bg-brand text-white' : 'hover:bg-brand-50')}>
                            {label}
                        </Link>
                    ) : (
                        <span key={i} className="min-w-8 px-2.5 py-1 text-center text-muted/60">{label}</span>
                    );
                })}
            </div>
        </div>
    );
}
