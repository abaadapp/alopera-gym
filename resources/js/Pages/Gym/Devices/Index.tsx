import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Copy, Fingerprint, KeyRound, Pencil, Plus, RefreshCw, ShieldAlert, Trash2, Wifi, WifiOff } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Toggle } from '@/Components/ui';
import { useCan, useShared } from '@/hooks/useShared';
import type { Option } from '@/types';

type Device = {
    id: number;
    name: string;
    type: string;
    driver: string;
    vendor: string | null;
    model: string | null;
    serial_number: string | null;
    direction: string;
    purpose: string;
    token_last4: string;
    is_active: boolean;
    last_ip: string | null;
    branch_id: number | null;
    branch: string | null;
    last_seen_at: string | null;
};

type StaffCredential = { id: number; type: string; identifier: string; label: string | null; holder_id: number; is_active: boolean };

type DeviceForm = {
    name: string;
    type: string;
    vendor: string;
    model: string;
    serial_number: string;
    direction: string;
    purpose: string;
    branch_id: number | string | null;
    is_active: boolean;
};

const TYPE_LABELS: Record<string, string> = {
    fingerprint: 'جهاز بصمة',
    qr_reader: 'قارئ QR',
    barcode_reader: 'قارئ باركود',
    rfid_reader: 'قارئ بطاقات',
    turnstile: 'بوابة دخول',
    kiosk: 'شاشة تسجيل ذاتي',
};
const DIRECTIONS: Record<string, string> = { in: 'دخول', out: 'خروج', both: 'دخول وخروج' };
const PURPOSES: Record<string, string> = { members: 'الأعضاء', staff: 'الموظفون', both: 'الأعضاء والموظفون' };
const CREDENTIAL_TYPES: Record<string, string> = { fingerprint: 'بصمة (رقم المستخدم في الجهاز)', card: 'بطاقة', pin: 'رمز PIN', barcode: 'باركود' };

const blank: DeviceForm = { name: '', type: 'qr_reader', vendor: '', model: '', serial_number: '', direction: 'in', purpose: 'members', branch_id: null, is_active: true };

/** Considered online when it called the API in the last 10 minutes. */
function online(lastSeen: string | null): boolean {
    if (!lastSeen) return false;
    return Date.now() - new Date(lastSeen.replace(' ', 'T')).getTime() < 10 * 60 * 1000;
}

function copy(text: string) {
    void navigator.clipboard?.writeText(text);
}

