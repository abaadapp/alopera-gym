import { useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, FolderTree, Package, Pencil, Plus, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Field, Input, Modal, PageHeader } from '@/Components/ui';
import { DropdownItem, DropdownSeparator } from '@/Components/ui/Dropdown';
import { ConfirmDelete, ProductThumb, RowMenu } from '@/Components/Products/parts';
import { number } from '@/lib/format';

interface Category {
    id: number;
    name: string;
    products: number;
    active: number;
    website: number;
    addons: number;
    sample: { name: string; image_url: string | null }[];
}

/**
 * The categories tab: the club's product categories in its own order (the order of the point of sale and the shop),
 * what is in each, renaming, and removing one that nothing uses.
 */
export default function CategoriesIndex({ categories, uncategorised }: { categories: Category[]; uncategorised: number }) {
    const [editing, setEditing] = useState<Category | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Category | null>(null);

    const move = (index: number, by: -1 | 1) => {
        const ids = categories.map((c) => c.id);
        [ids[index], ids[index + by]] = [ids[index + by], ids[index]];
        router.post(route('club.product-categories.reorder'), { ids }, { preserveScroll: true, preserveState: true });
    };

    return (
        <ClubLayout title="التصنيفات">
            <PageHeader title="التصنيفات" subtitle="تصنيفات المنتجات بترتيب ظهورها في نقطة البيع ومتجر الموقع" actions={<Button icon={<Plus />} onClick={() => setEditing('new')}>تصنيف جديد</Button>} />

            <p className="mb-3 text-[12px] text-[#9ca3af]"><span className="num">{number(categories.length)}</span> تصنيف</p>

            <Card padded={false} className="overflow-hidden">
                {categories.length === 0 ? (
                    <EmptyState icon={<FolderTree className="size-6" />} title="لا توجد تصنيفات بعد" description="رتّب منتجاتك في تصنيفات (مكملات، مشروبات، ملابس…) لتسهل على الموظف والعميل الوصول إليها." action={<Button size="sm" icon={<Plus />} onClick={() => setEditing('new')}>تصنيف جديد</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th className="w-20">الترتيب</th><th>التصنيف</th><th>المنتجات</th><th>في متجر الموقع</th><th>إضافات خاصة به</th><th className="w-12"><span className="sr-only">إجراءات</span></th></tr></thead>
                            <tbody>
                                {categories.map((c, i) => (
                                    <tr key={c.id}>
                                        <td>
                                            <div className="flex items-center gap-0.5">
                                                <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="grid size-7 place-items-center rounded-[8px] text-[#6b7280] hover:bg-ink/[0.045] hover:text-ink disabled:opacity-30" aria-label={`رفع ${c.name}`}><ArrowUp className="size-4" /></button>
                                                <button type="button" disabled={i === categories.length - 1} onClick={() => move(i, 1)} className="grid size-7 place-items-center rounded-[8px] text-[#6b7280] hover:bg-ink/[0.045] hover:text-ink disabled:opacity-30" aria-label={`خفض ${c.name}`}><ArrowDown className="size-4" /></button>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-3">
                                                <div className="flex -space-x-3 rtl:space-x-reverse">
                                                    {c.sample.length ? c.sample.slice(0, 3).map((p, j) => <ProductThumb key={j} url={p.image_url} className="size-9 ring-2 ring-white" />) : <ProductThumb url={null} className="size-9" />}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="font-medium text-ink">{c.name}</p>
                                                    <p className="max-w-72 truncate text-[11px] text-[#9ca3af]">{c.sample.map((p) => p.name).join('، ') || 'لا منتجات فيه بعد'}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="text-[13px]"><span className="font-medium num">{number(c.products)}</span>{c.products !== c.active && <span className="text-[#9ca3af]"> (<span className="num">{number(c.active)}</span> متاح)</span>}</td>
                                        <td className="text-[13px] num">{number(c.website)}</td>
                                        <td className="text-[13px] num">{c.addons ? number(c.addons) : <span className="text-[#9ca3af]">—</span>}</td>
                                        <td className="text-end">
                                            <RowMenu label={`إجراءات ${c.name}`}>
                                                {(close) => (
                                                    <>
                                                        <DropdownItem href={route('club.products.index', { category: c.id })}><Eye /> عرض المنتجات</DropdownItem>
                                                        <DropdownItem onClick={() => { close(); setEditing(c); }}><Pencil /> تعديل الاسم</DropdownItem>
                                                        <DropdownSeparator />
                                                        <DropdownItem destructive onClick={() => { close(); setDeleting(c); }}><Trash2 /> حذف</DropdownItem>
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
                {uncategorised > 0 && (
                    <Link href={route('club.products.index', { category: 'none' })} className="flex items-center gap-2 border-t border-line px-4 py-3 text-[13px] text-muted hover:bg-[#fafafa] hover:text-ink">
                        <Package className="size-4" /> <span className="num">{number(uncategorised)}</span> منتج بدون تصنيف — عرضها
                    </Link>
                )}
            </Card>

            {editing && <CategoryModal category={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            <ConfirmDelete
                target={deleting}
                url={deleting && !deleting.products && !deleting.addons ? route('club.product-categories.destroy', deleting.id) : null}
                title={`حذف «${deleting?.name}»`}
                message="يُحذف التصنيف نهائيًا. لا يتأثر أي منتج لأنه فارغ."
                onClose={() => setDeleting(null)}
            />
            {deleting && (deleting.products > 0 || deleting.addons > 0) && (
                <Modal open onClose={() => setDeleting(null)} size="sm" title={`لا يمكن حذف «${deleting.name}»`} footer={<><Button variant="outline" onClick={() => setDeleting(null)}>حسنًا</Button>{deleting.products > 0 && <Button href={route('club.products.index', { category: deleting.id })}>عرض منتجاته</Button>}</>}>
                    <p className="text-sm leading-7 text-[#4b4b4b]">
                        التصنيف مستخدم في {[deleting.products ? `${number(deleting.products)} منتج` : '', deleting.addons ? `${number(deleting.addons)} إضافة` : ''].filter(Boolean).join(' و')}.
                        انقل المنتجات إلى تصنيف آخر وغيّر الإضافات المرتبطة به أولًا، حتى لا تفقد المنتجات تصنيفها.
                    </p>
                </Modal>
            )}
        </ClubLayout>
    );
}

function CategoryModal({ category, onClose }: { category: Category | null; onClose: () => void }) {
    const form = useForm({ name: category?.name ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (category) form.put(route('club.product-categories.update', category.id), options);
        else form.post(route('club.product-categories.store'), options);
    };

    return (
        <Modal open onClose={onClose} size="sm" title={category ? 'تعديل التصنيف' : 'تصنيف جديد'} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="category-form" loading={form.processing}>حفظ</Button></>}>
            <form id="category-form" onSubmit={submit}>
                <Field label="اسم التصنيف" error={form.errors.name} required>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={80} placeholder="مثل: المكملات الغذائية" autoFocus />
                </Field>
            </form>
        </Modal>
    );
}
