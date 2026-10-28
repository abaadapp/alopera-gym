import { useEffect, useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { History, Package, Pencil, Plus, Scale, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Pagination, SearchInput, Select, Tabs, Textarea, Toggle } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface ProductRow {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    description: string | null;
    price: string;
    cost: string;
    stock_quantity: string;
    reorder_level: string;
    track_stock: boolean;
    is_active: boolean;
    product_category_id: number | null;
    category: string | null;
    low: boolean;
}

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

const movementType: Record<string, string> = { purchase: 'شراء', sale: 'بيع', adjustment: 'تعديل جرد', return: 'مرتجع', void: 'إلغاء بيع' };

export default function ProductsIndex({ products, categories, filters }: { products: Paginated<ProductRow>; categories: Option[]; filters: { q?: string; low?: string | boolean } }) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<ProductRow | 'new' | null>(null);
    const [adjusting, setAdjusting] = useState<ProductRow | null>(null);
    const [history, setHistory] = useState<ProductRow | null>(null);
    const low = filters.low === true || filters.low === '1' || filters.low === 'true';

    return (
        <ClubLayout title="المنتجات والمخزون">
            <PageHeader title="المنتجات والمخزون" subtitle={`${products.total} منتج`} actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>منتج جديد</Button>} />

            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <Tabs
                        tabs={[{ key: '', label: 'كل المنتجات' }, { key: '1', label: 'وصلت لحد إعادة الطلب' }]}
                        value={low ? '1' : ''}
                        onChange={(key) => router.get(route('club.products.index'), { q: filters.q || undefined, low: key || undefined }, { preserveState: true, replace: true })}
                        className="border-0"
                    />
                    <SearchInput value={filters.q} placeholder="الاسم، SKU، الباركود…" extra={{ low: low ? 1 : undefined }} />
                </div>

                {products.data.length === 0 ? (
                    <EmptyState icon={<Package className="size-6" />} title="لا توجد منتجات" action={<Button size="sm" onClick={() => setEditing('new')}>إضافة منتج</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>المنتج</th><th>التصنيف</th><th>السعر</th><th>التكلفة</th><th>المخزون</th><th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {products.data.map((p) => (
                                    <tr key={p.id}>
                                        <td>
                                            <p className="font-medium">{p.name}</p>
                                            <p className="text-xs text-muted num">{[p.sku, p.barcode].filter(Boolean).join(' · ') || '—'}</p>
                                        </td>
                                        <td>{p.category ?? '—'}</td>
                                        <td className="num">{money(p.price, currency)}</td>
                                        <td className="text-muted num">{money(p.cost, currency)}</td>
                                        <td>
                                            {p.track_stock ? (
                                                <span className={cn('font-medium num', p.low && 'text-red-600')}>
                                                    {number(p.stock_quantity, 3)}
                                                    {p.low && <Badge tone="red" className="ms-2">منخفض</Badge>}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-muted">غير متتبع</span>
                                            )}
                                        </td>
                                        <td>{p.is_active ? <Badge tone="green">متاح</Badge> : <Badge>موقوف</Badge>}</td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                {p.track_stock && <Button variant="ghost" size="sm" icon={<Scale className="size-3.5" />} onClick={() => setAdjusting(p)}>جرد</Button>}
                                                <Button variant="ghost" size="sm" icon={<History className="size-3.5" />} onClick={() => setHistory(p)} aria-label="حركة المخزون" />
                                                <Button variant="ghost" size="sm" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(p)} aria-label="تعديل" />
                                                <ConfirmAction url={route('club.products.destroy', p.id)} method="delete" title="حذف المنتج" message={`حذف «${p.name}»؟ تبقى حركاته السابقة في التقارير.`} confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-3.5 text-red-600" />}>
                                                    <span className="sr-only">حذف</span>
                                                </ConfirmAction>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={products} />
            </Card>

            {editing && <ProductModal product={editing === 'new' ? null : editing} categories={categories} onClose={() => setEditing(null)} />}
            {adjusting && <AdjustModal product={adjusting} onClose={() => setAdjusting(null)} />}
            <MovementsDrawer product={history} onClose={() => setHistory(null)} currency={currency} />
        </ClubLayout>
    );
}

function ProductModal({ product, categories, onClose }: { product: ProductRow | null; categories: Option[]; onClose: () => void }) {
    const [newCategory, setNewCategory] = useState(false);
    const form = useForm<{
        name: string;
        sku: string;
        barcode: string;
        description: string;
        price: string;
        reorder_level: string;
        track_stock: boolean;
        is_active: boolean;
        product_category_id: string;
        category_name: string;
        opening_stock: string;
        opening_cost: string;
        image: File | null;
    }>({
        name: product?.name ?? '',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        description: product?.description ?? '',
        price: product?.price ?? '',
        reorder_level: product ? String(Number(product.reorder_level)) : '0',
        track_stock: product?.track_stock ?? true,
        is_active: product?.is_active ?? true,
        product_category_id: product?.product_category_id ? String(product.product_category_id) : '',
        category_name: '',
        opening_stock: '',
        opening_cost: '',
        image: null,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: onClose };
        if (product) {
            form.transform((d) => ({ ...d, opening_stock: '', opening_cost: '', _method: 'put' }));
            form.post(route('club.products.update', product.id), options);
        } else {
            form.post(route('club.products.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={product ? `تعديل ${product.name}` : 'منتج جديد'}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="product-form" loading={form.processing}>حفظ</Button>
                </>
            }
        >
            <form id="product-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="اسم المنتج" error={form.errors.name} required className="sm:col-span-2">
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                </Field>
                <Field label="سعر البيع" error={form.errors.price} required>
                    <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} required />
                </Field>
                <Field label="التصنيف" error={form.errors.product_category_id || form.errors.category_name}>
                    {newCategory ? (
                        <div className="flex gap-2">
                            <Input value={form.data.category_name} onChange={(e) => form.setData('category_name', e.target.value)} placeholder="اسم التصنيف الجديد" />
                            <Button type="button" variant="ghost" size="sm" onClick={() => { setNewCategory(false); form.setData('category_name', ''); }}>إلغاء</Button>
                        </div>
                    ) : (
                        <div className="flex gap-2">
                            <Select value={form.data.product_category_id} onChange={(e) => form.setData('product_category_id', e.target.value)} placeholder="بدون تصنيف">
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Button type="button" variant="outline" onClick={() => setNewCategory(true)} icon={<Plus className="size-4" />} aria-label="تصنيف جديد" />
                        </div>
                    )}
                </Field>
                <Field label="SKU" error={form.errors.sku}>
                    <Input dir="ltr" value={form.data.sku} onChange={(e) => form.setData('sku', e.target.value)} />
                </Field>
                <Field label="الباركود" error={form.errors.barcode}>
                    <Input dir="ltr" value={form.data.barcode} onChange={(e) => form.setData('barcode', e.target.value)} />
                </Field>
                <Field label="حد إعادة الطلب" error={form.errors.reorder_level} hint="تنبيه عندما يصل المخزون لهذا الحد">
                    <Input type="number" step="1" min="0" dir="ltr" value={form.data.reorder_level} onChange={(e) => form.setData('reorder_level', e.target.value)} />
                </Field>
                <Field label="صورة" error={form.errors.image}>
                    <Input type="file" accept="image/*" onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)} />
                </Field>
                <Field label="الوصف" error={form.errors.description} className="sm:col-span-2">
                    <Textarea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </Field>
                {!product && form.data.track_stock && (
                    <>
                        <Field label="الرصيد الافتتاحي" error={form.errors.opening_stock}>
                            <Input type="number" step="1" min="0" dir="ltr" value={form.data.opening_stock} onChange={(e) => form.setData('opening_stock', e.target.value)} />
                        </Field>
                        <Field label="تكلفة الوحدة الافتتاحية" error={form.errors.opening_cost}>
                            <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.opening_cost} onChange={(e) => form.setData('opening_cost', e.target.value)} />
                        </Field>
                    </>
                )}
                <div className="space-y-2 sm:col-span-2">
                    <Toggle checked={form.data.track_stock} onChange={(v) => form.setData('track_stock', v)} label="تتبّع المخزون" hint="أوقفيه للخدمات أو المنتجات غير المخزنة" />
                    <Checkbox label="متاح للبيع" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                </div>
            </form>
        </Modal>
    );
}

function AdjustModal({ product, onClose }: { product: ProductRow; onClose: () => void }) {
    const form = useForm({ counted: String(Number(product.stock_quantity)), reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.products.adjust', product.id), { preserveScroll: true, onSuccess: onClose });
    };

    const diff = Number(form.data.counted || 0) - Number(product.stock_quantity);

    return (
        <Modal
            open
            onClose={onClose}
            size="sm"
            title={`جرد: ${product.name}`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="adjust-form" loading={form.processing}>حفظ الجرد</Button>
                </>
            }
        >
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

function MovementsDrawer({ product, onClose, currency }: { product: ProductRow | null; onClose: () => void; currency: string }) {
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
                        <thead>
                            <tr><th>التاريخ</th><th>النوع</th><th>الكمية</th><th>التكلفة</th><th>الرصيد</th><th>ملاحظة</th></tr>
                        </thead>
                        <tbody>
                            {rows.map((m) => (
                                <tr key={m.id}>
                                    <td className="whitespace-nowrap text-xs text-muted">{m.at}<br />{m.user}</td>
                                    <td>{movementType[m.type] ?? m.type}</td>
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
