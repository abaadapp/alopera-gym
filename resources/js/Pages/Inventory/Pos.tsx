import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    Banknote, Check, CheckCircle2, ChevronDown, CreditCard, Dumbbell, Link2, Package, Plus, Printer, ScanBarcode, ScanLine, Search, ShoppingCart,
    Ticket, TicketPercent, Trash2, User, UserPlus, X,
} from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Button, Field, Input, Modal, Notice, type MemberHit } from '@/Components/ui';
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
type Plan = { id: number; name: string; price: string; signup_fee: string; duration: string; sessions: number | null };
type PtPack = { id: number; name: string; price: string; sessions: number; trainer: string | null };
type Device = { name: string; branch: string | null; auto_print: boolean; paper_width: number; printer: string | null } | null;
type Doc = { kind: 'sale' | 'membership' | 'pt'; id: number; number: string; label: string; total: string; paid: boolean; payment_id: number | null; pay_url: string | null };
type Result = { documents: Doc[]; total: string; method: string; at: string };
type MemberCard = MemberHit & { balance: string };

/** A line of the basket: a product (with its add-ons), a membership, or a training package. */
type Line =
    | { key: string; kind: 'product'; product: Product; quantity: number; addons: ChosenAddon[] }
    | { key: string; kind: 'membership'; plan: Plan; total: number | null }
    | { key: string; kind: 'pt'; pack: PtPack };

type Kind = 'products' | 'memberships' | 'pt';
type Method = 'cash' | 'card' | 'paymob';

interface Props {
    products: Product[];
    categories: string[];
    plans: Plan[];
    packages: PtPack[];
    can: { discount: boolean; devices: boolean; refund: boolean; memberships: boolean; pt: boolean; print: boolean; paymob: boolean };
    lastResult: Result | null;
    device: Device;
    branch: string | null;
    requireDevice: boolean;
}

const chip = 'whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-colors touch:px-5 touch:py-2.5';
const chipOn = 'bg-gray-900 text-white shadow-sm';
const chipOff = 'border border-gray-100 bg-white text-gray-600 hover:bg-gray-50';
const tile = 'group flex select-none flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white text-start shadow-sm transition-[border-color,box-shadow] hover:border-gray-300 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-gray-100 disabled:hover:shadow-sm';
const plusChip = 'flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-900 transition-colors group-hover:bg-gray-900 group-hover:text-white';

/**
 * The point of sale: products (scanned or tapped, with their add-ons), memberships and their renewal, and personal
 * training packages, for a member or a walk-in customer; paid in cash, by card or — for memberships and packages — by
 * a Paymob link; its receipt printed on the till's thermal printer. Totals here guide the cashier; the server prices
 * the basket again, issues every invoice and records who sold what, where and on which device.
 */
