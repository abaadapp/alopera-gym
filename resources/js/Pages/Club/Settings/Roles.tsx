import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Lock, Pencil, Plus, ShieldCheck, Trash2, Users } from 'lucide-react';
import { cn } from '@/lib/cn';
import { SettingsScreen } from '@/Components/Settings';
import { Badge, Button, Card, ConfirmAction, Field, Input, Modal, Textarea } from '@/Components/ui';

/*
 * What kind of power each permission is, so a role reads like a matrix: view, add, edit, delete or cancel, approve,
 * print, export, money and confidential data, point of sale. Told from the permission's own key.
 */
const ACTIONS: [string, string, RegExp][] = [
    ['money', 'بيانات مالية', /^(reports\.financial|accounting\.view|payroll\.salaries|dues\.view)$/],
    ['pos', 'نقطة البيع', /^(pos\.sell|sales\.discount|pos_devices\.manage)$/],
    ['print', 'طباعة', /(\.print|\.payslips)$/],
    ['export', 'تصدير', /(\.export|^payroll\.reports)$/],
    ['approve', 'اعتماد', /(\.approve|\.confirm)$/],
    ['delete', 'حذف وإلغاء', /(\.delete|\.void|\.cancel|\.refund|\.credit)$/],
    ['view', 'عرض', /(\.view|\.inbox)$/],
    ['add', 'إضافة', /(\.create|\.sell|\.record|\.perform|\.prepare|\.mark|\.send|\.receive|\.renew)$/],
    ['edit', 'تعديل وإدارة', /./],
];
const actionOf = (key: string) => ACTIONS.find(([, , pattern]) => pattern.test(key)) ?? ACTIONS[ACTIONS.length - 1];
const actionTone: Record<string, string> = {
    view: 'bg-[#eff6ff] text-[#1d4ed8]',
    add: 'bg-[#ecfdf5] text-[#047857]',
    edit: 'bg-[#f2f2f0] text-[#4b4b4b]',
    delete: 'bg-[#fef2f2] text-[#b91c1c]',
    approve: 'bg-[#f5f3ff] text-[#6d28d9]',
    print: 'bg-[#f2f2f0] text-[#4b4b4b]',
    export: 'bg-[#f2f2f0] text-[#4b4b4b]',
    money: 'bg-[#fffbeb] text-[#b45309]',
    pos: 'bg-[#f5f3ff] text-[#6d28d9]',
};

interface Role {
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_system: boolean;
    is_locked: boolean;
    users_count: number;
    permissions: string[];
}

interface Group {
    key: string;
    label: string;
    permissions: { key: string; label: string }[];
}

