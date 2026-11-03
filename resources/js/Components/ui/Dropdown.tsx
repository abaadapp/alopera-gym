import { useEffect, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { Link } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ChevronLeft } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

type Align = 'start' | 'end';

/**
 * The menu of the system, on Radix's dropdown menu as the reference's: a white panel 12px rounded with a #e8e8e8
 * hairline and a soft shadow, 6px under its trigger; items are 8px-rounded rows that turn light grey under the pointer
 * and the keyboard. It appears and goes at once, as the reference's does in the browser. Click (or Enter, Space, the
 * down arrow) opens it; the arrows, Home, End and a first letter move through its items; Escape, a click outside or a
 * choice close it and the trigger has the focus again. While it is open the page behind holds still and takes no
 * pointer (as the reference's) — except a `hover` menu, which also opens while the pointer is over the trigger or the
 * menu (closing a moment after it leaves, so moving from one to the other does not drop it) and leaves the page live.
 *
 * Its edge is the trigger's end edge (the left in Arabic), or its start edge (`align`) — the other one when the menu
 * would run off the screen that way (a button at the right of a phone's screen: the menu under it, edge to edge, as the
 * reference's). Near the bottom of the screen it opens upwards. `DropdownItem` is a link, a button or a destructive
 * (red) action; `DropdownSub` a row that opens its own list beside the menu.
 */
export function Dropdown({ trigger, children, label, hover = false, align = 'end', className, panelClassName }: {
    trigger: (state: { open: boolean }) => ReactNode;
    children: ReactNode | ((close: () => void) => ReactNode);
    label: string;
    hover?: boolean;
    align?: Align;
    className?: string;
    panelClassName?: string;
}) {
    const [open, setOpen] = useState(false);
    const admin = useAdmin();
    const button = useRef<HTMLButtonElement>(null);
    const dir = typeof document !== 'undefined' && document.documentElement.dir === 'ltr' ? 'ltr' : 'rtl';
    const [edge, setEdge] = useState<Align>(align);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    // Opened by the pointer coming over it: the click that usually follows keeps it open instead of closing it.
    const byHover = useRef(false);

    useEffect(() => () => clearTimeout(timer.current), []);

    const change = (next: boolean) => {
        if (!next) {
            byHover.current = false;
            setEdge(align);
        }
        setOpen(next);
    };
    // An item chosen: the menu is gone and the focus back on the trigger before the item acts, so a dialog the item
    // opens gives the focus back there too (the item itself is gone by then).
    const close = () => {
        flushSync(() => change(false));
        button.current?.focus({ preventScroll: true });
    };
    // The edge it hangs from, once its width is known: the other one when it would not fit on the screen that way.
    const fit = (panel: HTMLDivElement | null) => {
        const t = button.current?.getBoundingClientRect();
        if (!panel || !t) return;
        const width = panel.offsetWidth;
        const room = window.innerWidth - 8;
        const fromLeft = (dir === 'rtl') === (align === 'end');
        const fits = fromLeft ? t.left + width <= room : t.right - width >= 8;
        const want = fits ? align : align === 'end' ? 'start' : 'end';
        if (want !== edge) setEdge(want);
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
        timer.current = setTimeout(() => change(false), 140);
    };

    return (
        <div className={cn('relative', className)} onMouseEnter={enter} onMouseLeave={leave}>
            <Menu.Root open={open} onOpenChange={change} modal={!hover} dir={dir}>
                <Menu.Trigger asChild>
                    <button
                        ref={button}
                        type="button"
                        aria-label={label}
                        onPointerDown={(e) => {
                            if (!byHover.current) return;
                            byHover.current = false;
                            e.preventDefault();
                        }}
                        className="block rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-[#111]/15"
                    >
                        {trigger({ open })}
                    </button>
                </Menu.Trigger>
                <Menu.Portal>
                    <Menu.Content
                        ref={fit}
                        // A layer of its own (data-layer): opened from a dialog it is not «outside» it.
                        data-layer
                        aria-label={label}
                        align={edge}
                        sideOffset={6}
                        collisionPadding={8}
                        onMouseEnter={enter}
                        onMouseLeave={leave}
                        // The trigger is not «outside»: a click on it is its own (it opens, closes, or keeps a hover menu).
                        onInteractOutside={(e) => button.current?.contains(e.target as Node) && e.preventDefault()}
                        onCloseAutoFocus={(e) => {
                            e.preventDefault();
                            // A dialog an item opened keeps the focus; otherwise the trigger has it again.
                            const covered = [...document.querySelectorAll('[role=dialog][data-state=open]')].some((d) => !d.contains(button.current));
                            if (!covered) button.current?.focus({ preventScroll: true });
                        }}
                        className={cn(
                            // Portalled out of the page, so it carries the control panel's look along (see AdminScope).
                            admin && 'admin-ui',
                            'z-[60] min-w-[10rem] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 text-start shadow-[0_8px_30px_rgba(0,0,0,0.10)] outline-none',
                            panelClassName,
                        )}
                    >
                        {typeof children === 'function' ? children(close) : children}
                    </Menu.Content>
                </Menu.Portal>
            </Menu.Root>
        </div>
    );
}

