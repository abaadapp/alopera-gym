import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';

/** Debounced search box that updates the current page's `q` query parameter. */
export default function SearchInput({ value, placeholder = 'بحث…', param = 'q', extra = {} }: { value?: string | null; placeholder?: string; param?: string; extra?: Record<string, string | number | undefined | null> }) {
    const [term, setTerm] = useState(value ?? '');
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = setTimeout(() => {
            router.get(window.location.pathname, { ...extra, [param]: term || undefined } as Record<string, string | undefined>, { preserveState: true, replace: true, preserveScroll: true });
        }, 350);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className="relative w-full sm:w-72">
            <Search className="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-muted" />
            <input className="input ps-9" value={term} onChange={(e) => setTerm(e.target.value)} placeholder={placeholder} type="search" />
        </div>
    );
}
