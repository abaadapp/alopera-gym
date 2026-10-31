import { useEffect, useId, useLayoutEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

/** Open dialogs, newest last: Escape closes the top one only, and the page scrolls again when the last one closes. */
const stack: string[] = [];

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** The panel's widths: a confirmation, a short form (the default), a picker list, a form in two columns, a wide table, the whole screen. */
const SIZES = { xs: 'sm:max-w-sm', sm: 'sm:max-w-md', md: 'sm:max-w-lg', wide: 'sm:max-w-xl', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl', full: 'sm:max-w-[min(1200px,calc(100vw-3rem))] h-[calc(100dvh-var(--kb,0px)-1.5rem)]' };

/**
 * The one dialog of the system. In the panel: a white card centred on a dimmed, blurred page (the whole width of a
 * phone), 16px rounded with a hairline and a soft shadow; the title 17px semibold with an optional grey line under it
 * and the ✕ in the corner; the content 20px in; the buttons at the end, cancel first and the action last. It fades and
 * grows in. It is never taller than the screen (less the keyboard on a phone): a long one scrolls as a whole, title
 * and buttons with it, as the reference's does; only the full-screen size keeps its title in place over a body that
 * scrolls. Escape and the dimmed page close it; Tab stays inside it; the first field is focused on opening and the
 * button that opened it gets the focus back on closing (the page itself when that button is gone, a deleted row's);
 * the page behind does not scroll. Elsewhere (the member's pages) it rises from the bottom on a phone.
 */
export default function Modal({ open, onClose, title, description, children, footer, size = 'md' }: { open: boolean; onClose: () => void; title: ReactNode; description?: ReactNode; children: ReactNode; footer?: ReactNode; size?: keyof typeof SIZES }) {
    const admin = useAdmin();
    const id = useId();
    const panel = useRef<HTMLDivElement>(null);
    // The element that had the focus before the dialog took it: read as the dialog first renders open, before a field
    // marked autoFocus (focused by React as it mounts) or the first field below can take it.
    const opener = useRef<HTMLElement | null>(null);
    if (open && opener.current === null && typeof document !== 'undefined' && document.activeElement instanceof HTMLElement && document.activeElement !== document.body) {
        opener.current = document.activeElement;
    }
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        if (!open) return;
        stack.push(id);
        document.body.style.overflow = 'hidden';

        const onKey = (e: KeyboardEvent) => {
            if (stack[stack.length - 1] !== id) return;
            if (e.key === 'Escape') {
                e.stopPropagation();
                closeRef.current();
                return;
            }
            if (e.key !== 'Tab' || !panel.current) return;
            const items = [...panel.current.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => el.offsetParent !== null);
            if (items.length === 0) {
                e.preventDefault();
                panel.current.focus();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && (document.activeElement === first || !panel.current.contains(document.activeElement))) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', onKey);

        // On a phone the keyboard takes the bottom of the screen: the card stays above it.
        const viewport = window.visualViewport;
        const fit = () => panel.current?.parentElement?.style.setProperty('--kb', `${Math.max(0, window.innerHeight - (viewport?.height ?? window.innerHeight))}px`);
        viewport?.addEventListener('resize', fit);

        return () => {
            document.removeEventListener('keydown', onKey);
            viewport?.removeEventListener('resize', fit);
            stack.splice(stack.indexOf(id), 1);
            if (stack.length === 0) document.body.style.overflow = '';
            (opener.current?.isConnected ? opener.current : document.querySelector<HTMLElement>('main[tabindex]'))?.focus({ preventScroll: true });
            opener.current = null;
        };
    }, [open, id]);

    // The first field of the body (or one marked autoFocus), else the card itself, so Escape and Tab work at once. On a
    // touch screen the card itself: a field focused on opening would throw the keyboard over the dialog.
    useLayoutEffect(() => {
        if (!open || !panel.current) return;
        const body = panel.current.querySelector<HTMLElement>('[data-modal-body]');
        const touch = window.matchMedia('(pointer: coarse)').matches;
        const target = touch ? null : (panel.current.querySelector<HTMLElement>('[autofocus]') ?? body?.querySelector<HTMLElement>('input:not([type="hidden"]):not([disabled]):not([type="checkbox"]):not([type="radio"]), select:not([disabled]), textarea:not([disabled])'));
        (target ?? panel.current).focus({ preventScroll: true });
    }, [open]);

    if (!open) return null;
    // The panel's dialogs scroll as one card; the full-screen one and the member's sheet keep their title in place.
    const whole = admin && size !== 'full';

    // Portalled out of the page, so it carries the control panel's look along (see AdminScope).
    return createPortal(
        <div className={cn('fixed inset-0 z-50 flex justify-center', admin ? 'admin-ui items-center' : 'items-end sm:items-center sm:p-4')}>
            <div className={cn('absolute inset-0 animate-[overlay-in_0.15s_ease-out]', admin ? 'bg-black/20 backdrop-blur-sm' : 'bg-ink/40 backdrop-blur-[2px]')} onClick={onClose} aria-hidden />
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={`${id}-title`}
                aria-describedby={description ? `${id}-description` : undefined}
                tabIndex={-1}
                className={cn(
                    'relative bg-white focus:outline-none',
                    admin
                        ? cn('max-h-[calc(100dvh-var(--kb,0px)-1.5rem)] w-full animate-[dialog-in_0.18s_cubic-bezier(0.22,1,0.36,1)] rounded-2xl border border-line shadow-[0_20px_60px_rgba(0,0,0,0.15)]', whole ? 'overflow-y-auto overscroll-contain' : 'flex flex-col')
                        : 'flex max-h-[92vh] w-full flex-col rounded-t-3xl shadow-2xl sm:rounded-3xl',
                    SIZES[size],
                )}
            >
                {admin ? (
                    <>
                        <div className="shrink-0 p-5 pb-3 pe-12">
                            <h2 id={`${id}-title`} className="text-[17px] font-semibold text-ink">{title}</h2>
                            {description && <p id={`${id}-description`} className="mt-1.5 text-[13px] text-[#6b7280]">{description}</p>}
                        </div>
                        <button type="button" onClick={onClose} className="absolute end-4 top-4 rounded-[8px] p-1.5 text-[#6b7280] transition-colors hover:bg-[#f2f2f0] hover:text-ink" aria-label="إغلاق">
                            <X className="size-4" />
                        </button>
                    </>
                ) : (
                    <div className="flex shrink-0 items-center justify-between gap-4 border-b border-line px-5 py-4">
                        <div>
                            <h2 id={`${id}-title`} className="text-base font-semibold text-ink">{title}</h2>
                            {description && <p id={`${id}-description`} className="mt-1 text-[13px] text-muted">{description}</p>}
                        </div>
                        <button type="button" onClick={onClose} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-brand-50" aria-label="إغلاق">
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <div data-modal-body className={cn(!whole && 'min-h-0 flex-1 overflow-y-auto overscroll-contain', admin ? cn('px-5', footer ? 'pb-1' : 'pb-5') : 'px-5 py-4')}>{children}</div>
                {footer && <div className={cn('flex shrink-0 flex-wrap items-center justify-end gap-2', admin ? 'px-5 pt-3 pb-5' : 'border-t border-line px-5 py-3')}>{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
