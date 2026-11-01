import { useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Globe, Monitor, Plus, Power, Puzzle, Search } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { clientKey } from '@/Components/Inventory/parts';
import { Badge, Button, Card, DataTable, Field, Input, Modal, PageHeader, RowActions, Select, type Column, type TableFilter } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { currencyLabel, fold, money, number } from '@/lib/format';
import type { Option } from '@/types';

type Scope = 'all' | 'categories' | 'products';

interface Addon {
    id: number;
    name: string;
    description: string | null;
    price: string;
    max_quantity: number;
    applies_to: Scope;
    product_ids: number[];
    product_names: string[];
    category_ids: number[];
    category_names: string[];
    show_in_pos: boolean;
    show_on_website: boolean;
    is_active: boolean;
    stock_product_id: number | null;
    stock_product: string | null;
    stock_usage: string;
    stock_left: string | null;
    sold: number;
}

type ProductOption = { id: number; name: string; category: string | null; is_active: boolean; track_stock: boolean; stock: number | null };
type Filters = { q: string; status: string; channel: string; sort: string; dir: string };
type Toggle = 'is_active' | 'show_in_pos' | 'show_on_website';

interface Props {
    addons: Addon[];
    counts: { all: number; active: number; inactive: number };
    products: ProductOption[];
    categories: Option[];
    filters: Filters;
}

const SCOPES: [Scope, string][] = [['all', 'جميع المنتجات'], ['categories', 'تصنيفات محددة'], ['products', 'منتجات محددة']];

/** Which products an add-on goes with, in a few words. */
function scopeText(a: Addon): string {
    if (a.applies_to === 'all') return 'جميع المنتجات';
    const names = a.applies_to === 'categories' ? a.category_names : a.product_names;
    const head = names.slice(0, 2).join('، ');
    return names.length > 2 ? `${head} و${number(names.length - 2)} أخرى` : head || '—';
}

/** Where it is offered: a chip for the point of sale and one for the website shop, or a word when it is in neither. */
function Where({ a }: { a: Addon }) {
    const chip = 'inline-flex items-center gap-1 rounded-full bg-[#f2f2f0] px-2 py-0.5 text-[12px] whitespace-nowrap text-[#4b4b4b] [&_svg]:size-3 [&_svg]:shrink-0';
    if (!a.show_in_pos && !a.show_on_website) return <span className="text-[12px] text-[#9ca3af]">لا تظهر للبيع</span>;
    return (
        <span className="flex flex-wrap gap-1">
            {a.show_in_pos && <span className={chip}><Monitor /> نقطة البيع</span>}
            {a.show_on_website && <span className={chip}><Globe /> متجر الموقع</span>}
        </span>
    );
}

const Tile = () => <span className="grid size-11 shrink-0 place-items-center rounded-[8px] border border-line bg-[#f7f7f5] text-[#6b7280]"><Puzzle className="size-4" /></span>;

/**
 * The add-ons tab: every extra a customer can buy with a product, its price, the products it goes with, where it is
 * offered and the stock it uses up — in the panel's list (search, «أضف فلتر», «ترتيب», status tabs, a card per add-on on
 * phones and tablets). The one place add-ons are created and changed (a product's page only links or unlinks those
 * offered on chosen products). Switching one off, or out of the point of sale or the shop, is in its ⋮ menu.
 */
