import { useEffect, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { Link } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ChevronLeft } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

/**
 * The menu of the system, on Radix's dropdown menu and placed as the reference's: a white panel 12px rounded with a
 * #e8e8e8 hairline and a soft shadow, 6px under its trigger; items are 8px-rounded rows that turn light grey under the
 * pointer and the keyboard. It appears and goes at once, as the reference's does in the browser. Click (or Enter,
 * Space, the down arrow) opens it; the arrows, Home, End and a first letter move through its items; Escape, a click
 * outside or a choice close it and the trigger has the focus again. While it is open the page behind holds still and
 * takes no pointer (as the reference's) — except a `hover` menu, which also opens while the pointer is over the trigger
 * or the menu (closing a moment after it leaves, so moving from one to the other does not drop it) and leaves the page
 * live.
 *
 * As the reference's it is given no direction, so Radix draws it left to right whatever the page's: `align` end hangs it
 * from the trigger's right edge, start from its left edge, Radix keeps it on the screen, rows start at the left and a
 * `DropdownSub` opens to the right. `DropdownItem` is a link, a button or a destructive (red) action; a `file` link hands
 * its click to `onClick` and, as the reference's, leaves the menu open; `keepOpen` holds it open behind the dialog an item
 * opens, again as the reference's.
 */
export function Dropdown({ trigger, children, label, hover = false, align = 'end', disabled = false, className, panelClassName }: {
    trigger: (state: { open: boolean }) => ReactNode;
    children: ReactNode | ((close: () => void) => ReactNode);
    label: string;
    hover?: boolean;
    align?: 'start' | 'end';
    disabled?: boolean;
    className?: string;
    panelClassName?: string;
}) {
    const [open, setOpen] = useState(false);
    const admin = useAdmin();
    const button = useRef<HTMLButtonElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    // An item chosen: the menu is gone and the focus back on the trigger before the item acts, so a dialog the item
    // opens gives the focus back there too (the item itself is gone by then).
    const close = () => {
        flushSync(() => setOpen(false));
        button.current?.focus({ preventScroll: true });
    };

    const enter = () => {
        if (!hover) return;
        clearTimeout(timer.current);
        setOpen(true);
    };
    const leave = () => {
        if (!hover) return;
        clearTimeout(timer.current);
        timer.current = setTimeout(() => setOpen(false), 140);
    };

    return (
        <div className={cn('relative', className)} onMouseEnter={enter} onMouseLeave={leave}>
            <Menu.Root open={open} onOpenChange={setOpen} modal={!hover}>
                <Menu.Trigger asChild>
                    <button ref={button} type="button" aria-label={label} disabled={disabled} className="block rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-[#111]/15 disabled:cursor-not-allowed">
                        {trigger({ open })}
                    </button>
                </Menu.Trigger>
                <Menu.Portal>
                    <Menu.Content
                        // A layer of its own (data-layer): opened from a dialog it is not «outside» it.
                        data-layer
                        aria-label={label}
                        align={align}
                        sideOffset={6}
                        onMouseEnter={enter}
                        onMouseLeave={leave}
                        onCloseAutoFocus={(e) => {
                            if (hover) return;
                            e.preventDefault();
                            // A dialog an item opened keeps the focus; otherwise the trigger has it again.
                            const covered = [...document.querySelectorAll('[role=dialog][data-state=open]')].some((d) => !d.contains(button.current));
                            if (!covered) button.current?.focus({ preventScroll: true });
                        }}
                        className={cn(
                            // Portalled out of the page, so it carries the control panel's look along (see AdminScope).
                            admin && 'admin-ui',
                            'z-50 min-w-[10rem] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 shadow-[0_8px_30px_rgba(0,0,0,0.10)] outline-none',
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

export function DropdownItem({ href, onClick, destructive, external, file, keepOpen, children, className }: { href?: string; onClick?: () => void; destructive?: boolean; external?: boolean; file?: boolean; keepOpen?: boolean; children: ReactNode; className?: string }) {
    const cls = cn(itemClass, destructive ? 'text-[#b91c1c] focus:bg-[#fef2f2]' : 'text-[#111] focus:bg-[#f2f2f0]', className);
    return (
        <Menu.Item
            asChild
            onSelect={(e) => {
                if (keepOpen) e.preventDefault();
                onClick?.();
            }}
        >
            {href && file ? (
                <a
                    href={href}
                    className={cls}
                    onClick={(e) => {
                        e.preventDefault();
                        onClick?.();
                    }}
                >
                    {children}
                </a>
            ) : href && external ? (
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

/** A row that opens its own list beside the menu, as the reference's filters do. */
export function DropdownSub({ label, children, className }: { label: ReactNode; children: ReactNode; className?: string }) {
    const admin = useAdmin();
    return (
        <Menu.Sub>
            <Menu.SubTrigger className="flex w-full cursor-default select-none items-center gap-2 rounded-[8px] px-3 py-2 text-start text-sm outline-none focus:bg-[#f2f2f0] data-[state=open]:bg-[#f2f2f0]">
                {label}
                <ChevronLeft className="ms-auto size-4" />
            </Menu.SubTrigger>
            <Menu.Portal>
                <Menu.SubContent
                    data-layer
                    // 8rem at least, as the reference's — narrower only where the screen has less room beside the menu (a 360px phone).
                    className={cn(admin && 'admin-ui', 'z-50 min-w-[min(8rem,var(--radix-dropdown-menu-content-available-width))] max-w-[var(--radix-dropdown-menu-content-available-width)] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white p-1.5 shadow-[0_8px_30px_rgba(0,0,0,0.10)] outline-none', className)}
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
