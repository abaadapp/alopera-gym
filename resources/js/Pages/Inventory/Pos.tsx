import { useRef, useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Banknote, CreditCard, Minus, MonitorSmartphone, Package, Plus, Printer, ReceiptText, ScanBarcode, ShoppingBag, Trash2, UserRound, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, cardHover, ChoiceCard, EmptyState, Field, Input, MemberPicker, Modal, Notice, PageHeader, type MemberHit } from '@/Components/ui';
import { clientKey as newKey } from '@/Components/Inventory/parts';
import { Stepper } from '@/Components/Products/parts';
import { useCan, useCurrency } from '@/hooks/useShared';
import { describe, lineKey, lineTotal, payload, unitPrice, type AddonOption, type ChosenAddon } from '@/lib/addons';
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
    image_url: string | null;
    addons: AddonOption[];
}

/** A line of the basket: one product with one set of add-ons (the same product with other add-ons is another line). */
interface CartLine {
    key: string;
    product: Product;
    quantity: number;
    addons: ChosenAddon[];
}

/**
 * The point of sale: scan or tap products, choose their add-ons, take cash or card, print the receipt. A discount
 * needs its own permission. Totals here are for the cashier; the server prices the sale again from the database.
 */
type Device = { name: string; branch: string | null; auto_print: boolean; paper_width: number; printer: string | null } | null;

