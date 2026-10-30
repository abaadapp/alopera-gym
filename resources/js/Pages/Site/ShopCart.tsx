import { useEffect, useMemo, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CreditCard, Lock, Minus, Plus, ShoppingBag, Store, Trash2 } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button, Field, Input, Select, Textarea } from '@/Components/ui';
import { describe, lineTotal, payload, unitPrice } from '@/lib/addons';
import { useCart } from '@/lib/cart';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { PageHero } from './Plans';
import { ProductImage, type ShopProduct } from './Shop';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    products: ShopProduct[];
    methods: ('paymob' | 'pickup')[];
    branches: { id: number; name: string }[];
    pickupNote: string | null;
    windowMinutes: number;
    prefill: { name: string; phone: string | null; email: string | null } | null;
    currency: string;
}

const METHOD = {
    paymob: { label: 'الدفع الإلكتروني الآن', hint: 'بطاقة بنكية عبر صفحة دفع آمنة. يُؤكَّد الطلب عند وصول الدفع.', icon: CreditCard },
    pickup: { label: 'الدفع عند الاستلام', hint: 'ادفع نقدًا أو بالبطاقة عند استلام الطلب من النادي.', icon: Store },
} as const;

export default function ShopCart({ site, seo, products, methods, branches, pickupNote, windowMinutes, prefill, currency }: Props) {
    const cart = useCart();
    const byId = useMemo(() => new Map(products.map((p) => [p.id, p])), [products]);
    // Add-ons no longer offered drop out of a line (describe); the server prices what is sent again.
    const lines = cart.lines.flatMap((l) => {
        const product = byId.get(l.id);
        if (!product || !product.in_stock) return [];
        const addons = describe(l.addons, product.addons);
        return [{ key: l.key, product, qty: Math.min(l.qty, product.max), addons, chosen: addons.map((a) => ({ id: a.id, qty: a.qty })) }];
    });
    const total = lines.reduce((t, l) => t + Math.round(lineTotal(l.product.price, l.qty, l.chosen, l.product.addons) * 1000), 0) / 1000;

    // Products no longer offered (or sold out) leave the cart quietly.
    useEffect(() => {
        cart.lines.forEach((l) => {
            const product = byId.get(l.id);
            if (!product || !product.in_stock) cart.setQty(l.key, 0);
            else if (cart.quantityOf(l.id) > product.max) cart.setQty(l.key, l.qty, product.max);
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [byId]);

    const form = useForm({
        name: prefill?.name ?? '',
        phone: prefill?.phone ?? '',
        email: prefill?.email ?? '',
        notes: '',
        payment_method: methods[0] ?? '',
        branch_id: branches.length > 1 ? '' : String(branches[0]?.id ?? ''),
        website: '',
        items: [] as { product_id: number; quantity: number; addons: { addon_id: number; quantity: number }[] }[],
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, items: lines.map((l) => ({ product_id: l.product.id, quantity: l.qty, addons: payload(l.chosen) })), branch_id: data.branch_id || null }));
        try {
            sessionStorage.setItem('shop:placing', '1');
        } catch {
            // ignore
        }
        form.post(route('site.shop.order.store'), { preserveScroll: true, onSuccess: () => cart.clear() });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="سلة المشتريات" icon={<ShoppingBag className="size-6" />} />

            <section className="mx-auto max-w-5xl px-4 py-10">
                {lines.length === 0 ? (
                    <div className="py-12 text-center">
                        <p className="text-muted">السلة فارغة.</p>
                        <Link href={route('site.shop')} className="mt-4 inline-flex rounded-full bg-brand px-6 py-2.5 text-sm font-medium text-white">تصفح المتجر</Link>
                    </div>
                ) : (
                    <div className="grid gap-8 lg:grid-cols-[1fr_400px]">
                        <ul className="divide-y divide-line rounded-3xl border border-line bg-white px-5">
                            {lines.map(({ key, product, qty, addons, chosen }) => (
                                <li key={key} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-4 sm:flex-nowrap">
                                    <div className="size-16 shrink-0 overflow-hidden rounded-2xl border border-line"><ProductImage product={product} /></div>
                                    <div className="min-w-0 flex-1">
                                        <Link href={route('site.shop.product', product.id)} className="block truncate font-medium hover:text-brand">{product.name}</Link>
                                        {addons.map((a) => <p key={a.id} className="truncate text-xs text-muted">+ {a.name}{a.qty > 1 && <span className="num"> ×{a.qty}</span>} <span className="num">({money(Number(a.price) * a.qty, currency)})</span></p>)}
                                        <p className="text-sm text-muted num">{money(unitPrice(product.price, chosen, product.addons), currency)}{addons.length > 0 && ' للقطعة مع الإضافات'}</p>
                                    </div>
                                    <div className="flex items-center rounded-full border border-line">
                                        <button type="button" onClick={() => cart.setQty(key, qty - 1, product.max)} className="grid size-9 place-items-center" aria-label={`إنقاص ${product.name}`}>{qty === 1 ? <Trash2 className="size-4 text-red-600" /> : <Minus className="size-4" />}</button>
                                        <span className="w-8 text-center text-sm font-medium num">{qty}</span>
                                        <button type="button" disabled={cart.quantityOf(product.id) >= product.max} onClick={() => cart.setQty(key, qty + 1, product.max)} className="grid size-9 place-items-center disabled:opacity-40" aria-label={`زيادة ${product.name}`}><Plus className="size-4" /></button>
                                    </div>
                                    <p className="w-24 text-end font-semibold num">{money(lineTotal(product.price, qty, chosen, product.addons), currency)}</p>
                                </li>
                            ))}
                            <li className="flex items-center justify-between py-4 text-lg font-semibold"><span>الإجمالي</span><span className="num">{money(total, currency)}</span></li>
                        </ul>

                        <form onSubmit={submit} className="h-fit space-y-4 rounded-3xl border border-line bg-white p-5">
                            <h2 className="font-display text-lg font-semibold">بيانات الطلب</h2>
                            {methods.length === 0 ? (
                                <p className="rounded-2xl bg-amber-50 p-3 text-sm text-amber-800">الطلب من الموقع غير متاح حاليًا. تواصل مع النادي.</p>
                            ) : (
                                <>
                                    <Field label="الاسم" error={form.errors.name} required>
                                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="name" maxLength={120} />
                                    </Field>
                                    <Field label="رقم الهاتف" error={form.errors.phone} required>
                                        <Input dir="ltr" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} autoComplete="tel" />
                                    </Field>
                                    <Field label="البريد الإلكتروني (اختياري)" error={form.errors.email}>
                                        <Input dir="ltr" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="email" />
                                    </Field>
                                    {branches.length > 1 && (
                                        <Field label="فرع الاستلام" error={form.errors.branch_id} required>
                                            <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="اختر الفرع">
                                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                            </Select>
                                        </Field>
                                    )}
                                    <Field label="ملاحظات (اختياري)" error={form.errors.notes}>
                                        <Textarea rows={2} maxLength={500} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                                    </Field>
                                    {/* Left empty by people; filled by bots. */}
                                    <input type="text" name="website" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} className="hidden" tabIndex={-1} autoComplete="off" aria-hidden="true" />

                                    <div className="space-y-2">
                                        <p className="label">طريقة الدفع</p>
                                        {methods.map((m) => {
                                            const { label, hint, icon: Icon } = METHOD[m];
                                            return (
                                                <button key={m} type="button" onClick={() => form.setData('payment_method', m)} className={cn('flex w-full items-start gap-3 rounded-2xl border p-3 text-start transition', form.data.payment_method === m ? 'border-brand bg-brand-50' : 'border-line')}>
                                                    <Icon className="mt-0.5 size-5 shrink-0 text-brand" />
                                                    <span><span className="block text-sm font-medium">{label}</span><span className="block text-xs text-muted">{hint}{m === 'paymob' && ` تُحجز الكميات لمدة ${windowMinutes} دقيقة حتى يكتمل الدفع.`}</span></span>
                                                </button>
                                            );
                                        })}
                                        {form.errors.payment_method && <p className="text-xs text-red-600">{form.errors.payment_method}</p>}
                                    </div>
                                    {pickupNote && <p className="rounded-2xl bg-stone-50 p-3 text-xs leading-6 text-muted">{pickupNote}</p>}
                                    {form.errors.items && <p className="text-xs text-red-600">{form.errors.items}</p>}
                                    <Button type="submit" size="lg" className="w-full rounded-full" loading={form.processing} icon={<Lock className="size-4" />}>
                                        {form.data.payment_method === 'paymob' ? `متابعة إلى الدفع — ${money(total, currency)}` : 'إرسال الطلب'}
                                    </Button>
                                </>
                            )}
                        </form>
                    </div>
                )}
            </section>
        </SiteLayout>
    );
}
