import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Check, History, ImagePlus, Plus, Puzzle, Scale, Star, Trash2, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Field, Input, PageHeader, Select, Switch, Tabs, Textarea, BackLink } from '@/Components/ui';
import { AdjustModal, MovementsModal } from '@/Components/Products/Stock';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Option } from '@/types';
import type { ProductRow } from './Index';

interface Addon {
    id: number;
    name: string;
    price: string;
    is_active: boolean;
    applies_to: 'all' | 'categories' | 'products';
    categories: number[];
    category_names: string[];
    linked: boolean;
}

interface Props {
    product: (ProductRow & { description: string | null; reorder_level: string; photos: string[] }) | null;
    categories: Option[];
    addons: Addon[];
    maxPhotos: number;
}

type Tab = 'basic' | 'pricing' | 'photos' | 'addons';

const TAB_FIELDS: Record<Tab, string[]> = {
    basic: ['name', 'description', 'product_category_id', 'category_name', 'sku', 'barcode'],
    pricing: ['price', 'reorder_level', 'opening_stock', 'opening_cost'],
    photos: ['photos'],
    addons: ['addon_ids'],
};

/** A product's own page, to add it or change it: details, price and stock, photos, and the add-ons that go with it. */
export default function ProductForm({ product, categories, addons, maxPhotos }: Props) {
    const currency = useCurrency();
    const [tab, setTab] = useState<Tab>('basic');
    const [newCategory, setNewCategory] = useState(false);
    const [adjusting, setAdjusting] = useState(false);
    const [history, setHistory] = useState(false);
    const form = useForm({
        name: product?.name ?? '',
        description: product?.description ?? '',
        product_category_id: product?.product_category_id ? String(product.product_category_id) : '',
        category_name: '',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        price: product?.price ?? '',
        reorder_level: product ? String(Number(product.reorder_level)) : '0',
        track_stock: product?.track_stock ?? true,
        is_active: product?.is_active ?? true,
        show_on_website: product?.show_on_website ?? false,
        opening_stock: '',
        opening_cost: '',
        photos: [] as File[],
        addon_ids: addons.filter((a) => a.linked).map((a) => a.id),
        link_addons: true,
    });

    const previews = useMemo(() => form.data.photos.map((f) => URL.createObjectURL(f)), [form.data.photos]);
    useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews]);

    const existing = product?.photos ?? [];
    const room = maxPhotos - existing.length - form.data.photos.length;
    const cost = product ? Number(product.cost) : Number(form.data.opening_cost || 0);
    const price = Number(form.data.price || 0);
    const margin = price > 0 ? ((price - cost) / price) * 100 : null;
    const categoryId = form.data.product_category_id ? Number(form.data.product_category_id) : null;
    const automatic = addons.filter((a) => a.applies_to === 'all' || (a.applies_to === 'categories' && categoryId !== null && a.categories.includes(categoryId)));
    const chosen = addons.filter((a) => a.applies_to === 'products');
    const hasError = (t: Tab) => TAB_FIELDS[t].some((f) => Object.keys(form.errors).some((e) => e === f || e.startsWith(`${f}.`)));

    // A failed save opens the first tab with a mistake in it.
    useEffect(() => {
        const first = (Object.keys(TAB_FIELDS) as Tab[]).find(hasError);
        if (first) setTab(first);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.errors]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { forceFormData: true, preserveScroll: true };
        if (product) {
            form.transform((d) => ({ ...d, opening_stock: '', opening_cost: '', _method: 'put' }));
            form.post(route('club.products.update', product.id), options);
        } else {
            form.post(route('club.products.store'), options);
        }
    };

    const addPhotos = (files: FileList | null) => {
        if (!files) return;
        form.setData('photos', [...form.data.photos, ...Array.from(files)].slice(0, maxPhotos - existing.length));
    };
    const photoAction = (index: number, action: 'main' | 'remove') => product && router.post(route('club.products.photo', { product: product.id, index }), { action }, { preserveScroll: true, preserveState: true });
    const toggleAddon = (id: number) => form.setData('addon_ids', form.data.addon_ids.includes(id) ? form.data.addon_ids.filter((x) => x !== id) : [...form.data.addon_ids, id]);

    const tabLabel = (t: Tab, text: string) => <>{text}{hasError(t) && <span className="size-1.5 rounded-full bg-[#dc2626]" aria-label="فيه خطأ" />}</>;

    return (
        <ClubLayout title={product ? `تعديل ${product.name}` : 'إضافة منتج'}>
            <div className="mx-auto max-w-4xl">
                <PageHeader
                    back={<BackLink href={route('club.products.index')}>المنتجات</BackLink>}
                    title={product ? product.name : 'إضافة منتج'}
                    subtitle={product ? 'عدّل بيانات المنتج وسعره ومخزونه وصوره وإضافاته' : 'منتج جديد يظهر في نقطة البيع، وفي متجر الموقع إن اخترت ذلك'}
                    actions={product && (
                        <>
                            <Button variant="outline" icon={<History />} onClick={() => setHistory(true)}>حركة المخزون</Button>
                            {product.track_stock && <Button variant="outline" icon={<Scale />} onClick={() => setAdjusting(true)}>جرد</Button>}
                        </>
                    )}
                />

                <form onSubmit={submit}>
                    <Tabs
                        className="mb-6"
                        value={tab}
                        onChange={setTab}
                        tabs={[
                            { key: 'basic', label: tabLabel('basic', 'المعلومات الأساسية') },
                            { key: 'pricing', label: tabLabel('pricing', 'التسعير والمخزون') },
                            { key: 'photos', label: tabLabel('photos', 'صور المنتج'), count: existing.length + form.data.photos.length || null },
                            { key: 'addons', label: tabLabel('addons', 'الإضافات'), count: automatic.length + form.data.addon_ids.length || null },
                        ]}
                    />

                    {tab === 'basic' && (
                        <div className="space-y-6">
                            <Card className="p-6">
                                <h3 className="mb-4 font-bold text-ink">المعلومات الأساسية</h3>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label="اسم المنتج" error={form.errors.name} required className="md:col-span-2">
                                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={160} autoFocus={!product} />
                                    </Field>
                                    <Field label="الوصف" error={form.errors.description} hint="يظهر للعملاء في صفحة المنتج بمتجر الموقع" className="md:col-span-2">
                                        <Textarea rows={3} maxLength={2000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                                    </Field>
                                    <Field label="التصنيف" error={form.errors.product_category_id || form.errors.category_name}>
                                        {newCategory ? (
                                            <div className="flex gap-2">
                                                <Input value={form.data.category_name} onChange={(e) => form.setData('category_name', e.target.value)} placeholder="اسم التصنيف الجديد" maxLength={80} autoFocus />
                                                <Button type="button" variant="outline" icon={<X />} aria-label="إلغاء التصنيف الجديد" onClick={() => { setNewCategory(false); form.setData('category_name', ''); }} />
                                            </div>
                                        ) : (
                                            <div className="flex gap-2">
                                                <Select value={form.data.product_category_id} onChange={(e) => form.setData('product_category_id', e.target.value)} placeholder="بدون تصنيف">
                                                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                                </Select>
                                                <Button type="button" variant="outline" icon={<Plus />} aria-label="تصنيف جديد" title="تصنيف جديد" onClick={() => setNewCategory(true)} />
                                            </div>
                                        )}
                                    </Field>
                                    <div className="grid grid-cols-2 gap-4">
                                        <Field label="SKU" error={form.errors.sku}><Input dir="ltr" value={form.data.sku} onChange={(e) => form.setData('sku', e.target.value)} maxLength={60} /></Field>
                                        <Field label="الباركود" error={form.errors.barcode}><Input dir="ltr" value={form.data.barcode} onChange={(e) => form.setData('barcode', e.target.value)} maxLength={60} /></Field>
                                    </div>
                                </div>
                            </Card>

                            <Card className="p-6">
                                <h3 className="mb-2 font-bold text-ink">الحالة</h3>
                                <div className="divide-y divide-line">
                                    <SwitchRow checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="متاح للبيع" hint="يظهر في نقطة البيع ويمكن بيعه. أوقفه لإخفائه دون حذفه." />
                                    <SwitchRow checked={form.data.show_on_website} onChange={(v) => form.setData('show_on_website', v)} label="عرضه في متجر الموقع" hint="يظهر في متجر موقع النادي ويمكن طلبه أونلاين (إذا كان متاحًا للبيع)." />
                                </div>
                            </Card>
                        </div>
                    )}

                    {tab === 'pricing' && (
                        <div className="space-y-6">
                            <Card className="p-6">
                                <h3 className="mb-4 font-bold text-ink">التسعير</h3>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label={`سعر البيع (${currency})`} error={form.errors.price} required>
                                        <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />
                                    </Field>
                                    {product ? (
                                        <Field label="متوسط التكلفة" hint="يُحسب تلقائيًا من المشتريات والرصيد الافتتاحي">
                                            <Input dir="ltr" value={money(product.cost, currency)} readOnly disabled />
                                        </Field>
                                    ) : (
                                        <Field label={`تكلفة الوحدة (${currency})`} error={form.errors.opening_cost} hint="للرصيد الافتتاحي؛ تُحدَّث بعد ذلك من المشتريات">
                                            <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.opening_cost} onChange={(e) => form.setData('opening_cost', e.target.value)} />
                                        </Field>
                                    )}
                                </div>
                                {margin !== null && (
                                    <p className="mt-4 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] text-[#4b4b4b]">
                                        هامش الربح: <span className={cn('font-semibold num', margin < 0 ? 'text-[#b91c1c]' : margin < 10 ? 'text-[#d97706]' : 'text-ink')}>{number(margin, 1)}٪</span>
                                        <span className="text-[#9ca3af]"> · الربح في القطعة <span className="num">{money(price - cost, currency)}</span></span>
                                    </p>
                                )}
                            </Card>

                            <Card className="p-6">
                                <h3 className="mb-4 font-bold text-ink">المخزون</h3>
                                <div className="rounded-[12px] border border-line p-4">
                                    <SwitchRow checked={form.data.track_stock} onChange={(v) => form.setData('track_stock', v)} label="ربط المنتج بالمخزون" hint="عند تفعيله لا يُباع أكثر من المتوفر وتُسجَّل كل حركة. أوقفه للخدمات والمنتجات غير المخزنة." />
                                    {form.data.track_stock && (
                                        <div className="mt-4 grid gap-4 border-t border-line pt-4 md:grid-cols-2">
                                            {product ? (
                                                <Field label="الكمية الحالية" hint="تتغير بالبيع والشراء والجرد فقط">
                                                    <div className="flex gap-2">
                                                        <Input dir="ltr" value={number(product.stock_quantity, 3)} readOnly disabled />
                                                        <Button type="button" variant="outline" icon={<Scale />} onClick={() => setAdjusting(true)}>جرد</Button>
                                                    </div>
                                                </Field>
                                            ) : (
                                                <Field label="الرصيد الافتتاحي" error={form.errors.opening_stock}>
                                                    <Input type="number" step="1" min="0" dir="ltr" value={form.data.opening_stock} onChange={(e) => form.setData('opening_stock', e.target.value)} />
                                                </Field>
                                            )}
                                            <Field label="حد إعادة الطلب" error={form.errors.reorder_level} hint="تنبيه عندما يصل المخزون لهذا الحد">
                                                <Input type="number" step="1" min="0" dir="ltr" value={form.data.reorder_level} onChange={(e) => form.setData('reorder_level', e.target.value)} />
                                            </Field>
                                        </div>
                                    )}
                                </div>
                            </Card>
                        </div>
                    )}

                    {tab === 'photos' && (
                        <Card className="p-6">
                            <h3 className="mb-1 font-bold text-ink">صور المنتج</h3>
                            <p className="mb-4 text-[13px] text-muted">حتى <span className="num">{maxPhotos}</span> صور (JPG أو PNG أو WebP). الصورة الرئيسية تظهر في القوائم ونقطة البيع، والباقي في صفحة المنتج بالمتجر.</p>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                                {existing.map((url, i) => (
                                    <div key={url} className={cn('group relative aspect-square overflow-hidden rounded-[12px] border border-line', i === 0 && 'ring-2 ring-[#111]/15')}>
                                        <img src={url} alt="" className="size-full object-cover" />
                                        {i === 0 && <span className="absolute top-2 start-2 inline-flex items-center gap-1 rounded-full bg-[#111] px-2 py-0.5 text-[11px] font-medium text-white"><Star className="size-3" /> الرئيسية</span>}
                                        <div className="absolute inset-x-0 bottom-0 flex justify-center gap-1 bg-black/50 p-1.5 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                                            {i > 0 && <button type="button" onClick={() => photoAction(i, 'main')} className="rounded-[8px] px-2 py-1 text-[12px] text-white hover:bg-white/20">اجعلها الرئيسية</button>}
                                            <button type="button" onClick={() => photoAction(i, 'remove')} className="grid size-7 place-items-center rounded-[8px] text-white hover:bg-white/20" aria-label="حذف الصورة"><Trash2 className="size-4" /></button>
                                        </div>
                                    </div>
                                ))}
                                {previews.map((url, i) => (
                                    <div key={url} className="relative aspect-square overflow-hidden rounded-[12px] border border-dashed border-[#d1d5db]">
                                        <img src={url} alt="" className="size-full object-cover" />
                                        <span className="absolute top-2 start-2 rounded-full bg-white/90 px-2 py-0.5 text-[11px] text-[#4b4b4b]">{existing.length === 0 && i === 0 ? 'الرئيسية — ' : ''}تُرفع عند الحفظ</span>
                                        <button type="button" onClick={() => form.setData('photos', form.data.photos.filter((_, j) => j !== i))} className="absolute top-2 end-2 grid size-7 place-items-center rounded-full bg-white/90 text-ink hover:bg-white" aria-label="إزالة الصورة"><X className="size-4" /></button>
                                    </div>
                                ))}
                                {room > 0 && (
                                    <label className="grid aspect-square cursor-pointer place-items-center rounded-[12px] border-2 border-dashed border-line bg-[#fafafa] text-center text-[13px] text-muted transition hover:border-[#9ca3af] hover:text-ink">
                                        <span><ImagePlus className="mx-auto mb-2 size-6" />أضف صورًا</span>
                                        <input type="file" accept="image/jpeg,image/png,image/webp" multiple className="sr-only" onChange={(e) => { addPhotos(e.target.files); e.target.value = ''; }} />
                                    </label>
                                )}
                            </div>
                            {(form.errors.photos || Object.entries(form.errors).find(([k]) => k.startsWith('photos.'))?.[1]) && <p className="mt-3 text-xs text-red-600">{form.errors.photos || Object.entries(form.errors).find(([k]) => k.startsWith('photos.'))?.[1]}</p>}
                        </Card>
                    )}

                    {tab === 'addons' && (
                        <Card className="p-6">
                            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 className="font-bold text-ink">إضافات المنتج</h3>
                                    <p className="mt-0.5 text-[13px] text-muted">ما يمكن للعميل إضافته مع هذا المنتج في نقطة البيع ومتجر الموقع. الأسعار والتفاصيل تُدار من تبويب الإضافات.</p>
                                </div>
                                <Button variant="outline" size="sm" href={route('club.product-addons.index')} icon={<Puzzle />}>إدارة الإضافات</Button>
                            </div>

                            {addons.length === 0 ? (
                                <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-muted">لا توجد إضافات بعد. أنشئها من تبويب «الإضافات» ثم اربطها بهذا المنتج من هنا.</p>
                            ) : (
                                <div className="space-y-5">
                                    {chosen.length > 0 && (
                                        <div>
                                            <p className="mb-2 text-[13px] font-semibold text-[#4b4b4b]">إضافات تُختار لكل منتج — اختر ما يناسب هذا المنتج</p>
                                            <div className="flex flex-wrap gap-2">
                                                {chosen.map((a) => {
                                                    const on = form.data.addon_ids.includes(a.id);
                                                    return (
                                                        <button key={a.id} type="button" onClick={() => toggleAddon(a.id)} aria-pressed={on} className={cn('inline-flex items-center gap-2 rounded-[10px] border px-3 py-2 text-[13px] transition', on ? 'border-[#111] bg-[#111] text-white' : 'border-line bg-white text-ink hover:bg-[#fafafa]', !a.is_active && 'opacity-60')}>
                                                            {on ? <Check className="size-4" /> : <Plus className="size-4 text-[#9ca3af]" />}
                                                            {a.name}
                                                            <span className={cn('num', on ? 'text-white/70' : 'text-[#9ca3af]')}>+{money(a.price, currency)}</span>
                                                            {!a.is_active && <span className="text-[11px]">(موقوفة)</span>}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                    {automatic.length > 0 && (
                                        <div>
                                            <p className="mb-2 text-[13px] font-semibold text-[#4b4b4b]">تُطبَّق تلقائيًا على هذا المنتج</p>
                                            <ul className="flex flex-wrap gap-2">
                                                {automatic.map((a) => (
                                                    <li key={a.id} className={cn('inline-flex items-center gap-2 rounded-[8px] bg-[#f3f3f1] px-2.5 py-1.5 text-[12px] text-[#4b4b4b]', !a.is_active && 'opacity-60')}>
                                                        {a.name} <span className="num text-[#9ca3af]">+{money(a.price, currency)}</span>
                                                        <Badge tone="gray" className="py-0">{a.applies_to === 'all' ? 'كل المنتجات' : `تصنيف ${a.category_names.join('، ')}`}</Badge>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                    {chosen.length === 0 && automatic.length === 0 && <p className="text-[13px] text-muted">لا توجد إضافات تنطبق على هذا المنتج.</p>}
                                </div>
                            )}
                        </Card>
                    )}

                    <Card className="mt-6 flex flex-col-reverse gap-3 p-4 sm:flex-row sm:justify-end">
                        <Button variant="outline" href={route('club.products.index')} className="sm:w-32">إلغاء</Button>
                        <Button type="submit" loading={form.processing} className="sm:w-40">{product ? 'حفظ التعديلات' : 'إضافة المنتج'}</Button>
                    </Card>
                </form>
            </div>

            {product && adjusting && <AdjustModal product={product} onClose={() => setAdjusting(false)} />}
            <MovementsModal product={product && history ? product : null} onClose={() => setHistory(false)} currency={currency} />
        </ClubLayout>
    );
}

function SwitchRow({ checked, onChange, label, hint }: { checked: boolean; onChange: (v: boolean) => void; label: string; hint: string }) {
    return (
        <div className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
            <div>
                <p className="text-sm font-medium text-ink">{label}</p>
                <p className="mt-0.5 text-[12px] leading-5 text-muted">{hint}</p>
            </div>
            <Switch checked={checked} onChange={onChange} label={label} />
        </div>
    );
}
