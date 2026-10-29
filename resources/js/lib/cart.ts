import { useCallback, useSyncExternalStore } from 'react';
import { useShared } from '@/hooks/useShared';

/**
 * The website shop's cart, kept in this browser per club (localStorage). Only product ids and quantities are
 * stored; names, prices and limits always come fresh from the server, and the server checks everything again
 * when the order is placed.
 */
export type CartLine = { id: number; qty: number };

const EVENT = 'alopera-cart';
const key = (club: string) => `cart:${club}`;

function read(club: string): CartLine[] {
    try {
        const parsed = JSON.parse(localStorage.getItem(key(club)) ?? '[]');
        return Array.isArray(parsed) ? parsed.filter((l) => Number.isInteger(l?.id) && Number.isInteger(l?.qty) && l.qty > 0) : [];
    } catch {
        return [];
    }
}

function write(club: string, lines: CartLine[]) {
    try {
        localStorage.setItem(key(club), JSON.stringify(lines));
    } catch {
        // Private mode or storage blocked: the cart simply does not persist.
    }
    window.dispatchEvent(new Event(EVENT));
}

const cache = new Map<string, { raw: string | null; lines: CartLine[] }>();

function snapshot(club: string): CartLine[] {
    let raw: string | null = null;
    try {
        raw = localStorage.getItem(key(club));
    } catch {
        raw = null;
    }
    const hit = cache.get(club);
    if (hit && hit.raw === raw) return hit.lines;
    const lines = read(club);
    cache.set(club, { raw, lines });
    return lines;
}

function subscribe(callback: () => void) {
    window.addEventListener(EVENT, callback);
    window.addEventListener('storage', callback);
    return () => {
        window.removeEventListener(EVENT, callback);
        window.removeEventListener('storage', callback);
    };
}

const EMPTY: CartLine[] = [];

export function useCart() {
    const club = useShared().tenant?.slug ?? 'club';
    const lines = useSyncExternalStore(subscribe, () => snapshot(club), () => EMPTY);

    const setQty = useCallback((id: number, qty: number, max = 50) => {
        const next = read(club).filter((l) => l.id !== id);
        const clamped = Math.max(0, Math.min(Math.floor(qty), max));
        write(club, clamped > 0 ? [...next, { id, qty: clamped }].sort((a, b) => a.id - b.id) : next);
    }, [club]);

    const add = useCallback((id: number, qty = 1, max = 50) => {
        const current = read(club).find((l) => l.id === id)?.qty ?? 0;
        setQty(id, current + qty, max);
    }, [club, setQty]);

    const clear = useCallback(() => write(club, []), [club]);

    return { lines, count: lines.reduce((n, l) => n + l.qty, 0), add, setQty, clear };
}
