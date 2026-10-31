import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Check, Plus, UserRound, UserX } from 'lucide-react';
import { SettingsScreen } from '@/Components/Settings';
import { Badge, Button, Card, DataTable, Field, Input, Modal, RowActions, Select, Toggle, type Column } from '@/Components/ui';
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
    own_permissions: boolean;
    may_manage: boolean;
}

interface Props {
    users: StaffRow[];
    roles: (Option & { key: string; assignable: boolean })[];
    branches: Option[];
}

export default function Users({ users, roles, branches }: Props) {
    const { auth } = useShared();
    const [editing, setEditing] = useState<StaffRow | 'new' | null>(null);
    const [stopping, setStopping] = useState<StaffRow | null>(null);
    const [busy, setBusy] = useState(false);
    const me = (u: StaffRow) => u.id === auth?.user.id;

    const columns: Column<StaffRow>[] = [
        {
            key: 'name',
            header: 'الاسم',
            cell: (u) => (
                <div className="flex items-center gap-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]"><UserRound className="size-[18px]" /></span>
                    <span className="min-w-0">
                        <span className="block font-medium text-[#111]">{u.name}{me(u) && <span className="ms-1 text-[12px] font-normal text-[#9ca3af]">(أنت)</span>}</span>
                        <span className="block text-[12px] text-[#9ca3af]" dir="ltr">{u.email}</span>
                    </span>
                </div>
            ),
        },
        { key: 'role', header: 'الدور', cell: (u) => <><Badge tone={u.role_key === 'owner' ? 'brand' : 'gray'}>{u.role ?? '—'}</Badge>{u.own_permissions && <span className="ms-1.5 text-[12px] text-[#9ca3af]">صلاحيات مخصصة</span>}</> },
        { key: 'branch', header: 'الفرع', cell: (u) => <span className="text-[#6b7280]">{u.branch ?? 'كل الفروع'}</span> },
        { key: 'login', header: 'آخر دخول', cell: (u) => <span className="num text-[#6b7280]">{u.last_login_at ?? 'لم يسجّل بعد'}</span> },
        { key: 'status', header: 'الحالة', cell: (u) => (u.is_active ? <Badge tone="green">نشط</Badge> : <Badge tone="red">موقوف</Badge>) },
        {
            key: 'actions',
            header: <span className="sr-only">إجراءات</span>,
            align: 'end',
            cell: (u) =>
                u.may_manage || me(u) ? (
                    <RowActions
                        label={u.name}
                        edit={() => setEditing(u)}
                        extra={u.is_active && u.may_manage && !me(u) ? [{ label: 'إيقاف الحساب', icon: <UserX />, danger: true, onSelect: () => setStopping(u) }] : []}
                    />
                ) : (
                    <span className="text-[12px] text-[#9ca3af]" title="هذا الحساب يفتح ما لا تفتحه؛ يعدّله من يملك ذلك.">يفتح ما لا تفتحه</span>
                ),
        },
    ];

    const stop = () => stopping && router.delete(route('club.users.destroy', stopping.id), { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setStopping(null); } });

    return (
        <SettingsScreen title="المستخدمون" width="full">
            <Card padded={false} className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8e8e8] px-5 pb-4 pt-5">
                    <div>
                        <h2 className="text-[17px] font-bold text-[#111]">مستخدمو اللوحة</h2>
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">حسابات الموظفين وأدوارهم. الصلاحيات تُطبَّق على الخادم وليس على الواجهة فقط.</p>
                    </div>
                    <Button icon={<Plus />} onClick={() => setEditing('new')}>إضافة</Button>
                </div>
                <DataTable rows={users} columns={columns} rowKey={(u) => u.id} params={{}} empty="لا توجد حسابات" />
            </Card>
            {editing && <UserModal user={editing === 'new' ? null : editing} roles={roles} branches={branches} onClose={() => setEditing(null)} />}
            <Modal open={stopping !== null} onClose={() => setStopping(null)} size="xs" title="إيقاف الحساب" footer={<><Button variant="outline" onClick={() => setStopping(null)} disabled={busy}>إلغاء</Button><Button variant="danger" loading={busy} onClick={stop}>إيقاف</Button></>}>
                <p className="text-sm text-[#4b4b4b]">إيقاف حساب {stopping?.name}؟ لن يتمكن من الدخول، ويبقى سجله كما هو.</p>
            </Modal>
        </SettingsScreen>
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
        <Modal open onClose={onClose} title={user ? `تعديل: ${user.name}` : 'مستخدم جديد'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button icon={<Check />} onClick={submit} loading={form.processing}>حفظ</Button></>}>
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
                    <Select value={form.data.role_id} onChange={(e) => form.setData('role_id', e.target.value)} placeholder="اختر الدور">
                        {roles.map((r) => <option key={r.id} value={r.id} disabled={!r.assignable && String(r.id) !== String(user?.role_id ?? '')}>{r.name}{!r.assignable ? ' — يفتح ما لا تملكه' : ''}</option>)}
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
                <p className="text-[12px] text-[#9ca3af] sm:col-span-2">لربط حساب مدرب بملفه في الجدول، اختر الحساب من صفحة فريق التدريب.</p>
            </form>
        </Modal>
    );
}
