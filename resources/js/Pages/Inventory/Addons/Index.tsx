import { useMemo, useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Globe, Monitor, Package, Pencil, Plus, Puzzle, Search, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, Field, Input, Modal, PageHeader, Select, Switch, Textarea } from '@/Components/ui';
import { DropdownItem, DropdownSeparator } from '@/Components/ui/Dropdown';
import { ConfirmDelete, FilterMenu, RowMenu, StatusTabs, Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { fold, money, number } from '@/lib/format';
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

type ProductOption = { id: number; name: string; category: string | null; is_active: boolean; track_stock: boolean };
type Filters = { q: string; status: string; channel: string };

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

/**
 * The add-ons tab: every extra a customer can buy with a product, its price, the products it goes with, where it is
 * offered and the stock it uses up. The one place add-ons are created and changed (a product's page only links or
 * unlinks those offered on chosen products).
 */
export default function AddonsIndex({ addons, counts, products, categories, filters }: Props) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<Addon | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Addon | null>(null);
    const go = (change: Partial<Filters>) => visit('club.product-addons.index', filters, change);
    const toggle = (a: Addon, field: 'is_active' | 'show_in_pos' | 'show_on_website', value: boolean) => router.patch(route('club.product-addons.toggle', a.id), { field, value }, { preserveScroll: true, preserveState: true });
    const filtered = Boolean(filters.q || filters.status || filters.channel);

    return (
        <ClubLayout title="الإضافات">
            <PageHeader title="الإضافات" subtitle="ما يضيفه العميل إلى المنتج بسعر إضافي: سكوب بروتين، موز، زبدة فول سوداني، نكهة…" actions={<Button icon={<Plus />} onClick={() => setEditing('new')}>إضافة جديدة</Button>} />

            <p className="mb-3 text-[12px] text-[#9ca3af]"><span className="num">{number(addons.length)}</span> إضافة{filtered ? ' مطابقة' : ''}</p>

            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث باسم الإضافة" onSearch={(q) => go({ q })} />
                    <StatusTabs value={filters.status} counts={counts} onChange={(status) => go({ status })} />
                    <FilterMenu filters={[{ key: 'channel', label: 'مكان الظهور', options: [{ value: 'pos', label: 'نقطة البيع' }, { value: 'website', label: 'متجر الموقع' }] }]} values={filters} onChange={(key, value) => go({ [key]: value })} />
                </Toolbar>

                {addons.length === 0 ? (
                    <EmptyState
                        icon={<Puzzle className="size-6" />}
                        title={filtered ? 'لا توجد إضافات مطابقة' : 'لا توجد إضافات بعد'}
                        description={filtered ? 'غيّر البحث أو أزل الفلتر.' : 'أنشئ إضافة واختر المنتجات التي تظهر معها؛ يختارها الموظف في نقطة البيع والعميل في متجر الموقع.'}
                        action={!filtered ? <Button size="sm" icon={<Plus />} onClick={() => setEditing('new')}>إضافة جديدة</Button> : undefined}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الإضافة</th><th>السعر</th><th>تظهر مع</th><th>تخصم من المخزون</th><th>بيعت</th><th>نقطة البيع</th><th>متجر الموقع</th><th>مفعّلة</th><th className="w-12"><span className="sr-only">إجراءات</span></th></tr></thead>
                            <tbody>
                                {addons.map((a) => (
                                    <tr key={a.id} className={cn(!a.is_active && 'text-[#9ca3af]')}>
                                        <td>
                                            <button type="button" onClick={() => setEditing(a)} className="flex items-center gap-3 text-start">
                                                <span className="grid size-11 shrink-0 place-items-center rounded-[8px] border border-line bg-[#f7f7f5] text-[#6b7280]"><Puzzle className="size-4" /></span>
                                                <span className="min-w-0">
                                                    <span className={cn('block max-w-56 truncate font-medium', a.is_active ? 'text-ink' : 'text-[#6b7280]')}>{a.name}</span>
                                                    <span className="block max-w-56 truncate text-[11px] text-[#9ca3af]">{a.description || (a.max_quantity > 1 ? `حتى ${number(a.max_quantity)} لكل قطعة` : 'واحدة لكل قطعة')}</span>
                                                </span>
                                            </button>
                                        </td>
                                        <td className="font-medium num">+{money(a.price, currency)}</td>
                                        <td className="text-[13px]">
                                            <span className="block max-w-56 truncate">{scopeText(a)}</span>
                                            {a.applies_to !== 'all' && <span className="text-[11px] text-[#9ca3af]">{a.applies_to === 'categories' ? `${number(a.category_ids.length)} تصنيف` : `${number(a.product_ids.length)} منتج`}</span>}
                                        </td>
                                        <td className="text-[13px]">
                                            {a.stock_product ? (
                                                <>
                                                    <span className="block max-w-44 truncate">{a.stock_product}</span>
                                                    <span className="text-[11px] text-[#9ca3af]"><span className="num">{number(a.stock_usage, 3)}</span> لكل إضافة{a.stock_left !== null && <> · المتبقي <span className="num">{number(a.stock_left, 3)}</span></>}</span>
                                                </>
                                            ) : <span className="text-[#9ca3af]">لا</span>}
                                        </td>
                                        <td className="text-[13px] num">{number(a.sold, a.sold % 1 ? 3 : 0)}</td>
                                        <td><Switch size="sm" checked={a.show_in_pos} onChange={(v) => toggle(a, 'show_in_pos', v)} label={`${a.name} في نقطة البيع`} /></td>
                                        <td><Switch size="sm" checked={a.show_on_website} onChange={(v) => toggle(a, 'show_on_website', v)} label={`${a.name} في متجر الموقع`} /></td>
                                        <td><Switch size="sm" checked={a.is_active} onChange={(v) => toggle(a, 'is_active', v)} label={`${a.name} مفعّلة`} /></td>
                                        <td className="text-end">
                                            <RowMenu label={`إجراءات ${a.name}`}>
                                                {(close) => (
                                                    <>
                                                        <DropdownItem onClick={() => { close(); setEditing(a); }}><Pencil /> تعديل</DropdownItem>
                                                        <DropdownSeparator />
                                                        <DropdownItem destructive onClick={() => { close(); setDeleting(a); }}><Trash2 /> حذف</DropdownItem>
                                                    </>
                                                )}
                                            </RowMenu>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {editing && <AddonDialog addon={editing === 'new' ? null : editing} products={products} categories={categories} onClose={() => setEditing(null)} />}
            <ConfirmDelete target={deleting} url={deleting && route('club.product-addons.destroy', deleting.id)} title={`حذف «${deleting?.name}»`} message="تختفي من نقطة البيع ومتجر الموقع، وتبقى في المبيعات والفواتير السابقة باسمها وسعرها كما بيعت." onClose={() => setDeleting(null)} />
        </ClubLayout>
    );
}

function AddonDialog({ addon, products, categories, onClose }: { addon: Addon | null; products: ProductOption[]; categories: Option[]; onClose: () => void }) {
    const currency = useCurrency();
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

    const shown = useMemo(() => {
        const q = fold(search.trim());
        return products.filter((p) => !q || fold(p.name).includes(q) || (p.category && fold(p.category).includes(q)));
    }, [products, search]);

    const pick = (key: 'product_ids' | 'category_ids', id: number) => form.setData(key, form.data[key].includes(id) ? form.data[key].filter((x) => x !== id) : [...form.data[key], id]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, stock_product_id: usesStock ? d.stock_product_id || null : null, stock_usage: usesStock ? d.stock_usage : null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (addon) form.put(route('club.product-addons.update', addon.id), options);
        else form.post(route('club.product-addons.store'), options);
    };

    const choice = (on: boolean) => cn('flex-1 rounded-[10px] border px-3 py-2 text-[13px] font-medium transition', on ? 'border-[#111] bg-[#111] text-white' : 'border-line bg-white text-ink hover:bg-[#fafafa]');

    return (
        <Modal open onClose={onClose} size="lg" title={addon ? `تعديل «${addon.name}»` : 'إضافة جديدة'} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="addon-form" loading={form.processing}>حفظ</Button></>}>
            <form id="addon-form" onSubmit={submit} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-[1fr_9rem_8rem]">
                    <Field label="اسم الإضافة" error={form.errors.name} required>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={120} placeholder="مثل: سكوب بروتين إضافي" autoFocus={!addon} />
                    </Field>
                    <Field label={`السعر (${currency})`} error={form.errors.price} required>
                        <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />
                    </Field>
                    <Field label="الحد لكل قطعة" error={form.errors.max_quantity}>
                        <Input type="number" step="1" min="1" max="20" dir="ltr" value={form.data.max_quantity} onChange={(e) => form.setData('max_quantity', e.target.value)} />
                    </Field>
                </div>
                <Field label="الوصف (اختياري)" error={form.errors.description} hint="يظهر للعميل تحت اسم الإضافة في متجر الموقع">
                    <Textarea rows={2} maxLength={500} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </Field>

                <div>
                    <p className="label">تظهر مع</p>
                    <div className="flex flex-wrap gap-2">
                        {SCOPES.map(([key, label]) => <button key={key} type="button" onClick={() => form.setData('applies_to', key)} aria-pressed={form.data.applies_to === key} className={choice(form.data.applies_to === key)}>{label}</button>)}
                    </div>
                    {form.data.applies_to === 'categories' && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {categories.length === 0 && <p className="text-[13px] text-muted">لا توجد تصنيفات بعد. أنشئها من تبويب «التصنيفات».</p>}
                            {categories.map((c) => {
                                const on = form.data.category_ids.includes(c.id);
                                return <button key={c.id} type="button" onClick={() => pick('category_ids', c.id)} aria-pressed={on} className={cn('rounded-full border px-3 py-1.5 text-[13px] transition', on ? 'border-[#111] bg-[#111] text-white' : 'border-line hover:bg-[#fafafa]')}>{c.name}</button>;
                            })}
                        </div>
                    )}
                    {form.data.applies_to === 'products' && (
                        <div className="mt-3 rounded-[12px] border border-line">
                            <label className="flex items-center gap-2 border-b border-line px-3">
                                <Search className="size-4 text-[#9ca3af]" />
                                <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث عن منتج" aria-label="ابحث عن منتج" className="h-10 w-full border-0 bg-transparent p-0 text-sm focus:outline-none focus:ring-0" />
                                <span className="shrink-0 text-[12px] text-[#9ca3af]"><span className="num">{number(form.data.product_ids.length)}</span> مختار</span>
                            </label>
                            <ul className="max-h-48 overflow-y-auto p-1.5">
                                {shown.length === 0 && <li className="px-2 py-3 text-center text-[13px] text-muted">لا توجد منتجات</li>}
                                {shown.map((p) => (
                                    <li key={p.id}>
                                        <label className="flex cursor-pointer items-center gap-2.5 rounded-[8px] px-2 py-1.5 text-sm hover:bg-[#f7f7f5]">
                                            <input type="checkbox" className="size-4 accent-[#111]" checked={form.data.product_ids.includes(p.id)} onChange={() => pick('product_ids', p.id)} />
                                            <span className={cn('flex-1', !p.is_active && 'text-[#9ca3af]')}>{p.name}</span>
                                            {p.category && <span className="text-[11px] text-[#9ca3af]">{p.category}</span>}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {(form.errors.product_ids || form.errors.category_ids) && <p className="mt-1.5 text-xs text-red-600">{form.errors.product_ids || form.errors.category_ids}</p>}
                </div>

                <div>
                    <p className="label">هل تخصم من المخزون؟</p>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setUsesStock(false)} aria-pressed={!usesStock} className={choice(!usesStock)}>لا</button>
                        <button type="button" onClick={() => setUsesStock(true)} aria-pressed={usesStock} className={choice(usesStock)}>نعم، من منتج في المخزون</button>
                    </div>
                    {usesStock && (
                        <div className="mt-3 grid gap-4 sm:grid-cols-[1fr_10rem]">
                            <Field label="المنتج الذي تُخصم منه" error={form.errors.stock_product_id}>
                                <Select value={form.data.stock_product_id} onChange={(e) => form.setData('stock_product_id', e.target.value)} placeholder="اختر منتجًا">
                                    {products.filter((p) => p.track_stock).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                </Select>
                            </Field>
                            <Field label="الكمية لكل إضافة" error={form.errors.stock_usage} hint="مثل 1 قطعة، أو 0.030 من العبوة">
                                <Input type="number" step="0.001" min="0.001" dir="ltr" value={form.data.stock_usage} onChange={(e) => form.setData('stock_usage', e.target.value)} />
                            </Field>
                        </div>
                    )}
                </div>

                <div className="divide-y divide-line rounded-[12px] border border-line px-4">
                    {([
                        ['show_in_pos', 'تظهر في نقطة البيع', 'يختارها الموظف عند بيع المنتج', Monitor],
                        ['show_on_website', 'تظهر في متجر الموقع', 'يختارها العميل في صفحة المنتج بالمتجر', Globe],
                        ['is_active', 'مفعّلة', 'أوقفها لإخفائها من كل مكان دون حذفها', Package],
                    ] as const).map(([key, label, hint, Icon]) => (
                        <div key={key} className="flex items-center justify-between gap-4 py-3">
                            <span className="flex items-start gap-3">
                                <Icon className="mt-0.5 size-4 text-[#6b7280]" />
                                <span><span className="block text-sm font-medium">{label}</span><span className="block text-[12px] text-muted">{hint}</span></span>
                            </span>
                            <Switch checked={form.data[key]} onChange={(v) => form.setData(key, v)} label={label} />
                        </div>
                    ))}
                </div>
                {addon && addon.sold > 0 && <Badge tone="blue">بيعت <span className="num">{number(addon.sold, addon.sold % 1 ? 3 : 0)}</span> مرة؛ تغيير السعر لا يغيّر المبيعات السابقة</Badge>}
            </form>
        </Modal>
    );
}