export default function Pos({ products, categories, plans, packages, can: allowed, device, branch, requireDevice }: Props) {
    const currency = useCurrency();
    const can = useCan();
    const m = (v: string | number) => money(v, currency);
    const [kind, setKind] = useState<Kind>('products');
    const [query, setQuery] = useState('');
    const [code, setCode] = useState('');
    const [category, setCategory] = useState('');
    const [cart, setCart] = useState<Line[]>([]);
    const [member, setMember] = useState<MemberCard | null>(null);
    const [guest, setGuest] = useState('');
    const [discount, setDiscount] = useState('');
    const [discountDraft, setDiscountDraft] = useState('');
    const [picking, setPicking] = useState<Product | null>(null);
    const [paying, setPaying] = useState(false);
    const [result, setResult] = useState<Result | null>(null);
    // One key per basket: a double click or a retried request is one sale, never two (the server keeps the first).
    const [basketKey, setBasketKey] = useState(newKey);
    const scanRef = useRef<HTMLInputElement>(null);
    const blocked = requireDevice && !device;
    const kinds = ([['products', 'المنتجات', Package], ['memberships', 'العضويات', Ticket], ['pt', 'التدريب الشخصي', Dumbbell]] as const).filter(([k]) => k === 'products' || (k === 'memberships' ? plans.length > 0 : packages.length > 0));

    const q = query.trim().toLowerCase();
    const visibleProducts = products.filter((p) => (!q || p.name.toLowerCase().includes(q) || p.barcode === q || p.sku?.toLowerCase() === q) && (!category || p.category === category));
    const visiblePlans = plans.filter((p) => !q || p.name.toLowerCase().includes(q));
    const visiblePacks = packages.filter((p) => !q || p.name.toLowerCase().includes(q));

    const inCart = (id: number) => cart.reduce((n, l) => n + (l.kind === 'product' && l.product.id === id ? l.quantity : 0), 0);
    const available = (p: Product) => (p.track_stock ? Number(p.stock_quantity) : Infinity);
    const hasProducts = cart.some((l) => l.kind === 'product');
    const hasServices = cart.some((l) => l.kind !== 'product');

    // A membership's price for this member: the plan, plus the sign-up fee on their very first one (asked of the server).
    useEffect(() => {
        const line = cart.find((l) => l.kind === 'membership');
        if (!line || line.kind !== 'membership') return;
        if (!member) {
            setCart((c) => c.map((l) => (l.kind === 'membership' ? { ...l, total: null } : l)));
            return;
        }
        let live = true;
        fetch(route('club.memberships.quote', { member_id: member.id, membership_plan_id: line.plan.id }), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((quote) => live && quote && setCart((c) => c.map((l) => (l.kind === 'membership' ? { ...l, total: Number(quote.total) } : l))))
            .catch(() => undefined);
        return () => {
            live = false;
        };
    }, [member?.id, cart.find((l) => l.kind === 'membership')?.key]); // eslint-disable-line react-hooks/exhaustive-deps

    const addProduct = (p: Product, quantity = 1, addons: ChosenAddon[] = [], choose = true) => {
        if (choose && p.addons.length > 0) return setPicking(p);
        if (inCart(p.id) + quantity > available(p)) return toast.warning(`الكمية المتوفرة من «${p.name}» غير كافية`);
        const key = lineKey(p.id, addons);
        setCart((c) => (c.some((l) => l.key === key) ? c.map((l) => (l.key === key && l.kind === 'product' ? { ...l, quantity: l.quantity + quantity } : l)) : [...c, { key, kind: 'product', product: p, quantity, addons }]));
        toast.success(`أُضيف «${p.name}» إلى السلة`, { duration: 1200 });
    };
    const addPlan = (plan: Plan) => {
        // One membership a basket: a second would start after the first, priced differently.
        setCart((c) => [...c.filter((l) => l.kind !== 'membership'), { key: `membership-${plan.id}`, kind: 'membership', plan, total: null }]);
        if (!member) toast.info('اختر العضو قبل الدفع: العضوية تُباع لعضو.');
    };
    const addPack = (pack: PtPack) => {
        if (cart.some((l) => l.key === `pt-${pack.id}`)) return toast.info('الباقة في السلة بالفعل');
        setCart((c) => [...c, { key: `pt-${pack.id}`, kind: 'pt', pack }]);
        if (!member) toast.info('اختر العضو قبل الدفع: التدريب الشخصي يُباع لعضو.');
    };
    const setQty = (key: string, qty: number) =>
        setCart((c) => c.flatMap((l) => {
            if (l.key !== key || l.kind !== 'product') return [l];
            if (inCart(l.product.id) - l.quantity + qty > available(l.product)) {
                toast.warning('الكمية أكبر من المتوفر');
                return [l];
            }
            return [{ ...l, quantity: Math.max(1, qty) }];
        }));
    const remove = (key: string) => setCart((c) => c.filter((l) => l.key !== key));

    const scan = (value: string) => {
        const hit = products.find((p) => p.barcode === value.trim() || p.sku?.toLowerCase() === value.trim().toLowerCase());
        if (hit) addProduct(hit);
        else if (value.trim()) toast.warning(`لا يوجد منتج بهذا الباركود: ${value.trim()}`);
        setCode('');
    };

    const lineAmount = (l: Line) => (l.kind === 'product' ? lineTotal(l.product.price, l.quantity, l.addons, l.product.addons) : l.kind === 'membership' ? (l.total ?? Number(l.plan.price)) : Number(l.pack.price));
    const productsTotal = Math.round(cart.reduce((s, l) => s + (l.kind === 'product' ? lineAmount(l) : 0) * 1000, 0)) / 1000;
    const servicesTotal = Math.round(cart.reduce((s, l) => s + (l.kind !== 'product' ? lineAmount(l) : 0) * 1000, 0)) / 1000;
    const discountValue = allowed.discount ? Math.min(Number(discount || 0), productsTotal) : 0;
    const total = Math.round((productsTotal - discountValue + servicesTotal) * 1000) / 1000;
    const count = cart.reduce((n, l) => n + (l.kind === 'product' ? l.quantity : 1), 0);

    const reset = () => {
        setBasketKey(newKey());
        setCart([]);
        setMember(null);
        setGuest('');
        setDiscount('');
        setDiscountDraft('');
        setResult(null);
        scanRef.current?.focus();
    };

    const openPayment = () => {
        if (cart.length === 0) return toast.warning('السلة فارغة: اختر منتجًا أو عضوية أو باقة.');
        if (blocked) return toast.error('البيع من أجهزة نقاط البيع المفعّلة فقط. فعّل هذا الجهاز أولًا.');
        if (hasServices && !member) return toast.warning('العضوية والتدريب الشخصي يُباعان لعضو: اختر العضو أولًا.');
        if (member?.status === 'blocked') return toast.error('حساب العضو موقوف.');
        setPaying(true);
    };

    return (
        <PosLayout title="نقطة البيع" device={device} branch={branch} fill>
            <div className="flex flex-col gap-4 p-4 max-md:pb-24 md:h-full md:flex-row md:overflow-hidden">
                <section className="flex min-w-0 flex-1 flex-col md:min-h-0">
                    {!device && (
                        <Notice tone={blocked ? 'red' : 'gray'} className="mb-3 shrink-0" href={route('club.pos.device')}>
                            {blocked ? 'البيع من أجهزة نقاط البيع المفعّلة فقط. فعّل هذا الجهاز برمز الربط لتتمكن من البيع.' : `هذا المتصفح ليس جهاز نقطة بيع مفعّلًا؛ تُسجَّل المبيعات على ${branch ?? 'الفرع الحالي'}. تفعيل هذا الجهاز`}
                        </Notice>
                    )}
                    <div className="mb-4 flex shrink-0 flex-col gap-3 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                            <Input type="search" enterKeyHint="search" autoComplete="off" autoCorrect="off" autoCapitalize="off" spellCheck={false} value={query} onChange={(e) => setQuery(e.target.value)} placeholder={kind === 'products' ? 'ابحث باسم المنتج' : kind === 'memberships' ? 'ابحث باسم الباقة' : 'ابحث باسم باقة التدريب'} className="ps-9 touch:h-12" aria-label="بحث" />
                        </div>
                        {kind === 'products' && (
                            <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); scan(code); }}>
                                <div className="relative w-44 max-sm:flex-1">
                                    <ScanBarcode className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                    <Input ref={scanRef} value={code} onChange={(e) => setCode(e.target.value)} placeholder="الباركود" dir="ltr" autoFocus autoComplete="off" className="ps-9 touch:h-12" aria-label="الباركود" />
                                </div>
                                <Button type="button" icon={<ScanLine />} onClick={() => { scanRef.current?.focus(); toast.info('جاهز للمسح'); }}>مسح</Button>
                            </form>
                        )}
                    </div>

                    {kinds.length > 1 && (
                        <div className="mb-3 flex shrink-0 items-center gap-2 overflow-x-auto pb-1">
                            {kinds.map(([k, label, Icon]) => (
                                <button key={k} type="button" onClick={() => { setKind(k); setQuery(''); }} className={cn(chip, 'inline-flex items-center gap-2', kind === k ? chipOn : chipOff)}>
                                    <Icon className="size-4" /> {label}
                                </button>
                            ))}
                        </div>
                    )}
                    {kind === 'products' && categories.length > 0 && (
                        <div className="mb-4 flex shrink-0 items-center gap-2 overflow-x-auto pb-1">
                            {['', ...categories].map((c) => (
                                <button key={c || 'all'} type="button" onClick={() => setCategory(c)} className={cn(chip, category === c ? chipOn : chipOff)}>{c || 'الكل'}</button>
                            ))}
                        </div>
                    )}

                    <div className="-mx-1 px-1 md:flex-1 md:overflow-y-auto md:overscroll-contain">
                        {kind === 'products' && (visibleProducts.length === 0 ? (
                            <p className="py-16 text-center text-sm text-gray-400">{products.length === 0 ? 'لا منتجات بعد — تُضاف من قسم المنتجات.' : 'لا نتائج لهذا البحث'}</p>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-4">
                                {visibleProducts.map((p) => {
                                    const left = available(p) - inCart(p.id);
                                    const out = p.track_stock && left <= 0;
                                    const low = p.track_stock && !out && left <= 5;
                                    return (
                                        <button key={p.id} type="button" disabled={out} onClick={() => addProduct(p)} className={tile}>
                                            <div className="relative aspect-square w-full overflow-hidden bg-gray-50">
                                                {p.image_url ? <img src={p.image_url} alt="" loading="lazy" className="size-full object-cover transition-transform duration-300 group-hover:scale-105" /> : <span className="flex size-full items-center justify-center text-gray-300"><Package className="size-10" /></span>}
                                                <span className={cn('absolute end-2 top-2 rounded-full px-2.5 py-1 text-[12px] font-medium', !p.track_stock ? 'bg-[#f2f2f0] text-[#4b4b4b]' : out ? 'bg-[#fef2f2] text-[#b91c1c]' : low ? 'bg-[#fffbeb] text-[#d97706]' : 'bg-[#ecfdf5] text-[#047857]')}>
                                                    {!p.track_stock ? 'خدمة' : out ? 'نفد' : low ? 'كمية قليلة' : 'متوفر'}
                                                </span>
                                            </div>
                                            <div className="w-full p-3">
                                                <p className="truncate text-sm font-semibold text-gray-800">{p.name}</p>
                                                <p className="mt-0.5 text-xs text-gray-400">{p.track_stock ? <>متوفر: <span className="num">{number(Math.max(0, left))}</span></> : 'لا يتتبع المخزون'}{p.addons.length > 0 && ' · إضافات'}</p>
                                                <div className="mt-2 flex items-center justify-between gap-1">
                                                    <span className="text-sm font-bold text-gray-900 num">{m(p.price)}</span>
                                                    <span className={plusChip}><Plus className="size-4" /></span>
                                                </div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                        {kind === 'memberships' && (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {visiblePlans.map((p) => (
                                    <ServiceTile key={p.id} icon={<Ticket className="size-5" />} title={p.name} lines={[p.duration, p.sessions ? `${p.sessions} حصة` : 'دخول غير محدود', Number(p.signup_fee) > 0 ? `رسوم تسجيل للعضو الجديد ${m(p.signup_fee)}` : null]} price={m(p.price)} selected={cart.some((l) => l.key === `membership-${p.id}`)} onClick={() => addPlan(p)} />
                                ))}
                                {visiblePlans.length === 0 && <p className="col-span-full py-16 text-center text-sm text-gray-400">لا نتائج لهذا البحث</p>}
                            </div>
                        )}
                        {kind === 'pt' && (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {visiblePacks.map((p) => (
                                    <ServiceTile key={p.id} icon={<Dumbbell className="size-5" />} title={p.name} lines={[`${p.sessions} جلسات`, p.trainer ? `المدرب: ${p.trainer}` : 'أي مدرب متاح']} price={m(p.price)} selected={cart.some((l) => l.key === `pt-${p.id}`)} onClick={() => addPack(p)} />
                                ))}
                                {visiblePacks.length === 0 && <p className="col-span-full py-16 text-center text-sm text-gray-400">لا نتائج لهذا البحث</p>}
                            </div>
                        )}
                    </div>
                </section>

                <aside id="pos-cart" className="flex w-full scroll-mt-20 flex-col rounded-2xl border border-gray-100 bg-white shadow-sm max-md:min-h-[20rem] md:min-h-0 md:w-[21rem] md:shrink-0 lg:w-1/3">
                    <div className="shrink-0 border-b border-gray-100 px-4 py-3">
                        <div className="flex items-center justify-between">
                            <p className="font-bold text-gray-800">الطلب</p>
                            <p className="text-xs text-gray-400"><span className="num">{count}</span> {count === 1 ? 'صنف' : 'أصناف'}</p>
                        </div>
                        <CustomerPicker member={member} guest={guest} onMember={setMember} onGuest={setGuest} canAddMember={can('members.manage')} />
                        {member && <MemberNotes member={member} currency={currency} />}
                    </div>

                    <div className="min-h-[7rem] flex-1 px-3 py-3 md:overflow-y-auto md:overscroll-contain">
                        {cart.length === 0 ? (
                            <div className="flex h-full flex-col items-center justify-center py-10 text-center">
                                <span className="mb-3 flex size-16 items-center justify-center rounded-2xl bg-gray-100 text-gray-400"><ShoppingCart className="size-8" /></span>
                                <p className="font-semibold text-gray-600">السلة فارغة</p>
                                <p className="mt-1 text-sm text-gray-400">اختر منتجًا أو عضوية أو باقة لإضافتها إلى الطلب</p>
                            </div>
                        ) : (
                            <ul className="space-y-2">
                                {cart.map((l) => (
                                    <li key={l.key} className="rounded-xl bg-gray-50 p-2.5">
                                        <div className="flex items-center gap-2.5">
                                            {l.kind === 'product' ? (
                                                l.product.image_url ? <img src={l.product.image_url} alt="" className="size-12 shrink-0 rounded-lg object-cover" /> : <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-400"><Package className="size-5" /></span>
                                            ) : (
                                                <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-[#f5f3ff] text-[#6d28d9]">{l.kind === 'membership' ? <Ticket className="size-5" /> : <Dumbbell className="size-5" />}</span>
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold text-gray-800">{l.kind === 'product' ? l.product.name : l.kind === 'membership' ? l.plan.name : l.pack.name}</p>
                                                {l.kind === 'product' && (
                                                    <>
                                                        <p className="text-xs font-medium text-gray-900 num">{m(unitPrice(l.product.price, l.addons, l.product.addons))}</p>
                                                        {describe(l.addons, l.product.addons).map((a) => <p key={a.id} className="truncate text-[11px] text-[#7c3aed]">+ {a.name}{a.qty > 1 && <span className="num"> ×{a.qty}</span>} · <span className="num">{m(Number(a.price) * a.qty)}</span></p>)}
                                                    </>
                                                )}
                                                {l.kind === 'membership' && <p className="text-xs text-gray-500">{member?.membership ? 'تجديد' : 'عضوية جديدة'} · {l.plan.duration}</p>}
                                                {l.kind === 'pt' && <p className="text-xs text-gray-500">تدريب شخصي · <span className="num">{l.pack.sessions}</span> جلسات</p>}
                                            </div>
                                            <button type="button" onClick={() => remove(l.key)} className="flex size-7 shrink-0 items-center justify-center rounded-full text-[#ef4444] hover:bg-[#fef2f2] touch:size-10" aria-label="حذف من السلة"><Trash2 className="size-4" /></button>
                                        </div>
                                        <div className="mt-2 flex items-center justify-between gap-2">
                                            {l.kind === 'product' ? (
                                                <div className="flex items-center gap-1 rounded-lg border border-gray-200 bg-white">
                                                    <button type="button" onClick={() => setQty(l.key, l.quantity - 1)} disabled={l.quantity <= 1} className="flex size-7 items-center justify-center text-gray-500 hover:text-gray-900 disabled:opacity-30 touch:size-11" aria-label={`إنقاص ${l.product.name}`}>−</button>
                                                    <span className="w-7 text-center text-sm font-bold text-gray-800 num touch:w-9 touch:text-base">{l.quantity}</span>
                                                    <button type="button" onClick={() => setQty(l.key, l.quantity + 1)} className="flex size-7 items-center justify-center text-gray-500 hover:text-gray-900 touch:size-11" aria-label={`زيادة ${l.product.name}`}>+</button>
                                                </div>
                                            ) : (
                                                <span className="text-[11px] text-gray-400">{l.kind === 'membership' && l.total === null && member === null ? 'يُحسب السعر عند اختيار العضو' : 'تُفعّل بعد الدفع'}</span>
                                            )}
                                            <span className="text-sm font-bold text-gray-800 num">{m(lineAmount(l))}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="min-h-0 shrink-0 space-y-2.5 overflow-y-auto overscroll-contain border-t border-gray-100 p-3">
                        <div className="flex items-center justify-between text-sm text-gray-600"><span>المجموع</span><span className="font-medium text-gray-800 num">{m(productsTotal + servicesTotal)}</span></div>
                        {allowed.discount && hasProducts && (discountValue > 0 ? (
                            <div className="flex items-center justify-between rounded-lg border border-[#059669]/20 bg-[#ecfdf5] px-3 py-2">
                                <span className="text-sm font-medium text-[#047857]">خصم على المنتجات (− <span className="num">{m(discountValue)}</span>)</span>
                                <button type="button" onClick={() => { setDiscount(''); setDiscountDraft(''); }} className="text-[#047857]" aria-label="إلغاء الخصم"><X className="size-4" /></button>
                            </div>
                        ) : (
                            <form className="flex items-center gap-2" onSubmit={(e) => { e.preventDefault(); setDiscount(discountDraft); }}>
                                <div className="relative flex-1">
                                    <TicketPercent className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                    <Input type="number" min="0" step="0.001" dir="ltr" value={discountDraft} onChange={(e) => setDiscountDraft(e.target.value)} placeholder="خصم على المنتجات" className="h-9 ps-8 touch:h-12" aria-label="الخصم" />
                                </div>
                                <Button type="submit" size="sm" disabled={!discountDraft}>تطبيق</Button>
                            </form>
                        ))}
                        <div className="flex items-center justify-between border-t border-dashed border-gray-200 pt-2">
                            <span className="font-bold text-gray-800">الإجمالي</span>
                            <span className="text-xl font-extrabold text-gray-900 num">{m(total)}</span>
                        </div>
                        {cart.length > 0 && (
                            <button type="button" onClick={() => { setCart([]); setDiscount(''); setDiscountDraft(''); }} className="flex w-full items-center justify-center gap-2 rounded-full bg-[#fef2f2] py-2 text-xs font-medium text-[#dc2626] transition-colors hover:bg-[#fee2e2] touch:py-3">
                                <Trash2 className="size-4" /> تفريغ السلة
                            </button>
                        )}
                    </div>
                    <div className="shrink-0 border-t border-gray-100 p-3">
                        <button type="button" onClick={openPayment} className="flex w-full items-center justify-center gap-2 rounded-full bg-gray-900 py-3.5 text-base font-bold text-white shadow-sm transition-colors hover:bg-gray-800">
                            <CreditCard className="size-5" /> الدفع · <span className="num">{m(total)}</span>
                        </button>
                    </div>
                </aside>
            </div>

            {/* Phones: the basket's count and total stay in reach at the foot; a tap goes down to the basket. */}
            <div className="fixed inset-x-0 bottom-0 z-20 border-t border-gray-100 bg-white/95 p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] backdrop-blur md:hidden">
                <button type="button" onClick={() => document.getElementById('pos-cart')?.scrollIntoView({ behavior: 'smooth' })} className="flex w-full items-center justify-between gap-2 rounded-full bg-gray-900 px-5 py-3 text-sm font-bold text-white">
                    <span className="flex items-center gap-2"><ShoppingCart className="size-5" /> السلة · <span className="num">{count}</span></span>
                    <span className="num">{m(total)}</span>
                </button>
            </div>

            {picking && (
                <ItemOptions product={picking} room={available(picking) - inCart(picking.id)} currency={currency} onClose={() => setPicking(null)} onAdd={(quantity, addons) => { addProduct(picking, quantity, addons, false); setPicking(null); scanRef.current?.focus(); }} />
            )}
            {(paying || result) && (
                <PaymentDialog
                    total={total}
                    cart={cart}
                    member={member}
                    guest={guest}
                    discount={discountValue}
                    basketKey={basketKey}
                    device={device}
                    allowed={allowed}
                    hasProducts={hasProducts}
                    result={result}
                    onResult={setResult}
                    onClose={() => { if (result) reset(); setPaying(false); setResult(null); }}
                />
            )}
        </PosLayout>
    );
}

/** A membership plan or a training package to sell: its name and terms, its price and the add chip. */
function ServiceTile({ icon, title, lines, price, selected, onClick }: { icon: ReactNode; title: string; lines: (string | null)[]; price: string; selected: boolean; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className={cn(tile, 'p-4', selected && 'border-gray-900 shadow-md')}>
            <div className="flex w-full items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[#f5f3ff] text-[#6d28d9]">{icon}</span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-gray-800">{title}</p>
                    {lines.filter(Boolean).map((line) => <p key={line} className="mt-0.5 truncate text-xs text-gray-400">{line}</p>)}
                </div>
            </div>
            <div className="mt-3 flex w-full items-center justify-between gap-1">
                <span className="text-sm font-bold text-gray-900 num">{price}</span>
                <span className={plusChip}>{selected ? <Check className="size-4" /> : <Plus className="size-4" />}</span>
            </div>
        </button>
    );
}

/** The customer of the basket: a member found by name, phone or number, or a walk-in customer by name. */
function CustomerPicker({ member, guest, onMember, onGuest, canAddMember }: { member: MemberCard | null; guest: string; onMember: (m: MemberCard | null) => void; onGuest: (v: string) => void; canAddMember: boolean }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<MemberHit[]>([]);

    useEffect(() => {
        if (!open || q.trim().length < 2) return setHits([]);
        const controller = new AbortController();
        const id = setTimeout(() => {
            fetch(route('club.members.search', { q }), { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((r) => (r.ok ? r.json() : []))
                .then(setHits)
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [q, open]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    const pick = async (hit: MemberHit) => {
        setOpen(false);
        setQ('');
        onGuest('');
        onMember({ ...hit, balance: '0' });
        const res = await fetch(route('club.pos.member', hit.id), { headers: { Accept: 'application/json' } }).catch(() => null);
        if (res?.ok) onMember(await res.json());
    };

    return (
        <div className="relative mt-3 flex items-center gap-2">
            <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} className="flex w-full min-w-0 items-center gap-2 rounded-full bg-gray-50 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">
                <User className="size-4 shrink-0 text-gray-900" />
                <span className="truncate">{member ? `${member.full_name} · ${member.member_no}` : guest ? `زائر: ${guest}` : 'عميل زائر'}</span>
                <ChevronDown className="ms-auto size-4 shrink-0 text-gray-400" />
            </button>
            {member ? (
                <button type="button" onClick={() => onMember(null)} className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200" aria-label="إزالة العضو"><X className="size-5" /></button>
            ) : (
                canAddMember && <a href={route('club.members.create')} target="_blank" rel="noreferrer" className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200" title="تسجيل عضو جديد" aria-label="تسجيل عضو جديد"><UserPlus className="size-5" /></a>
            )}
            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
                    <div className="absolute start-0 top-full z-20 mt-1 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-[12px] border border-gray-200 bg-white shadow-lg">
                        <div className="relative p-2">
                            <Search className="pointer-events-none absolute start-4 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                            <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="الاسم أو الهاتف أو رقم العضوية" className="ps-8" aria-label="بحث عن عضو" />
                        </div>
                        <div className="max-h-60 overflow-y-auto">
                            {hits.map((h) => (
                                <button key={h.id} type="button" onClick={() => pick(h)} className="flex w-full items-center justify-between gap-2 px-4 py-2 text-start text-sm hover:bg-gray-50">
                                    <span className="truncate">{h.full_name}</span>
                                    <span className="shrink-0 font-mono text-xs text-gray-400" dir="ltr">{h.phone_display}</span>
                                </button>
                            ))}
                            {q.trim().length >= 2 && hits.length === 0 && <p className="px-4 py-3 text-[13px] text-gray-400">لا أعضاء بهذا البحث</p>}
                        </div>
                        <div className="border-t border-gray-100 p-2">
                            <Input value={guest} onChange={(e) => onGuest(e.target.value)} placeholder="أو اسم العميل الزائر" maxLength={120} aria-label="اسم العميل الزائر" onKeyDown={(e) => e.key === 'Enter' && setOpen(false)} />
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

/** What the cashier should know about the chosen member: blocked, frozen, the membership's end, an unpaid balance. */
function MemberNotes({ member, currency }: { member: MemberCard; currency: string }) {
    const ms = member.membership;
    const notes: { tone: string; text: string }[] = [];
    if (member.status === 'blocked') notes.push({ tone: 'rounded-xl border border-[#fecaca] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]', text: 'حساب العضو موقوف — لا يُباع له حتى يُعاد تفعيله.' });
    if (ms?.status === 'frozen') notes.push({ tone: 'rounded-xl border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]', text: `العضوية مجمّدة (${ms.plan}).` });
    else if (ms) notes.push({ tone: 'rounded-xl bg-[#f5f3ff] px-3 py-2 text-[12px] font-medium text-[#6d28d9]', text: `${ms.plan} — حتى ${ms.ends_on} (${ms.days_left} يومًا متبقيًا)` });
    else notes.push({ tone: 'rounded-xl bg-gray-50 px-3 py-2 text-[12px] text-[#4b4b4b]', text: 'لا عضوية سارية — العضوية من هنا تبدأ اليوم.' });
    if (Number(member.balance) > 0) notes.push({ tone: 'rounded-xl border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]', text: `عليه مبالغ غير مسددة: ${money(member.balance, currency)}` });
    return <div className="mt-3 space-y-2">{notes.map((n) => <p key={n.text} className={n.tone}>{n.text}</p>)}</div>;
}

/** Paying the basket: the amount due, the method, cash given and change, then the receipts to print and a new sale. */
function PaymentDialog({ total, cart, member, guest, discount, basketKey, device, allowed, hasProducts, result, onResult, onClose }: {
    total: number;
    cart: Line[];
    member: MemberCard | null;
    guest: string;
    discount: number;
    basketKey: string;
    device: Device;
    allowed: Props['can'];
    hasProducts: boolean;
    result: Result | null;
    onResult: (r: Result) => void;
    onClose: () => void;
}) {
    const currency = useCurrency();
    const m = (v: string | number) => money(v, currency);
    const [method, setMethod] = useState<Method | null>(null);
    const [given, setGiven] = useState('');
    const [cardRef, setCardRef] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);
    const methods = useMemo(() => ([['cash', 'نقدًا', Banknote], ['card', 'بطاقة', CreditCard], ['paymob', 'رابط Paymob', Link2]] as const).filter(([k]) => k !== 'paymob' || (allowed.paymob && !hasProducts)), [allowed.paymob, hasProducts]);
    const change = method === 'cash' && given !== '' ? Math.round((Number(given) - total) * 1000) / 1000 : null;
    const quick = Array.from(new Set([total, Math.ceil(total), Math.ceil(total / 5) * 5, Math.ceil(total / 10) * 10, Math.ceil(total / 20) * 20])).filter((v) => v >= total).slice(0, 4);

    const receiptUrl = (d: Doc) => (d.kind === 'sale' ? route('club.sales.receipt', d.id) : d.payment_id ? route('club.payments.receipt', { payment: d.payment_id, face: 'strip' }) : null);

    const confirm = () => {
        if (!method) return setError('اختر طريقة الدفع');
        if (method === 'card' && cardRef.trim() === '') return setError('أدخل رقم العملية من جهاز الشبكة');
        if (method === 'cash' && change !== null && change < 0) return setError('المبلغ المستلم أقل من الإجمالي');
        setError(null);
        setBusy(true);
        // Opened now, while the click still counts, so a popup blocker lets the receipt through after the sale.
        const printer = device?.auto_print && allowed.print && method !== 'paymob' ? window.open('', '_blank') : null;
        router.post(
            route('club.pos.checkout'),
            {
                items: cart.flatMap((l) => (l.kind === 'product' ? [{ product_id: l.product.id, quantity: l.quantity, addons: payload(l.addons) }] : [])),
                memberships: cart.flatMap((l) => (l.kind === 'membership' ? [{ plan_id: l.plan.id }] : [])),
                packages: cart.flatMap((l) => (l.kind === 'pt' ? [{ package_id: l.pack.id }] : [])),
                payment_method: method,
                card_reference: method === 'card' ? cardRef.trim() : null,
                member_id: member?.id ?? null,
                customer_name: member ? null : guest.trim() || null,
                discount: discount || 0,
                client_key: basketKey,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const r = (page.props as { lastResult?: Result | null }).lastResult;
                    if (!r) return;
                    onResult(r);
                    const first = r.documents.map(receiptUrl).find(Boolean);
                    if (printer && first) printer.location.replace(first);
                    else if (printer) {
                        printer.close();
                    }
                },
                onError: (errs) => {
                    printer?.close();
                    setError(Object.values(errs)[0] ?? 'تعذّر إتمام البيع');
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Modal
            open
            onClose={busy ? () => undefined : onClose}
            title={result ? 'تمت العملية' : 'إتمام الدفع'}
            footer={
                result ? undefined : (
                    <Button variant="success" size="lg" className="w-full rounded-full touch:h-14 touch:text-base" loading={busy} onClick={confirm}>
                        {method === 'paymob' ? 'إصدار الفواتير ورابط الدفع' : 'تأكيد الدفع'}
                    </Button>
                )
            }
        >
            {result ? (
                <div className="space-y-4 text-center">
                    <span className="mx-auto flex size-20 items-center justify-center rounded-full bg-[#ecfdf5] text-[#059669]"><CheckCircle2 className="size-12" /></span>
                    <div className="space-y-2 rounded-2xl bg-gray-50 p-4 text-start text-sm">
                        {result.documents.map((d) => (
                            <div key={`${d.kind}-${d.id}`} className="flex items-center justify-between gap-3">
                                <span className="text-gray-500">{d.label} · <span className="num">{d.number}</span>{!d.paid && <span className="ms-1 text-[#d97706]">(بانتظار الدفع)</span>}</span>
                                <span className="font-bold text-[#111] num">{m(d.total)}</span>
                            </div>
                        ))}
                        <div className="flex justify-between border-t border-dashed border-gray-200 pt-2"><span className="text-gray-500">الإجمالي</span><span className="font-bold text-[#111] num">{m(result.total)}</span></div>
                        <div className="flex justify-between"><span className="text-gray-500">طريقة الدفع</span><span className="font-bold text-[#111]">{({ cash: 'نقدًا', card: 'بطاقة', paymob: 'رابط Paymob' } as Record<string, string>)[result.method]}</span></div>
                        {member && <div className="flex justify-between"><span className="text-gray-500">العضو</span><span className="font-bold text-[#111]">{member.full_name}</span></div>}
                    </div>
                    {result.documents.filter((d) => d.pay_url).map((d) => (
                        <div key={d.id} className="rounded-xl border border-[#e8e8e8] p-3 text-start">
                            <p className="mb-2 text-[13px] font-medium text-[#111]">رابط دفع {d.label} — تُفعّل بعد إشعار Paymob</p>
                            <div className="flex gap-2">
                                <Input readOnly value={d.pay_url ?? ''} dir="ltr" className="text-xs" onFocus={(e) => e.currentTarget.select()} />
                                <Button variant="outline" onClick={() => { navigator.clipboard?.writeText(d.pay_url ?? ''); toast.success('نُسخ الرابط'); }}>نسخ</Button>
                            </div>
                        </div>
                    ))}
                    <div className="grid grid-cols-2 gap-3">
                        {allowed.print && result.documents.map((d) => receiptUrl(d) && (
                            <Button key={`p-${d.kind}-${d.id}`} variant="outline" className="rounded-full" icon={<Printer />} href={receiptUrl(d) ?? undefined} external>
                                إيصال {d.label}
                            </Button>
                        ))}
                        {allowed.print && result.documents.some((d) => receiptUrl(d)) && (
                            <Button variant="outline" className="rounded-full" onClick={() => setPreview(result.documents.map(receiptUrl).find(Boolean) ?? null)}>معاينة الإيصال</Button>
                        )}
                        <Button className="col-span-2 rounded-full" icon={<Plus />} onClick={onClose}>عملية جديدة</Button>
                    </div>
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="rounded-2xl bg-gray-100 p-4 text-center">
                        <p className="text-sm text-[#111]">المبلغ المستحق</p>
                        <p className="mt-1 text-3xl font-extrabold text-[#111] num">{m(total)}</p>
                    </div>
                    <div>
                        <p className="label">طريقة الدفع <span className="text-[#dc2626]">*</span></p>
                        <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${methods.length}, minmax(0, 1fr))` }}>
                            {methods.map(([k, label, Icon]) => (
                                <button key={k} type="button" onClick={() => { setMethod(k); setError(null); }} className={cn('flex flex-col items-center gap-1 rounded-full border py-2.5 text-xs font-medium transition-colors touch:py-3.5', method === k ? 'border-[#111] bg-gray-100 text-[#111]' : 'border-gray-200 text-gray-600 hover:bg-gray-50')}>
                                    <Icon className="size-5" /> {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    {method === 'cash' && (
                        <div className="space-y-2">
                            <Field label="المبلغ المستلم">
                                <Input type="number" min="0" step="0.001" dir="ltr" value={given} onChange={(e) => setGiven(e.target.value)} className="h-12 text-center text-xl" autoFocus />
                            </Field>
                            <div className="flex flex-wrap gap-2">
                                {quick.map((v) => <button key={v} type="button" onClick={() => setGiven(String(v))} className="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-[13px] font-medium text-gray-700 hover:bg-gray-50 num">{m(v)}</button>)}
                            </div>
                            {change !== null && (
                                <div className={cn('flex items-center justify-between rounded-2xl p-3 text-sm', change < 0 ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#ecfdf5] text-[#047857]')}>
                                    <span>{change < 0 ? 'المتبقي على العميل' : 'الباقي للعميل'}</span>
                                    <span className="text-lg font-bold num">{m(Math.abs(change))}</span>
                                </div>
                            )}
                        </div>
                    )}
                    {method === 'card' && (
                        <Field label="رقم العملية من جهاز الشبكة" required hint="يُطبع على إيصال جهاز البطاقات">
                            <Input dir="ltr" value={cardRef} onChange={(e) => setCardRef(e.target.value)} autoFocus />
                        </Field>
                    )}
                    {method === 'paymob' && <p className="rounded-xl bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]">تصدر الفواتير ويظهر رابط دفع يرسله الموظف للعضو؛ تُفعّل العضوية أو الباقة تلقائيًا بعد إشعار Paymob الموقّع، لا قبله.</p>}
                    {error && <p className="text-[12px] text-[#b91c1c]">{error}</p>}
                </div>
            )}
            {preview && (
                <Modal open onClose={() => setPreview(null)} size="xl" title="معاينة الإيصال">
                    <iframe src={`${preview}${preview.includes('?') ? '&' : '?'}print=0`} title="الإيصال" className="h-[70vh] w-full rounded-[12px] border border-[#e8e8e8] bg-white" />
                </Modal>
            )}
        </Modal>
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
            size="md"
            title={product.name}
            footer={
                <div className="w-full">
                    <div className="mb-3 flex items-center justify-between text-sm"><span className="text-gray-600">الإجمالي</span><span className="text-base font-bold text-gray-900 num">{money(total, currency)}</span></div>
                    <Button size="lg" className="w-full" disabled={room < quantity} onClick={() => onAdd(quantity, addons)} icon={<Check />}>إضافة للسلة</Button>
                </div>
            }
        >
            <div className="space-y-5">
                <div className="flex items-center justify-between gap-3">
                    <p className="text-sm text-gray-500">السعر <span className="font-semibold text-ink num">{money(product.price, currency)}</span>{addons.length > 0 && <> · مع الإضافات <span className="font-semibold text-ink num">{money(unitPrice(product.price, addons, product.addons), currency)}</span></>}</p>
                    <Stepper value={quantity} onChange={setQuantity} min={1} max={Math.max(1, Math.min(room, 999))} label="الكمية" />
                </div>
                <div>
                    <p className="mb-2 text-[13px] font-semibold text-[#4b4b4b]">إضافات اختيارية <span className="font-normal text-gray-400">(لكل قطعة)</span></p>
                    <ul className="space-y-2">
                        {product.addons.map((a) => {
                            const qty = chosen[a.id] ?? 0;
                            return (
                                <li key={a.id} className={cn('flex items-center justify-between gap-3 rounded-[12px] border px-3 py-2', qty > 0 ? 'border-gray-900/30 bg-[#fafafa]' : 'border-gray-100', a.available === false && 'opacity-50')}>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{a.name}<span className="ms-2 text-xs font-bold text-[#7c3aed] num">+{money(a.price, currency)}</span></p>
                                        <p className="text-xs text-gray-400">{a.available === false ? <span className="text-red-600">نفدت</span> : a.max > 1 ? <>حتى <span className="num">{a.max}</span> لكل قطعة</> : 'واحدة لكل قطعة'}</p>
                                    </div>
                                    <Stepper size="sm" value={qty} onChange={(v) => setChosen((c) => ({ ...c, [a.id]: v }))} min={0} max={a.available === false ? 0 : a.max} label={a.name} />
                                </li>
                            );
                        })}
                    </ul>
                </div>
                {room < quantity && <p className="text-[12px] text-[#b91c1c]">الكمية المتوفرة غير كافية.</p>}
            </div>
        </Modal>
    );
}
