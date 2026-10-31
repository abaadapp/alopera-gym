import { type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Field, Input, Modal, Select } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import type { BranchFilterState } from './parts';

const OUT_ONLY = ['damaged', 'lost', 'expired', 'internal'];

/**
 * «تعديل مخزون»: damaged, lost, expired or used in house (always taken away), or a correction either way, on one
 * branch. Recorded as a movement with its own number; never edited afterwards — an opposite adjustment undoes it.
 */
export default function AdjustDialog({ products, branch, reasons, onClose }: { products: { id: number; name: string; cost: string }[]; branch: BranchFilterState; reasons: Record<string, string>; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm({ product_id: '', branch_id: branch.value !== 'all' ? branch.value : String(branch.options[0]?.id ?? ''), direction: 'out', quantity: '', reason: 'damaged', note: '' });
    const outOnly = OUT_ONLY.includes(form.data.reason);
    const product = products.find((p) => String(p.id) === form.data.product_id);
    const impact = product ? Number(product.cost) * Number(form.data.quantity || 0) * (outOnly || form.data.direction === 'out' ? -1 : 1) : 0;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, direction: outOnly ? 'out' : d.direction }));
        form.post(route('club.inventory.adjust'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} title="تعديل مخزون" footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="adjust-stock" loading={form.processing}>تسجيل</Button></>}>
            <form id="adjust-stock" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="الفرع" error={form.errors.branch_id} required>
                        <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} disabled={branch.locked && branch.value !== 'all'}>
                            {branch.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="السبب" error={form.errors.reason} required>
                        <Select value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}>
                            {['damaged', 'lost', 'expired', 'internal', 'correction'].map((r) => <option key={r} value={r}>{reasons[r]}</option>)}
                        </Select>
                    </Field>
                </div>
                <Field label="المنتج" error={form.errors.product_id} required>
                    <Select value={form.data.product_id} onChange={(e) => form.setData('product_id', e.target.value)} placeholder="اختر المنتج">
                        {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </Select>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="الاتجاه">
                        <div className="flex gap-2">
                            {([['out', 'نقص'], ['in', 'زيادة']] as const).map(([key, label]) => (
                                <button key={key} type="button" disabled={outOnly && key === 'in'} onClick={() => form.setData('direction', key)} aria-pressed={(outOnly ? 'out' : form.data.direction) === key}
                                    className={cn('flex-1 rounded-[10px] border px-3 py-2 text-[13px] font-medium transition disabled:opacity-40', (outOnly ? 'out' : form.data.direction) === key ? 'border-[#111] bg-[#111] text-white' : 'border-line bg-white hover:bg-[#fafafa]')}>{label}</button>
                            ))}
                        </div>
                    </Field>
                    <Field label="الكمية" error={form.errors.quantity} required>
                        <Input type="number" min="0" step="any" dir="ltr" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                    </Field>
                </div>
                <Field label="ملاحظة" error={form.errors.note}>
                    <Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={200} placeholder="مثل: علبة مكسورة عند الاستلام" />
                </Field>
                {product && Number(form.data.quantity) > 0 && (
                    <p className={cn('rounded-[12px] px-4 py-3 text-[13px]', impact < 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700')}>
                        الأثر على قيمة المخزون: <span className="font-semibold num">{money(impact, currency)}</span>
                    </p>
                )}
            </form>
        </Modal>
    );
}
