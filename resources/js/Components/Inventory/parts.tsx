import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Building2, Check, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Field, Input, Modal, Select, Textarea } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';

/*
 * Pieces shared by the inventory and purchases sections: the branch a list shows, product lines (a product and a
 * quantity, with each branch's balance), a document's steps, a status pill, and a dialog that asks for a reason.
 */

export type BranchFilterState = { value: string; options: { id: number; name: string }[]; locked: boolean };
export type StockProduct = { id: number; name: string; sku?: string | null; balances?: Record<number, number> };
export type Line = { product_id: string; quantity: string };

/** Which branch the list shows (club-wide users with several branches); a tied user sees only their own. */
export function BranchPicker({ state, onChange }: { state: BranchFilterState; onChange: (value: string) => void }) {
    if (state.locked) {
        const name = state.options.find((o) => String(o.id) === state.value)?.name;
        return name ? <span className="inline-flex h-9 items-center gap-1.5 rounded-full bg-[#f2f2f0] px-3 text-[13px] text-[#4b4b4b]"><Building2 className="size-3.5" />{name}</span> : null;
    }
    return (
        <Select className="h-9 w-44 py-1.5 text-[13px]" value={state.value} onChange={(e) => onChange(e.target.value)} aria-label="الفرع">
            <option value="all">كل الفروع</option>
            {state.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </Select>
    );
}

export type Tone = 'gray' | 'amber' | 'blue' | 'violet' | 'green' | 'red';

export function Pill({ tone, children }: { tone: Tone; children: ReactNode }) {
    return <Badge tone={tone}>{children}</Badge>;
}

export const TRANSFER_TONES: Record<string, Tone> = { pending: 'amber', approved: 'blue', in_transit: 'violet', discrepancy: 'red', received: 'green', rejected: 'gray', cancelled: 'gray' };
export const ORDER_TONES: Record<string, Tone> = { draft: 'gray', ordered: 'blue', partial: 'amber', received: 'green', cancelled: 'red', closed: 'gray' };
export const RECEIPT_LABELS: Record<string, [string, Tone]> = { pending: ['بانتظار الاعتماد', 'amber'], approved: ['معتمد', 'green'], rejected: ['مرفوض', 'red'] };
export const COUNT_LABELS: Record<string, [string, Tone]> = { draft: ['مسودة — قيد العد', 'amber'], approved: ['معتمد', 'green'], cancelled: ['ملغى', 'gray'] };
export const SUPPLY_LABELS: Record<string, [string, Tone]> = { pending: ['بانتظار المراجعة', 'amber'], in_progress: ['قيد التزويد', 'blue'], fulfilled: ['اكتمل التزويد', 'green'], rejected: ['مرفوض', 'gray'], cancelled: ['ملغى', 'gray'] };
export const BILL_STATES: Record<string, [string, Tone]> = { pending: ['بانتظار الاعتماد', 'amber'], unpaid: ['غير مدفوع', 'amber'], partial: ['مدفوع جزئيًا', 'blue'], paid: ['مدفوع', 'green'], overdue: ['متأخر', 'red'], rejected: ['مرفوض', 'gray'], void: ['ملغى', 'gray'] };

/** Product lines: a product, a quantity, and — when a branch is given — what that branch holds. */
export function LinesEditor({ lines, onChange, products, branchId, error, hint }: { lines: Line[]; onChange: (lines: Line[]) => void; products: StockProduct[]; branchId?: number | null; error?: string; hint?: string }) {
    const set = (i: number, patch: Partial<Line>) => onChange(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const used = new Set(lines.map((l) => l.product_id));

    return (
        <div>
            <div className="space-y-2">
                {lines.map((line, i) => {
                    const product = products.find((p) => String(p.id) === line.product_id);
                    const available = branchId && product?.balances ? product.balances[branchId] ?? 0 : null;
                    return (
                        <div key={i} className="grid grid-cols-[1fr_7rem_2.5rem] items-start gap-2">
                            <div>
                                <Select value={line.product_id} onChange={(e) => set(i, { product_id: e.target.value })} placeholder="اختر المنتج" aria-label={`المنتج ${i + 1}`}>
                                    {products.map((p) => <option key={p.id} value={p.id} disabled={used.has(String(p.id)) && String(p.id) !== line.product_id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}
                                </Select>
                                {available !== null && <p className={cn('mt-1 text-[11px]', Number(line.quantity) > available ? 'text-red-600' : 'text-[#9ca3af]')}>المتوفر في الفرع: <span className="num">{number(available, available % 1 ? 3 : 0)}</span></p>}
                            </div>
                            <Input type="number" min="0" step="any" dir="ltr" value={line.quantity} onChange={(e) => set(i, { quantity: e.target.value })} placeholder="الكمية" aria-label={`الكمية ${i + 1}`} />
                            <Button type="button" variant="ghost" icon={<Trash2 />} aria-label="حذف السطر" disabled={lines.length === 1} onClick={() => onChange(lines.filter((_, j) => j !== i))} />
                        </div>
                    );
                })}
            </div>
            <Button type="button" variant="outline" size="sm" className="mt-2" icon={<Plus />} onClick={() => onChange([...lines, { product_id: '', quantity: '' }])}>سطر آخر</Button>
            {hint && <p className="mt-2 text-[12px] text-muted">{hint}</p>}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** A document's steps: done ones dark with who and when, the rest grey. */
export function Steps({ steps }: { steps: { label: string; by?: string | null; at?: string | null }[] }) {
    return (
        <ol className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {steps.map((s, i) => (
                <li key={i} className="flex items-start gap-2.5">
                    <span className={cn('grid size-7 shrink-0 place-items-center rounded-full text-[12px] font-semibold', s.at ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#9ca3af]')}>{s.at ? <Check className="size-3.5" /> : <span className="num">{i + 1}</span>}</span>
                    <span className="min-w-0">
                        <span className={cn('block text-[13px] font-medium', s.at ? 'text-ink' : 'text-[#9ca3af]')}>{s.label}</span>
                        {s.at && <span className="block text-[11px] text-[#9ca3af]">{s.by ? `${s.by} · ` : ''}<span className="num">{s.at}</span></span>}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** A confirmation that needs a written reason (reject, cancel, void…), posted to `url`. */
export function ReasonDialog({ open, title, message, url, confirm, tone = 'danger', onClose, extra }: { open: boolean; title: string; message?: ReactNode; url: string; confirm: string; tone?: 'danger' | 'primary'; onClose: () => void; extra?: Record<string, unknown> }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    if (!open) return null;
    const submit = () => router.post(url, { reason, ...extra }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => { setReason(''); onClose(); }, onError: (e) => setError(e.reason ?? Object.values(e)[0]) });

    return (
        <Modal open onClose={onClose} size="sm" title={title} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={busy} onClick={submit}>{confirm}</Button></>}>
            {message && <p className="mb-3 text-sm leading-7 text-[#4b4b4b]">{message}</p>}
            <Field label="السبب" error={error} required>
                <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} autoFocus />
            </Field>
        </Modal>
    );
}

/** A unique key per form submission, so a double click or a retried request makes one document. */
export function clientKey(): string {
    try {
        return crypto.randomUUID();
    } catch {
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
    }
}
