import { useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2, Truck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, Field, Input, Modal, PageHeader, Pagination, Select, Tabs, Textarea } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface OrderRow {
    id: number;
    number: string;
    status: string;
    total: string;
    items_count: number;
    supplier: string | null;
    order_date: string;
    received_at: string | null;
}

interface ProductOption {
    id: number;
    name: string;
    cost: string;
    stock_quantity: string;
}

const purchaseStatus: Record<string, [string, 'gray' | 'blue' | 'green' | 'red']> = {
    draft: ['مسودة', 'gray'],
    ordered: ['بانتظار الاستلام', 'blue'],
    received: ['مستلم', 'green'],
    cancelled: ['ملغى', 'red'],
};

interface Props {
    orders: Paginated<OrderRow>;
    suppliers: Option[];
    products: ProductOption[];
    filters: { status?: string };
}

export default function PurchasesIndex({ orders, suppliers, products, filters }: Props) {
    const currency = useCurrency();
    const [creating, setCreating] = useState(false);

    return (
        <ClubLayout title="المشتريات">
            <PageHeader
                title="المشتريات"
                subtitle="أوامر الشراء من الموردين واستلام البضاعة"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)} disabled={suppliers.length === 0}>أمر شراء</Button>}
            />
            {suppliers.length === 0 && (
                <p className="mb-4 rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    أضيفي موردًا أولًا من <Link href={route('club.suppliers.index')} className="underline">صفحة الموردين</Link>.
                </p>
            )}

            <Card padded={false}>
                <div className="p-4">
                    <Tabs
                        tabs={[{ key: '', label: 'الكل' }, { key: 'ordered', label: 'بانتظار الاستلام' }, { key: 'received', label: 'مستلمة' }, { key: 'cancelled', label: 'ملغاة' }]}
                        value={filters.status ?? ''}
                        onChange={(key) => router.get(route('club.purchases.index'), key ? { status: key } : {}, { preserveState: true, replace: true })}
                        className="border-0"
                    />
                </div>
                {orders.data.length === 0 ? (
                    <EmptyState icon={<Truck className="size-6" />} title="لا توجد أوامر شراء" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الرقم</th><th>المورد</th><th>التاريخ</th><th>البنود</th><th>الإجمالي</th><th>الحالة</th></tr>
                            </thead>
                            <tbody>
                                {orders.data.map((o) => {
                                    const [text, tone] = purchaseStatus[o.status] ?? [o.status, 'gray'];
                                    return (
                                        <tr key={o.id} className="cursor-pointer" onClick={() => router.visit(route('club.purchases.show', o.id))}>
                                            <td><Link href={route('club.purchases.show', o.id)} className="font-medium text-brand num">{o.number}</Link></td>
                                            <td>{o.supplier ?? '—'}</td>
                                            <td>{date(o.order_date)}{o.received_at && <p className="text-xs text-muted">استُلم {date(o.received_at)}</p>}</td>
                                            <td className="num">{o.items_count}</td>
                                            <td className="font-medium num">{money(o.total, currency)}</td>
                                            <td><Badge tone={tone}>{text}</Badge></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={orders} />
            </Card>

            {creating && <CreateOrderModal suppliers={suppliers} products={products} onClose={() => setCreating(false)} currency={currency} />}
        </ClubLayout>
    );
}

interface Line {
    product_id: string;
    quantity: string;
    unit_cost: string;
}

function CreateOrderModal({ suppliers, products, onClose, currency }: { suppliers: Option[]; products: ProductOption[]; onClose: () => void; currency: string }) {
    const form = useForm<{ supplier_id: string; order_date: string; expected_on: string; notes: string; items: Line[] }>({
        supplier_id: suppliers[0] ? String(suppliers[0].id) : '',
        order_date: new Date().toISOString().slice(0, 10),
        expected_on: '',
        notes: '',
        items: [{ product_id: '', quantity: '1', unit_cost: '' }],
    });

    const setLine = (i: number, patch: Partial<Line>) => form.setData('items', form.data.items.map((l, x) => (x === i ? { ...l, ...patch } : l)));
    const total = form.data.items.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0);
    const err = (i: number, f: string) => (form.errors as Record<string, string>)[`items.${i}.${f}`];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, expected_on: d.expected_on || null, notes: d.notes || null }));
        form.post(route('club.purchases.store'));
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title="أمر شراء جديد"
            footer={
                <>
                    <span className="me-auto self-center text-sm text-muted">الإجمالي: <span className="font-semibold text-ink num">{money(total, currency)}</span></span>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="po-form" loading={form.processing}>إنشاء الأمر</Button>
                </>
            }
        >
            <form id="po-form" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="المورد" error={form.errors.supplier_id} required>
                        <Select value={form.data.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)} required>
                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="تاريخ الطلب" error={form.errors.order_date}>
                        <Input type="date" value={form.data.order_date} onChange={(e) => form.setData('order_date', e.target.value)} />
                    </Field>
                    <Field label="الاستلام المتوقع" error={form.errors.expected_on}>
                        <Input type="date" value={form.data.expected_on} onChange={(e) => form.setData('expected_on', e.target.value)} />
                    </Field>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <p className="label mb-0">البنود</p>
                        <Button type="button" size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => form.setData('items', [...form.data.items, { product_id: '', quantity: '1', unit_cost: '' }])}>بند</Button>
                    </div>
                    {form.errors.items && <p className="mb-2 text-xs text-red-600">{form.errors.items}</p>}
                    <div className="space-y-2">
                        {form.data.items.map((line, i) => {
                            const product = products.find((p) => String(p.id) === line.product_id);
                            return (
                                <div key={i} className="grid gap-2 rounded-xl border border-line p-3 sm:grid-cols-12">
                                    <Field className="sm:col-span-6" error={err(i, 'product_id')}>
                                        <Select
                                            value={line.product_id}
                                            onChange={(e) => {
                                                const p = products.find((x) => String(x.id) === e.target.value);
                                                setLine(i, { product_id: e.target.value, unit_cost: line.unit_cost || (p ? String(Number(p.cost)) : '') });
                                            }}
                                            placeholder="اختاري المنتج"
                                            required
                                        >
                                            {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                        </Select>
                                        {product && <p className="mt-1 text-xs text-muted">المخزون الحالي: <span className="num">{number(product.stock_quantity, 3)}</span></p>}
                                    </Field>
                                    <Field className="sm:col-span-2" error={err(i, 'quantity')}>
                                        <Input type="number" step="any" min="0.001" dir="ltr" placeholder="الكمية" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} required />
                                    </Field>
                                    <Field className="sm:col-span-3" error={err(i, 'unit_cost')}>
                                        <Input type="number" step="0.001" min="0" dir="ltr" placeholder="تكلفة الوحدة" value={line.unit_cost} onChange={(e) => setLine(i, { unit_cost: e.target.value })} required />
                                    </Field>
                                    <div className="flex items-start justify-end sm:col-span-1">
                                        <button type="button" disabled={form.data.items.length === 1} onClick={() => form.setData('items', form.data.items.filter((_, x) => x !== i))} className="grid size-10 place-items-center rounded-xl text-muted hover:bg-red-50 hover:text-red-600 disabled:opacity-30" aria-label="حذف">
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <Field label="ملاحظات" error={form.errors.notes}>
                    <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}
