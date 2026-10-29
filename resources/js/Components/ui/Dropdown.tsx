import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';

/**
 * A menu that opens under its trigger: click (or Enter/Space) toggles it, Escape and a click outside close it, and
 * with `hover` it also opens while the pointer is over the trigger or the menu (closing a moment after it leaves,
 * so moving from one to the other does not drop it). Aligned to the end of the trigger: the left in Arabic.
 *
 * The panel is 12px-rounded, white, with a soft shadow; items are 8px-rounded rows that turn light grey on hover
 * and focus. `DropdownItem` renders a link, a button, or a destructive (red) action.
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
    const box = useRef<HTMLDivElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    // Opened by the pointer coming over it: the click that usually follows keeps it open instead of closing it.
    const byHover = useRef(false);
    const close = () => setOpen(false);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                box.current?.querySelector<HTMLElement>('[aria-haspopup]')?.focus();
            }
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    useEffect(() => () => clearTimeout(timer.current), []);

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
            <button type="button" aria-haspopup="menu" aria-expanded={open} aria-label={label} onClick={toggle} className="block rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-[#111]/15">
                {trigger({ open })}
            </button>
            {open && (
                <div
                    role="menu"
                    aria-label={label}
                    className={cn(
                        'absolute end-0 top-full z-50 mt-1.5 min-w-[10rem] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 shadow-[0_8px_30px_rgba(0,0,0,0.10)]',
                        'origin-top-left animate-[menu-in_.14s_ease-out]',
                        panelClassName,
                    )}
                >
                    {typeof children === 'function' ? children(close) : children}
                </div>
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
