import { Children, cloneElement, Fragment, isValidElement, useId, type ComponentProps, type InputHTMLAttributes, type ReactElement, type ReactNode, type TextareaHTMLAttributes } from 'react';
import * as SelectPrimitive from '@radix-ui/react-select';
import { Check, ChevronDown, ChevronUp, Paperclip, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

type ControlProps = { id?: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string };

/** The field's single form control, when it is one we can label (so the label focuses it and screen readers announce it). */
function labelledControl(children: ReactNode): ReactElement<ControlProps> | null {
    if (!isValidElement<ControlProps>(children)) return null;
    const t = children.type;
    return t === Input || t === Select || t === Textarea || t === 'input' || t === 'select' || t === 'textarea' ? children : null;
}

export function Field({ label, error, hint, children, className, required }: { label?: ReactNode; error?: string; hint?: ReactNode; children: ReactNode; className?: string; required?: boolean }) {
    const autoId = useId();
    const admin = useAdmin();
    const control = labelledControl(children);
    const id = control ? (control.props.id ?? autoId) : undefined;
    const noteId = id && (error || hint) ? `${id}-note` : undefined;
    return (
        <div className={className}>
            {label && (
                <label className="label" htmlFor={id}>
                    {label}
                    {required && <span className={admin ? 'text-[#dc2626]' : 'ms-0.5 text-brand'}>{admin ? ' *' : '*'}</span>}
                </label>
            )}
            {control ? cloneElement(control, { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': noteId }) : children}
            {hint && !error && <p id={noteId} className={admin ? 'mt-1.5 text-[12px] text-[#9ca3af]' : 'mt-1 text-xs text-muted'}>{hint}</p>}
            {error && <p id={noteId} className={admin ? 'mt-1.5 text-[12px] text-[#b91c1c]' : 'mt-1 text-xs font-medium text-red-600'}>{error}</p>}
        </div>
    );
}

/** Arabic-Indic and Persian digits, and the Arabic decimal and thousands marks, as arithmetic and the server read them. */
export function asciiDigits(value: string): string {
    return value
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/٫/g, '.')
        .replace(/٬/g, '');
}

/**
 * A figure typed on an Arabic keyboard is a figure: number, phone and decimal fields turn «١٢٫٥» into 12.5 as it is
 * typed. A number field is drawn as text with a numeric keyboard (a browser's number field drops what it cannot read);
 * the server checks its range.
 */
export function Input({ className, onChange, type, inputMode, ...props }: ComponentProps<'input'>) {
    const number = type === 'number';
    const numeric = number || type === 'tel' || inputMode === 'decimal' || inputMode === 'numeric' || inputMode === 'tel';
    const change: typeof onChange = numeric && onChange
        ? (e) => {
              const fixed = asciiDigits(e.target.value);
              if (fixed !== e.target.value) e.target.value = fixed;
              onChange(e);
          }
        : onChange;
    const fractional = number && props.step !== undefined && String(props.step) !== '1';

    // Figures, dates and times read left to right whatever the page's direction.
    const ltr = number || type === 'date' || type === 'time' || type === 'datetime-local' || type === 'month';

    return <input className={cn('input', className)} type={number ? 'text' : type} inputMode={number ? (fractional ? 'decimal' : 'numeric') : inputMode} autoComplete={number ? 'off' : props.autoComplete} dir={ltr ? 'ltr' : undefined} onChange={change} {...props} />;
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <textarea className={cn('input min-h-20', className)} {...props} />;
}

type Choice = { value: string; label: string; disabled?: boolean };

/** A node's words, as an option's label reads them («أحمد — مرتبط بموظف آخر» from `{name}{note}`). */
function textOf(node: ReactNode): string {
    return Children.toArray(node)
        .map((c) => (typeof c === 'string' || typeof c === 'number' ? String(c) : isValidElement<{ children?: ReactNode }>(c) ? textOf(c.props.children) : ''))
        .join('');
}

/** The `<option>`s written inside a Select (in arrays, fragments and conditions), as the choices of its list. */
function choicesOf(children: ReactNode): Choice[] {
    const out: Choice[] = [];
    Children.forEach(children, (child) => {
        if (!isValidElement<{ children?: ReactNode; value?: string | number; disabled?: boolean }>(child)) return;
        if (child.type === Fragment) out.push(...choicesOf(child.props.children));
        else if (child.type === 'option') out.push({ value: String(child.props.value ?? textOf(child.props.children)), label: textOf(child.props.children), disabled: child.props.disabled });
    });
    return out;
}

// Radix keeps an empty value for «nothing chosen»; the empty choice a list offers («كل الفروع») goes by this one inside.
const EMPTY = '__empty__';

type SelectProps = {
    value?: string | number | null;
    /** As a native select's change event, so every caller reads `e.target.value` as before. */
    onChange?: (event: { target: { value: string; name: string } }) => void;
    /** The empty first choice: said on the button while nothing is chosen, and chosen again to clear it. */
    placeholder?: string;
    name?: string;
    id?: string;
    disabled?: boolean;
    required?: boolean;
    className?: string;
    children?: ReactNode;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

/**
 * A list to choose from, drawn in the page as the reference's (a Radix select) rather than by the system: it opens 6px
 * under its field at the field's width, as high as the screen allows and scrolling inside, a long choice wrapping
 * instead of being cut, the chosen one ticked; Escape, a click outside or a choice close it and the focus is back on
 * the field. The choices are the `<option>`s written inside it; one with an empty value is the placeholder.
 */
export function Select({ value, onChange, placeholder, name, id, disabled, required, className, children, ...aria }: SelectProps) {
    const admin = useAdmin();
    const choices = choicesOf(children);
    const hint = placeholder ?? choices.find((c) => c.value === '')?.label;
    // Drawn over the page, the list takes the page's direction (Radix would draw it left to right).
    const dir = typeof document !== 'undefined' && document.documentElement.dir === 'ltr' ? 'ltr' : 'rtl';

    return (
        <SelectPrimitive.Root
            dir={dir}
            // The empty value is passed as it is: undefined would leave Radix holding its own choice, and clearing would not stick.
            value={value === null || value === undefined ? '' : String(value)}
            onValueChange={(next) => onChange?.({ target: { value: next === EMPTY ? '' : next, name: name ?? '' } })}
            name={name}
            disabled={disabled}
            required={required}
        >
            <SelectPrimitive.Trigger
                id={id}
                {...aria}
                className={cn(
                    admin
                        ? 'flex h-10 w-full items-center justify-between gap-2 rounded-[10px] border border-[#e8e8e8] bg-white px-3 text-start text-sm text-[#111] outline-none transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] disabled:cursor-not-allowed disabled:opacity-60 data-[placeholder]:text-[#9ca3af]'
                        : 'input flex items-center justify-between gap-2 text-start data-[placeholder]:text-muted/70',
                    // A long choice is cut on the button only; the open list shows it whole.
                    '[&>span]:min-w-0 [&>span]:truncate',
                    className,
                )}
            >
                <SelectPrimitive.Value placeholder={hint} />
                <SelectPrimitive.Icon asChild>
                    <ChevronDown className="size-4 shrink-0 text-[#6b7280]" />
                </SelectPrimitive.Icon>
            </SelectPrimitive.Trigger>
            <SelectPrimitive.Portal>
                <SelectPrimitive.Content
                    position="popper"
                    sideOffset={6}
                    className="relative z-[60] max-h-[var(--radix-select-content-available-height)] w-[var(--radix-select-trigger-width)] overflow-hidden rounded-[12px] border border-[#e8e8e8] bg-white text-[#111] shadow-[0_8px_30px_rgba(0,0,0,0.10)]"
                >
                    <SelectPrimitive.ScrollUpButton className="flex cursor-default items-center justify-center py-1 text-[#6b7280]">
                        <ChevronUp className="size-4" />
                    </SelectPrimitive.ScrollUpButton>
                    <SelectPrimitive.Viewport className="p-1.5">
                        {hint !== undefined && <SelectChoice value={EMPTY}>{hint}</SelectChoice>}
                        {choices.filter((c) => c.value !== '').map((c) => (
                            <SelectChoice key={c.value} value={c.value} disabled={c.disabled}>
                                {c.label}
                            </SelectChoice>
                        ))}
                    </SelectPrimitive.Viewport>
                    <SelectPrimitive.ScrollDownButton className="flex cursor-default items-center justify-center py-1 text-[#6b7280]">
                        <ChevronDown className="size-4" />
                    </SelectPrimitive.ScrollDownButton>
                </SelectPrimitive.Content>
            </SelectPrimitive.Portal>
        </SelectPrimitive.Root>
    );
}

function SelectChoice({ value, disabled, children }: { value: string; disabled?: boolean; children: ReactNode }) {
    return (
        <SelectPrimitive.Item
            value={value}
            disabled={disabled}
            className="relative flex w-full cursor-pointer select-none items-start gap-2 rounded-[8px] py-2 pe-8 ps-3 text-start text-sm leading-snug break-words outline-none transition-colors focus:bg-[#f2f2f0] data-[state=checked]:font-semibold data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
        >
            <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
            <span className="absolute end-2 top-2 flex size-4 items-center justify-center">
                <SelectPrimitive.ItemIndicator>
                    <Check className="size-4" />
                </SelectPrimitive.ItemIndicator>
            </span>
        </SelectPrimitive.Item>
    );
}

/**
 * A file to attach: a dashed 40px box with a paperclip and «اختيار ملف», then the chosen file's name (with ✕ to take it
 * off) — the system's own control in place of the browser's, whose words follow the browser's language.
 */
export function FileInput({ accept, file, onChange, placeholder = 'اختيار ملف', className }: { accept?: string; file: File | null | undefined; onChange: (file: File | null) => void; placeholder?: string; className?: string }) {
    return (
        <label className={cn('flex h-10 cursor-pointer items-center gap-2 rounded-[10px] border border-dashed border-line bg-[#fafafa] px-3 text-[13px] text-ink transition-colors hover:bg-[#f4f4f5] focus-within:ring-2 focus-within:ring-[#d1d5db]', className)}>
            <Paperclip className="size-4 shrink-0 text-[#6b7280]" />
            <span className={cn('min-w-0 flex-1 truncate', !file && 'text-[#6b7280]')}>{file?.name ?? placeholder}</span>
            {file && (
                <button type="button" className="shrink-0 rounded p-0.5 text-[#6b7280] hover:text-[#b91c1c]" aria-label="إزالة الملف" onClick={(e) => { e.preventDefault(); onChange(null); }}>
                    <X className="size-3.5" />
                </button>
            )}
            <input type="file" accept={accept} className="sr-only" onChange={(e) => { onChange(e.target.files?.[0] ?? null); e.target.value = ''; }} />
        </label>
    );
}

export function Checkbox({ label, className, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: ReactNode }) {
    return (
        <label className={cn('inline-flex cursor-pointer items-center gap-2.5 text-sm text-[#374151]', className)}>
            <input type="checkbox" className="size-4 rounded border-[#d1d5db] accent-[var(--brand)]" {...props} />
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
            className={cn('relative inline-flex shrink-0 rounded-full transition disabled:cursor-not-allowed disabled:opacity-50', size === 'sm' ? 'h-5 w-9' : 'h-6 w-12', checked ? 'bg-brand' : 'bg-[#d1d5db]')}
        >
            <span className={cn('absolute top-0.5 rounded-full bg-white shadow transition-all', size === 'sm' ? 'size-4' : 'size-5', checked ? (size === 'sm' ? 'start-[18px]' : 'start-[26px]') : 'start-0.5')} />
        </button>
    );
}

export function Toggle({ checked, onChange, label, hint }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; hint?: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 py-2">
            <span className="cursor-pointer" onClick={() => onChange(!checked)}>
                <span className="block text-sm font-medium text-ink">{label}</span>
                {hint && <span className="mt-0.5 block text-[12px] text-[#9ca3af]">{hint}</span>}
            </span>
            <Switch checked={checked} onChange={onChange} label={typeof label === 'string' ? label : ''} />
        </div>
    );
}
