import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export function Field({ label, error, hint, children, className, required }: { label?: ReactNode; error?: string; hint?: ReactNode; children: ReactNode; className?: string; required?: boolean }) {
    return (
        <div className={className}>
            {label && (
                <label className="label">
                    {label}
                    {required && <span className="ms-0.5 text-brand">*</span>}
                </label>
            )}
            {children}
            {hint && !error && <p className="mt-1 text-xs text-muted">{hint}</p>}
            {error && <p className="mt-1 text-xs font-medium text-red-600">{error}</p>}
        </div>
    );
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={cn('input', className)} {...props} />;
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <textarea className={cn('input min-h-24', className)} {...props} />;
}

export function Select({ className, children, placeholder, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { placeholder?: string }) {
    return (
        <select className={cn('input appearance-none bg-[length:1rem] bg-[left_0.75rem_center] bg-no-repeat pe-3.5 ps-9', className)} style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237a6e7e' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }} {...props}>
            {placeholder !== undefined && <option value="">{placeholder}</option>}
            {children}
        </select>
    );
}

export function Checkbox({ label, className, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: ReactNode }) {
    return (
        <label className={cn('inline-flex cursor-pointer items-center gap-2 text-sm text-ink', className)}>
            <input type="checkbox" className="size-4 rounded border-line accent-[var(--brand)]" {...props} />
            {label}
        </label>
    );
}

export function Toggle({ checked, onChange, label, hint }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; hint?: ReactNode }) {
    return (
        <label className="flex cursor-pointer items-start justify-between gap-4 py-1">
            <span>
                <span className="block text-sm font-medium text-ink">{label}</span>
                {hint && <span className="block text-xs text-muted">{hint}</span>}
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cn('relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full transition', checked ? 'bg-brand' : 'bg-line')}
            >
                <span className={cn('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', checked ? 'start-[22px]' : 'start-0.5')} />
            </button>
        </label>
    );
}
