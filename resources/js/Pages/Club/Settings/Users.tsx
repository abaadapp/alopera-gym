import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Plus, UserRound, UserX } from 'lucide-react';
import { SettingsScreen } from '@/Components/Settings';
import PermissionPicker, { type PermissionGroup } from '@/Components/Permissions/PermissionPicker';
import { Badge, Button, Card, DataTable, Field, Input, Modal, RowActions, Select, Toggle, type Column } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import type { Option } from '@/types';
import useDialogRequest from '@/hooks/useDialogRequest';

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
    employee_id: number | null;
    custom: string[] | null;
    may_manage: boolean;
}

interface Props {
    users: StaffRow[];
    roles: (Option & { key: string; assignable: boolean; permissions: string[] })[];
    branches: Option[];
    groups: PermissionGroup[];
    decides: string[];
    sensitive: string[];
    requires: Record<string, string>;
}

export default function Users({ users, roles, branches, groups, decides, sensitive, requires }: Props) {
    const { auth } = useShared();
    const [editing, setEditing] = useState<StaffRow | 'new' | null>(null);
    const [stopping, setStopping] = useState<StaffRow | null>(null);
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
        { key: 'role', header: 'الدور', cell: (u) => <><Badge tone={u.role_key === 'owner' ? 'brand' : 'gray'}>{u.role ?? '—'}</Badge>{u.own_permissions && <span className="ms-1.5 text-[12px] text-[#9ca3af]">صلاحيات مخصصة</span>}{u.employee_id && u.may_manage && <Link href={`${route('club.employees.show', u.employee_id)}?tab=permissions`} className="ms-2 text-[12px] text-[#111] underline-offset-2 hover:underline">الصلاحيات</Link>}</> },
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

    const stopRequest = useDialogRequest();
    const stop = () => stopping && stopRequest.send('delete', route('club.users.destroy', stopping.id), () => setStopping(null));

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
            {editing && <UserModal user={editing === 'new' ? null : editing} isMe={editing !== 'new' && me(editing)} roles={roles} branches={branches} picker={{ groups, decides, sensitive, requires }} onClose={() => setEditing(null)} />}
            <Modal open={stopping !== null} onClose={() => setStopping(null)} size="confirm" title="إيقاف الحساب" footer={<><Button variant="outline" onClick={() => setStopping(null)} disabled={stopRequest.busy}>إلغاء</Button><Button variant="danger" loading={stopRequest.busy} onClick={stop}>إيقاف</Button></>}>
                <p className="text-sm text-[#4b4b4b]">إيقاف حساب {stopping?.name}؟ لن يتمكن من الدخول، ويبقى سجله كما هو.</p>
                {stopRequest.error && <p className="mt-2 text-sm text-red-600" role="alert">{stopRequest.error}</p>}
            </Modal>
        </SettingsScreen>
    );
}

/** An account's details and reach; for someone else's account, also its permissions: its role's, or a list of its own. */
function UserModal({ user, isMe, roles, branches, picker, onClose }: { user: StaffRow | null; isMe: boolean; roles: Props['roles']; branches: Option[]; picker: Pick<Props, 'groups' | 'decides' | 'sensitive' | 'requires'>; onClose: () => void }) {
    const roleList = (id: string) => roles.find((r) => String(r.id) === id)?.permissions ?? [];
    // Not one's own permissions, nor the owner's (who keeps everything).
    const permits = user !== null && !isMe && user.role_key !== 'owner';
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        phone: user?.phone ?? '',
        role_id: user?.role_id ? String(user.role_id) : '',
        branch_id: user?.branch_id ? String(user.branch_id) : '',
        is_active: user?.is_active ?? true,
        password: '',
        custom: user?.custom !== null && user?.custom !== undefined,
        permissions: user?.custom ?? roleList(user?.role_id ? String(user.role_id) : ''),
    });
    const shown = form.data.custom ? form.data.permissions : roleList(form.data.role_id);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        // The list goes only from where it is shown, so an edit elsewhere never touches it.
        form.transform((d) => (permits ? d : { ...d, custom: undefined, permissions: undefined }));
        if (user) form.put(route('club.users.update', user.id), options);
        else form.post(route('club.users.store'), options);
    };

    return (
        <Modal open onClose={onClose} size={permits ? 'wide' : undefined} title={user ? `تعديل: ${user.name}` : 'مستخدم جديد'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
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
                {permits && (
                    <div className="space-y-3 sm:col-span-2">
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {[{ on: false, title: 'اتبع صلاحيات الدور', hint: 'تتبدّل صلاحياته كلما تبدّل دوره' }, { on: true, title: 'خصّص لهذا الحساب', hint: 'قائمة له وحده — لا تتبدّل مع الدور' }].map((opt) => (
                                <button key={String(opt.on)} type="button" onClick={() => form.setData((d) => ({ ...d, custom: opt.on, permissions: opt.on ? (user?.custom ?? roleList(d.role_id)) : d.permissions }))}
                                    className={`rounded-[10px] border p-3 text-start transition-colors ${form.data.custom === opt.on ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]'}`}>
                                    <span className="flex items-center gap-2">
                                        <span className={`flex size-4 shrink-0 items-center justify-center rounded-full border ${form.data.custom === opt.on ? 'border-[#111]' : 'border-[#d1d5db]'}`}>{form.data.custom === opt.on && <span className="size-2 rounded-full bg-[#111]" />}</span>
                                        <span className="text-sm font-medium text-ink">{opt.title}</span>
                                    </span>
                                    <span className="mt-1 block ps-6 text-[12px] text-[#6b7280]">{opt.hint}</span>
                                </button>
                            ))}
                        </div>
                        {!form.data.custom && <p className="text-[12px] text-muted">ما يفتحه الدور المختار — يُعرض ولا يُعدَّل من هنا. للإضافة إليه أو الحذف منه لهذا الحساب وحده اختر «خصّص لهذا الحساب»؛ الدور نفسه لا يتغير.</p>}
                        <PermissionPicker groups={picker.groups} value={shown} onChange={(next) => form.setData('permissions', next)} editable={form.data.custom} mine={(p) => picker.decides.includes(p)} sensitive={picker.sensitive} requires={picker.requires} />
                        {(form.errors as Record<string, string>).custom && <p className="text-xs text-red-600">{(form.errors as Record<string, string>).custom}</p>}
                        {form.errors.permissions && <p className="text-xs text-red-600">{form.errors.permissions}</p>}
                    </div>
                )}
                <p className="text-[12px] text-[#9ca3af] sm:col-span-2">لربط حساب مدرب بملفه في الجدول، اختر الحساب من صفحة فريق التدريب.</p>
            </form>
        </Modal>
    );
}
