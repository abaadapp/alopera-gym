import { useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

/**
 * A menu that opens under its trigger: click (or Enter/Space) toggles it, Escape and a click outside close it, and
 * with `hover` it also opens while the pointer is over the trigger or the menu (closing a moment after it leaves,
 * so moving from one to the other does not drop it). Aligned to the end of the trigger: the left in Arabic.
 *
 * The panel is 12px-rounded, white, with a soft shadow; items are 8px-rounded rows that turn light grey on hover
 * and focus. `DropdownItem` renders a link, a button, or a destructive (red) action.
 *
 * The panel is drawn over the page (portalled), placed from its trigger: a row's menu in a table that scrolls sideways
 * is never cut off, and near the bottom of the screen it opens upwards. The arrow keys move through its items.
 */
export function Dropdown({ trigger, children, label, hover = false, className, panelClassName }: {
    trigger: (state: { open: boolean }) => ReactNode;
    children: ReactNode | ((close: () => void) => ReactNode);
    label: string;
    hover?: boolean;
    className?: string;
    panelClassName?: string;
}) {
    const [open, setOpen] = useState(false);
    const admin = useAdmin();
    const box = useRef<HTMLDivElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    // Fixed from the first frame (as wide as its items, not the page), hidden until it is placed.
    const hidden: CSSProperties = { position: 'fixed', top: 0, left: 0, visibility: 'hidden' };
    const [place, setPlace] = useState<CSSProperties>(hidden);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    // Opened by the pointer coming over it: the click that usually follows keeps it open instead of closing it.
    const byHover = useRef(false);
    const button = useRef<HTMLButtonElement>(null);
    // An item chosen: the focus goes back to the trigger first, so a dialog the item opens returns it there too
    // (the item itself is gone once the menu closes).
    const close = () => {
        button.current?.focus({ preventScroll: true });
        setOpen(false);
    };

    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && !panel.current?.contains(e.target as Node) && setOpen(false);
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') close();
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    useEffect(() => () => clearTimeout(timer.current), []);

    // Under the trigger at its end edge (the left in Arabic), kept on the screen, upwards when there is no room below;
    // placed again as the page or a table scrolls.
    useLayoutEffect(() => {
        if (!open) {
            setPlace(hidden);
            return;
        }
        const put = () => {
            const trigger = box.current?.getBoundingClientRect();
            const menu = panel.current;
            if (!trigger || !menu) return;
            const width = menu.offsetWidth;
            const height = menu.offsetHeight;
            const rtl = getComputedStyle(box.current!).direction === 'rtl';
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            let left = rtl ? trigger.left : trigger.right - width;
            left = Math.min(Math.max(8, left), vw - width - 8);
            const below = trigger.bottom + 6;
            const top = below + height > vh - 8 && trigger.top - height - 6 > 8 ? trigger.top - height - 6 : below;
            setPlace({ position: 'fixed', top, left, visibility: 'visible' });
        };
        put();
        window.addEventListener('scroll', put, true);
        window.addEventListener('resize', put);
        return () => {
            window.removeEventListener('scroll', put, true);
            window.removeEventListener('resize', put);
        };
    }, [open]);

    // Down and up move through the items, Home and End to the first and last.
    const keys = (e: ReactKeyboardEvent) => {
        const items = [...(panel.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])];
        if (!items.length || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
        e.preventDefault();
        const at = items.indexOf(document.activeElement as HTMLElement);
        const next = e.key === 'Home' ? 0 : e.key === 'End' ? items.length - 1 : e.key === 'ArrowDown' ? (at + 1) % items.length : (at - 1 + items.length) % items.length;
        items[next].focus();
    };

    const enter = () => {
        if (!hover || !window.matchMedia('(hover: hover)').matches) return;
        clearTimeout(timer.current);
        if (!open) byHover.current = true;
        setOpen(true);
    };
    const leave = () => {
        if (!hover) return;
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            byHover.current = false;
            setOpen(false);
        }, 180);
    };
    const toggle = () => {
        if (byHover.current) {
            byHover.current = false;
            setOpen(true);
            return;
        }
        setOpen((v) => !v);
    };

    return (
        <div ref={box} className={cn('relative', className)} onMouseEnter={enter} onMouseLeave={leave}>
            <button ref={button} type="button" aria-haspopup="menu" aria-expanded={open} aria-label={label} onClick={toggle}
                onKeyDown={(e) => e.key === 'ArrowDown' && open && (e.preventDefault(), panel.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus())}
                className="block rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-[#111]/15">
                {trigger({ open })}
            </button>
            {open && createPortal(
                // A layer of its own (data-layer): opened from a dialog it is not «outside» it, and it takes the pointer
                // the dialog withholds from the page.
                <div data-layer className={cn('pointer-events-auto', admin && 'admin-ui')} dir={box.current ? getComputedStyle(box.current).direction : undefined}>
                    <div
                        ref={panel}
                        role="menu"
                        aria-label={label}
                        style={place}
                        onKeyDown={keys}
                        onMouseEnter={enter}
                        onMouseLeave={leave}
                        className={cn(
                            'z-[60] min-w-[10rem] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 shadow-[0_8px_30px_rgba(0,0,0,0.10)]',
                            'origin-top-left animate-[menu-in_.14s_ease-out]',
                            panelClassName,
                        )}
                    >
                        {typeof children === 'function' ? children(close) : children}
                    </div>
                </div>,
                document.body,
            )}
        </div>
    );
}

const itemClass = 'relative flex w-full cursor-pointer select-none items-center gap-2.5 rounded-[8px] px-3 py-2 text-start text-sm outline-none transition-colors [&_svg]:size-4 [&_svg]:shrink-0';

export function DropdownItem({ href, onClick, destructive, external, children, className }: { href?: string; onClick?: () => void; destructive?: boolean; external?: boolean; children: ReactNode; className?: string }) {
    const cls = cn(itemClass, destructive ? 'text-[#b91c1c] hover:bg-[#fef2f2] focus-visible:bg-[#fef2f2]' : 'text-[#111] hover:bg-[#f2f2f0] focus-visible:bg-[#f2f2f0]', className);
    if (href && external) return <a role="menuitem" href={href} target="_blank" rel="noreferrer" className={cls} onClick={onClick}>{children}</a>;
    if (href) return <Link role="menuitem" href={href} className={cls} onClick={onClick}>{children}</Link>;
    return <button role="menuitem" type="button" className={cls} onClick={onClick}>{children}</button>;
}

export function DropdownLabel({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('px-3 py-1.5 text-[12px] font-semibold text-[#9ca3af]', className)}>{children}</div>;
}

export function DropdownSeparator() {
    return <div role="separator" className="-mx-1.5 my-1.5 h-px bg-[#e8e8e8]" />;
}
