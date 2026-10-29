import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Lock, Pencil, Plus, ShieldCheck, Trash2, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, Button, Card, ConfirmAction, Field, Input, Modal, PageHeader, Textarea } from '@/Components/ui';

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

export default function Roles({ roles, groups }: { roles: Role[]; groups: Group[] }) {
    const [editing, setEditing] = useState<Role | 'new' | null>(null);
    const total = groups.reduce((n, g) => n + g.permissions.length, 0);

    return (
        <ClubLayout title="الأدوار والصلاحيات">
            <PageHeader back={<SettingsBack />}
                title="الأدوار والصلاحيات"
                subtitle="كل صلاحية تُفحص على الخادم في كل طلب؛ إخفاء الأزرار للتسهيل فقط"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>دور مخصص</Button>}
            />
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {roles.map((r) => (
                    <Card key={r.id} className="flex flex-col">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <div className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[#f2f2f0] text-ink">{r.is_locked ? <Lock className="size-5" /> : <ShieldCheck className="size-5" />}</div>
                                <div>
                                    <p className="font-semibold">{r.name}</p>
                                    <p className="text-xs text-muted">{r.is_system ? 'دور افتراضي' : 'دور مخصص'}</p>
                                </div>
                            </div>
                            <Badge tone="gray"><Users className="size-3.5" /> <span className="num">{r.users_count}</span></Badge>
                        </div>
                        {r.description && <p className="mt-3 text-sm text-muted">{r.description}</p>}
                        <div className="mt-4">
                            <div className="mb-1 flex justify-between text-xs text-muted">
                                <span>الصلاحيات</span>
                                <span className="num">{r.permissions.length}/{total}</span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-brand-50">
                                <div className="h-full rounded-full bg-brand" style={{ width: `${(r.permissions.length / Math.max(1, total)) * 100}%` }} />
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
            {editing && <RoleModal role={editing === 'new' ? null : editing} groups={groups} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function RoleModal({ role, groups, onClose }: { role: Role | null; groups: Group[]; onClose: () => void }) {
    const locked = role?.is_locked ?? false;
    const form = useForm({ name: role?.name ?? '', description: role?.description ?? '', permissions: role?.permissions ?? ([] as string[]) });
    const has = (p: string) => form.data.permissions.includes(p);
    const toggle = (p: string) => !locked && form.setData('permissions', has(p) ? form.data.permissions.filter((x) => x !== p) : [...form.data.permissions, p]);
    const toggleGroup = (g: Group) => {
        if (locked) return;
        const keys = g.permissions.map((p) => p.key);
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
            footer={locked ? <Button variant="ghost" onClick={onClose}>إغلاق</Button> : <><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}
        >
            {locked && <p className="mb-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-700">دور المالك يملك كل الصلاحيات ولا يمكن تعديله، حتى لا يُغلق النادي على نفسه.</p>}
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
                            <div key={g.key} className="rounded-2xl border border-line p-4">
                                <label className="mb-2 flex cursor-pointer items-center justify-between gap-2">
                                    <span className="font-medium">{g.label}</span>
                                    <span className="flex items-center gap-2 text-xs text-muted">
                                        <span className="num">{count}/{keys.length}</span>
                                        <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={count === keys.length} ref={(el) => { if (el) el.indeterminate = count > 0 && count < keys.length; }} onChange={() => toggleGroup(g)} disabled={locked} />
                                    </span>
                                </label>
                                <div className="space-y-1.5">
                                    {g.permissions.map((p) => (
                                        <label key={p.key} className="flex cursor-pointer items-center gap-2 text-sm text-ink/85">
                                            <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={has(p.key)} onChange={() => toggle(p.key)} disabled={locked} />
                                            {p.label}
                                        </label>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
                {form.errors.permissions && <p className="text-xs text-red-600">{form.errors.permissions}</p>}
            </form>
        </Modal>
    );
}