export default function Pos({ products, categories, can: allowed, lastSale, device, branch, requireDevice }: { products: Product[]; categories: string[]; can: { discount: boolean; devices: boolean }; lastSale: number | null; device: Device; branch: string | null; requireDevice: boolean }) {
    const currency = useCurrency();
    const can = useCan();
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string>('');
    const [cart, setCart] = useState<CartLine[]>([]);
    const [member, setMember] = useState<MemberHit | null>(null);
    const [guest, setGuest] = useState('');
    const [discount, setDiscount] = useState('');
    const [method, setMethod] = useState<'cash' | 'card'>('cash');
    const [cardRef, setCardRef] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [picking, setPicking] = useState<Product | null>(null);
    // One key per basket: a double click or a retried request is one sale, never two (the server keeps the first).
    const [basketKey, setBasketKey] = useState(newKey);
    const scanRef = useRef<HTMLInputElement>(null);
    const blocked = requireDevice && !device;

    const visible = products.filter((p) => {
        const q = query.trim().toLowerCase();
        const matches = !q || p.name.toLowerCase().includes(q) || p.barcode === q || p.sku?.toLowerCase() === q;
        return matches && (!category || p.category === category);
    });

    const inCart = (id: number) => cart.filter((l) => l.product.id === id).reduce((n, l) => n + l.quantity, 0);
    const available = (p: Product) => (p.track_stock ? Number(p.stock_quantity) : Infinity);

    /** Put a product (with its chosen add-ons) in the basket; a product that has add-ons opens their choice first. */
    const add = (p: Product, quantity = 1, addons: ChosenAddon[] = [], choose = true) => {
        if (choose && p.addons.length > 0) {
            setPicking(p);
            return;
        }
        if (inCart(p.id) + quantity > available(p)) {
            toast.error(`الكمية المتوفرة من «${p.name}» غير كافية`);
            return;
        }
        const key = lineKey(p.id, addons);
        setCart((c) => (c.some((l) => l.key === key) ? c.map((l) => (l.key === key ? { ...l, quantity: l.quantity + quantity } : l)) : [...c, { key, product: p, quantity, addons }]));
    };

    const setQty = (key: string, qty: number) => {
        setCart((c) => c.flatMap((l) => {
            if (l.key !== key) return [l];
            if (qty <= 0) return [];
            if (inCart(l.product.id) - l.quantity + qty > available(l.product)) {
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

    const subtotal = Math.round(cart.reduce((s, l) => s + lineTotal(l.product.price, l.quantity, l.addons, l.product.addons) * 1000, 0)) / 1000;
    const discountValue = allowed.discount ? Math.min(Number(discount || 0), subtotal) : 0;
    const total = subtotal - discountValue;

    const checkout = () => {
        if (cart.length === 0 || blocked) return;
        setBusy(true);
        setErrors({});
        // Opened now, while the click still counts, so a popup blocker lets the receipt through after the sale.
        const receipt = device?.auto_print ? window.open('', '_blank') : null;
        router.post(
            route('club.pos.checkout'),
            {
                items: cart.map((l) => ({ product_id: l.product.id, quantity: l.quantity, addons: payload(l.addons) })),
                payment_method: method,
                card_reference: method === 'card' ? cardRef.trim() : null,
                member_id: member?.id ?? null,
                customer_name: member ? null : guest.trim() || null,
                discount: discountValue || 0,
                client_key: basketKey,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const sale = (page.props as { lastSale?: number | null }).lastSale;
                    if (receipt && sale) receipt.location.replace(route('club.sales.receipt', sale));
                    else receipt?.close();
                    setBasketKey(newKey());
                    setCart([]);
                    setMember(null);
                    setGuest('');
                    setDiscount('');
                    setMethod('cash');
                    setCardRef('');
                    scanRef.current?.focus();
                },
                onError: (errs) => {
                    receipt?.close();
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
                subtitle="بيع المنتجات للأعضاء والزوار نقدًا أو بالبطاقة"
                actions={
                    <>
                        {lastSale && <Button variant="outline" href={route('club.sales.receipt', lastSale)} external icon={<Printer />}>إيصال آخر عملية</Button>}
                        {can('sales.view') && <Button variant="outline" href={route('club.sales.index')} icon={<ReceiptText />}>عمليات البيع</Button>}
                    </>
                }
            />

            {device ? (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[12px] border border-line bg-white px-4 py-2.5 text-[13px] text-[#4b4b4b]">
                    <MonitorSmartphone className="size-4 text-emerald-700" /> جهاز «{device.name}» · {device.branch}
                    {device.printer && <span className="text-[#9ca3af]">· الطابعة {device.printer} (<span className="num">{device.paper_width}</span> مم){device.auto_print ? ' · طباعة تلقائية' : ''}</span>}
                </div>
            ) : (
                <Notice tone={blocked ? 'red' : 'gray'} className="mb-4" href={route('club.pos.device')}>
                    {blocked ? 'البيع من أجهزة نقاط البيع المفعّلة فقط. فعّل هذا الجهاز برمز الربط لتتمكن من البيع.' : `هذا المتصفح ليس جهاز نقطة بيع مفعّلًا؛ تُسجَّل المبيعات على ${branch ?? 'الفرع الحالي'}. تفعيل هذا الجهاز`}
                </Notice>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                <div className="min-w-0 space-y-4">
                    <form onSubmit={onScan} className="relative">
                        <ScanBarcode className="pointer-events-none absolute top-1/2 start-3 size-5 -translate-y-1/2 text-muted" />
                        <input
                            ref={scanRef}
                            className="input h-12 ps-11 text-base"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="امسح الباركود أو ابحث باسم المنتج ثم Enter"
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
                            <EmptyState icon={<Package className="size-6" />} title="لا توجد منتجات" description={products.length === 0 ? 'أضف منتجات من قسم المنتجات.' : 'جرّب بحثًا آخر.'} action={products.length === 0 && can('products.manage') ? <Button size="sm" href={route('club.products.index')}>المنتجات</Button> : undefined} />
                        </Card>
                    ) : (
                        <div className="grid auto-rows-fr grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                            {visible.map((p) => {
                                const out = p.track_stock && Number(p.stock_quantity) - inCart(p.id) <= 0;
                                return (
                                    <button
                                        key={p.id}
                                        type="button"
                                        disabled={out}
                                        onClick={() => add(p)}
                                        className={cn('card flex h-full min-h-28 flex-col items-start justify-between p-3 text-start active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50', cardHover)}
                                    >
                                        {p.image_url && <img src={p.image_url} alt="" loading="lazy" className="mb-2 h-16 w-full rounded-lg object-cover" />}
                                        <span className="line-clamp-2 text-sm font-medium">{p.name}</span>
                                        {p.addons.length > 0 && <span className="mt-1 text-[11px] text-[#6b7280]">+ إضافات</span>}
                                        <span className="mt-2 flex w-full items-end justify-between gap-2">
                                            <span className="font-semibold text-[#111] num">{money(p.price, currency)}</span>
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
                            <p className="py-10 text-center text-sm text-muted">اختر منتجات لإضافتها</p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {cart.map((l) => (
                                    <li key={l.key} className="flex items-center gap-3 py-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{l.product.name}</p>
                                            {describe(l.addons, l.product.addons).map((a) => (
                                                <p key={a.id} className="truncate text-[11px] text-[#6b7280]">+ {a.name}{a.qty > 1 && <span className="num"> ×{a.qty}</span>} <span className="num">({money(Number(a.price) * a.qty, currency)})</span></p>
                                            ))}
                                            <p className="text-xs text-muted num">{money(lineTotal(l.product.price, l.quantity, l.addons, l.product.addons), currency)}</p>
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <button type="button" onClick={() => setQty(l.key, l.quantity - 1)} className="grid size-8 place-items-center rounded-lg border border-line hover:bg-brand-50" aria-label={`إنقاص ${l.product.name}`}>
                                                {l.quantity === 1 ? <Trash2 className="size-3.5 text-red-600" /> : <Minus className="size-3.5" />}
                                            </button>
                                            <span className="w-8 text-center text-sm font-medium num">{l.quantity}</span>
                                            <button type="button" onClick={() => setQty(l.key, l.quantity + 1)} className="grid size-8 place-items-center rounded-lg border border-line hover:bg-brand-50" aria-label={`زيادة ${l.product.name}`}>
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
                            <p className="label">العميل (اختياري)</p>
                            {member ? (
                                <div className="flex items-center justify-between rounded-[12px] border border-line bg-[#fafafa] px-3 py-2 text-sm">
                                    <span className="flex items-center gap-2"><UserRound className="size-4 text-[#6b7280]" />{member.full_name}</span>
                                    <button type="button" onClick={() => setMember(null)} aria-label="إزالة"><X className="size-4 text-muted" /></button>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    <MemberPicker onSelect={setMember} placeholder="ابحث لربط البيع بعضو" />
                                    <Input value={guest} onChange={(e) => setGuest(e.target.value)} placeholder="أو اسم العميل الزائر" maxLength={120} aria-label="اسم العميل الزائر" />
                                </div>
                            )}
                        </div>
                        {allowed.discount && (
                            <Field label="خصم" error={errors.discount}>
                                <Input type="number" min="0" step="0.001" dir="ltr" value={discount} onChange={(e) => setDiscount(e.target.value)} placeholder="0.000" />
                            </Field>
                        )}
                        <div className="grid grid-cols-2 gap-2">
                            {([['cash', 'نقدًا', Banknote], ['card', 'بطاقة', CreditCard]] as const).map(([key, text, Icon]) => (
                                <ChoiceCard key={key} selected={method === key} onClick={() => setMethod(key)} className="flex items-center justify-center gap-2 py-2.5 text-sm">
                                    <Icon className="size-4" /> {text}
                                </ChoiceCard>
                            ))}
                        </div>
                        {method === 'card' && (
                            <Field label="رقم العملية من جهاز الشبكة" error={errors.card_reference} required>
                                <Input dir="ltr" value={cardRef} onChange={(e) => setCardRef(e.target.value)} />
                            </Field>
                        )}
                        <dl className="space-y-1 text-sm">
                            <div className="flex justify-between"><dt className="text-muted">المجموع</dt><dd className="num">{money(subtotal, currency)}</dd></div>
                            {discountValue > 0 && <div className="flex justify-between"><dt className="text-muted">الخصم</dt><dd className="num">- {money(discountValue, currency)}</dd></div>}
                            <div className="flex justify-between border-t border-line pt-2 text-lg font-semibold"><dt>الإجمالي</dt><dd className="num">{money(total, currency)}</dd></div>
                        </dl>
                        {errors.items && <p className="text-xs text-red-600">{errors.items}</p>}
                        <Button size="lg" className="w-full" disabled={blocked || cart.length === 0 || (method === 'card' && cardRef.trim() === '')} loading={busy} onClick={checkout}>
                            إتمام البيع
                        </Button>
                        <p className="text-center text-xs text-muted">
                            تُصدر فاتورة مدفوعة ويُخصم المخزون تلقائيًا.{' '}
                            {can('invoices.view') && <Link href={route('club.invoices.index', { type: 'pos' })} className="text-brand">فواتير المبيعات</Link>}
                        </p>
                    </div>
                </Card>
            </div>

            {picking && (
                <ItemOptions
                    product={picking}
                    room={available(picking) - inCart(picking.id)}
                    currency={currency}
                    onClose={() => setPicking(null)}
                    onAdd={(quantity, addons) => {
                        add(picking, quantity, addons, false);
                        setPicking(null);
                        scanRef.current?.focus();
                    }}
                />
            )}
        </ClubLayout>
    );
}

/** The add-ons of a product being sold: how many of each per piece, the quantity, and the line's total as it changes. */
function ItemOptions({ product, room, currency, onClose, onAdd }: { product: Product; room: number; currency: string; onClose: () => void; onAdd: (quantity: number, addons: ChosenAddon[]) => void }) {
    const [quantity, setQuantity] = useState(1);
    const [chosen, setChosen] = useState<Record<number, number>>({});
    const addons: ChosenAddon[] = Object.entries(chosen).filter(([, qty]) => qty > 0).map(([id, qty]) => ({ id: Number(id), qty }));
    const total = lineTotal(product.price, quantity, addons, product.addons);

    return (
        <Modal
            open
            onClose={onClose}
            title={product.name}
            footer={
                <div className="flex w-full items-center justify-between gap-3">
                    <div>
                        <p className="text-[12px] text-muted">الإجمالي</p>
                        <p className="text-lg font-bold num">{money(total, currency)}</p>
                    </div>
                    <Button size="lg" disabled={room < quantity} onClick={() => onAdd(quantity, addons)} icon={<Plus />}>إضافة للسلة</Button>
                </div>
            }
        >
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <p className="text-sm text-muted">السعر <span className="font-semibold text-ink num">{money(product.price, currency)}</span>{addons.length > 0 && <> · مع الإضافات <span className="font-semibold text-ink num">{money(unitPrice(product.price, addons, product.addons), currency)}</span></>}</p>
                    <Stepper value={quantity} onChange={setQuantity} min={1} max={Math.max(1, Math.min(room, 999))} label="الكمية" />
                </div>
                <div>
                    <p className="mb-2 text-[13px] font-semibold text-[#4b4b4b]">إضافات اختيارية <span className="font-normal text-muted">(لكل قطعة)</span></p>
                    <ul className="space-y-2">
                        {product.addons.map((a) => {
                            const qty = chosen[a.id] ?? 0;
                            return (
                                <li key={a.id} className={cn('flex items-center justify-between gap-3 rounded-[12px] border px-3 py-2', qty > 0 ? 'border-[#111]/30 bg-[#fafafa]' : 'border-line', a.available === false && 'opacity-50')}>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{a.name}</p>
                                        <p className="text-xs text-muted"><span className="font-semibold text-[#4b4b4b] num">+{money(a.price, currency)}</span>{a.available === false && <span className="text-red-600"> · نفدت</span>}{a.max > 1 && <> · حتى <span className="num">{a.max}</span> لكل قطعة</>}</p>
                                    </div>
                                    <Stepper size="sm" value={qty} onChange={(v) => setChosen((c) => ({ ...c, [a.id]: v }))} min={0} max={a.available === false ? 0 : a.max} label={a.name} />
                                </li>
                            );
                        })}
                    </ul>
                </div>
                {room < quantity && <p className="text-xs text-red-600">الكمية المتوفرة غير كافية.</p>}
            </div>
        </Modal>
    );
}