export default function Roles({ roles, groups, grantable }: { roles: Role[]; groups: Group[]; grantable: string[] }) {
    const [editing, setEditing] = useState<Role | 'new' | null>(null);
    const total = groups.reduce((n, g) => n + g.permissions.length, 0);

    return (
        <SettingsScreen title="الأدوار والصلاحيات" width="full">
            <Card padded={false} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 className="text-[17px] font-bold text-[#111]">الأدوار والصلاحيات</h2>
                    <p className="mt-0.5 text-[13px] text-[#6b7280]">العرض والإضافة والتعديل والحذف والاعتماد والطباعة والتصدير والبيانات المالية — كل صلاحية تُفحص على الخادم في كل طلب.</p>
                </div>
                <Button icon={<Plus />} onClick={() => setEditing('new')}>دور مخصص</Button>
            </Card>
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {roles.map((r) => (
                    <Card key={r.id} className="flex flex-col">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <div className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[#f2f2f0] text-ink">{r.is_locked ? <Lock className="size-5" /> : <ShieldCheck className="size-5" />}</div>
                                <div>
                                    <p className="font-semibold text-[#111]">{r.name}</p>
                                    <p className="text-[12px] text-[#9ca3af]">{r.is_system ? 'دور افتراضي' : 'دور مخصص'}</p>
                                </div>
                            </div>
                            <Badge tone="gray"><Users className="size-3.5" /> <span className="num">{r.users_count}</span></Badge>
                        </div>
                        {r.description && <p className="mt-3 text-[13px] leading-relaxed text-[#6b7280]">{r.description}</p>}
                        <div className="mt-4">
                            <div className="mb-1.5 flex justify-between text-[12px] text-[#6b7280]">
                                <span>الصلاحيات</span>
                                <span className="num">{r.is_locked ? total : r.permissions.length}/{total}</span>
                            </div>
                            <div className="h-1 overflow-hidden rounded-full bg-[#f2f2f0]">
                                <div className="h-full rounded-full bg-[#111]" style={{ width: `${((r.is_locked ? total : r.permissions.length) / Math.max(1, total)) * 100}%` }} />
                            </div>
                        </div>
                        <div className="mt-auto flex gap-1 pt-4">
                            <Button size="sm" variant="outline" icon={r.is_locked ? <Lock className="size-3.5" /> : <Pencil className="size-3.5" />} onClick={() => setEditing(r)}>
                                {r.is_locked ? 'عرض' : 'تعديل الصلاحيات'}
                            </Button>
                            {!r.is_system && (
                                <ConfirmAction url={route('club.roles.destroy', r.id)} method="delete" title="حذف الدور" message={r.users_count > 0 ? 'الدور مسند لمستخدمين؛ انقلهم أولًا.' : `حذف دور «${r.name}»؟`} variant="ghost" icon={<Trash2 className="size-3.5" />}>
                                    حذف
                                </ConfirmAction>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
            {editing && <RoleModal role={editing === 'new' ? null : editing} groups={groups} grantable={grantable} onClose={() => setEditing(null)} />}
        </SettingsScreen>
    );
}

function RoleModal({ role, groups, grantable, onClose }: { role: Role | null; groups: Group[]; grantable: string[]; onClose: () => void }) {
    const locked = role?.is_locked ?? false;
    // Nobody gives what they do not hold: those boxes stay as they are, and the server keeps them so.
    const mine = (p: string) => grantable.includes(p);
    const form = useForm({ name: role?.name ?? '', description: role?.description ?? '', permissions: role?.permissions ?? ([] as string[]) });
    const has = (p: string) => form.data.permissions.includes(p);
    const toggle = (p: string) => !locked && mine(p) && form.setData('permissions', has(p) ? form.data.permissions.filter((x) => x !== p) : [...form.data.permissions, p]);
    const toggleGroup = (g: Group) => {
        if (locked) return;
        const keys = g.permissions.map((p) => p.key).filter(mine);
        const all = keys.every(has);
        form.setData('permissions', all ? form.data.permissions.filter((p) => !keys.includes(p)) : Array.from(new Set([...form.data.permissions, ...keys])));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (locked) return onClose();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (role) form.put(route('club.roles.update', role.id), options);
        else form.post(route('club.roles.store'), options);
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title={role ? role.name : 'دور مخصص جديد'}
            footer={locked ? <Button variant="outline" onClick={onClose}>إغلاق</Button> : <><Button variant="outline" onClick={onClose}>إلغاء</Button><Button icon={<Check />} onClick={submit} loading={form.processing}>حفظ</Button></>}
        >
            {locked && <p className="mb-4 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]">دور صاحب النشاط يملك كل الصلاحيات ولا يمكن تعديله، حتى لا يُغلق النظام على نفسه.</p>}
            {!locked && groups.some((g) => g.permissions.some((p) => !mine(p.key))) && <p className="mb-4 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">الصلاحيات الباهتة لا تملكها أنت، فلا تمنحها ولا تسحبها؛ تبقى على حالها في الدور.</p>}
            <div className="mb-4 flex flex-wrap gap-1.5">
                {ACTIONS.map(([key, label]) => <span key={key} className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', actionTone[key])}>{label}</span>)}
            </div>
            <form onSubmit={submit} className="space-y-4">
                {!locked && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="اسم الدور" error={form.errors.name} required>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="مثال: مشرف الصالة" />
                        </Field>
                        <Field label="وصف" error={form.errors.description}>
                            <Textarea rows={1} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                    </div>
                )}
                <div className="grid gap-3 md:grid-cols-2">
                    {groups.map((g) => {
                        const keys = g.permissions.map((p) => p.key);
                        const count = keys.filter(has).length;
                        return (
                            <div key={g.key} className="rounded-[12px] border border-[#e8e8e8] p-4">
                                <label className="mb-3 flex cursor-pointer items-center justify-between gap-2">
                                    <span className="font-semibold text-[#111]">{g.label}</span>
                                    <span className="flex items-center gap-2 text-[12px] text-[#9ca3af]">
                                        <span className="num">{locked ? keys.length : count}/{keys.length}</span>
                                        <input type="checkbox" className="size-4 rounded border-[#d1d5db] accent-[#111]" checked={locked || count === keys.length} ref={(el) => { if (el) el.indeterminate = count > 0 && count < keys.length; }} onChange={() => toggleGroup(g)} disabled={locked} />
                                    </span>
                                </label>
                                <div className="space-y-2">
                                    {g.permissions.map((p) => {
                                        const [action, actionLabel] = actionOf(p.key);
                                        return (
                                            <label key={p.key} title={!locked && !mine(p.key) ? 'لا تملك هذه الصلاحية، فلا تمنحها ولا تسحبها.' : undefined} className={cn('flex items-start gap-2.5 text-sm text-[#374151]', !locked && !mine(p.key) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer')}>
                                                <input type="checkbox" className="mt-0.5 size-4 shrink-0 rounded border-[#d1d5db] accent-[#111]" checked={locked || has(p.key)} onChange={() => toggle(p.key)} disabled={locked || !mine(p.key)} />
                                                <span className="min-w-0 flex-1">{p.label}</span>
                                                <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium', actionTone[action])}>{actionLabel}</span>
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>
                {form.errors.permissions && <p className="text-[12px] text-[#b91c1c]">{form.errors.permissions}</p>}
            </form>
        </Modal>
    );
}