export default function DevicesIndex({ devices, staffCredentials, staff, branches, types, apiBase }: { devices: Device[]; staffCredentials: StaffCredential[]; staff: Option[]; branches: Option[]; types: string[]; apiBase: string }) {
    const { flash } = useShared();
    const can = useCan();
    const [editing, setEditing] = useState<Device | null>(null);
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const form = useForm<DeviceForm>(blank);
    const credForm = useForm({ holder_id: '' as number | string, type: 'fingerprint', identifier: '', label: '' });
    const staffName = (id: number) => staff.find((s) => s.id === id)?.name ?? `#${id}`;

    const openForm = (d: Device | null) => {
        setEditing(d);
        form.clearErrors();
        form.setData(d ? {
            name: d.name, type: d.type, vendor: d.vendor ?? '', model: d.model ?? '', serial_number: d.serial_number ?? '',
            direction: d.direction, purpose: d.purpose, branch_id: d.branch_id, is_active: d.is_active,
        } : blank);
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('club.devices.update', editing.id), options);
        else form.post(route('club.devices.store'), options);
    };

    const addCredential = (e: FormEvent) => {
        e.preventDefault();
        credForm.post(route('club.devices.staff-credentials'), { preserveScroll: true, onSuccess: () => credForm.reset('identifier', 'label') });
    };

    const example = JSON.stringify({ credential_type: 'qr', credential: '<رمز QR المقروء>' }, null, 2);

    return (
        <ClubLayout title="أجهزة الدخول">
            <PageHeader
                title="أجهزة الدخول والبصمة"
                subtitle="قارئات QR والباركود والبطاقات، أجهزة البصمة، والبوابات — كل جهاز يتصل برمز خاص"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => openForm(null)}>جهاز جديد</Button>}
            />

            {flash.token && (
                <Card className="mb-6 border-amber-300 bg-amber-50">
                    <div className="flex items-start gap-3">
                        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-amber-700" />
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold text-amber-900">رمز الجهاز — انسخه الآن، لن يظهر مرة أخرى</p>
                            <p className="mt-1 text-sm text-amber-800">أدخله في إعدادات الجهاز (أو برنامج الربط) كترويسة <span className="font-mono" dir="ltr">Authorization: Bearer …</span></p>
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <code className="block max-w-full overflow-x-auto rounded-lg bg-white px-3 py-2 font-mono text-sm text-ink" dir="ltr">{flash.token}</code>
                                <Button size="sm" variant="outline" icon={<Copy className="size-3.5" />} onClick={() => { copy(flash.token!); setCopied(true); }}>
                                    {copied ? 'تم النسخ' : 'نسخ'}
                                </Button>
                            </div>
                        </div>
                    </div>
                </Card>
            )}

            <Card padded={false}>
                {devices.length === 0 ? (
                    <EmptyState icon={<Fingerprint className="size-6" />} title="لا توجد أجهزة" description="أضف جهازًا لتحصل على رمز الربط. يمكن استخدام الدخول اليدوي وقارئ QR من شاشة الاستقبال بدون أجهزة." action={<Button onClick={() => openForm(null)}>إضافة جهاز</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الجهاز</th><th>النوع</th><th>الاستخدام</th><th>الفرع</th><th>الاتصال</th><th>الرمز</th><th /></tr>
                            </thead>
                            <tbody>
                                {devices.map((d) => {
                                    const isOnline = online(d.last_seen_at);
                                    return (
                                        <tr key={d.id} className={d.is_active ? '' : 'opacity-60'}>
                                            <td>
                                                <p className="font-medium">{d.name}</p>
                                                <p className="text-xs text-muted">{[d.vendor, d.model, d.serial_number].filter(Boolean).join(' · ') || '—'}</p>
                                            </td>
                                            <td>{TYPE_LABELS[d.type] ?? d.type}</td>
                                            <td>
                                                <p>{PURPOSES[d.purpose] ?? d.purpose}</p>
                                                <p className="text-xs text-muted">{DIRECTIONS[d.direction] ?? d.direction}</p>
                                            </td>
                                            <td>{d.branch ?? '—'}</td>
                                            <td>
                                                {!d.is_active ? <Badge tone="gray">موقوف</Badge> : isOnline ? <Badge tone="green"><Wifi className="size-3.5" />متصل</Badge> : <Badge tone="amber"><WifiOff className="size-3.5" />غير متصل</Badge>}
                                                <p className="mt-0.5 text-[11px] text-muted num">{d.last_seen_at ?? 'لم يتصل بعد'}{d.last_ip ? ` · ${d.last_ip}` : ''}</p>
                                            </td>
                                            <td><span className="font-mono text-xs text-muted" dir="ltr">…{d.token_last4}</span></td>
                                            <td>
                                                <div className="flex justify-end gap-1">
                                                    <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => openForm(d)}>تعديل</Button>
                                                    <ConfirmAction url={route('club.devices.token', d.id)} title="إصدار رمز جديد" message="الرمز الحالي سيتوقف فورًا، ويجب إدخال الرمز الجديد في الجهاز." confirmLabel="إصدار" tone="primary" variant="ghost" icon={<RefreshCw className="size-3.5" />}>رمز جديد</ConfirmAction>
                                                    <ConfirmAction url={route('club.devices.destroy', d.id)} method="delete" title="حذف الجهاز" message="سيُبطل رمزه ولن يستطيع فتح الباب." confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-3.5" />}>حذف</ConfirmAction>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <div className="mt-6 grid gap-6 lg:grid-cols-2 [&>*]:min-w-0">
                <Card>
                    <CardHeader title="بصمات وبطاقات الموظفين" subtitle="اربط رقم المستخدم في جهاز البصمة (أو رقم البطاقة) بحسابه لتسجيل الحضور والانصراف" />
                    <form onSubmit={addCredential} className="grid gap-3 sm:grid-cols-2">
                        <Field label="الموظف" error={credForm.errors.holder_id}>
                            <Select value={credForm.data.holder_id} onChange={(e) => credForm.setData('holder_id', e.target.value)} placeholder="اختر…" required>
                                {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="النوع" error={credForm.errors.type}>
                            <Select value={credForm.data.type} onChange={(e) => credForm.setData('type', e.target.value)}>
                                {Object.entries(CREDENTIAL_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </Select>
                        </Field>
                        <Field label="المعرّف في الجهاز" error={credForm.errors.identifier}>
                            <Input dir="ltr" value={credForm.data.identifier} onChange={(e) => credForm.setData('identifier', e.target.value)} required />
                        </Field>
                        <Field label="وصف (اختياري)" error={credForm.errors.label}>
                            <Input value={credForm.data.label} onChange={(e) => credForm.setData('label', e.target.value)} placeholder="مثال: السبابة اليمنى" />
                        </Field>
                        <div className="sm:col-span-2">
                            <Button type="submit" variant="secondary" loading={credForm.processing} icon={<Plus className="size-4" />}>ربط</Button>
                        </div>
                    </form>
                    {staffCredentials.length > 0 && (
                        <ul className="mt-4 divide-y divide-line rounded-xl border border-line">
                            {staffCredentials.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                    <span>
                                        <span className="font-medium">{staffName(c.holder_id)}</span>
                                        <span className="ms-2 text-xs text-muted">{CREDENTIAL_TYPES[c.type]?.split(' ')[0] ?? c.type} · <span className="font-mono" dir="ltr">{c.identifier}</span>{c.label ? ` · ${c.label}` : ''}</span>
                                    </span>
                                    {can('devices.manage') && (
                                        <button type="button" className="grid size-8 place-items-center rounded-lg text-muted hover:bg-red-50 hover:text-red-600" aria-label="حذف"
                                            onClick={() => router.delete(route('club.devices.staff-credentials.destroy', c.id), { preserveScroll: true })}>
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card>
                    <CardHeader title="طريقة الربط" subtitle="بروتوكول JSON عام — يتطلب جهازًا أو برنامج وسيط يدعم إرسال طلبات HTTP" />
                    <ol className="list-decimal space-y-2 ps-5 text-sm text-ink/80">
                        <li>أضف الجهاز وانسخ رمزه.</li>
                        <li>
                            يرسل الجهاز عند كل قراءة طلب <span className="font-mono text-xs" dir="ltr">POST</span> إلى:
                            <code className="mt-1 flex items-center justify-between gap-2 rounded-lg bg-canvas px-3 py-2 font-mono text-xs" dir="ltr">
                                <span className="min-w-0 truncate">{apiBase}/access</span>
                                <button type="button" onClick={() => copy(`${apiBase}/access`)} aria-label="نسخ"><Copy className="size-3.5" /></button>
                            </code>
                        </li>
                        <li>
                            مع الترويسة <span className="font-mono text-xs" dir="ltr">Authorization: Bearer &lt;رمز الجهاز&gt;</span> والجسم:
                            <pre className="mt-1 overflow-x-auto rounded-lg bg-ink p-3 font-mono text-xs text-white" dir="ltr">{example}</pre>
                        </li>
                        <li>
                            الرد يحتوي <span className="font-mono text-xs" dir="ltr">allowed: true/false</span> والسبب؛ يفتح الجهاز البوابة عند السماح فقط. أنواع القراءة:
                            <span className="font-mono text-xs" dir="ltr"> qr · card · fingerprint · pin · barcode</span>.
                        </li>
                        <li>
                            فحص الاتصال: <span className="font-mono text-xs" dir="ltr">POST {apiBase}/heartbeat</span>
                        </li>
                    </ol>
                    <p className="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">
                        <KeyRound className="mt-0.5 size-3.5 shrink-0" />
                        الربط المباشر بموديل معيّن (مثل أجهزة البصمة التي تعمل ببروتوكولات خاصة) يحتاج تحققًا من توافق الجهاز واختبارًا عليه قبل الاعتماد.
                    </p>
                </Card>
            </div>

            <Modal open={open} onClose={() => setOpen(false)} title={editing ? `تعديل: ${editing.name}` : 'جهاز جديد'} size="lg" footer={
                <>
                    <Button variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
                    <Button type="submit" form="device-form" loading={form.processing}>{editing ? 'حفظ' : 'إضافة وإصدار رمز'}</Button>
                </>
            }>
                <form id="device-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="اسم الجهاز" error={form.errors.name} required className="sm:col-span-2">
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="مثال: بوابة المدخل الرئيسي" required autoFocus />
                    </Field>
                    <Field label="النوع" error={form.errors.type}>
                        <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            {types.map((t) => <option key={t} value={t}>{TYPE_LABELS[t] ?? t}</option>)}
                        </Select>
                    </Field>
                    <Field label="الاستخدام" error={form.errors.purpose}>
                        <Select value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)}>
                            {Object.entries(PURPOSES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <Field label="الاتجاه" error={form.errors.direction}>
                        <Select value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                            {Object.entries(DIRECTIONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <Field label="الفرع" error={form.errors.branch_id}>
                        <Select value={form.data.branch_id ?? ''} onChange={(e) => form.setData('branch_id', e.target.value || null)} placeholder="—">
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="الشركة المصنعة" error={form.errors.vendor}>
                        <Input value={form.data.vendor} onChange={(e) => form.setData('vendor', e.target.value)} />
                    </Field>
                    <Field label="الموديل" error={form.errors.model}>
                        <Input value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} />
                    </Field>
                    <Field label="الرقم التسلسلي" error={form.errors.serial_number} className="sm:col-span-2">
                        <Input dir="ltr" value={form.data.serial_number} onChange={(e) => form.setData('serial_number', e.target.value)} />
                    </Field>
                    {editing && (
                        <div className="sm:col-span-2">
                            <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="مفعّل" hint="الجهاز الموقوف يُرفض اتصاله" />
                        </div>
                    )}
                </form>
            </Modal>
        </ClubLayout>
    );
}
