import { useEffect, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, EmptyState, Field, Input, Modal } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';

/** A product's stock count (set the shelf quantity, the difference is recorded) and its stock card. */

type Target = { id: number; name: string; stock_quantity: string };

interface Movement {
    id: number;
    type: string;
    quantity: string;
    unit_cost: string;
    balance_after: string;
    note: string | null;
    user: string | null;
    at: string;
}

export const MOVEMENT_TYPES: Record<string, string> = { purchase: 'شراء', sale: 'بيع', adjustment: 'تعديل جرد', return: 'مرتجع', void: 'إلغاء بيع', order: 'محجوز لطلب من الموقع', release: 'إلغاء طلب من الموقع' };

export function AdjustModal({ product, onClose }: { product: Target; onClose: () => void }) {
    const form = useForm({ counted: String(Number(product.stock_quantity)), reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.products.adjust', product.id), { preserveScroll: true, onSuccess: onClose });
    };
    const diff = Number(form.data.counted || 0) - Number(product.stock_quantity);

    return (
        <Modal open onClose={onClose} size="sm" title={`جرد: ${product.name}`} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="adjust-form" loading={form.processing}>حفظ الجرد</Button></>}>
            <form id="adjust-form" onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted">الرصيد في النظام: <span className="font-medium text-ink num">{number(product.stock_quantity, 3)}</span></p>
                <Field label="الكمية الفعلية على الرف" error={form.errors.counted} required>
                    <Input type="number" step="any" min="0" dir="ltr" value={form.data.counted} onChange={(e) => form.setData('counted', e.target.value)} required />
                </Field>
                {diff !== 0 && <p className={cn('text-sm num', diff > 0 ? 'text-emerald-600' : 'text-red-600')}>الفرق: {diff > 0 ? '+' : ''}{number(diff, 3)}</p>}
                <Field label="السبب" error={form.errors.reason} required>
                    <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="جرد شهري، تالف، هدية…" required />
                </Field>
            </form>
        </Modal>
    );
}

export function MovementsModal({ product, onClose, currency }: { product: Target | null; onClose: () => void; currency: string }) {
    const [rows, setRows] = useState<Movement[] | null>(null);

    useEffect(() => {
        if (!product) return;
        setRows(null);
        const controller = new AbortController();
        fetch(route('club.products.movements', product.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((r) => (r.ok ? r.json() : []))
            .then((data: Movement[]) => setRows(data))
            .catch(() => undefined);
        return () => controller.abort();
    }, [product]);

    return (
        <Modal open={product !== null} onClose={onClose} size="lg" title={`حركة المخزون: ${product?.name ?? ''}`}>
            {rows === null ? (
                <p className="py-6 text-center text-sm text-muted">جارٍ التحميل…</p>
            ) : rows.length === 0 ? (
                <EmptyState title="لا توجد حركات بعد" />
            ) : (
                <div className="-mx-5 overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>التاريخ</th><th>النوع</th><th>الكمية</th><th>التكلفة</th><th>الرصيد</th><th>ملاحظة</th></tr></thead>
                        <tbody>
                            {rows.map((m) => (
                                <tr key={m.id}>
                                    <td className="whitespace-nowrap text-xs text-muted">{m.at}<br />{m.user}</td>
                                    <td>{MOVEMENT_TYPES[m.type] ?? m.type}</td>
                                    <td className={cn('font-medium num', Number(m.quantity) >= 0 ? 'text-emerald-600' : 'text-red-600')}>{Number(m.quantity) > 0 ? '+' : ''}{number(m.quantity, 3)}</td>
                                    <td className="text-xs num">{money(m.unit_cost, currency)}</td>
                                    <td className="num">{number(m.balance_after, 3)}</td>
                                    <td className="text-xs text-muted">{m.note ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Modal>
    );
}
