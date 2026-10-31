import { useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

/**
 * A dialog. In a control panel: a white card centred on a dimmed, blurred page (on a phone too), 16px rounded, its title
 * 17px semibold with the ✕ in the corner, the content 20px in from the edges and the buttons under it — no lines between
 * them. Elsewhere (the member's pages) it rises from the bottom on a phone. `xs` is the width of a confirmation.
 */
export default function Modal({ open, onClose, title, children, footer, size = 'md' }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; footer?: ReactNode; size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' }) {
    const admin = useAdmin();
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open, onClose]);

    if (!open) return null;

    // Portalled out of the page, so it carries the control panel's look along (see AdminScope).
    return createPortal(
        <div className={cn('fixed inset-0 z-50 flex justify-center', admin ? 'admin-ui items-center' : 'items-end sm:items-center sm:p-4')} role="dialog" aria-modal="true">
            <div className={cn('absolute inset-0', admin ? 'bg-black/20 backdrop-blur-sm' : 'bg-ink/40 backdrop-blur-[2px]')} onClick={onClose} />
            <div
                className={cn(
                    'relative flex w-full flex-col bg-white',
                    admin ? 'max-h-[calc(100dvh-1.5rem)] rounded-2xl border border-line shadow-[0_20px_60px_rgba(0,0,0,0.15)]' : 'max-h-[92vh] rounded-t-3xl shadow-2xl sm:rounded-3xl',
                    { xs: 'sm:max-w-sm', sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' }[size],
                )}
            >
                {admin ? (
                    <>
                        <div className="p-5 pb-3 pe-12">
                            <h2 className="text-[17px] font-semibold text-ink">{title}</h2>
                        </div>
                        <button type="button" onClick={onClose} className="absolute end-4 top-4 rounded-[8px] p-1.5 text-[#6b7280] transition-colors hover:bg-[#f2f2f0] hover:text-ink" aria-label="إغلاق">
                            <X className="size-4" />
                        </button>
                    </>
                ) : (
                    <div className="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                        <h2 className="text-base font-semibold text-ink">{title}</h2>
                        <button type="button" onClick={onClose} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-brand-50" aria-label="إغلاق">
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <div className={cn('overflow-y-auto', admin ? 'px-5 pb-5' : 'px-5 py-4')}>{children}</div>
                {footer && <div className={cn('flex flex-wrap justify-end gap-2', admin ? 'px-5 pb-5' : 'border-t border-line px-5 py-3')}>{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
