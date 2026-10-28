import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Pencil, UserPlus, Users as UsersIcon } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Toggle } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import type { Option } from '@/types';

interface StaffRow {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    role_id: number | null;
    branch_id: number | null;
    role: string | null;
    role_key: string | null;
    branch: string | null;
    last_login_at: string | null;
}

interface Props {
    users: StaffRow[];
    roles: (Option & { key: string })[];
    branches: Option[];
}

export default function Users({ users, roles, branches }: Props) {
    const { auth } = useShared();
    const [editing, setEditing] = useState<StaffRow | 'new' | null>(null);

    return (
        <ClubLayout title="المستخدمون">
            <PageHeader title="مستخدمات اللوحة" subtitle="حسابات الموظفات وأدوارهن. الصلاحيات تُطبّق على الخادم وليس على الواجهة فقط." actions={<Button icon={<UserPlus className="size-4" />} onClick={() => setEditing('new')}>مستخدمة جديدة</Button>} />
            <Card padded={false}>
                {users.length === 0 ? (
                    <EmptyState icon={<UsersIcon className="size-6" />} title="لا توجد حسابات" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الفرع</th><th>آخر دخول</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {users.map((u) => (
                                    <tr key={u.id} className={u.is_active ? '' : 'opacity-60'}>
                                        <td className="font-medium">{u.name}{u.id === auth?.user.id && <span className="ms-1 text-xs text-muted">(أنتِ)</span>}</td>
                                        <td className="text-xs" dir="ltr">{u.email}</td>
                                        <td><Badge tone={u.role_key === 'owner' ? 'brand' : 'gray'}>{u.role ?? '—'}</Badge></td>
                                        <td className="text-xs">{u.branch ?? 'كل الفروع'}</td>
                                        <td className="text-xs text-muted num">{u.last_login_at ?? 'لم تسجّل بعد'}</td>
                                        <td>{u.is_active ? <Badge tone="green">نشط</Badge> : <Badge tone="red">موقوف</Badge>}</td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(u)}>تعديل</Button>
                                                {u.is_active && u.id !== auth?.user.id && (
                                                    <ConfirmAction url={route('club.users.destroy', u.id)} method="delete" title="إيقاف الحساب" message={`إيقاف حساب ${u.name}؟ لن تتمكن من الدخول.`} variant="ghost">إيقاف</ConfirmAction>
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
            {editing && <UserModal user={editing === 'new' ? null : editing} roles={roles} branches={branches} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function UserModal({ user, roles, branches, onClose }: { user: StaffRow | null; roles: Props['roles']; branches: Option[]; onClose: () => void }) {
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        phone: user?.phone ?? '',
        role_id: user?.role_id ? String(user.role_id) : '',
        branch_id: user?.branch_id ? String(user.branch_id) : '',
        is_active: user?.is_active ?? true,
        password: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (user) form.put(route('club.users.update', user.id), options);
        else form.post(route('club.users.store'), options);
    };

    return (
        <Modal open onClose={onClose} title={user ? `تعديل: ${user.name}` : 'مستخدمة جديدة'} footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="الاسم" error={form.errors.name} required className="sm:col-span-2">
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </Field>
                <Field label="البريد الإلكتروني (للدخول)" error={form.errors.email} required>
                    <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="الهاتف" error={form.errors.phone}>
                    <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="الدور" error={form.errors.role_id} required>
                    <Select value={form.data.role_id} onChange={(e) => form.setData('role_id', e.target.value)} placeholder="اختاري الدور">
                        {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                    </Select>
                </Field>
                <Field label="الفرع" error={form.errors.branch_id}>
                    <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="كل الفروع">
                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                    </Select>
                </Field>
                <Field label={user ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور'} error={form.errors.password} required={!user} className="sm:col-span-2" hint="8 أحرف على الأقل">
                    <Input type="password" dir="ltr" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                {user && <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="الحساب نشط" />}
                <p className="text-xs text-muted sm:col-span-2">لربط حساب مدربة بملفها في الجدول، اختاري الحساب من صفحة المدربات.</p>
            </form>
        </Modal>
    );
}
