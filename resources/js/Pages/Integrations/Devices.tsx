import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Copy, FlaskConical, Fingerprint, KeyRound, Pencil, Plus, RefreshCw, ShieldAlert, ShieldCheck, Trash2, Wifi, WifiOff } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Toggle } from '@/Components/ui';
import { IntegrationBack, IntegrationSummary, type IntegrationStatusData } from '@/Components/Integrations';
import { useBranchDefault, useCan, useShared } from '@/hooks/useShared';
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
    online: boolean;
};

type DeviceEvent = { key: string; at: string; device: string | null; who: string | null; kind: 'member' | 'staff'; result: string; reason: string; method: string; hint: string | null };
type Simulation = { allowed: boolean; message: string; holder: string | null; device: string; type: string; credential: string; at: string };
type Driver = { key: string; label: string; transport: string; description: string };

interface Props {
    devices: Device[];
    status: IntegrationStatusData;
    events: DeviceEvent[];
    simulation: Simulation | null;
    drivers: Driver[];
    staffCredentials: StaffCredential[];
    staff: Option[];
    branches: Option[];
    types: string[];
    apiBase: string;
    onlineMinutes: number;
}

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
const READING_TYPES: Record<string, string> = { qr: 'رمز QR للعضو', card: 'بطاقة', fingerprint: 'بصمة (رقم المستخدم في الجهاز)', pin: 'رمز PIN', barcode: 'باركود' };
const RESULTS: Record<string, { label: string; tone: 'green' | 'red' | 'gray' }> = { allowed: { label: 'مسموح', tone: 'green' }, denied: { label: 'مرفوض', tone: 'red' }, duplicate: { label: 'مكرر', tone: 'gray' } };

const blank: DeviceForm = { name: '', type: 'qr_reader', vendor: '', model: '', serial_number: '', direction: 'in', purpose: 'members', branch_id: null, is_active: true };

function copy(text: string) {
    void navigator.clipboard?.writeText(text);
}

