import { cloneElement, isValidElement, useId, type InputHTMLAttributes, type ReactElement, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

type ControlProps = { id?: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string };

/** The field's single form control, when it is one we can label (so the label focuses it and screen readers announce it). */
function labelledControl(children: ReactNode): ReactElement<ControlProps> | null {
    if (!isValidElement<ControlProps>(children)) return null;
    const t = children.type;
    return t === Input || t === Select || t === Textarea || t === 'input' || t === 'select' || t === 'textarea' ? children : null;
}

export function Field({ label, error, hint, children, className, required }: { label?: ReactNode; error?: string; hint?: ReactNode; children: ReactNode; className?: string; required?: boolean }) {
    const autoId = useId();
    const control = labelledControl(children);
    const id = control ? (control.props.id ?? autoId) : undefined;
    const noteId = id && (error || hint) ? `${id}-note` : undefined;
    return (
        <div className={className}>
            {label && (
                <label className="label" htmlFor={id}>
                    {label}
                    {required && <span className="ms-0.5 text-brand">*</span>}
                </label>
            )}
            {control ? cloneElement(control, { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': noteId }) : children}
            {hint && !error && <p id={noteId} className="mt-1 text-xs text-muted">{hint}</p>}
            {error && <p id={noteId} className="mt-1 text-xs font-medium text-red-600">{error}</p>}
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

/** A bare on/off switch (a table row's, a card's); Toggle below is the same switch with its label. */
export function Switch({ checked, onChange, label, disabled, size = 'md' }: { checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean; size?: 'sm' | 'md' }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn('relative inline-flex shrink-0 rounded-full transition disabled:cursor-not-allowed disabled:opacity-50', size === 'sm' ? 'h-5 w-9' : 'mt-0.5 h-6 w-11', checked ? 'bg-brand' : 'bg-line')}
        >
            <span className={cn('absolute top-0.5 rounded-full bg-white shadow transition-all', size === 'sm' ? 'size-4' : 'size-5', checked ? (size === 'sm' ? 'start-[18px]' : 'start-[22px]') : 'start-0.5')} />
        </button>
    );
}

export function Toggle({ checked, onChange, label, hint }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; hint?: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-1">
            <span className="cursor-pointer" onClick={() => onChange(!checked)}>
                <span className="block text-sm font-medium text-ink">{label}</span>
                {hint && <span className="block text-xs text-muted">{hint}</span>}
            </span>
            <Switch checked={checked} onChange={onChange} label={typeof label === 'string' ? label : ''} />
        </div>
    );
}
