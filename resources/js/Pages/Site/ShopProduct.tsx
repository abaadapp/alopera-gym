import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Minus, Plus, ShoppingBag } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { lineTotal, unitPrice, type ChosenAddon } from '@/lib/addons';
import { useCart } from '@/lib/cart';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { AddToCart, ProductImage, type ShopProduct } from './Shop';

/** A product of the club's shop: its photos, its add-ons to choose from, and the price as they are chosen. */
export default function ShopProductPage({ site, seo, product, currency }: { site: SiteInfo; seo: { title: string }; product: ShopProduct & { description: string | null; photos: string[] }; currency: string }) {
    const [qty, setQty] = useState(1);
    const [photo, setPhoto] = useState(0);
    const [chosen, setChosen] = useState<Record<number, number>>({});
    const cart = useCart();
    const room = Math.max(0, product.max - cart.quantityOf(product.id));
    const addons: ChosenAddon[] = Object.entries(chosen).filter(([, n]) => n > 0).map(([id, n]) => ({ id: Number(id), qty: n }));
    const photos = product.photos.length ? product.photos : [];

    const setAddon = (id: number, n: number) => setChosen((c) => ({ ...c, [id]: n }));

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="mx-auto max-w-5xl px-4 py-10">
                <Link href={route('site.shop')} className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> المتجر</Link>
                <div className="grid gap-8 md:grid-cols-2">
                    <div>
                        <div className="aspect-square overflow-hidden rounded-3xl border border-line bg-white">
                            {photos.length ? <img src={photos[photo]} alt={product.name} className="size-full object-cover" /> : <ProductImage product={product} />}
                        </div>
                        {photos.length > 1 && (
                            <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
                                {photos.map((url, i) => (
                                    <button key={url} type="button" onClick={() => setPhoto(i)} aria-label={`الصورة ${i + 1}`} aria-pressed={photo === i} className={cn('size-16 shrink-0 overflow-hidden rounded-2xl border-2 transition', photo === i ? 'border-brand' : 'border-transparent opacity-70 hover:opacity-100')}>
                                        <img src={url} alt="" className="size-full object-cover" />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <div>
                        {product.category && <p className="text-sm text-muted">{product.category}</p>}
                        <h1 className="mt-1 font-display text-3xl font-semibold text-ink">{product.name}</h1>
                        <p className="mt-3 text-2xl font-semibold text-brand num">{money(unitPrice(product.price, addons, product.addons), currency)}</p>
                        {addons.length > 0 && <p className="mt-1 text-sm text-muted">السعر الأساسي <span className="num">{money(product.price, currency)}</span> + الإضافات</p>}
                        {product.description && <p className="mt-6 leading-8 whitespace-pre-line text-ink/80">{product.description}</p>}

                        {product.addons.length > 0 && product.in_stock && (
                            <fieldset className="mt-8">
                                <legend className="mb-3 font-semibold text-ink">إضافات اختيارية <span className="text-sm font-normal text-muted">(لكل قطعة)</span></legend>
                                <ul className="space-y-2">
                                    {product.addons.map((a) => {
                                        const n = chosen[a.id] ?? 0;
                                        return (
                                            <li key={a.id} className={cn('flex items-center justify-between gap-3 rounded-2xl border px-4 py-3 transition', n > 0 ? 'border-brand bg-brand-50' : 'border-line bg-white')}>
                                                <label className="flex min-w-0 flex-1 cursor-pointer items-start gap-3">
                                                    <input type="checkbox" className="mt-1 size-4 accent-[var(--brand)]" checked={n > 0} onChange={(e) => setAddon(a.id, e.target.checked ? 1 : 0)} />
                                                    <span className="min-w-0">
                                                        <span className="block font-medium text-ink">{a.name}</span>
                                                        {a.description && <span className="block text-xs leading-5 text-muted">{a.description}</span>}
                                                        <span className="block text-sm font-semibold text-brand num">+{money(a.price, currency)}</span>
                                                    </span>
                                                </label>
                                                {a.max > 1 && n > 0 && (
                                                    <div className="flex items-center rounded-full border border-line bg-white">
                                                        <button type="button" onClick={() => setAddon(a.id, n - 1)} className="grid size-8 place-items-center" aria-label={`إنقاص ${a.name}`}><Minus className="size-3.5" /></button>
                                                        <span className="w-6 text-center text-sm font-medium num">{n}</span>
                                                        <button type="button" disabled={n >= a.max} onClick={() => setAddon(a.id, n + 1)} className="grid size-8 place-items-center disabled:opacity-40" aria-label={`زيادة ${a.name}`}><Plus className="size-3.5" /></button>
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </fieldset>
                        )}

                        {product.in_stock && room > 0 && (
                            <div className="mt-8 flex items-center gap-3">
                                <span className="text-sm text-muted">الكمية</span>
                                <div className="flex items-center rounded-full border border-line">
                                    <button type="button" onClick={() => setQty(Math.max(1, qty - 1))} className="grid size-10 place-items-center" aria-label="إنقاص"><Minus className="size-4" /></button>
                                    <span className="w-10 text-center font-medium num">{qty}</span>
                                    <button type="button" onClick={() => setQty(Math.min(room, qty + 1))} className="grid size-10 place-items-center" aria-label="زيادة"><Plus className="size-4" /></button>
                                </div>
                                {(qty > 1 || addons.length > 0) && <span className="text-sm text-muted">الإجمالي <span className="font-semibold text-ink num">{money(lineTotal(product.price, qty, addons, product.addons), currency)}</span></span>}
                            </div>
                        )}
                        <div className="mt-6 flex flex-wrap gap-3">
                            <AddToCart product={product} qty={Math.min(qty, Math.max(room, 1))} addons={addons} className="px-6" />
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
