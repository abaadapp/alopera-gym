import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Minus, Plus, ShoppingBag } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { useCart } from '@/lib/cart';
import { money } from '@/lib/format';
import { AddToCart, ProductImage, type ShopProduct } from './Shop';

export default function ShopProductPage({ site, seo, product, currency }: { site: SiteInfo; seo: { title: string }; product: ShopProduct & { description: string | null }; currency: string }) {
    const [qty, setQty] = useState(1);
    const cart = useCart();
    const room = Math.max(0, product.max - (cart.lines.find((l) => l.id === product.id)?.qty ?? 0));

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="mx-auto max-w-5xl px-4 py-10">
                <Link href={route('site.shop')} className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> المتجر</Link>
                <div className="grid gap-8 md:grid-cols-2">
                    <div className="aspect-square overflow-hidden rounded-3xl border border-line bg-white"><ProductImage product={product} /></div>
                    <div>
                        {product.category && <p className="text-sm text-muted">{product.category}</p>}
                        <h1 className="mt-1 font-display text-3xl font-semibold text-ink">{product.name}</h1>
                        <p className="mt-3 text-2xl font-semibold text-brand num">{money(product.price, currency)}</p>
                        {product.description && <p className="mt-6 leading-8 whitespace-pre-line text-ink/80">{product.description}</p>}

                        {product.in_stock && room > 0 && (
                            <div className="mt-8 flex items-center gap-3">
                                <span className="text-sm text-muted">الكمية</span>
                                <div className="flex items-center rounded-full border border-line">
                                    <button type="button" onClick={() => setQty(Math.max(1, qty - 1))} className="grid size-10 place-items-center" aria-label="إنقاص"><Minus className="size-4" /></button>
                                    <span className="w-10 text-center font-medium num">{qty}</span>
                                    <button type="button" onClick={() => setQty(Math.min(room, qty + 1))} className="grid size-10 place-items-center" aria-label="زيادة"><Plus className="size-4" /></button>
                                </div>
                            </div>
                        )}
                        <div className="mt-6 flex flex-wrap gap-3">
                            <AddToCart product={product} qty={qty} className="px-6" />
                            {cart.count > 0 && (
                                <Link href={route('site.shop.cart')} className="inline-flex h-10 items-center gap-2 rounded-full border border-line px-5 text-sm hover:border-brand-300">
                                    <ShoppingBag className="size-4" /> السلة ({cart.count})
                                </Link>
                            )}
                        </div>
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
