import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Pencil, Plus, Tags, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader } from '@/Components/ui';
import { number } from '@/lib/format';

type Type = { id: number; name: string; renewal_months: number | null; is_active: boolean; documents_count: number };

export default function DocumentTypes({ types }: { types: Type[] }) {
    const [editing, setEditing] = useState<Type | 'new' | null>(null);

    return (
        <ClubLayout title="أنواع المستندات">
            <PageHeader title="أنواع المستندات" subtitle="تصنيف مستندات النشاط ومدة تجديدها المعتادة" actions={<Button icon={<Plus />} onClick={() => setEditing('new')}>نوع جديد</Button>} />
            <Card padded={false}>
                {types.length === 0 ? (
                    <EmptyState icon={<Tags />} title="لا أنواع بعد" />
                ) : (
                    <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>النوع</th><th>مدة التجديد</th><th>المستندات</th><th>الحالة</th><th /></tr></thead>
                        <tbody>
                            {types.map((t) => (
                                <tr key={t.id} className={t.is_active ? undefined : 'opacity-60'}>
                                    <td className="font-medium">{t.name}</td>
                                    <td className="text-sm">{t.renewal_months ? `${number(t.renewal_months)} شهر` : '—'}</td>
                                    <td className="num">{number(t.documents_count)}</td>
                                    <td>{t.is_active ? <Badge tone="green">مستخدم</Badge> : <Badge>موقوف</Badge>}</td>
                                    <td>
                                        <div className="flex justify-end gap-1">
                                            <Button size="sm" variant="ghost" icon={<Pencil />} onClick={() => setEditing(t)}>تعديل</Button>
                                            <ConfirmAction url={route('club.document-types.destroy', t.id)} method="delete" title={`حذف «${t.name}»`} message={t.documents_count ? 'النوع مستخدم في مستندات، فسيوقف بدل حذفه.' : 'يُحذف النوع.'} confirmLabel="تأكيد" variant="ghost" icon={<Trash2 />}>حذف</ConfirmAction>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}
            </Card>
            {editing && <TypeModal type={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function TypeModal({ type, onClose }: { type: Type | null; onClose: () => void }) {
    const form = useForm({ name: type?.name ?? '', renewal_months: type?.renewal_months ? String(type.renewal_months) : '', is_active: type?.is_active ?? true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, renewal_months: d.renewal_months || null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (type) form.put(route('club.document-types.update', type.id), options);
        else form.post(route('club.document-types.store'), options);
    };

    return (
        <Modal open onClose={onClose} title={type ? 'تعديل النوع' : 'نوع جديد'} footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button loading={form.processing} onClick={submit}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="اسم النوع" error={form.errors.name} required>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={120} />
                </Field>
                <Field label="مدة التجديد المعتادة (شهر)" hint="تُقترح عند إضافة المستند وتجديده" error={form.errors.renewal_months}>
                    <Input type="number" min={1} max={120} dir="ltr" value={form.data.renewal_months} onChange={(e) => form.setData('renewal_months', e.target.value)} />
                </Field>
                {type && <Checkbox label="مستخدم" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />}
            </form>
        </Modal>
    );
}
