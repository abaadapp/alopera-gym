import { useId, useState } from 'react';
import { ChevronDown, ChevronUp, GripVertical, Plus, Trash2 } from 'lucide-react';
import { Button, Field, Input, Select, Textarea, Toggle } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { checkVideo, hasErrorUnder, isHexColor, move, newListItem } from '@/lib/site-builder';
import type { FieldSchema, ImageRef } from '@/types/site';
import { useBuilder } from './context';
import ImageField from './ImageField';
import LinkPicker from './LinkPicker';
import { useDragSort } from './useDragSort';

type Value = unknown;

/** «٣٥/٦٠» under a text field, red past the limit. */
function Counter({ value, max }: { value: string; max?: number }) {
    if (!max) return null;
    const n = [...value].length;
    return <span className={cn('num text-[11px]', n > max ? 'text-red-600' : 'text-muted')}>{n}/{max}</span>;
}

/**
 * One field of a section (or of the menu, the footer, the contact details), drawn from its schema. `path` is where
 * its value sits in the document, so the server's error for it shows under it.
 */
export default function FieldInput({ field, value, onChange, path }: { field: FieldSchema; value: Value; onChange: (v: Value) => void; path: string }) {
    const { errors } = useBuilder();
    const id = useId();
    const key = path ? `${path}.${field.key}` : field.key;
    const error = errors[key];

    switch (field.type) {
        case 'text': {
            const v = typeof value === 'string' ? value : '';
            return (
                <Field label={<span className="flex w-full items-center justify-between gap-2"><span>{field.label}</span><Counter value={v} max={field.max} /></span>} hint={field.help} error={error}>
                    <Input id={id} value={v} maxLength={field.max} placeholder={field.placeholder} onChange={(e) => onChange(e.target.value)} />
                </Field>
            );
        }
        case 'textarea': {
            const v = typeof value === 'string' ? value : '';
            return (
                <Field label={<span className="flex w-full items-center justify-between gap-2"><span>{field.label}</span><Counter value={v} max={field.max} /></span>} hint={field.help} error={error}>
                    <Textarea id={id} value={v} maxLength={field.max} rows={field.rows ?? 3} placeholder={field.placeholder} onChange={(e) => onChange(e.target.value)} />
                </Field>
            );
        }
        case 'link': {
            const v = (value && typeof value === 'object' ? value : { label: '', href: '' }) as { label?: string; href?: string };
            return (
                <fieldset className="min-w-0 space-y-2 rounded-xl border border-line p-3">
                    <legend className="px-1 text-sm font-medium text-ink">{field.label}</legend>
                    {field.help && <p className="text-[12px] text-muted">{field.help}</p>}
                    <Field label={<span className="flex w-full items-center justify-between gap-2"><span>نص الزر</span><Counter value={v.label ?? ''} max={40} /></span>} error={errors[`${key}.label`] ?? error}>
                        <Input id={`${id}-label`} value={v.label ?? ''} maxLength={40} onChange={(e) => onChange({ label: e.target.value, href: v.href ?? '' })} />
                    </Field>
                    <div>
                        <span className="label">يفتح</span>
                        <LinkPicker id={`${id}-href`} value={v.href ?? ''} onChange={(href) => onChange({ label: v.label ?? '', href })} error={errors[`${key}.href`]} />
                    </div>
                </fieldset>
            );
        }
        case 'href':
            return (
                <div>
                    <span className="label">{field.label}</span>
                    <LinkPicker id={id} value={typeof value === 'string' ? value : ''} onChange={onChange} error={error} />
                    {field.help && <p className="mt-1 text-[12px] text-muted">{field.help}</p>}
                </div>
            );
        case 'image':
            return (
                <div>
                    <span className="label">{field.label}</span>
                    <ImageField field={field} value={value as ImageRef | null} onChange={onChange} error={error ?? errors[`${key}.id`]} />
                </div>
            );
        case 'toggle':
            return (
                <div>
                    <Toggle checked={value === true} onChange={onChange} label={field.label} hint={field.help} />
                    {error && <p className="text-xs font-medium text-red-600">{error}</p>}
                </div>
            );
        case 'select':
            return (
                <Field label={field.label} hint={field.help} error={error}>
                    <Select id={id} value={typeof value === 'string' ? value : (field.options?.[0]?.value ?? '')} onChange={(e) => onChange(e.target.value || field.options?.[0]?.value || '')}>
                        {(field.options ?? []).map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </Select>
                </Field>
            );
        case 'number':
            return <NumberField field={field} value={value} onChange={onChange} error={error} id={id} />;
        case 'color': {
            const v = typeof value === 'string' ? value : '#000000';
            return (
                <Field label={field.label} hint={field.help} error={error ?? (isHexColor(v) ? undefined : 'اكتب اللون هكذا: #8A6700')}>
                    <div className="flex items-center gap-2">
                        <input type="color" value={isHexColor(v) ? v : '#000000'} onChange={(e) => onChange(e.target.value.toUpperCase())} className="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-line bg-white p-1" aria-label={field.label} />
                        <Input id={id} dir="ltr" value={v} maxLength={7} onChange={(e) => onChange(e.target.value.trim())} className="font-mono" />
                    </div>
                </Field>
            );
        }
        case 'video': {
            const v = typeof value === 'string' ? value : '';
            return (
                <Field label={field.label} hint={field.help} error={error ?? checkVideo(v) ?? undefined}>
                    <Input id={id} dir="ltr" value={v} maxLength={500} placeholder={field.placeholder} onChange={(e) => onChange(e.target.value.trim())} />
                </Field>
            );
        }
        case 'list':
            return <ListField field={field} value={value} onChange={onChange} path={key} />;
        default:
            return null;
    }
}

function NumberField({ field, value, onChange, error, id }: { field: FieldSchema; value: Value; onChange: (v: Value) => void; error?: string; id: string }) {
    const n = typeof value === 'number' ? value : Number(value) || (field.min ?? 0);
    const [text, setText] = useState(String(n));
    const clamp = (x: number) => Math.min(field.max ?? Number.MAX_SAFE_INTEGER, Math.max(field.min ?? 0, Math.round(x)));
    return (
        <Field label={field.label} hint={field.help ?? (field.min !== undefined && field.max !== undefined ? `من ${field.min} إلى ${field.max}` : undefined)} error={error}>
            <Input
                id={id}
                type="number"
                value={text}
                onChange={(e) => {
                    setText(e.target.value);
                    const parsed = parseInt(e.target.value, 10);
                    if (!Number.isNaN(parsed)) onChange(clamp(parsed));
                }}
                onBlur={() => setText(String(clamp(parseInt(text, 10) || n)))}
                className="max-w-32"
            />
        </Field>
    );
}

/** A list of items (images, cards, questions…): each collapsible, added up to its most, removed, and reordered. */
function ListField({ field, value, onChange, path }: { field: FieldSchema; value: Value; onChange: (v: Value) => void; path: string }) {
    const { errors } = useBuilder();
    const items = Array.isArray(value) ? (value as Record<string, unknown>[]) : [];
    const [open, setOpen] = useState<string | null>(null);
    const max = field.max ?? 50;
    const name = field.item ?? 'عنصر';
    const sort = useDragSort(path, items.length, (from, to) => onChange(move(items, from, to)));

    const update = (i: number, key: string, v: Value) => onChange(items.map((item, j) => (j === i ? { ...item, [key]: v } : item)));
    const add = () => {
        const item = newListItem(field);
        onChange([...items, item]);
        setOpen(item.id as string);
    };

    const title = (item: Record<string, unknown>, i: number) => {
        const first = (field.fields ?? []).find((f) => (f.type === 'text' || f.type === 'textarea') && typeof item[f.key] === 'string' && (item[f.key] as string).trim() !== '');
        const words = first ? (item[first.key] as string).trim() : '';
        return `${name} ${i + 1}${words ? ` — ${words.slice(0, 50)}` : ''}`;
    };

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <span className="label mb-0">{field.label}</span>
                <span className="num text-[12px] text-muted">{items.length}/{max}</span>
            </div>
            {field.help && <p className="text-[12px] text-muted">{field.help}</p>}
            {errors[path] && <p className="text-xs font-medium text-red-600">{errors[path]}</p>}
            {items.length === 0 && <p className="rounded-xl border border-dashed border-line px-3 py-4 text-center text-[13px] text-muted">لا يوجد {name} بعد.</p>}
            <div>
                {items.map((item, i) => {
                    const itemId = (item.id as string) || String(i);
                    const expanded = open === itemId;
                    const itemPath = `${path}.${i}`;
                    const bad = hasErrorUnder(errors, itemPath);
                    return (
                        <div key={itemId}>
                            {sort.lineAt(i) && <div className="my-1 h-0.5 rounded bg-ink" />}
                            <div className={cn('mb-2 rounded-xl border bg-white', bad ? 'border-red-300' : 'border-line', sort.dragging === i && 'opacity-50')}>
                                <div {...sort.itemProps(i)} className="flex items-center gap-1 px-2 py-1.5">
                                    <GripVertical className="size-4 shrink-0 cursor-grab text-[#9ca3af]" aria-hidden />
                                    <button type="button" onClick={() => setOpen(expanded ? null : itemId)} aria-expanded={expanded} className="min-w-0 flex-1 truncate py-1 text-start text-[13px] font-medium text-ink">
                                        {title(item, i)}
                                        {bad && <span className="ms-2 text-red-600">• فيه خطأ</span>}
                                    </button>
                                    <button type="button" className="grid size-8 place-items-center rounded-lg text-[#6b7280] hover:bg-[#f2f2f0] disabled:opacity-30" disabled={i === 0} onClick={() => onChange(move(items, i, i - 1))} aria-label={`نقل ${name} ${i + 1} للأعلى`}>
                                        <ChevronUp className="size-4" />
                                    </button>
                                    <button type="button" className="grid size-8 place-items-center rounded-lg text-[#6b7280] hover:bg-[#f2f2f0] disabled:opacity-30" disabled={i === items.length - 1} onClick={() => onChange(move(items, i, i + 1))} aria-label={`نقل ${name} ${i + 1} للأسفل`}>
                                        <ChevronDown className="size-4" />
                                    </button>
                                    <button type="button" className="grid size-8 place-items-center rounded-lg text-red-700 hover:bg-red-50" onClick={() => onChange(items.filter((_, j) => j !== i))} aria-label={`حذف ${name} ${i + 1}`}>
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                                {expanded && (
                                    <div className="space-y-3 border-t border-line p-3">
                                        {(field.fields ?? []).map((sub) => (
                                            <FieldInput key={sub.key} field={sub} value={item[sub.key]} onChange={(v) => update(i, sub.key, v)} path={itemPath} />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
                {sort.lineAt(items.length) && <div className="my-1 h-0.5 rounded bg-ink" />}
            </div>
            <Button type="button" variant="outline" size="sm" icon={<Plus />} disabled={items.length >= max} onClick={add}>
                إضافة {name}
            </Button>
        </div>
    );
}
