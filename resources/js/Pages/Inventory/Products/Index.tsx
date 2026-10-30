import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { History, Package, Pencil, Plus, Puzzle, Scale, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, PageHeader, Pagination, Select, Switch } from '@/Components/ui';
import { DropdownItem, DropdownSeparator } from '@/Components/ui/Dropdown';
import { ConfirmDelete, FilterMenu, ProductThumb, RowMenu, StatusTabs, StockBadge, Toolbar, ToolbarSearch, ViewToggle, useView, visit, type FilterDef } from '@/Components/Products/parts';
import { AdjustModal, MovementsModal } from '@/Components/Products/Stock';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { addonCount, money, number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

export interface ProductRow {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: string;
    cost: string;
    stock_quantity: string;
    track_stock: boolean;
    is_active: boolean;
    show_on_website: boolean;
    product_category_id: number | null;
    category: string | null;
    image_url: string | null;
    photos_count: number;
    low: boolean;
    out: boolean;
    margin: number | null;
    addons: number;
}

type Filters = { q: string; category: string; status: string; stock: string; website: string; sort: string };

const SORTS = [['name', 'الاسم'], ['newest', 'الأحدث'], ['price', 'الأعلى سعرًا'], ['stock', 'الأقل مخزونًا']] as const;

/**
 * The products tab: every product of the club as a table or as cards, searched and filtered, with its price, cost,
 * margin, stock and the two switches (available for sale, shown in the website shop). Adding and editing open the
 * product's own page.
 */
export default function ProductsIndex({ products, counts, categories, filters }: { products: Paginated<ProductRow>; counts: { all: number; active: number; inactive: number }; categories: Option[]; filters: Filters }) {
    const currency = useCurrency();
    const [view, setView] = useView('products');
    const [adjusting, setAdjusting] = useState<ProductRow | null>(null);
    const [history, setHistory] = useState<ProductRow | null>(null);
    const [deleting, setDeleting] = useState<ProductRow | null>(null);
    const go = (change: Partial<Filters>) => visit('club.products.index', filters, change);
    const toggle = (p: ProductRow, field: 'is_active' | 'show_on_website', value: boolean) => router.patch(route('club.products.toggle', p.id), { field, value }, { preserveScroll: true, preserveState: true });

    const filterDefs: FilterDef[] = [
        { key: 'category', label: 'التصنيف', options: [...categories.map((c) => ({ value: String(c.id), label: c.name })), { value: 'none', label: 'بدون تصنيف' }] },
        { key: 'stock', label: 'المخزون', options: [{ value: 'low', label: 'وصل لحد إعادة الطلب' }, { value: 'out', label: 'نفد' }, { value: 'untracked', label: 'غير متتبع' }] },
        { key: 'website', label: 'متجر الموقع', options: [{ value: '1', label: 'ظاهر في المتجر' }, { value: '0', label: 'غير ظاهر' }] },
    ];
    const filtered = Boolean(filters.q || filters.category || filters.stock || filters.website || filters.status);

    const actions = (p: ProductRow) => (
        <RowMenu label={`إجراءات ${p.name}`}>
            {(close) => (
                <>
                    <DropdownItem href={route('club.products.edit', p.id)}><Pencil /> تعديل</DropdownItem>
                    {p.track_stock && <DropdownItem onClick={() => { close(); setAdjusting(p); }}><Scale /> جرد المخزون</DropdownItem>}
                    <DropdownItem onClick={() => { close(); setHistory(p); }}><History /> حركة المخزون</DropdownItem>
                    <DropdownItem onClick={() => { close(); toggle(p, 'is_active', !p.is_active); }}>{p.is_active ? 'إيقاف البيع' : 'تفعيل البيع'}</DropdownItem>
                    <DropdownSeparator />
                    <DropdownItem destructive onClick={() => { close(); setDeleting(p); }}><Trash2 /> حذف</DropdownItem>
                </>
            )}
        </RowMenu>
    );

    return (
        <ClubLayout title="المنتجات">
            <PageHeader title="المنتجات" subtitle="منتجات النادي بأسعارها ومخزونها، وما يظهر منها في نقطة البيع ومتجر الموقع" actions={<Button icon={<Plus />} href={route('club.products.create')}>إضافة منتج</Button>} />

            <p className="mb-3 text-[12px] text-[#9ca3af]"><span className="num">{number(products.total)}</span> منتج{filtered ? ' مطابق' : ''}</p>

            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث بالاسم أو SKU أو الباركود" onSearch={(q) => go({ q })} />
                    <StatusTabs value={filters.status} counts={counts} labels={['متاح', 'موقوف']} onChange={(status) => go({ status })} />
                    <FilterMenu filters={filterDefs} values={filters} onChange={(key, value) => go({ [key]: value })} />
                    <div className="ms-auto flex items-center gap-1">
                        <Select className="h-9 w-36 py-1.5 text-[13px]" value={filters.sort} onChange={(e) => go({ sort: e.target.value === 'name' ? '' : e.target.value })} aria-label="الترتيب">
                            {SORTS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </Select>
                        <ViewToggle value={view} onChange={setView} />
                    </div>
                </Toolbar>

                {products.data.length === 0 ? (
                    <EmptyState
                        icon={<Package className="size-6" />}
                        title={filtered ? 'لا توجد منتجات مطابقة' : 'لا توجد منتجات بعد — أضف أول منتج'}
                        description={filtered ? 'غيّر البحث أو أزل بعض الفلاتر.' : 'المنتجات التي تضيفها هنا تظهر في نقطة البيع، وما تختاره منها يظهر في متجر الموقع.'}
                        action={!filtered ? <Button size="sm" href={route('club.products.create')} icon={<Plus />}>إضافة منتج</Button> : undefined}
                    />
                ) : view === 'table' ? (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>المنتج</th><th>التصنيف</th><th>السعر</th><th>التكلفة</th><th>الهامش</th><th>المخزون</th><th>متجر الموقع</th><th>متاح للبيع</th><th className="w-12"><span className="sr-only">إجراءات</span></th></tr>
                            </thead>
                            <tbody>
                                {products.data.map((p) => (
                                    <tr key={p.id} className={cn(!p.is_active && 'text-[#9ca3af]')}>
                                        <td>
                                            <Link href={route('club.products.edit', p.id)} className="flex items-center gap-3">
                                                <ProductThumb url={p.image_url} className={cn(!p.is_active && 'opacity-60')} />
                                                <span className="min-w-0">
                                                    <span className={cn('block max-w-56 truncate font-medium', p.is_active ? 'text-ink' : 'text-[#6b7280]')}>{p.name}</span>
                                                    <span className="flex items-center gap-2 text-[11px] text-[#9ca3af]">
                                                        <span className="num">{[p.sku, p.barcode].filter(Boolean).join(' · ') || '—'}</span>
                                                        {p.addons > 0 && <span className="inline-flex items-center gap-1"><Puzzle className="size-3" />{addonCount(p.addons)}</span>}
                                                    </span>
                                                </span>
                                            </Link>
                                        </td>
                                        <td className="text-[13px]">{p.category ?? <span className="text-[#9ca3af]">—</span>}</td>
                                        <td className="font-medium num">{money(p.price, currency)}</td>
                                        <td className="text-[#6b7280] num">{money(p.cost, currency)}</td>
                                        <td className={cn('num text-[13px]', p.margin === null ? 'text-[#9ca3af]' : p.margin < 0 ? 'text-[#b91c1c]' : p.margin < 10 ? 'text-[#d97706]' : 'text-[#4b4b4b]')}>{p.margin === null ? '—' : `${number(p.margin, 1)}٪`}</td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                {p.track_stock && <span className={cn('font-medium num', (p.low || p.out) && 'text-[#b91c1c]')}>{number(p.stock_quantity, Number(p.stock_quantity) % 1 ? 3 : 0)}</span>}
                                                <StockBadge track={p.track_stock} out={p.out} low={p.low} />
                                            </div>
                                        </td>
                                        <td><Switch size="sm" checked={p.show_on_website} onChange={(v) => toggle(p, 'show_on_website', v)} label={`إظهار ${p.name} في متجر الموقع`} disabled={!p.is_active} /></td>
                                        <td><Switch size="sm" checked={p.is_active} onChange={(v) => toggle(p, 'is_active', v)} label={`${p.name} متاح للبيع`} /></td>
                                        <td className="text-end">{actions(p)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {products.data.map((p) => (
                            <div key={p.id} className={cn('card flex h-full flex-col overflow-hidden', !p.is_active && 'opacity-70')}>
                                <div className="relative aspect-[4/3] overflow-hidden bg-[#f7f7f5]">
                                    {p.image_url ? <img src={p.image_url} alt="" loading="lazy" className="size-full object-cover" /> : <span className="grid size-full place-items-center text-[#c4c4c4]"><Package className="size-10" /></span>}
                                    <span className="absolute top-2.5 start-2.5"><StockBadge track={p.track_stock} out={p.out} low={p.low} /></span>
                                    <span className="absolute top-2 end-2 rounded-[10px] bg-white/90 backdrop-blur">{actions(p)}</span>
                                </div>
                                <div className="flex flex-1 flex-col p-3.5">
                                    <Link href={route('club.products.edit', p.id)} className="line-clamp-2 text-[14px] font-semibold text-ink hover:underline">{p.name}</Link>
                                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{p.category ?? 'بدون تصنيف'}{p.addons > 0 && ` · ${addonCount(p.addons)}`}</p>
                                    <div className="mt-auto flex items-end justify-between gap-2 pt-3">
                                        <span className="text-[15px] font-bold text-ink num">{money(p.price, currency)}</span>
                                        {p.track_stock && <span className={cn('text-[12px] num', p.low || p.out ? 'text-[#b91c1c]' : 'text-[#6b7280]')}>الكمية {number(p.stock_quantity, Number(p.stock_quantity) % 1 ? 3 : 0)}</span>}
                                    </div>
                                    <div className="mt-3 flex items-center justify-between gap-2 border-t border-line pt-3 text-[12px] text-[#6b7280]">
                                        <span className="flex items-center gap-2"><Switch size="sm" checked={p.is_active} onChange={(v) => toggle(p, 'is_active', v)} label={`${p.name} متاح للبيع`} /> متاح</span>
                                        <span className="flex items-center gap-2"><Switch size="sm" checked={p.show_on_website} onChange={(v) => toggle(p, 'show_on_website', v)} label={`إظهار ${p.name} في متجر الموقع`} disabled={!p.is_active} /> في المتجر</span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
                <Pagination data={products} />
            </Card>

            {adjusting && <AdjustModal product={adjusting} onClose={() => setAdjusting(null)} />}
            <MovementsModal product={history} onClose={() => setHistory(null)} currency={currency} />
            <ConfirmDelete target={deleting} url={deleting && route('club.products.destroy', deleting.id)} title={`حذف «${deleting?.name}»`} message="يختفي من نقطة البيع ومتجر الموقع، وتبقى مبيعاته وفواتيره وحركات مخزونه في السجلات والتقارير." onClose={() => setDeleting(null)} />
        </ClubLayout>
    );
}
