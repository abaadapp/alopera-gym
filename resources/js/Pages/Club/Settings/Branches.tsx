import { useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Building2, Check, CircleCheck, CirclePause, Plus, PowerOff } from 'lucide-react';
import { SettingsScreen } from '@/Components/Settings';
import { Badge, Button, Card, DataTable, Field, Input, Modal, RowActions, Toggle, type Column } from '@/Components/ui';

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
    const [stopping, setStopping] = useState<Branch | null>(null);
    const [busy, setBusy] = useState(false);
    const active = branches.filter((b) => b.is_active).length;

    const columns: Column<Branch>[] = [
        {
            key: 'name',
            header: 'الفرع',
            cell: (b) => (
                <div className="flex items-center gap-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]"><Building2 className="size-[18px]" /></span>
                    <span className="font-medium text-[#111]">{b.name}</span>
                    {b.is_main && <Badge tone="brand">رئيسي</Badge>}
                </div>
            ),
        },
        { key: 'code', header: 'الرمز', cell: (b) => <span className="num">{b.code ?? '—'}</span> },
        { key: 'phone', header: 'الهاتف', cell: (b) => <span className="num">{b.phone ?? '—'}</span> },
        { key: 'address', header: 'العنوان', className: 'max-w-xs', cell: (b) => <span className="text-[#6b7280]">{b.address ?? '—'}</span> },
        { key: 'status', header: 'الحالة', cell: (b) => (b.is_active ? <Badge tone="green">نشط</Badge> : <Badge>متوقف</Badge>) },
        {
            key: 'actions',
            header: <span className="sr-only">إجراءات</span>,
            align: 'end',
            cell: (b) => <RowActions label={b.name} edit={() => setEditing(b)} extra={!b.is_main && b.is_active ? [{ label: 'إيقاف الفرع', icon: <PowerOff />, danger: true, onSelect: () => setStopping(b) }] : []} />,
        },
    ];

    const stop = () => stopping && router.delete(route('club.branches.destroy', stopping.id), { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setStopping(null); } });

    return (
        <SettingsScreen title="الفروع" width="full">
            <div className="grid gap-4 sm:grid-cols-3">
                <Summary icon={<Building2 />} value={branches.length} label="كل الفروع" />
                <Summary icon={<CircleCheck />} value={active} label="فروع نشطة" />
                <Summary icon={<CirclePause />} value={branches.length - active} label="فروع متوقفة" />
            </div>
            <Card padded={false} className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8e8e8] px-5 pb-4 pt-5">
                    <div>
                        <h2 className="text-[17px] font-bold text-[#111]">الفروع</h2>
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">فروع النشاط: لكل فرع موظفوه ومخزونه وعملياته، ويُدار الكل من هذه اللوحة.</p>
                    </div>
                    <Button icon={<Plus />} onClick={() => setEditing('new')}>إضافة</Button>
                </div>
                <DataTable rows={branches} columns={columns} rowKey={(b) => b.id} params={{}} empty="لا توجد فروع" />
            </Card>
            {editing && <BranchModal branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            <Modal open={stopping !== null} onClose={() => setStopping(null)} size="xs" title="إيقاف الفرع" footer={<><Button variant="outline" onClick={() => setStopping(null)} disabled={busy}>إلغاء</Button><Button variant="danger" loading={busy} onClick={stop}>إيقاف</Button></>}>
                <p className="text-sm text-[#4b4b4b]">إيقاف فرع «{stopping?.name}»؟ تبقى بياناته محفوظة.</p>
            </Modal>
        </SettingsScreen>
    );
}

/** A count over the list: a 44px dark icon tile, the figure and its label. */
function Summary({ icon, value, label }: { icon: ReactNode; value: number; label: string }) {
    return (
        <Card padded={false} className="flex items-center gap-4 p-5">
            <span className="grid size-11 shrink-0 place-items-center rounded-[12px] bg-[#111] text-white [&_svg]:size-5">{icon}</span>
            <span>
                <span className="block text-[22px] font-bold leading-7 text-[#111] num">{value}</span>
                <span className="block text-[12px] text-[#9ca3af]">{label}</span>
            </span>
        </Card>
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
        <Modal open onClose={onClose} title={branch ? 'تعديل الفرع' : 'فرع جديد'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button icon={<Check />} onClick={submit} loading={form.processing}>حفظ</Button></>}>
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