const itemClass = 'relative flex w-full cursor-pointer select-none items-center gap-2.5 rounded-[8px] px-3 py-2 text-start text-sm outline-none transition-colors data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0';

export function DropdownItem({ href, onClick, destructive, external, children, className }: { href?: string; onClick?: () => void; destructive?: boolean; external?: boolean; children: ReactNode; className?: string }) {
    const cls = cn(itemClass, destructive ? 'text-[#b91c1c] focus:bg-[#fef2f2]' : 'text-[#111] focus:bg-[#f2f2f0]', className);
    return (
        <Menu.Item asChild onSelect={() => onClick?.()}>
            {href && external ? (
                <a href={href} target="_blank" rel="noreferrer" className={cls}>{children}</a>
            ) : href ? (
                <Link href={href} className={cls}>{children}</Link>
            ) : (
                <button type="button" className={cls}>{children}</button>
            )}
        </Menu.Item>
    );
}

/**
 * A button or link of a menu's own look (a notification, «تعليم الكل كمقروء»), reached with the arrows like any item:
 * choosing it closes the menu, unless `keepOpen` (an action whose answer the menu itself shows).
 */
export function DropdownAction({ children, keepOpen = false }: { children: ReactNode; keepOpen?: boolean }) {
    return <Menu.Item asChild onSelect={(e) => keepOpen && e.preventDefault()}>{children}</Menu.Item>;
}

/** A row that opens its own list beside the menu (to the left in Arabic), as the reference's filters do. */
export function DropdownSub({ label, children, className }: { label: ReactNode; children: ReactNode; className?: string }) {
    const admin = useAdmin();
    return (
        <Menu.Sub>
            <Menu.SubTrigger className="flex w-full cursor-default select-none items-center gap-2 rounded-[8px] px-3 py-2 text-start text-sm text-[#111] outline-none focus:bg-[#f2f2f0] data-[state=open]:bg-[#f2f2f0]">
                {label}
                <ChevronLeft className="ms-auto size-4 ltr:rotate-180" />
            </Menu.SubTrigger>
            <Menu.Portal>
                <Menu.SubContent
                    data-layer
                    collisionPadding={8}
                    // 8rem at least, as the reference's — narrower only where the screen has less room beside the menu (a 360px phone).
                    className={cn(admin && 'admin-ui', 'z-[60] min-w-[min(8rem,var(--radix-dropdown-menu-content-available-width))] max-w-[var(--radix-dropdown-menu-content-available-width)] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 text-start shadow-[0_8px_30px_rgba(0,0,0,0.10)] outline-none', className)}
                >
                    {children}
                </Menu.SubContent>
            </Menu.Portal>
        </Menu.Sub>
    );
}

export function DropdownLabel({ children, className }: { children: ReactNode; className?: string }) {
    return <Menu.Label className={cn('px-3 py-1.5 text-[12px] font-semibold text-[#9ca3af]', className)}>{children}</Menu.Label>;
}

export function DropdownSeparator() {
    return <Menu.Separator className="-mx-1.5 my-1.5 h-px bg-[#e8e8e8]" />;
}