export default function DevicesIntegration({ devices, status, events, simulation, drivers, staffCredentials, staff, branches, types, apiBase, onlineMinutes }: Props) {
    const { flash } = useShared();
    const can = useCan();
    const branchDefault = useBranchDefault();
    const [editing, setEditing] = useState<Device | null>(null);
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const form = useForm<DeviceForm>(blank);
    const credForm = useForm({ holder_id: '' as number | string, type: 'fingerprint', identifier: '', label: '' });
    const simForm = useForm({ device_id: (devices[0]?.id ?? '') as number | string, credential_type: 'qr', credential: '' });
    const staffName = (id: number) => staff.find((s) => s.id === id)?.name ?? `#${id}`;

    const openForm = (d: Device | null) => {
        setEditing(d);
        form.clearErrors();
        form.setData(d ? {
            name: d.name, type: d.type, vendor: d.vendor ?? '', model: d.model ?? '', serial_number: d.serial_number ?? '',
            direction: d.direction, purpose: d.purpose, branch_id: d.branch_id, is_active: d.is_active,
        } : { ...blank, branch_id: branchDefault ? Number(branchDefault) : null });
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

    const simulate = (e: FormEvent) => {
        e.preventDefault();
        if (!simForm.data.device_id) return;
        simForm.post(route('club.devices.simulate', simForm.data.device_id), { preserveScroll: true });
    };

    const example = JSON.stringify({ credential_type: 'qr', credential: '<رمز QR المقروء>' }, null, 2);

    return (
        <ClubLayout title="أجهزة البصمة والبوابات">
            <PageHeader
                back={<IntegrationBack />}
                title="أجهزة البصمة والبوابات"
                subtitle="قارئات QR والباركود والبطاقات، أجهزة البصمة، والبوابات — كل جهاز يتصل برمز خاص"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => openForm(null)}>جهاز جديد</Button>}
            />

            <IntegrationSummary service="devices" title="أجهزة البصمة والبوابات" status={status} />

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
                                    const isOnline = d.online;
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
                                                <p className="mt-0.5 text-[11px] text-muted num">{d.last_seen_at ? `آخر اتصال ${d.last_seen_at}` : 'لم يتصل بعد — التوافق غير مؤكد'}{d.last_ip ? ` · ${d.last_ip}` : ''}</p>
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
                        <Field label="المعرّف في الجهاز" error={credForm.errors.identifier} hint={credForm.data.type === 'fingerprint' ? 'رقم المستخدم في جهاز البصمة فقط؛ البصمة نفسها لا تُحفظ في النظام' : undefined}>
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
                    <CardHeader title="طريقة الربط" subtitle={`${drivers[0]?.label ?? 'البروتوكول العام'} — ${drivers[0]?.transport ?? 'HTTPS · JSON'}`} />
                    <p className="mb-3 text-[13px] leading-6 text-muted">{drivers[0]?.description}</p>
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
                            فحص الاتصال: <span className="font-mono text-xs" dir="ltr">POST {apiBase}/heartbeat</span> — يظهر الجهاز «متصل» إذا اتصل خلال آخر {onlineMinutes} دقائق.
                        </li>
                    </ol>
                    <p className="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs leading-6 text-amber-800">
                        <KeyRound className="mt-1 size-3.5 shrink-0" />
                        لا يُفترض توافق أي جهاز قبل معرفة الشركة والموديل والبروتوكول. الأجهزة ذات البروتوكولات الخاصة تحتاج جسرًا يتحدث البروتوكول العام، أو مشغّلًا يُبنى لها بعد اختباره على الجهاز نفسه.
                    </p>
                    <ul className="mt-3 space-y-1.5 text-xs leading-6 text-muted">
                        <li className="flex gap-2"><ShieldCheck className="mt-1 size-3.5 shrink-0" />النظام يرد بقرار السماح أو الرفض فقط، ولا يرسل أمر فتح لأي بوابة. فتح البوابة قرار الجهاز نفسه عند السماح.</li>
                        <li className="flex gap-2"><ShieldCheck className="mt-1 size-3.5 shrink-0" />البصمة تُسجَّل وتُطابق داخل الجهاز. النظام يحفظ رقم المستخدم في الجهاز فقط، لا صورة البصمة ولا قالبها.</li>
                    </ul>
                </Card>
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-5 [&>*]:min-w-0">
                <Card className="lg:col-span-2">
                    <CardHeader title="محاكاة قراءة" subtitle="جرّب ما سيرد به النظام على جهاز قبل توفره. لا يُسجَّل دخول ولا حضور، ولا يُفتح شيء." />
                    {devices.length === 0 ? (
                        <p className="text-sm text-muted">أضف جهازًا أولًا.</p>
                    ) : (
                        <form onSubmit={simulate} className="space-y-3">
                            <Field label="الجهاز">
                                <Select value={simForm.data.device_id} onChange={(e) => simForm.setData('device_id', e.target.value)}>
                                    {devices.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                </Select>
                            </Field>
                            <Field label="نوع القراءة" error={simForm.errors.credential_type}>
                                <Select value={simForm.data.credential_type} onChange={(e) => simForm.setData('credential_type', e.target.value)}>
                                    {Object.entries(READING_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </Select>
                            </Field>
                            <Field label="القيمة المقروءة" error={simForm.errors.credential} hint="رمز QR من بطاقة العضو، أو رقم البطاقة، أو رقم المستخدم في جهاز البصمة">
                                <Input dir="ltr" value={simForm.data.credential} onChange={(e) => simForm.setData('credential', e.target.value)} required />
                            </Field>
                            <Button type="submit" variant="secondary" loading={simForm.processing} icon={<FlaskConical className="size-4" />}>محاكاة</Button>
                        </form>
                    )}
                    {simulation && (
                        <div className={`mt-4 rounded-xl border p-3 text-sm ${simulation.allowed ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'}`}>
                            <p className="font-semibold">{simulation.allowed ? 'سيُسمح بالدخول' : 'سيُرفض'} — {simulation.message}</p>
                            <p className="mt-1 text-xs text-muted">
                                {simulation.device} · {READING_TYPES[simulation.type] ?? simulation.type} <span dir="ltr" className="font-mono">{simulation.credential}</span>
                                {simulation.holder ? ` · ${simulation.holder}` : ''} · <span className="num">{simulation.at}</span>
                            </p>
                            <p className="mt-1 text-[11px] text-muted">محاكاة فقط: لم يُسجَّل شيء.</p>
                        </div>
                    )}
                </Card>

                <Card padded={false} className="lg:col-span-3">
                    <div className="p-5 pb-0"><CardHeader title="سجل قراءات الأجهزة" subtitle="آخر ما وصل من الأجهزة: دخول الأعضاء وحضور الموظفين" /></div>
                    {events.length === 0 ? (
                        <EmptyState title="لا توجد قراءات بعد" description="تظهر هنا كل قراءة يرسلها جهاز متصل برمزه." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>الوقت</th><th>الجهاز</th><th>الشخص</th><th>النتيجة</th></tr></thead>
                                <tbody>
                                    {events.map((e) => (
                                        <tr key={e.key}>
                                            <td className="num text-xs">{e.at}</td>
                                            <td>{e.device ?? '—'}</td>
                                            <td>
                                                <p>{e.who ?? '—'}</p>
                                                <p className="text-[11px] text-muted">{e.kind === 'staff' ? 'موظف' : 'عضو'}{e.hint ? <> · <span dir="ltr" className="font-mono">{e.hint}</span></> : null}</p>
                                            </td>
                                            <td>
                                                <Badge tone={RESULTS[e.result]?.tone ?? 'gray'}>{RESULTS[e.result]?.label ?? e.result}</Badge>
                                                <p className="mt-0.5 text-[11px] text-muted">{e.reason}</p>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
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
