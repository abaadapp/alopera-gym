import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Building2, Pencil, Plus } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Toggle } from '@/Components/ui';

interface Branch {
    id: number;
    name: string;
    code: string | null;
    phone: string | null;
    address: string | null;
    is_main: boolean;
    is_active: boolean;
}

export default function Branches({ branches }: { branches: Branch[] }) {
    const [editing, setEditing] = useState<Branch | 'new' | null>(null);

    return (
        <ClubLayout title="الفروع">
            <PageHeader back={<SettingsBack />} title="الفروع" subtitle="النظام جاهز لتعدد الفروع؛ عدد الفروع المتاح يحدده اشتراك المنصة" actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>فرع جديد</Button>} />
            <Card padded={false}>
                {branches.length === 0 ? (
                    <EmptyState icon={<Building2 className="size-6" />} title="لا توجد فروع" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الفرع</th><th>الرمز</th><th>الهاتف</th><th>العنوان</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {branches.map((b) => (
                                    <tr key={b.id}>
                                        <td className="font-medium">{b.name} {b.is_main && <Badge tone="brand" className="ms-1">رئيسي</Badge>}</td>
                                        <td className="num">{b.code ?? '—'}</td>
                                        <td className="num">{b.phone ?? '—'}</td>
                                        <td className="text-xs text-muted">{b.address ?? '—'}</td>
                                        <td>{b.is_active ? <Badge tone="green">نشط</Badge> : <Badge>متوقف</Badge>}</td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(b)}>تعديل</Button>
                                                {!b.is_main && b.is_active && (
                                                    <ConfirmAction url={route('club.branches.destroy', b.id)} method="delete" title="إيقاف الفرع" message={`إيقاف فرع «${b.name}»؟ تبقى بياناته محفوظة.`} variant="ghost">إيقاف</ConfirmAction>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            {editing && <BranchModal branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function BranchModal({ branch, onClose }: { branch: Branch | null; onClose: () => void }) {
    const form = useForm({ name: branch?.name ?? '', code: branch?.code ?? '', phone: branch?.phone ?? '', address: branch?.address ?? '', is_active: branch?.is_active ?? true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (branch) form.put(route('club.branches.update', branch.id), options);
        else form.post(route('club.branches.store'), options);
    };

    return (
        <Modal open onClose={onClose} title={branch ? 'تعديل الفرع' : 'فرع جديد'} footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="اسم الفرع" error={form.errors.name} required className="sm:col-span-2">
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </Field>
                <Field label="الرمز" error={form.errors.code} hint="حروف وأرقام بدون مسافات">
                    <Input dir="ltr" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                </Field>
                <Field label="الهاتف" error={form.errors.phone}>
                    <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="العنوان" error={form.errors.address} className="sm:col-span-2">
                    <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                </Field>
                {branch && !branch.is_main && <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="نشط" />}
            </form>
        </Modal>
    );
}
