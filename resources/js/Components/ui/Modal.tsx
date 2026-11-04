import { useEffect, useRef, useState, type FormEvent, type MouseEvent, type ReactNode } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

/**
 * The widths of a dialog, by what it holds — the reference's own widths for each kind:
 * - confirm (384px): a question, a deletion, a reason to give;
 * - compact (448px): a short form of a few fields;
 * - form (512px, the default): adding or editing a record;
 * - large (672px): choosing products, members or customers, or a form in two columns;
 * - wide (896px): a table, or a long form;
 * - full (1024px, the reference's widest, and the height of the screen): a paper at its size.
 * As the reference's, a screen narrower than the width has the dialog across all of it, and a slightly wider one
 * shows the page in the sliver left at its sides (a confirmation on a 390px phone: 3px each side).
 */
export const MODAL_SIZES = {
    confirm: 'max-w-sm',
    compact: 'max-w-md',
    form: 'max-w-lg',
    large: 'max-w-2xl',
    wide: 'max-w-4xl',
    full: 'max-w-5xl h-[calc(100dvh-var(--kb,0px)-1.5rem)]',
} as const;

export type ModalSize = keyof typeof MODAL_SIZES;

/** Layers that live outside the dialog but belong to it — a menu opened from it, the notices — are not «outside». */
const OWN_LAYERS = '[data-layer], [data-sonner-toaster]';

const TABBABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])';

// Safari gives no focus to a button it presses, on a phone or a computer: what opened a dialog is known from the press,
// and from the focus only when the keyboard opened it.
let pressed: HTMLElement | null = null;
if (typeof document !== 'undefined') {
    document.addEventListener('pointerdown', (e) => {
        pressed = e.target instanceof Element ? e.target.closest<HTMLElement>('button, a[href], [role="button"]') : null;
    }, true);
    document.addEventListener('keydown', () => {
        pressed = null;
    }, true);
}

/**
 * The one dialog of the system, on Radix Dialog as the reference's: a white card 16px rounded with a #e8e8e8 hairline
 * and a soft shadow, on a page dimmed to black/20 and slightly blurred; the title 17px semibold and an optional 13px
 * grey line under it, 20px in, the ✕ in the corner; the buttons at the end, 20px under what is above them, cancel
 * first and the action last. It appears and goes at once, as the reference's does in the browser.
 *
 * It sits in the middle of what is visible: the on-screen keyboard's height (--kb, see lib/keyboard) is taken off, so
 * the fields and buttons stay above it. It is never taller than that; a long one scrolls as a whole, buttons included
 * (the full size keeps its title in place over a body that scrolls). Escape, the dimmed page and ✕ close it — but not
 * while a form inside it is being sent, nor when `dismissible` is false. A form inside it cannot be sent again while
 * the first sending is on its way. Focus goes to its first field or button and stays inside it (dialogs over dialogs
 * each in turn); the element that opened it gets it back on closing — the page when that element is gone (a deleted
 * row's). The page behind does not scroll.
 */
export default function Modal({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    size = 'form',
    dismissible = true,
}: {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    size?: ModalSize;
    /** false while something must not be abandoned midway: Escape, the page and ✕ then do nothing. */
    dismissible?: boolean;
}) {
    const admin = useAdmin();
    const card = useRef<HTMLDivElement>(null);

    // The element that had the focus before the dialog took it, read as the dialog first renders open.
    const opener = useRef<HTMLElement | null>(null);
    if (open && opener.current === null && typeof document !== 'undefined') {
        opener.current = pressed?.isConnected ? pressed : document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
    }

    // A request this dialog started (a form sent, a button pressed) on its way to the server: sent once, and the dialog
    // stays until the answer. The ref answers at once, before the state has drawn the card busy.
    const [sending, setSending] = useState(false);
    const busy = useRef(false);
    const actedAt = useRef(0);
    useEffect(() => {
        if (!open) return;
        const offStart = router.on('start', () => {
            if (performance.now() - actedAt.current < 150) {
                busy.current = true;
                setSending(true);
            }
        });
        const offFinish = router.on('finish', () => {
            busy.current = false;
            setSending(false);
        });
        return () => {
            offStart();
            offFinish();
            busy.current = false;
            setSending(false);
        };
    }, [open]);

    const locked = !dismissible || sending;
    // While it is on its way, a second submit or click inside the dialog is not passed on: nothing is sent twice.
    const guard = (e: FormEvent | MouseEvent) => {
        if (busy.current) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        actedAt.current = performance.now();
    };
    const outside = (e: CustomEvent<{ originalEvent: Event }>) => {
        const target = e.detail.originalEvent.target;
        if (locked || (target instanceof Element && target.closest(OWN_LAYERS))) e.preventDefault();
    };
    // The full size keeps its title in place; every other scrolls as one card.
    const whole = size !== 'full';

    return (
        <DialogPrimitive.Root open={open} onOpenChange={(next) => !next && !locked && onClose()}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/20 backdrop-blur-sm" />
                <DialogPrimitive.Content
                    ref={card}
                    {...(description ? {} : { 'aria-describedby': undefined })}
                    onSubmitCapture={guard}
                    onClickCapture={guard}
                    aria-busy={sending || undefined}
                    onEscapeKeyDown={(e) => locked && e.preventDefault()}
                    onPointerDownOutside={outside}
                    onInteractOutside={outside}
                    onOpenAutoFocus={(e) => {
                        // A paper's frame first would take the focus, and Escape with it: the card holds it instead.
                        if (card.current?.querySelector(TABBABLE)?.tagName === 'IFRAME') {
                            e.preventDefault();
                            card.current.focus({ preventScroll: true });
                        }
                    }}
                    onCloseAutoFocus={(e) => {
                        e.preventDefault();
                        (opener.current?.isConnected ? opener.current : document.querySelector<HTMLElement>('main[tabindex]'))?.focus({ preventScroll: true });
                        opener.current = null;
                    }}
                    className={cn(
                        // Portalled out of the page, so it carries the control panel's look along (see AdminScope).
                        admin && 'admin-ui',
                        'fixed left-1/2 top-[calc(50%-var(--kb,0px)/2)] z-50 w-full -translate-x-1/2 -translate-y-1/2',
                        'max-h-[calc(100dvh-var(--kb,0px)-1.5rem)] rounded-2xl border border-[#e8e8e8] bg-white text-start shadow-[0_20px_60px_rgba(0,0,0,0.15)] focus:outline-none',
                        whole ? 'overflow-y-auto overscroll-contain' : 'flex flex-col',
                        MODAL_SIZES[size],
                    )}
                >
                    <div className="shrink-0 p-5 pb-3 pe-12">
                        <DialogPrimitive.Title className="text-[17px] font-semibold text-[#111]">{title}</DialogPrimitive.Title>
                        {description && <DialogPrimitive.Description className="mt-1.5 text-[13px] text-[#6b7280]">{description}</DialogPrimitive.Description>}
                    </div>
                    <div data-modal-body className={cn(!whole && 'min-h-0 flex-1 overflow-y-auto overscroll-contain', 'px-5', footer ? 'pb-1' : 'pb-5')}>{children}</div>
                    {footer && <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 px-5 pt-4 pb-5">{footer}</div>}
                    <DialogPrimitive.Close
                        disabled={locked}
                        aria-label="إغلاق"
                        className="absolute end-4 top-4 rounded-[8px] p-1.5 text-[#6b7280] transition-colors hover:bg-[#f2f2f0] hover:text-[#111] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#111]/15 disabled:pointer-events-none disabled:opacity-40"
                    >
                        <X className="size-4" />
                    </DialogPrimitive.Close>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