export default function AddonsIndex({ addons, products, categories, filters }: Props) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<Addon | 'new' | null>(null);
    const toggle = (a: Addon, field: Toggle, value: boolean) => router.patch(route('club.product-addons.toggle', a.id), { field, value }, { preserveScroll: true, preserveState: true });
    const filtered = Boolean(filters.q || filters.status || filters.channel);

    const actions = (a: Addon) => (
        <RowActions
            label={a.name}
            edit={() => setEditing(a)}
            extra={[
                { label: a.is_active ? 'إيقاف الإضافة' : 'تفعيل الإضافة', icon: <Power />, onSelect: () => toggle(a, 'is_active', !a.is_active) },
                { label: a.show_in_pos ? 'إخفاء من نقطة البيع' : 'إظهار في نقطة البيع', icon: <Monitor />, onSelect: () => toggle(a, 'show_in_pos', !a.show_in_pos) },
                { label: a.show_on_website ? 'إخفاء من متجر الموقع' : 'إظهار في متجر الموقع', icon: <Globe />, onSelect: () => toggle(a, 'show_on_website', !a.show_on_website) },
            ]}
            destroy={{ url: route('club.product-addons.destroy', a.id), message: `تُحذف «${a.name}» من نقطة البيع ومتجر الموقع، وتبقى في المبيعات والفواتير السابقة باسمها وسعرها كما بيعت.` }}
        />
    );
    const status = (a: Addon) => <Badge tone={a.is_active ? 'green' : 'gray'}>{a.is_active ? 'مفعّلة' : 'موقوفة'}</Badge>;
    const stock = (a: Addon) => (a.stock_product ? (
        <>
            <span className="block max-w-44 truncate">{a.stock_product}</span>
            <span className="text-[11px] text-[#9ca3af]"><span className="num">{number(a.stock_usage, 3)}</span> لكل إضافة{a.stock_left !== null && <> · المتبقي <span className="num">{number(a.stock_left, 3)}</span></>}</span>
        </>
    ) : <span className="text-[#9ca3af]">لا</span>);

    const columns: Column<Addon>[] = [
        {
            key: 'name',
            header: 'الإضافة',
            cell: (a) => (
                <button type="button" onClick={() => setEditing(a)} className="flex items-center gap-3 text-start">
                    <Tile />
                    <span className="min-w-0">
                        <span className={cn('block max-w-56 truncate font-medium', a.is_active ? 'text-ink' : 'text-[#6b7280]')}>{a.name}</span>
                        <span className="block max-w-56 truncate text-[11px] text-[#9ca3af]">{a.description || (a.max_quantity > 1 ? `حتى ${number(a.max_quantity)} لكل قطعة` : 'واحدة لكل قطعة')}</span>
                    </span>
                </button>
            ),
        },
        { key: 'price', header: 'السعر', cell: (a) => <span className="font-semibold text-ink num">{money(a.price, currency)}</span> },
        {
            key: 'scope',
            header: 'تظهر مع',
            cell: (a) => (
                <span className="text-[13px]">
                    <span className="block max-w-56 truncate">{scopeText(a)}</span>
                    {a.applies_to !== 'all' && <span className="text-[11px] text-[#9ca3af]">{a.applies_to === 'categories' ? `${number(a.category_ids.length)} تصنيف` : `${number(a.product_ids.length)} منتج`}</span>}
                </span>
            ),
        },
        { key: 'stock', header: 'تخصم من المخزون', cell: (a) => <span className="text-[13px]">{stock(a)}</span> },
        { key: 'sold', header: 'بيعت', cell: (a) => <span className="text-[13px] num">{number(a.sold, a.sold % 1 ? 3 : 0)}</span> },
        { key: 'where', header: 'مكان الظهور', cell: (a) => <Where a={a} /> },
        { key: 'status', header: 'الحالة', cell: status },
        { key: 'actions', header: 'إجراءات', align: 'end', cell: actions },
    ];

    // Phones, tablets and small laptops: one card per add-on — name and price, what it goes with, where and its state.
    const card = (a: Addon) => (
        <div className="relative flex items-start gap-3 px-4 py-3">
            <Tile />
            <div className="min-w-0 flex-1">
                <div className="flex items-start gap-2">
                    <button type="button" onClick={() => setEditing(a)} className={cn('min-w-0 flex-1 truncate text-start text-sm font-medium after:absolute after:inset-0', a.is_active ? 'text-ink' : 'text-[#6b7280]')}>{a.name}</button>
                    <span className="shrink-0 text-sm font-semibold text-ink num">{money(a.price, currency)}</span>
                </div>
                <p className="mt-0.5 truncate text-[12px] text-[#9ca3af]">مع {scopeText(a)}{a.stock_product && <> · تخصم من {a.stock_product}</>}</p>
                <div className="mt-2 flex flex-wrap items-center gap-1.5">{status(a)}<Where a={a} /></div>
            </div>
            <div className="relative z-10 -my-1 shrink-0">{actions(a)}</div>
        </div>
    );

    const tableFilters: TableFilter[] = [
        { param: 'status', label: 'الحالة', tabs: true, options: [{ value: 'active', label: 'مفعّلة', dot: '#059669' }, { value: 'inactive', label: 'موقوفة', dot: '#9ca3af' }] },
        { param: 'channel', label: 'مكان الظهور', options: [{ value: 'pos', label: 'نقطة البيع' }, { value: 'website', label: 'متجر الموقع' }] },
    ];

    return (
        <ClubLayout title="الإضافات">
            <PageHeader title="الإضافات" subtitle="ما يضيفه العميل إلى المنتج بسعر إضافي: سكوب بروتين، موز، زبدة فول سوداني، نكهة…" actions={<Button icon={<Plus />} onClick={() => setEditing('new')}>إضافة جديدة</Button>} />

            <p className="mb-3 text-[12px] text-[#9ca3af]"><span className="num">{number(addons.length)}</span> إضافة{filtered ? ' مطابقة' : ''}</p>

            <Card padded={false} className="overflow-hidden">
                <DataTable
                    rows={addons}
                    columns={columns}
                    card={card}
                    rowKey={(a) => a.id}
                    params={filters}
                    search={{ placeholder: 'ابحث باسم الإضافة…' }}
                    filters={tableFilters}
                    sorts={[{ key: 'name', label: 'الاسم' }, { key: 'price', label: 'السعر' }]}
                    empty={
                        <span className="flex flex-col items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-full bg-[#f2f2f0] text-[#6b7280]"><Puzzle className="size-5" /></span>
                            <span className="text-sm font-medium text-ink">لا توجد إضافات بعد</span>
                            <span className="max-w-sm text-[13px]">أنشئ إضافة واختر المنتجات التي تظهر معها؛ يختارها الموظف في نقطة البيع وفواتير العملاء، والعميل في متجر الموقع.</span>
                            <Button size="sm" icon={<Plus />} onClick={() => setEditing('new')}>إضافة جديدة</Button>
                        </span>
                    }
                />
            </Card>

            {editing && <AddonDialog addon={editing === 'new' ? null : editing} products={products} categories={categories} currency={currency} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

/** A question over its choices, as the panel's dialogs ask it. */
function Ask({ label, hint, children }: { label: string; hint?: ReactNode; children: ReactNode }) {
    return (
        <div>
            <span className="mb-1.5 block text-[13px] font-medium text-ink">{label}</span>
            {children}
            {hint && <p className="mt-1.5 text-[12px] text-[#9ca3af]">{hint}</p>}
        </div>
    );
}

function Choice({ on, onClick, children }: { on: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={on} className={cn('flex flex-1 items-center justify-center gap-1.5 rounded-[10px] border px-3 py-2 text-[13px] font-medium transition-colors [&_svg]:size-4', on ? 'border-[#111] bg-[#111] text-white' : 'border-line bg-white text-[#4b4b4b] hover:bg-[#f7f7f5]')}>
            {children}
        </button>
    );
}

function AddonDialog({ addon, products, categories, currency, onClose }: { addon: Addon | null; products: ProductOption[]; categories: Option[]; currency: string; onClose: () => void }) {
    const [search, setSearch] = useState('');
    const form = useForm({
        name: addon?.name ?? '',
        description: addon?.description ?? '',
        price: addon?.price ?? '',
        max_quantity: String(addon?.max_quantity ?? 1),
        applies_to: (addon?.applies_to ?? 'products') as Scope,
        product_ids: addon?.product_ids ?? [],
        category_ids: addon?.category_ids ?? [],
        show_in_pos: addon?.show_in_pos ?? true,
        show_on_website: addon?.show_on_website ?? true,
        is_active: addon?.is_active ?? true,
        stock_product_id: addon?.stock_product_id ? String(addon.stock_product_id) : '',
        stock_usage: addon ? String(Number(addon.stock_usage)) : '1',
    });
    const [usesStock, setUsesStock] = useState(Boolean(addon?.stock_product_id));
    const d = form.data;
    // One key while the dialog is open: «حفظ» clicked twice adds one add-on (SubmittedOnce).
    const key = useRef(clientKey());

    const shown = useMemo(() => {
        const q = fold(search.trim());
        return products.filter((p) => !q || fold(p.name).includes(q) || (p.category && fold(p.category).includes(q)));
    }, [products, search]);

    const pick = (key: 'product_ids' | 'category_ids', id: number) => form.setData(key, d[key].includes(id) ? d[key].filter((x) => x !== id) : [...d[key], id]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((x) => ({ ...x, client_key: key.current, stock_product_id: usesStock ? x.stock_product_id || null : null, stock_usage: usesStock ? x.stock_usage : null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (addon) form.put(route('club.product-addons.update', addon.id), options);
        else form.post(route('club.product-addons.store'), options);
    };

    return (
        <Modal open onClose={onClose} size="md" title={addon ? 'تعديل إضافة' : 'إضافة جديدة'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button type="submit" form="addon-form" loading={form.processing}>حفظ</Button></>}>
            <form id="addon-form" onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="الاسم" error={form.errors.name} required>
                        <Input value={d.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={120} placeholder="سكوب بروتين إضافي" autoFocus={!addon} />
                    </Field>
                    <Field label={`السعر (${currencyLabel(currency)})`} error={form.errors.price} required>
                        <Input inputMode="decimal" dir="ltr" value={d.price} onChange={(e) => form.setData('price', e.target.value)} placeholder="0.000" />
                    </Field>
                </div>
                <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-3">
                    <Field label="الحد لكل قطعة" error={form.errors.max_quantity}>
                        <Input inputMode="numeric" dir="ltr" value={d.max_quantity} onChange={(e) => form.setData('max_quantity', e.target.value)} />
                    </Field>
                    <Field label="الوصف" error={form.errors.description}>
                        <Input value={d.description} onChange={(e) => form.setData('description', e.target.value)} maxLength={500} placeholder="يظهر للعميل في متجر الموقع (اختياري)" />
                    </Field>
                </div>

                <Ask label="هل تخصم هذه الإضافة من المخزون؟">
                    <div className="flex gap-2">
                        <Choice on={!usesStock} onClick={() => setUsesStock(false)}>لا، خدمة أو رسوم فقط</Choice>
                        <Choice on={usesStock} onClick={() => setUsesStock(true)}>نعم، مرتبطة بصنف</Choice>
                    </div>
                </Ask>
                {usesStock && (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="الصنف المخزني" error={form.errors.stock_product_id} required>
                            <Select value={d.stock_product_id} onChange={(e) => form.setData('stock_product_id', e.target.value)} placeholder="اختر الصنف…">
                                {products.filter((p) => p.track_stock).map((p) => <option key={p.id} value={p.id}>{p.stock === null ? p.name : `${p.name} (${number(p.stock, p.stock % 1 ? 3 : 0)})`}</option>)}
                            </Select>
                        </Field>
                        <Field label="الكمية المستهلكة" error={form.errors.stock_usage}>
                            <Input inputMode="decimal" dir="ltr" value={d.stock_usage} onChange={(e) => form.setData('stock_usage', e.target.value)} />
                        </Field>
                        <p className="col-span-2 -mt-1 text-[12px] text-[#9ca3af]">مثال: «إضافة موز» تخصم موزة واحدة من صنف الموز؛ «سكوب بروتين» تخصم 0.030 من عبوة البروتين.</p>
                    </div>
                )}

                <Ask label="تظهر مع">
                    <div className="flex gap-2">
                        {SCOPES.map(([key, label]) => <Choice key={key} on={d.applies_to === key} onClick={() => form.setData('applies_to', key)}>{label}</Choice>)}
                    </div>
                </Ask>
                {d.applies_to === 'categories' && (
                    <div className="flex flex-wrap gap-2">
                        {categories.length === 0 && <p className="text-[13px] text-[#9ca3af]">لا توجد تصنيفات بعد. أنشئها من تبويب «التصنيفات».</p>}
                        {categories.map((c) => {
                            const on = d.category_ids.includes(c.id);
                            return <button key={c.id} type="button" onClick={() => pick('category_ids', c.id)} aria-pressed={on} className={cn('rounded-full border px-3 py-1.5 text-[13px] transition-colors', on ? 'border-[#111] bg-[#111] text-white' : 'border-line text-[#4b4b4b] hover:bg-[#f7f7f5]')}>{c.name}</button>;
                        })}
                    </div>
                )}
                {d.applies_to === 'products' && (
                    <div className="rounded-[12px] border border-line">
                        <label className="flex items-center gap-2 border-b border-line px-3 py-2">
                            <Search className="size-4 shrink-0 text-[#9ca3af]" />
                            <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث عن منتج…" aria-label="ابحث عن منتج" className="w-full border-0 bg-transparent p-0 text-[13px] focus:outline-none focus:ring-0" />
                            <span className="shrink-0 text-[12px] text-[#9ca3af]"><span className="num">{number(d.product_ids.length)}</span> مختار</span>
                        </label>
                        <div className="max-h-40 overflow-y-auto p-1">
                            {shown.length === 0 ? (
                                <p className="p-3 text-[13px] text-[#9ca3af]">لا نتائج</p>
                            ) : shown.map((p) => (
                                <label key={p.id} className="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-1.5 hover:bg-[#f7f7f5]">
                                    <input type="checkbox" className="size-4 accent-[#111]" checked={d.product_ids.includes(p.id)} onChange={() => pick('product_ids', p.id)} />
                                    <span className={cn('flex-1 text-[13px]', p.is_active ? 'text-ink' : 'text-[#9ca3af]')}>{p.name}</span>
                                    {p.category && <span className="text-[11px] text-[#9ca3af]">{p.category}</span>}
                                </label>
                            ))}
                        </div>
                    </div>
                )}
                {(form.errors.product_ids || form.errors.category_ids) && <p className="-mt-2 text-[12px] text-[#b91c1c]">{form.errors.product_ids || form.errors.category_ids}</p>}

                <Ask label="أين تظهر؟" hint="يختارها الموظف عند بيع المنتج في نقطة البيع وفي فاتورة العميل، والعميل في صفحة المنتج بمتجر الموقع.">
                    <div className="flex gap-2">
                        <Choice on={d.show_in_pos} onClick={() => form.setData('show_in_pos', !d.show_in_pos)}><Monitor /> نقطة البيع</Choice>
                        <Choice on={d.show_on_website} onClick={() => form.setData('show_on_website', !d.show_on_website)}><Globe /> متجر الموقع</Choice>
                    </div>
                </Ask>

                <Ask label="الحالة" hint={d.is_active ? undefined : 'موقوفة: لا تظهر في أي مكان، وتبقى محفوظة لتفعيلها لاحقًا.'}>
                    <div className="flex gap-2">
                        <Choice on={d.is_active} onClick={() => form.setData('is_active', true)}>مفعّلة</Choice>
                        <Choice on={!d.is_active} onClick={() => form.setData('is_active', false)}>موقوفة</Choice>
                    </div>
                </Ask>

                {addon && addon.sold > 0 && <p className="text-[12px] text-[#6b7280]">بيعت <span className="num">{number(addon.sold, addon.sold % 1 ? 3 : 0)}</span> مرة؛ تغيير السعر لا يغيّر المبيعات السابقة.</p>}
            </form>
        </Modal>
    );
}
