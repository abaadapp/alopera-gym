import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, Package, Plus, ShoppingBag } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { useCart } from '@/lib/cart';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { PageHero } from './Plans';

export interface ShopProduct {
    id: number;
    name: string;
    price: string;
    category: string | null;
    image_url: string | null;
    in_stock: boolean;
    max: number;
}

export function ProductImage({ product, className }: { product: ShopProduct; className?: string }) {
    return product.image_url ? (
        <img src={product.image_url} alt={product.name} loading="lazy" className={cn('size-full object-cover', className)} />
    ) : (
        <div className="grid size-full place-items-center bg-gradient-to-br from-brand-50 to-white text-brand-300"><Package className="size-12" /></div>
    );
}

export function AddToCart({ product, qty = 1, className }: { product: ShopProduct; qty?: number; className?: string }) {
    const cart = useCart();
    const inCart = cart.lines.find((l) => l.id === product.id)?.qty ?? 0;
    const full = inCart >= product.max;

    if (!product.in_stock) {
        return <span className={cn('inline-flex h-10 items-center justify-center rounded-full bg-stone-100 px-4 text-sm text-muted', className)}>نفدت الكمية</span>;
    }
    return (
        <button
            type="button"
            disabled={full}
            onClick={() => cart.add(product.id, qty, product.max)}
            className={cn('inline-flex h-10 items-center justify-center gap-2 rounded-full bg-brand px-4 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-60', className)}
        >
            {inCart > 0 ? <Check className="size-4" /> : <Plus className="size-4" />}
            {full ? 'الحد الأقصى في السلة' : inCart > 0 ? `في السلة (${inCart}) — أضف المزيد` : 'أضف إلى السلة'}
        </button>
    );
}

export default function Shop({ site, seo, products, categories, currency }: { site: SiteInfo; seo: { title: string }; products: ShopProduct[]; categories: string[]; currency: string }) {
    const [category, setCategory] = useState('');
    const cart = useCart();
    const visible = products.filter((p) => !category || p.category === category);

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="المتجر" subtitle="منتجات النادي بأسعارها المحدّثة. اطلب أونلاين واستلم طلبك من النادي." icon={<ShoppingBag className="size-6" />} />

            <section className="mx-auto max-w-6xl px-4 py-12">
                {categories.length > 1 && (
                    <div className="mb-8 flex gap-2 overflow-x-auto pb-1">
                        {['', ...categories].map((c) => (
                            <button key={c || 'all'} type="button" onClick={() => setCategory(c)} className={cn('whitespace-nowrap rounded-full border px-4 py-2 text-sm transition', category === c ? 'border-brand bg-brand text-white' : 'border-line bg-white hover:border-brand-300')}>
                                {c || 'الكل'}
                            </button>
                        ))}
                    </div>
                )}

                {visible.length === 0 ? (
                    <p className="py-16 text-center text-muted">لا توجد منتجات معروضة حاليًا.</p>
                ) : (
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {visible.map((p) => (
                            <article key={p.id} className="group flex flex-col overflow-hidden rounded-3xl border border-line bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-brand/5">
                                <Link href={route('site.shop.product', p.id)} className="relative block aspect-[4/3] overflow-hidden">
                                    <ProductImage product={p} className="transition duration-500 group-hover:scale-105" />
                                    {p.category && <span className="absolute top-3 start-3 rounded-full bg-white/90 px-3 py-1 text-xs font-medium text-ink backdrop-blur">{p.category}</span>}
                                </Link>
                                <div className="flex flex-1 flex-col p-5">
                                    <Link href={route('site.shop.product', p.id)} className="font-display text-lg font-semibold text-ink hover:text-brand">{p.name}</Link>
                                    <p className="mt-1 text-lg font-semibold text-brand num">{money(p.price, currency)}</p>
                                    <div className="mt-auto pt-4"><AddToCart product={p} className="w-full" /></div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}

                {cart.count > 0 && (
                    <div className="sticky bottom-4 mt-10 flex justify-center">
                        <Link href={route('site.shop.cart')} className="inline-flex items-center gap-2 rounded-full bg-ink px-6 py-3 text-sm font-medium text-white shadow-lg">
                            <ShoppingBag className="size-4" /> إتمام الطلب ({cart.count})
                        </Link>
                    </div>
                )}
            </section>
        </SiteLayout>
    );
}
