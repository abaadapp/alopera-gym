import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { BadgeCheck, Pencil, Plus, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Toggle } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';

type Title = { id: number; name: string; description: string | null; is_active: boolean; employees_count: number; active_count: number };

export default function JobTitles({ titles }: { titles: Title[] }) {
    const manage = useCan()('employees.manage');
    const [editing, setEditing] = useState<Title | 'new' | null>(null);

    return (
        <ClubLayout title="المسميات الوظيفية">
            <PageHeader title="المسميات الوظيفية" subtitle="وظائف فريق النادي كما تظهر في ملفات الموظفين ومسيرات الرواتب" actions={manage && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>مسمى جديد</Button>} />
            <Card padded={false}>
                {titles.length ? (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>المسمى</th><th>الوصف</th><th>الموظفات</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {titles.map((t) => (
                                    <tr key={t.id}>
                                        <td className="font-medium">{t.name}</td>
                                        <td className="max-w-80 text-muted">{t.description ?? '—'}</td>
                                        <td className="num">{t.active_count}{t.employees_count > t.active_count && <span className="text-xs text-muted"> (+{t.employees_count - t.active_count} سابقات)</span>}</td>
                                        <td><Badge tone={t.is_active ? 'green' : 'gray'}>{t.is_active ? 'مستخدم' : 'موقوف'}</Badge></td>
                                        <td className="text-end">
                                            {manage && (
                                                <span className="inline-flex gap-1">
                                                    <button type="button" onClick={() => setEditing(t)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" aria-label="تعديل"><Pencil className="size-4" /></button>
                                                    {t.employees_count === 0 && <ConfirmAction url={route('club.job-titles.destroy', t.id)} method="delete" title="حذف المسمى" message={`سيُحذف المسمى «${t.name}».`} variant="ghost" icon={<Trash2 className="size-4" />}>{''}</ConfirmAction>}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState icon={<BadgeCheck className="size-6" />} title="لا مسميات بعد" />
                )}
            </Card>
            {editing && <TitleForm title={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function TitleForm({ title, onClose }: { title: Title | null; onClose: () => void }) {
    const form = useForm({ name: title?.name ?? '', description: title?.description ?? '', is_active: title?.is_active ?? true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        title ? form.put(route('club.job-titles.update', title.id), opts) : form.post(route('club.job-titles.store'), opts);
    };
    return (
        <Modal open onClose={onClose} size="sm" title={title ? 'تعديل المسمى' : 'مسمى جديد'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="المسمى" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                <Field label="الوصف" error={form.errors.description}><Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
                {title && <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="مستخدم" hint="المسمى الموقوف يبقى في الملفات القديمة ولا يُختار لملف جديد" />}
            </form>
        </Modal>
    );
}
