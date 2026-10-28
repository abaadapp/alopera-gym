import { useMemo, useRef, useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Banknote, CreditCard, Minus, Package, Plus, ReceiptText, ScanBarcode, ShoppingBag, Trash2, UserRound, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Field, Input, MemberPicker, PageHeader, type MemberHit } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';

interface Product {
    id: number;
    name: string;
    price: string;
    stock_quantity: string;
    track_stock: boolean;
    barcode: string | null;
    sku: string | null;
    category: string | null;
}

interface CartLine {
    product: Product;
    quantity: number;
}

/** Front-desk shop (spec 1.6): scan or tap products, take cash or card, done. */
export default function Pos({ products }: { products: Product[] }) {
    const currency = useCurrency();
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string>('');
    const [cart, setCart] = useState<CartLine[]>([]);
    const [member, setMember] = useState<MemberHit | null>(null);
    const [discount, setDiscount] = useState('');
    const [method, setMethod] = useState<'cash' | 'card'>('cash');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const scanRef = useRef<HTMLInputElement>(null);

    const categories = useMemo(() => Array.from(new Set(products.map((p) => p.category).filter(Boolean))) as string[], [products]);

    const visible = products.filter((p) => {
        const q = query.trim().toLowerCase();
        const matches = !q || p.name.toLowerCase().includes(q) || p.barcode === q || p.sku?.toLowerCase() === q;
        return matches && (!category || p.category === category);
    });

    const inCart = (id: number) => cart.find((l) => l.product.id === id)?.quantity ?? 0;
    const available = (p: Product) => (p.track_stock ? Number(p.stock_quantity) : Infinity);

    const add = (p: Product) => {
        if (inCart(p.id) + 1 > available(p)) {
            toast.error(`الكمية المتوفرة من «${p.name}» غير كافية`);
            return;
        }
        setCart((c) => (c.some((l) => l.product.id === p.id) ? c.map((l) => (l.product.id === p.id ? { ...l, quantity: l.quantity + 1 } : l)) : [...c, { product: p, quantity: 1 }]));
    };

    const setQty = (id: number, qty: number) => {
        setCart((c) => c.flatMap((l) => {
            if (l.product.id !== id) return [l];
            if (qty <= 0) return [];
            if (qty > available(l.product)) {
                toast.error('الكمية أكبر من المتوفر');
                return [l];
            }
            return [{ ...l, quantity: qty }];
        }));
    };

    const onScan = (e: FormEvent) => {
        e.preventDefault();
        const code = query.trim();
        const hit = products.find((p) => p.barcode === code || p.sku === code);
        if (hit) {
            add(hit);
            setQuery('');
        } else if (visible.length === 1) {
            add(visible[0]);
            setQuery('');
        }
    };

    const subtotal = cart.reduce((s, l) => s + Number(l.product.price) * l.quantity, 0);
    const discountValue = Math.min(Number(discount || 0), subtotal);
    const total = subtotal - discountValue;

    const checkout = () => {
        if (cart.length === 0) return;
        setBusy(true);
        setErrors({});
        router.post(
            route('club.pos.checkout'),
            {
                items: cart.map((l) => ({ product_id: l.product.id, quantity: l.quantity })),
                payment_method: method,
                member_id: member?.id ?? null,
                discount: discountValue || 0,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCart([]);
                    setMember(null);
                    setDiscount('');
                    setMethod('cash');
                    scanRef.current?.focus();
                },
                onError: (errs) => {
                    setErrors(errs);
                    const first = Object.values(errs)[0];
                    if (first) toast.error(first);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ClubLayout title="نقطة البيع">
            <PageHeader
                title="نقطة البيع"
                subtitle="بيع المنتجات من الاستقبال"
                actions={<Button variant="outline" href={route('club.pos.sales')} icon={<ReceiptText className="size-4" />}>عمليات اليوم</Button>}
            />

            <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                <div className="min-w-0 space-y-4">
                    <form onSubmit={onScan} className="relative">
                        <ScanBarcode className="pointer-events-none absolute top-1/2 start-3 size-5 -translate-y-1/2 text-muted" />
                        <input
                            ref={scanRef}
                            className="input h-12 ps-11 text-base"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="امسحي الباركود أو ابحثي باسم المنتج ثم Enter"
                            autoFocus
                        />
                    </form>
                    {categories.length > 0 && (
                        <div className="flex gap-2 overflow-x-auto pb-1">
                            {['', ...categories].map((c) => (
                                <button
                                    key={c || 'all'}
                                    type="button"
                                    onClick={() => setCategory(c)}
                                    className={cn('whitespace-nowrap rounded-full border px-3 py-1.5 text-sm', category === c ? 'border-brand bg-brand text-white' : 'border-line bg-white hover:border-brand-200')}
                                >
                                    {c || 'الكل'}
                                </button>
                            ))}
                        </div>
                    )}
                    {visible.length === 0 ? (
                        <Card>
                            <EmptyState icon={<Package className="size-6" />} title="لا توجد منتجات" description={products.length === 0 ? 'أضيفي منتجات من صفحة المنتجات والمخزون.' : 'جرّبي بحثًا آخر.'} action={products.length === 0 ? <Button size="sm" href={route('club.products.index')}>المنتجات</Button> : undefined} />
                        </Card>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                            {visible.map((p) => {
                                const out = p.track_stock && Number(p.stock_quantity) - inCart(p.id) <= 0;
                                return (
                                    <button
                                        key={p.id}
                                        type="button"
                                        disabled={out}
                                        onClick={() => add(p)}
                                        className="card flex min-h-28 flex-col items-start justify-between p-3 text-start transition hover:border-brand-300 hover:shadow-md active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span className="line-clamp-2 text-sm font-medium">{p.name}</span>
                                        <span className="mt-2 flex w-full items-end justify-between gap-2">
                                            <span className="font-semibold text-brand num">{money(p.price, currency)}</span>
                                            {p.track_stock && <span className={cn('text-xs num', out ? 'text-red-600' : 'text-muted')}>{out ? 'نفد' : number(Number(p.stock_quantity) - inCart(p.id))}</span>}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                <Card className="h-fit lg:sticky lg:top-20" padded={false}>
                    <div className="flex items-center justify-between border-b border-line px-5 py-4">
                        <p className="flex items-center gap-2 font-semibold"><ShoppingBag className="size-4 text-brand" /> السلة</p>
                        {cart.length > 0 && <button type="button" onClick={() => setCart([])} className="text-xs text-muted hover:text-red-600">تفريغ</button>}
                    </div>

                    <div className="max-h-80 overflow-y-auto px-5">
                        {cart.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted">اختاري منتجات لإضافتها</p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {cart.map((l) => (
                                    <li key={l.product.id} className="flex items-center gap-3 py-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{l.product.name}</p>
                                            <p className="text-xs text-muted num">{money(Number(l.product.price) * l.quantity, currency)}</p>
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <button type="button" onClick={() => setQty(l.product.id, l.quantity - 1)} className="grid size-8 place-items-center rounded-lg border border-line hover:bg-brand-50" aria-label="إنقاص">
                                                {l.quantity === 1 ? <Trash2 className="size-3.5 text-red-600" /> : <Minus className="size-3.5" />}
                                            </button>
                                            <span className="w-8 text-center text-sm font-medium num">{l.quantity}</span>
                                            <button type="button" onClick={() => setQty(l.product.id, l.quantity + 1)} className="grid size-8 place-items-center rounded-lg border border-line hover:bg-brand-50" aria-label="زيادة">
                                                <Plus className="size-3.5" />
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="space-y-4 border-t border-line px-5 py-4">
                        <div>
                            <p className="label">المشتركة (اختياري)</p>
                            {member ? (
                                <div className="flex items-center justify-between rounded-xl border border-brand-200 bg-brand-50 px-3 py-2 text-sm">
                                    <span className="flex items-center gap-2"><UserRound className="size-4 text-brand" />{member.full_name}</span>
                                    <button type="button" onClick={() => setMember(null)} aria-label="إزالة"><X className="size-4 text-muted" /></button>
                                </div>
                            ) : (
                                <MemberPicker onSelect={setMember} placeholder="ابحثي لربط البيع بمشتركة" />
                            )}
                        </div>
                        <Field label="خصم" error={errors.discount}>
                            <Input type="number" min="0" step="0.001" dir="ltr" value={discount} onChange={(e) => setDiscount(e.target.value)} placeholder="0.000" />
                        </Field>
                        <div className="grid grid-cols-2 gap-2">
                            {([['cash', 'نقدًا', Banknote], ['card', 'بطاقة', CreditCard]] as const).map(([key, text, Icon]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setMethod(key)}
                                    className={cn('flex items-center justify-center gap-2 rounded-xl border py-2.5 text-sm', method === key ? 'border-brand bg-brand-50 text-brand-700' : 'border-line hover:border-brand-200')}
                                >
                                    <Icon className="size-4" /> {text}
                                </button>
                            ))}
                        </div>
                        <dl className="space-y-1 text-sm">
                            <div className="flex justify-between"><dt className="text-muted">المجموع</dt><dd className="num">{money(subtotal, currency)}</dd></div>
                            {discountValue > 0 && <div className="flex justify-between"><dt className="text-muted">الخصم</dt><dd className="num">- {money(discountValue, currency)}</dd></div>}
                            <div className="flex justify-between border-t border-line pt-2 text-lg font-semibold"><dt>الإجمالي</dt><dd className="num">{money(total, currency)}</dd></div>
                        </dl>
                        {errors.items && <p className="text-xs text-red-600">{errors.items}</p>}
                        <Button size="lg" className="w-full" disabled={cart.length === 0} loading={busy} onClick={checkout}>
                            إتمام البيع
                        </Button>
                        <p className="text-center text-xs text-muted">تُصدر فاتورة مدفوعة ويُخصم المخزون تلقائيًا. <Link href={route('club.invoices.index', { type: 'pos' })} className="text-brand">فواتير نقطة البيع</Link></p>
                    </div>
                </Card>
            </div>
        </ClubLayout>
    );
}
