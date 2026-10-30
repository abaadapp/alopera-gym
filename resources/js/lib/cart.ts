import { useCallback, useSyncExternalStore } from 'react';
import { useShared } from '@/hooks/useShared';
import { lineKey, type ChosenAddon } from '@/lib/addons';

/**
 * The website shop's cart, kept in this browser per club (localStorage). Only product and add-on ids and quantities
 * are stored; names, prices and limits always come fresh from the server, and the server prices everything again
 * when the order is placed. The same product with other add-ons is a line of its own (`key`).
 */
export type CartLine = { id: number; qty: number; addons: ChosenAddon[]; key: string };

type Stored = { id: number; qty: number; addons?: ChosenAddon[] };

const validAddons = (addons: unknown): ChosenAddon[] =>
    Array.isArray(addons) ? addons.filter((a) => Number.isInteger(a?.id) && Number.isInteger(a?.qty) && a.qty > 0).map((a) => ({ id: a.id, qty: a.qty })) : [];

const EVENT = 'alopera-cart';
const key = (club: string) => `cart:${club}`;

function read(club: string): CartLine[] {
    try {
        const parsed = JSON.parse(localStorage.getItem(key(club)) ?? '[]');
        return Array.isArray(parsed)
            ? parsed.filter((l: Stored) => Number.isInteger(l?.id) && Number.isInteger(l?.qty) && l.qty > 0).map((l: Stored) => {
                const addons = validAddons(l.addons);
                return { id: l.id, qty: l.qty, addons, key: lineKey(l.id, addons) };
            })
            : [];
    } catch {
        return [];
    }
}

function write(club: string, lines: CartLine[]) {
    try {
        localStorage.setItem(key(club), JSON.stringify(lines.map(({ id, qty, addons }) => ({ id, qty, addons }))));
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

    /** Set a line's quantity (0 removes it); `max` caps the product across all its lines. */
    const setQty = useCallback((lineId: string, qty: number, max = 50) => {
        const all = read(club);
        const line = all.find((l) => l.key === lineId);
        if (!line) return;
        const others = all.filter((l) => l.key !== lineId);
        const elsewhere = others.filter((l) => l.id === line.id).reduce((n, l) => n + l.qty, 0);
        const clamped = Math.max(0, Math.min(Math.floor(qty), max - elsewhere));
        write(club, clamped > 0 ? all.map((l) => (l.key === lineId ? { ...l, qty: clamped } : l)) : others);
    }, [club]);

    const add = useCallback((id: number, qty = 1, max = 50, addons: ChosenAddon[] = []) => {
        const all = read(club);
        const k = lineKey(id, addons);
        const inCart = all.filter((l) => l.id === id).reduce((n, l) => n + l.qty, 0);
        const take = Math.max(0, Math.min(Math.floor(qty), max - inCart));
        if (take === 0) return;
        write(club, all.some((l) => l.key === k) ? all.map((l) => (l.key === k ? { ...l, qty: l.qty + take } : l)) : [...all, { id, qty: take, addons, key: k }]);
    }, [club]);

    const clear = useCallback(() => write(club, []), [club]);

    /** How many of a product are in the cart, whatever their add-ons. */
    const quantityOf = useCallback((id: number) => lines.filter((l) => l.id === id).reduce((n, l) => n + l.qty, 0), [lines]);

    return { lines, count: lines.reduce((n, l) => n + l.qty, 0), add, setQty, clear, quantityOf };
}
