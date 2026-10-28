import { useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

export default function Modal({ open, onClose, title, children, footer, size = 'md' }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; footer?: ReactNode; size?: 'sm' | 'md' | 'lg' | 'xl' }) {
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
        <div className={cn('fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4', admin && 'admin-ui')} role="dialog" aria-modal="true">
            <div className={cn('absolute inset-0', admin ? 'bg-black/20 backdrop-blur-sm' : 'bg-ink/40 backdrop-blur-[2px]')} onClick={onClose} />
            <div
                className={cn(
                    'relative flex max-h-[92vh] w-full flex-col bg-white',
                    admin ? 'rounded-t-2xl border border-line shadow-[0_20px_60px_rgba(0,0,0,0.15)] sm:rounded-2xl' : 'rounded-t-3xl shadow-2xl sm:rounded-3xl',
                    { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' }[size],
                )}
            >
                <div className="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                    <h2 className={cn('font-semibold text-ink', admin ? 'text-[17px]' : 'text-base')}>{title}</h2>
                    <button type="button" onClick={onClose} className={cn('grid size-8 place-items-center rounded-lg text-muted', admin ? 'hover:bg-[#f2f2f0] hover:text-ink' : 'hover:bg-brand-50')} aria-label="إغلاق">
                        <X className="size-4" />
                    </button>
                </div>
                <div className="overflow-y-auto px-5 py-4">{children}</div>
                {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
