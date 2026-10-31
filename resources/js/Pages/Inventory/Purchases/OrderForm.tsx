import { useMemo, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, Plus, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, Field, Input, PageHeader, Select, Textarea } from '@/Components/ui';
import { clientKey } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';

type Item = { product_id: string; quantity: string; unit_cost: string; unit: string; unit_factor: string };
interface Props {
    order: { id: number; number: string; supplier_id: number; branch_id: number | null; discount: string; shipping: string; tax_rate: string; notes: string | null; supplier_reference: string | null; payment_terms_days: number | null; order_date: string; expected_on: string | null; items: { product_id: number; quantity: string; unit_cost: string; unit: string | null; unit_factor: string }[] } | null;
    lines: { product_id: number; quantity: number; unit_cost: string; unit: string | null; unit_factor: number }[];
    suppliers: { id: number; name: string; payment_terms_days: number | null }[];
    products: { id: number; name: string; sku: string | null; cost: string; track_stock: boolean }[];
    branches: { id: number; name: string }[];
    defaultBranch: number | null;
    taxRate: number;
    units: string[];
    canApprove: boolean;
}

const m = (v: string | number) => Math.round(Number(v || 0) * 1000);

/** A purchase order: supplier, receiving branch, dates, lines in purchase units, discount, shipping and tax; saved as a draft or approved at once. */
export default function OrderForm({ order, lines, suppliers, products, branches, defaultBranch, taxRate, units, canApprove }: Props) {
    const currency = useCurrency();
    const key = useMemo(clientKey, []);
    const blank: Item = { product_id: '', quantity: '1', unit_cost: '', unit: '', unit_factor: '1' };
    const start = (order?.items ?? lines).map((i) => ({ product_id: String(i.product_id), quantity: String(Number(i.quantity)), unit_cost: String(i.unit_cost), unit: i.unit ?? '', unit_factor: String(Number(i.unit_factor ?? 1)) }));
    const form = useForm({
        // A new order opened from a supplier's file (?supplier=) starts with that supplier.
        supplier_id: order ? String(order.supplier_id) : (suppliers.find((x) => String(x.id) === new URLSearchParams(window.location.search).get('supplier'))?.id.toString() ?? ''),
        branch_id: String(order?.branch_id ?? defaultBranch ?? branches[0]?.id ?? ''),
        order_date: order?.order_date ?? new Date().toISOString().slice(0, 10),
        expected_on: order?.expected_on ?? '',
        supplier_reference: order?.supplier_reference ?? '',
        payment_terms_days: order?.payment_terms_days != null ? String(order.payment_terms_days) : '',
        discount: order ? String(Number(order.discount)) : '0',
        shipping: order ? String(Number(order.shipping)) : '0',
        tax_rate: order ? String(Number(order.tax_rate)) : String(taxRate),
        notes: order?.notes ?? '',
        items: start.length ? start : [blank],
        approve: false,
        client_key: key,
    });
    const set = (i: number, patch: Partial<Item>) => form.setData('items', form.data.items.map((it, j) => (j === i ? { ...it, ...patch } : it)));
    const pick = (i: number, id: string) => {
        const p = products.find((x) => String(x.id) === id);
        set(i, { product_id: id, unit_cost: form.data.items[i].unit_cost || (p ? String(Number(p.cost) * Number(form.data.items[i].unit_factor || 1)) : '') });
    };

    // Same rule as the server: subtotal − discount + shipping = taxable; + tax = total.
    const subtotal = form.data.items.reduce((t, it) => t + Math.round(m(it.unit_cost) * Number(it.quantity || 0)), 0);
    const taxable = subtotal - m(form.data.discount) + m(form.data.shipping);
    const tax = Math.round((taxable * Number(form.data.tax_rate || 0)) / 100);
    const total = (taxable + tax) / 1000;

    const submit = (approve: boolean) => (e?: FormEvent) => {
        e?.preventDefault();
        form.transform((d) => ({ ...d, approve, items: d.items.filter((it) => it.product_id), expected_on: d.expected_on || null, payment_terms_days: d.payment_terms_days || null }));
        if (order) form.put(route('club.purchases.update', order.id));
        else form.post(route('club.purchases.store'));
    };
    const err = form.errors as Record<string, string>;

    return (
        <ClubLayout title={order ? `تعديل ${order.number}` : 'أمر شراء جديد'}>
            <div className="mx-auto max-w-5xl">
                <PageHeader back={<Link href={route('club.purchases.index')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> أوامر الشراء</Link>} title={order ? <span className="num">تعديل {order.number}</span> : 'أمر شراء جديد'} subtitle="يُحفظ مسودة حتى يُعتمد ويُرسل للمورد؛ لا يغيّر المخزون ولا يسجل دفعًا" />
                <form onSubmit={submit(false)} className="space-y-6">
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-ink">المورد والتسليم</h3>
                        <div className="grid gap-4 md:grid-cols-3">
                            <Field label="المورد" error={form.errors.supplier_id} required>
                                <Select value={form.data.supplier_id} onChange={(e) => { const s = suppliers.find((x) => String(x.id) === e.target.value); form.setData((d) => ({ ...d, supplier_id: e.target.value, payment_terms_days: s?.payment_terms_days != null ? String(s.payment_terms_days) : d.payment_terms_days })); }} placeholder="اختر المورد">
                                    {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </Select>
                            </Field>
                            <Field label="الفرع المستلم" error={form.errors.branch_id} required>
                                <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)}>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</Select>
                            </Field>
                            <Field label="مرجع عرض المورد" error={form.errors.supplier_reference}><Input value={form.data.supplier_reference} onChange={(e) => form.setData('supplier_reference', e.target.value)} maxLength={60} /></Field>
                            <Field label="تاريخ الطلب" error={form.errors.order_date} required><Input type="date" value={form.data.order_date} onChange={(e) => form.setData('order_date', e.target.value)} /></Field>
                            <Field label="التسليم المتوقع" error={form.errors.expected_on}><Input type="date" value={form.data.expected_on} onChange={(e) => form.setData('expected_on', e.target.value)} /></Field>
                            <Field label="مدة السداد (يوم)" error={form.errors.payment_terms_days}><Input type="number" min="0" max="365" dir="ltr" value={form.data.payment_terms_days} onChange={(e) => form.setData('payment_terms_days', e.target.value)} /></Field>
                        </div>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-1 font-bold text-ink">البنود</h3>
                        <p className="mb-4 text-[13px] text-muted">بوحدة الشراء (كرتون، علبة…) وكم وحدة مخزون فيها؛ يدخل المخزون بوحدة المخزون عند الاستلام.</p>
                        <div className="space-y-3">
                            {form.data.items.map((it, i) => {
                                const stock = Number(it.quantity || 0) * Number(it.unit_factor || 1);
                                return (
                                    <div key={i} className="rounded-[12px] border border-line p-3">
                                        <div className="grid gap-3 md:grid-cols-[2fr_1fr_6rem_6rem_7rem_2.5rem] md:items-end">
                                            <Field label="المنتج" error={err[`items.${i}.product_id`]}>
                                                <Select value={it.product_id} onChange={(e) => pick(i, e.target.value)} placeholder="اختر المنتج">{products.map((p) => <option key={p.id} value={p.id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}</Select>
                                            </Field>
                                            <Field label="وحدة الشراء"><Select value={it.unit} onChange={(e) => set(i, { unit: e.target.value })}><option value="">وحدة المخزون</option>{units.map((u) => <option key={u} value={u}>{u}</option>)}</Select></Field>
                                            <Field label="فيها" error={err[`items.${i}.unit_factor`]}><Input type="number" min="1" step="any" dir="ltr" value={it.unit_factor} onChange={(e) => set(i, { unit_factor: e.target.value })} disabled={!it.unit} aria-label="وحدات المخزون في وحدة الشراء" /></Field>
                                            <Field label="الكمية" error={err[`items.${i}.quantity`]}><Input type="number" min="0" step="any" dir="ltr" value={it.quantity} onChange={(e) => set(i, { quantity: e.target.value })} /></Field>
                                            <Field label={`التكلفة (${currency})`} error={err[`items.${i}.unit_cost`]}><Input type="number" min="0" step="0.001" dir="ltr" value={it.unit_cost} onChange={(e) => set(i, { unit_cost: e.target.value })} /></Field>
                                            <Button type="button" variant="ghost" icon={<Trash2 />} aria-label="حذف البند" disabled={form.data.items.length === 1} onClick={() => form.setData('items', form.data.items.filter((_, j) => j !== i))} />
                                        </div>
                                        <p className="mt-2 text-[12px] text-[#9ca3af]">يدخل المخزون: <span className="text-ink num">{number(stock, stock % 1 ? 3 : 0)}</span> وحدة · الإجمالي <span className="text-ink num">{money(Math.round(m(it.unit_cost) * Number(it.quantity || 0)) / 1000, currency)}</span></p>
                                    </div>
                                );
                            })}
                        </div>
                        <Button type="button" variant="outline" size="sm" className="mt-3" icon={<Plus />} onClick={() => form.setData('items', [...form.data.items, blank])}>بند آخر</Button>
                        {err.items && <p className="mt-2 text-xs text-red-600">{err.items}</p>}
                    </Card>

                    <div className="grid gap-6 md:grid-cols-2">
                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-ink">الخصم والشحن والضريبة</h3>
                            <div className="grid grid-cols-3 gap-3">
                                <Field label="الخصم" error={form.errors.discount}><Input type="number" min="0" step="0.001" dir="ltr" value={form.data.discount} onChange={(e) => form.setData('discount', e.target.value)} /></Field>
                                <Field label="الشحن" error={form.errors.shipping}><Input type="number" min="0" step="0.001" dir="ltr" value={form.data.shipping} onChange={(e) => form.setData('shipping', e.target.value)} /></Field>
                                <Field label="الضريبة ٪" error={form.errors.tax_rate}><Input type="number" min="0" max="100" step="0.01" dir="ltr" value={form.data.tax_rate} onChange={(e) => form.setData('tax_rate', e.target.value)} /></Field>
                            </div>
                            <Field label="ملاحظات للمورد" className="mt-4" error={form.errors.notes}><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={1000} /></Field>
                        </Card>
                        <Card className="p-6">
                            <h3 className="mb-4 font-bold text-ink">الإجمالي</h3>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between"><dt className="text-muted">البنود</dt><dd className="num">{money(subtotal / 1000, currency)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted">الخصم</dt><dd className="num">- {money(m(form.data.discount) / 1000, currency)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted">الشحن</dt><dd className="num">{money(m(form.data.shipping) / 1000, currency)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted">الضريبة</dt><dd className="num">{money(tax / 1000, currency)}</dd></div>
                                <div className="flex justify-between border-t border-line pt-2 text-lg font-bold"><dt>الإجمالي</dt><dd className="num">{money(total, currency)}</dd></div>
                            </dl>
                        </Card>
                    </div>

                    <Card className="flex flex-col-reverse gap-3 p-4 sm:flex-row sm:justify-end">
                        <Button variant="outline" href={route('club.purchases.index')} className="sm:w-32">إلغاء</Button>
                        <Button type="submit" variant={canApprove ? 'outline' : 'primary'} loading={form.processing} className="sm:w-40">حفظ كمسودة</Button>
                        {canApprove && <Button type="button" loading={form.processing} onClick={() => submit(true)()} className="sm:w-48">حفظ واعتماد وإرسال</Button>}
                    </Card>
                </form>
            </div>
        </ClubLayout>
    );
}
