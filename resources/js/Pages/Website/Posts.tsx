import { useMemo, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { ImagePlus, Newspaper, Pencil, Plus, Tag, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Tabs, Textarea, Toggle } from '@/Components/ui';
import { date } from '@/lib/format';

interface Post {
    id: number;
    type: 'news' | 'offer';
    title: string;
    slug: string;
    excerpt: string | null;
    body: string | null;
    is_published: boolean;
    image_url: string | null;
    starts_on: string | null;
    ends_on: string | null;
    published_at: string | null;
}

type PostForm = {
    type: string;
    title: string;
    excerpt: string;
    body: string;
    starts_on: string;
    ends_on: string;
    is_published: boolean;
    image: File | null;
};

export default function Posts({ posts }: { posts: Post[] }) {
    const [tab, setTab] = useState<'all' | 'offer' | 'news'>('all');
    const [editing, setEditing] = useState<Post | 'new' | null>(null);
    const list = posts.filter((p) => tab === 'all' || p.type === tab);

    return (
        <ClubLayout title="الأخبار والعروض">
            <PageHeader
                title="الأخبار والعروض"
                subtitle="ما يُنشر هنا يظهر في الموقع (العروض تختفي تلقائيًا بعد تاريخ انتهائها)"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>إضافة</Button>}
            />

            <Tabs
                className="mb-5"
                value={tab}
                onChange={setTab}
                tabs={[
                    { key: 'all', label: 'الكل', count: posts.length },
                    { key: 'offer', label: 'العروض', count: posts.filter((p) => p.type === 'offer').length },
                    { key: 'news', label: 'الأخبار', count: posts.filter((p) => p.type === 'news').length },
                ]}
            />

            {list.length === 0 ? (
                <Card>
                    <EmptyState icon={<Newspaper className="size-6" />} title="لا يوجد محتوى بعد" action={<Button size="sm" onClick={() => setEditing('new')}>إضافة خبر أو عرض</Button>} />
                </Card>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {list.map((p) => (
                        <Card key={p.id} padded={false} className="overflow-hidden">
                            <div className="aspect-[16/9] bg-brand-50">
                                {p.image_url ? <img src={p.image_url} alt="" className="h-full w-full object-cover" loading="lazy" /> : <div className="grid h-full place-items-center text-brand-300"><Newspaper className="size-8" /></div>}
                            </div>
                            <div className="p-4">
                                <div className="mb-2 flex flex-wrap items-center gap-1.5">
                                    <Badge tone={p.type === 'offer' ? 'brand' : 'blue'}>{p.type === 'offer' ? <><Tag className="size-3.5" /> عرض</> : 'خبر'}</Badge>
                                    {p.is_published ? <Badge tone="green">منشور</Badge> : <Badge>مسودة</Badge>}
                                    {p.ends_on && <span className="text-xs text-muted">حتى {date(p.ends_on)}</span>}
                                </div>
                                <h3 className="font-semibold">{p.title}</h3>
                                {p.excerpt && <p className="mt-1 line-clamp-2 text-sm text-muted">{p.excerpt}</p>}
                                <div className="mt-3 flex gap-1">
                                    <Button size="sm" variant="outline" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(p)}>تعديل</Button>
                                    <ConfirmAction url={route('club.website.posts.destroy', p.id)} method="delete" title="حذف" message={`حذف «${p.title}»؟`} variant="ghost" icon={<Trash2 className="size-3.5" />}>حذف</ConfirmAction>
                                </div>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            {editing && <PostModal post={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function PostModal({ post, onClose }: { post: Post | null; onClose: () => void }) {
    const form = useForm<PostForm>({
        type: post?.type ?? 'offer',
        title: post?.title ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        starts_on: post?.starts_on ?? '',
        ends_on: post?.ends_on ?? '',
        is_published: post?.is_published ?? true,
        image: null,
    });
    const preview = useMemo(() => (form.data.image ? URL.createObjectURL(form.data.image) : post?.image_url ?? null), [form.data.image, post?.image_url]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const url = post ? route('club.website.posts.update', post.id) : route('club.website.posts.store');
        form.post(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={post ? 'تعديل' : 'خبر أو عرض جديد'}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>إلغاء</Button>
                    <Button onClick={submit} loading={form.processing}>حفظ</Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="النوع" error={form.errors.type}>
                        <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <option value="offer">عرض</option>
                            <option value="news">خبر / إعلان</option>
                        </Select>
                    </Field>
                    <Field label="العنوان" error={form.errors.title} className="sm:col-span-2" required>
                        <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} maxLength={160} />
                    </Field>
                </div>
                <Field label="ملخص قصير" error={form.errors.excerpt} hint="يظهر في البطاقات ونتائج البحث">
                    <Textarea rows={2} value={form.data.excerpt} onChange={(e) => form.setData('excerpt', e.target.value)} maxLength={500} />
                </Field>
                <Field label="المحتوى" error={form.errors.body}>
                    <Textarea rows={6} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="يبدأ في" error={form.errors.starts_on}>
                        <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                    </Field>
                    <Field label="ينتهي في" error={form.errors.ends_on} hint="يُخفى تلقائيًا بعده">
                        <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                    </Field>
                </div>
                <Field label="الصورة" error={form.errors.image}>
                    <div className="flex items-center gap-3">
                        <div className="grid h-20 w-32 place-items-center overflow-hidden rounded-xl border border-dashed border-line bg-canvas">
                            {preview ? <img src={preview} alt="" className="h-full w-full object-cover" /> : <ImagePlus className="size-5 text-muted" />}
                        </div>
                        <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-xs hover:bg-brand-50">
                            <ImagePlus className="size-3.5" /> اختيار صورة
                            <input type="file" accept="image/*" className="hidden" onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)} />
                        </label>
                    </div>
                </Field>
                <Toggle checked={form.data.is_published} onChange={(v) => form.setData('is_published', v)} label="منشور في الموقع" />
            </form>
        </Modal>
    );
}
