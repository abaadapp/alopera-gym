import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Ban, CheckCircle2, KeyRound, LoaderCircle, Monitor, MonitorSmartphone, Pencil, Plug, Plus, Printer, ScrollText, Trash2, Wifi } from 'lucide-react';
import { SettingsScreen, SettingsTitle } from '@/Components/Settings';
import { Badge, Button, Card, Checkbox, EmptyState, Field, Input, Modal, Notice, Select, Toggle } from '@/Components/ui';
import { DropdownItem, DropdownSeparator } from '@/Components/ui/Dropdown';
import { ConfirmDelete, RowMenu } from '@/Components/Products/parts';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';
import { recordPrint } from '@/lib/printing/print-log';
import { failureMessage, insideApp, printDocument, stageMessage, testPrinter } from '@/lib/printing/printer-service';
import type { PrinterLike, PrintOutcome, PrintStage } from '@/lib/printing/types';

interface Peripheral { id: number; type: string; name: string; brand: string | null; model: string | null; connection: string; address: string | null; port: number | null; paper_width: number | null; paper: string | null; direct: boolean; printing: PrinterLike | null; is_default: boolean; auto_print: boolean; print_website_orders: boolean; is_active: boolean; notes: string | null; last_status: string | null; last_error: string | null; last_checked_at: string | null }
interface PrintLog { id: number; at: string; document: string; number: string | null; trigger: string; via: string | null; status: 'claimed' | 'printed' | 'opened' | 'failed'; message: string | null; may_have_printed: boolean; printer: string | null; by: string | null }
interface Device {
    id: number; name: string; status: 'pending' | 'active' | 'revoked'; branch_id: number; branch: string | null; settlement_account_id: number | null; settlement_account: string | null; sales_count: number; revoke_reason: string | null;
    activated_by: string | null; activated_at: string | null; last_seen: string | null; last_seen_at: string | null; code_expires_at: string | null; code_expired: boolean; is_this: boolean; auto_print: boolean; errors: string[]; peripherals: Peripheral[]; logs: PrintLog[];
}
interface Props {
    devices: Device[];
    code: { device: number; code: string } | null;
    branches: { id: number; name: string }[];
    accounts: { id: number; name: string }[];
    policy: { require_device: boolean };
    types: Record<string, string>;
    drivable: string[];
    connections: Record<string, string>;
    printerConnections: Record<string, string>;
    papers: Record<string, string>;
    defaultPort: number;
}

/** What the panel can say of a printer's options, beside the labels the server sends. */
type Options = Pick<Props, 'types' | 'drivable' | 'connections' | 'printerConnections' | 'papers' | 'defaultPort'>;

const STATUS = { pending: ['بانتظار الربط', 'amber'], active: ['مفعّل', 'green'], revoked: ['ملغى التفعيل', 'red'] } as const;

/**
 * أجهزة نقاط البيع: each till or tablet of the club, its branch, status, last contact, who activated it, the bank its
 * card terminal pays into, and its peripherals. Separate from the access devices at the gates (integrations).
 */
export default function PosDevices({ devices, code, branches, accounts, policy, ...options }: Props) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Device | null>(null);
    const [hardware, setHardware] = useState<Device | null>(null);
    const [deleting, setDeleting] = useState<Device | null>(null);
    // Asked in the system's own dialog, not the browser's.
    const [revoking, setRevoking] = useState<Device | null>(null);
    const [busy, setBusy] = useState(false);
    const revoke = (d: Device) => setRevoking(d);
    const shown = code ? devices.find((d) => d.id === code.device) : null;
    const current = hardware ? devices.find((d) => d.id === hardware.id) ?? null : null;

    return (
        <SettingsScreen title="أجهزة نقاط البيع" width="full">
            <SettingsTitle title="أجهزة نقاط البيع" description="كل جهاز مربوط بفرع ويُعرَف بهوية مستقلة؛ منفصلة عن أجهزة البصمة وبوابات الدخول" actions={<Button icon={<Plus />} onClick={() => setAdding(true)}>إضافة جهاز</Button>} />

            {code && shown && (
                <Card className="mb-4 border-[#111]/20">
                    <div className="flex flex-wrap items-center gap-4">
                        <span className="grid size-12 place-items-center rounded-[12px] bg-[#111] text-white"><KeyRound className="size-5" /></span>
                        <div className="min-w-0 flex-1">
                            <p className="text-[13px] text-muted">رمز ربط «{shown.name}» — صالح 30 دقيقة ولمرة واحدة، ولن يظهر مرة أخرى</p>
                            <p className="mt-1 text-2xl font-bold tracking-[0.2em] num" dir="ltr">{code.code}</p>
                        </div>
                        <p className="text-[12px] leading-6 text-muted">على الجهاز: افتح <span className="font-medium text-ink">نقطة البيع ← تفعيل هذا الجهاز</span> وأدخل الرمز.</p>
                    </div>
                </Card>
            )}

            <Card className="mb-4">
                <Toggle checked={policy.require_device} onChange={(v) => router.put(route('club.settings.pos-devices.policy'), { require_device: v }, { preserveScroll: true })} label="البيع من الأجهزة المفعّلة فقط" hint="عند تفعيله لا تتم عملية بيع إلا من جهاز نقطة بيع مفعّل لفرع الموظف. عند إيقافه يمكن لمن يملك صلاحية البيع البيع من أي متصفح، وتُسجَّل عمليات الأجهزة المفعّلة باسمها." />
            </Card>

            <Card padded={false} className="overflow-hidden">
                {devices.length === 0 ? (
                    <EmptyState icon={<MonitorSmartphone className="size-6" />} title="لا توجد أجهزة بعد" description="أضف جهازًا واحصل على رمز ربط، أو افتح نقطة البيع على الجهاز وفعّله من هناك." action={<Button size="sm" icon={<Plus />} onClick={() => setAdding(true)}>إضافة جهاز</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الجهاز</th><th>الفرع</th><th>الحالة</th><th>الملحقات</th><th>آخر اتصال</th><th>التفعيل</th><th>عمليات البيع</th><th className="w-12" /></tr></thead>
                            <tbody>
                                {devices.map((d) => (
                                    <tr key={d.id}>
                                        <td>
                                            <div className="flex items-center gap-3">
                                                <span className="grid size-11 shrink-0 place-items-center rounded-[8px] border border-line bg-[#f7f7f5] text-[#6b7280]"><Monitor className="size-4" /></span>
                                                <div className="min-w-0">
                                                    <p className="font-medium text-ink">{d.name}{d.is_this && <span className="ms-2 rounded-full bg-[#eef2ff] px-2 py-0.5 text-[11px] text-[#4338ca]">هذا الجهاز</span>}</p>
                                                    {d.settlement_account && <p className="text-[11px] text-[#9ca3af]">جهاز البطاقات يودع في: {d.settlement_account}</p>}
                                                    {d.errors.length > 0 && <p className="flex items-center gap-1 text-[11px] text-red-700"><AlertTriangle className="size-3" />{d.errors[0]}</p>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="text-[13px]">{d.branch}</td>
                                        <td><Badge tone={STATUS[d.status][1]}>{STATUS[d.status][0]}</Badge>{d.status === 'pending' && <p className="mt-1 text-[11px] text-[#9ca3af]">{d.code_expired ? 'انتهى الرمز' : `الرمز حتى ${d.code_expires_at}`}</p>}{d.status === 'revoked' && d.revoke_reason && <p className="mt-1 max-w-40 truncate text-[11px] text-[#9ca3af]">{d.revoke_reason}</p>}</td>
                                        <td><button type="button" onClick={() => setHardware(d)} className="inline-flex items-center gap-1.5 rounded-[8px] px-2 py-1 text-[13px] hover:bg-[#f2f2f0]"><Plug className="size-3.5" />{d.peripherals.filter((p) => p.is_active).length ? `${number(d.peripherals.filter((p) => p.is_active).length)} ملحق` : 'إضافة'}</button></td>
                                        <td className="text-[13px] text-[#6b7280]" title={d.last_seen_at ?? undefined}>{d.last_seen ?? '—'}</td>
                                        <td className="text-[13px]">{d.activated_at ? <><span className="num">{d.activated_at}</span><span className="block text-[11px] text-[#9ca3af]">{d.activated_by}</span></> : '—'}</td>
                                        <td className="num">{number(d.sales_count)}</td>
                                        <td className="text-end">
                                            <RowMenu label={`إجراءات ${d.name}`}>
                                                {(close) => <>
                                                    <DropdownItem onClick={() => { close(); setEditing(d); }}><Pencil /> تعديل</DropdownItem>
                                                    <DropdownItem onClick={() => { close(); setHardware(d); }}><Plug /> الملحقات</DropdownItem>
                                                    {d.status !== 'active' && <DropdownItem onClick={() => { close(); router.post(route('club.settings.pos-devices.code', d.id), {}, { preserveScroll: true }); }}><KeyRound /> رمز ربط جديد</DropdownItem>}
                                                    <DropdownSeparator />
                                                    {d.status === 'active' ? <DropdownItem destructive onClick={() => { close(); revoke(d); }}><Ban /> إلغاء التفعيل</DropdownItem>
                                                        : d.sales_count === 0 && <DropdownItem destructive onClick={() => { close(); setDeleting(d); }}><Trash2 /> حذف السجل</DropdownItem>}
                                                </>}
                                            </RowMenu>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">يحمل الجهاز المفعّل هوية سرية في متصفحه لا تُخزَّن إلا مشفّرة عندنا؛ إلغاء التفعيل يقطعه فورًا، ونقله لفرع آخر يلغي تفعيله حتى يُربط من جديد. الجهاز الذي له عمليات بيع يبقى في السجل.</p>

            {adding && <DeviceDialog branches={branches} accounts={accounts} onClose={() => setAdding(false)} />}
            {editing && <DeviceDialog device={editing} branches={branches} accounts={accounts} onClose={() => setEditing(null)} />}
            {current && <PeripheralsDialog device={current} options={options} onClose={() => setHardware(null)} />}
            <Modal
                open={revoking !== null}
                onClose={() => setRevoking(null)}
                size="xs"
                title={`إلغاء تفعيل «${revoking?.name ?? ''}»`}
                footer={<><Button variant="outline" onClick={() => setRevoking(null)}>تراجع</Button><Button variant="danger" loading={busy} onClick={() => revoking && router.post(route('club.settings.pos-devices.revoke', revoking.id), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setRevoking(null); } })}>إلغاء التفعيل</Button></>}
            >
                <p className="text-sm text-[#4b4b4b]">{revoking?.is_this ? 'هذا هو الجهاز الذي تستخدمه الآن. ' : ''}لن يستطيع البيع حتى يُربط من جديد.</p>
            </Modal>
            <ConfirmDelete target={deleting} url={deleting && route('club.settings.pos-devices.destroy', deleting.id)} title={`حذف «${deleting?.name}»`} message="جهاز لم تُسجَّل عليه أي عملية بيع؛ يُحذف سجله وملحقاته." onClose={() => setDeleting(null)} />
        </SettingsScreen>
    );
}

function DeviceDialog({ device, branches, accounts, onClose }: { device?: Device; branches: Props['branches']; accounts: Props['accounts']; onClose: () => void }) {
    const form = useForm({ name: device?.name ?? '', branch_id: String(device?.branch_id ?? branches[0]?.id ?? ''), settlement_account_id: device?.settlement_account_id ? String(device.settlement_account_id) : '', auto_print: device?.auto_print ?? false });
    const moved = device && form.data.branch_id !== String(device.branch_id);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, settlement_account_id: d.settlement_account_id || null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (device) form.put(route('club.settings.pos-devices.update', device.id), options);
        else form.post(route('club.settings.pos-devices.store'), options);
    };

    return (
        <Modal open onClose={onClose} title={device ? `تعديل «${device.name}»` : 'إضافة جهاز نقطة بيع'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button type="submit" form="device-form" loading={form.processing}>{device ? 'حفظ' : 'إضافة وإصدار رمز ربط'}</Button></>}>
            <form id="device-form" onSubmit={submit} className="space-y-4">
                <Field label="اسم الجهاز" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={80} placeholder="مثل: كاشير الاستقبال" autoFocus /></Field>
                <Field label="الفرع" error={form.errors.branch_id} required hint={moved ? undefined : 'تُسجَّل مبيعات الجهاز ومخزونها على هذا الفرع'}>
                    <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)}>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</Select>
                </Field>
                {moved && <Notice tone="amber">نقل الجهاز لفرع آخر يلغي تفعيله الحالي؛ أصدر له رمز ربط جديدًا بعد الحفظ.</Notice>}
                <Field label="حساب تسوية جهاز البطاقات" error={form.errors.settlement_account_id} hint="الحساب البنكي الذي يودع فيه جهاز البطاقات المرتبط بهذه النقطة. يُسجَّل مع كل عملية بطاقة ولا يغيّر طريقة تسجيل الدفع؛ لا يوجد ربط مباشر مع البنك.">
                    <Select value={form.data.settlement_account_id} onChange={(e) => form.setData('settlement_account_id', e.target.value)}><option value="">لا يوجد</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select>
                </Field>
            </form>
        </Modal>
    );
}

function PeripheralsDialog({ device, options, onClose }: { device: Device; options: Options; onClose: () => void }) {
    const [editing, setEditing] = useState<Peripheral | 'new' | null>(device.peripherals.length ? null : 'new');
    const [removing, setRemoving] = useState<Peripheral | null>(null);
    const [tab, setTab] = useState<'hardware' | 'log'>('hardware');
    const { types, drivable, connections, papers } = options;
    // In the list, the short name of the way; the form says it in full.
    const connectionLabel = (p: Peripheral) => (p.type === 'printer' ? (p.connection === 'network' ? 'شبكة (IP)' : 'حوار الطباعة') : connections[p.connection]);

    return (
        <Modal open onClose={onClose} size="lg" title={`ملحقات «${device.name}»`} footer={editing === null ? <><Button variant="outline" onClick={onClose}>إغلاق</Button><Button icon={<Plus />} onClick={() => setEditing('new')}>إضافة ملحق</Button></> : undefined}>
            {editing !== null ? (
                <PeripheralForm device={device} peripheral={editing === 'new' ? null : editing} options={options} onDone={() => setEditing(null)} />
            ) : (
                <>
                    <div className="mb-3 flex gap-1 rounded-[10px] bg-[#f2f2f0] p-1 text-[13px]" role="tablist">
                        {([['hardware', 'الملحقات'], ['log', `سجل الطباعة${device.logs.length ? ` (${device.logs.length})` : ''}`]] as const).map(([k, label]) => (
                            <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cn('flex-1 rounded-[8px] px-3 py-1.5 font-medium', tab === k ? 'bg-white text-ink shadow-sm' : 'text-muted')}>{label}</button>
                        ))}
                    </div>
                    {tab === 'log' ? <PrintLogList logs={device.logs} /> : device.peripherals.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-muted">لا ملحقات بعد.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {device.peripherals.map((p) => (
                                <li key={p.id} className="space-y-2 py-3">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <span className={cn('grid size-10 place-items-center rounded-[10px] border border-line', p.is_active ? 'bg-[#f7f7f5] text-[#4b4b4b]' : 'text-[#c4c4c4]')}>{p.type === 'printer' ? <Printer className="size-4" /> : <Plug className="size-4" />}</span>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">{p.name} {!drivable.includes(p.type) && <Badge tone="amber" className="ms-1">للسجل</Badge>}{p.is_default && <Badge tone="blue" className="ms-1">الافتراضية</Badge>}{!p.is_active && <Badge className="ms-1">موقوفة</Badge>}</p>
                                            <p className="text-[12px] text-[#9ca3af]">{types[p.type]} · {connectionLabel(p)}{p.address && <> · <span className="num" dir="ltr">{p.address}:{p.port ?? options.defaultPort}</span></>}{p.paper && <> · {papers[p.paper] ?? p.paper}</>}{[p.brand, p.model].filter(Boolean).length > 0 && ` · ${[p.brand, p.model].filter(Boolean).join(' ')}`}</p>
                                            {p.type === 'printer' && <p className="text-[11px] text-[#9ca3af]">{p.auto_print ? 'طباعة تلقائية بعد نجاح العملية' : 'طباعة يدوية'}{p.print_website_orders && ' · تطبع طلبات الموقع عند تجهيزها'}</p>}
                                        </div>
                                        <Button size="sm" variant="ghost" icon={<Pencil />} aria-label={`تعديل ${p.name}`} onClick={() => setEditing(p)} />
                                        <Button size="sm" variant="ghost" icon={<Trash2 />} aria-label={`حذف ${p.name}`} onClick={() => setRemoving(p)} />
                                    </div>
                                    {p.type === 'printer' && p.is_active && <PrinterCheck device={device} printer={p} />}
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
            <ConfirmDelete target={removing} url={removing && route('club.settings.pos-devices.peripherals.destroy', { device: device.id, peripheral: removing.id })} title={`حذف «${removing?.name ?? ''}»`} message="يُحذف الملحق من هذا الجهاز؛ لا يؤثر في عمليات البيع المسجلة." onClose={() => setRemoving(null)} />
        </Modal>
    );
}

/**
 * A printer's state and its two tests. «Test the connection and print» is real: the club's app on this device connects
 * to the network printer and prints a page, and says what happened (it needs the app — a browser cannot reach a printer
 * on the local network, and it is not pretended). «Test page» goes through the print dialog, the browser's or the
 * system's in the app; whether it came out is for the person at the printer to see. Every test goes to the print log.
 */
function PrinterCheck({ device, printer }: { device: Device; printer: Peripheral }) {
    const [stage, setStage] = useState<PrintStage | null>(null);
    const [outcome, setOutcome] = useState<PrintOutcome | null>(null);
    const app = insideApp();
    const done = (o: PrintOutcome) => {
        setStage(null);
        setOutcome(o);
        void recordPrint(o, { subject: { document: 'test' }, trigger: 'test', printer: printer.id }).then(() => router.reload({ only: ['devices'] }));
    };
    const direct = () => {
        setOutcome(null);
        setStage('connecting');
        void testPrinter(printer.printing ?? {}, device.name, setStage).then(done);
    };
    const page = () => {
        setOutcome(null);
        void printDocument({ title: 'printer-test', url: route('club.settings.pos-devices.peripherals.test', { device: device.id, peripheral: printer.id }) }).then((o) => {
            if (!o.ok) setOutcome(o);
            else void recordPrint(o, { subject: { document: 'test' }, trigger: 'test', printer: printer.id });
        });
    };

    return (
        <div className="space-y-2 rounded-[10px] bg-[#f9fafb] px-3 py-2 text-[12px] sm:ms-13">
            <div className="flex flex-wrap items-center gap-2">
                <span className="min-w-0 basis-full sm:basis-auto sm:flex-1">
                    {printer.last_status === 'ok' && <span className="inline-flex items-center gap-1 text-[#047857]"><CheckCircle2 className="size-3.5" />آخر اختبار مباشر نجح{printer.last_checked_at && <> · <span className="num">{printer.last_checked_at}</span></>}</span>}
                    {printer.last_status === 'error' && <span className="inline-flex items-center gap-1 text-[#b91c1c]"><AlertTriangle className="size-3.5" />{printer.last_error ?? 'فشل آخر اختبار'}{printer.last_checked_at && <> · <span className="num">{printer.last_checked_at}</span></>}</span>}
                    {!printer.last_status && <span className="text-muted">لم تُختبر مباشرة بعد{printer.direct ? '' : ' — تُطبع عبر حوار الطباعة'}</span>}
                </span>
                {printer.direct && <Button size="sm" variant="outline" icon={<Wifi />} loading={stage !== null} disabled={!app} onClick={direct}>اختبار الاتصال والطباعة</Button>}
                <Button size="sm" variant="outline" icon={<Printer />} onClick={page}>صفحة اختبار</Button>
            </div>
            {printer.direct && !app && <p className="text-muted">الاختبار المباشر يعمل من تطبيق الجم على هاتف أو جهاز لوحي في شبكة الطابعة نفسها؛ المتصفح لا يصل إلى طابعات الشبكة المحلية.</p>}
            {stage && <p className="flex items-center gap-1.5 text-muted" role="status"><LoaderCircle className="size-3.5 animate-spin" />{stageMessage(stage)}</p>}
            {outcome && (outcome.ok ? (
                outcome.via === 'native' && <p className="flex items-center gap-1.5 text-[#047857]" role="status"><CheckCircle2 className="size-3.5" />تم الاتصال وطُبعت صفحة الاختبار{outcome.effectivePort ? <> · المنفذ <span className="num">{outcome.effectivePort}</span></> : null}</p>
            ) : (
                <p className="flex items-start gap-1.5 text-[#b91c1c]" role="alert"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{outcome.connected ? 'تم الاتصال بالطابعة، ' : ''}{failureMessage(outcome.code)}</p>
            ))}
        </div>
    );
}

const LOG_TONE = { printed: 'green', opened: 'blue', failed: 'red', claimed: 'amber' } as const;
const LOG_LABEL = { printed: 'طُبعت', opened: 'فُتح حوار الطباعة', failed: 'فشلت', claimed: 'لم يصل رد' } as const;

/** The device's print log: what was printed, how, by whom, and why a print failed. */
function PrintLogList({ logs }: { logs: PrintLog[] }) {
    if (logs.length === 0) return <p className="flex flex-col items-center gap-2 py-6 text-center text-[13px] text-muted"><ScrollText className="size-6 text-[#d1d5db]" />لا طباعة مسجّلة على هذا الجهاز بعد.</p>;

    return (
        <ul className="divide-y divide-line text-[13px]">
            {logs.map((l) => (
                <li key={l.id} className="flex flex-wrap items-start gap-x-3 gap-y-1 py-2.5">
                    <Badge tone={LOG_TONE[l.status]}>{LOG_LABEL[l.status]}</Badge>
                    <div className="min-w-0 flex-1">
                        <p className="text-ink">{l.document === 'test' ? 'صفحة اختبار' : <span className="num">{l.number ?? l.document}</span>} <span className="text-muted">· {l.trigger}{l.via && ` · ${l.via}`}{l.printer && ` · ${l.printer}`}</span></p>
                        {l.message && <p className="text-[12px] text-[#b91c1c]">{l.message}{l.may_have_printed && ' — ربما خرج جزء منه'}</p>}
                    </div>
                    <span className="text-[12px] text-muted"><span className="num">{l.at}</span>{l.by && ` · ${l.by}`}</span>
                </li>
            ))}
        </ul>
    );
}

function PeripheralForm({ device, peripheral, options, onDone }: { device: Device; peripheral: Peripheral | null; options: Options; onDone: () => void }) {
    const { types, drivable, connections, printerConnections, papers, defaultPort } = options;
    const form = useForm({
        type: peripheral?.type ?? 'printer', name: peripheral?.name ?? '', brand: peripheral?.brand ?? '', model: peripheral?.model ?? '',
        // A printer saved before as USB or Bluetooth is one printed through the dialog.
        connection: peripheral ? (peripheral.type === 'printer' && peripheral.connection !== 'network' ? 'browser' : peripheral.connection) : 'browser',
        address: peripheral?.address ?? '', port: peripheral?.port ? String(peripheral.port) : '', paper: peripheral?.paper ?? '80mm',
        is_default: peripheral?.is_default ?? !device.peripherals.some((p) => p.type === 'printer'), auto_print: peripheral?.auto_print ?? false, print_website_orders: peripheral?.print_website_orders ?? false, is_active: peripheral?.is_active ?? true, notes: peripheral?.notes ?? '',
    });
    const printer = form.data.type === 'printer';
    const thermal = form.data.paper === '80mm' || form.data.paper === '58mm';
    const choices = printer ? printerConnections : connections;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, port: d.port || null, address: d.address || null, paper: printer ? d.paper : null }));
        const opts = { preserveScroll: true, onSuccess: onDone };
        if (peripheral) form.put(route('club.settings.pos-devices.peripherals.update', { device: device.id, peripheral: peripheral.id }), opts);
        else form.post(route('club.settings.pos-devices.peripherals.store', device.id), opts);
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="النوع" error={form.errors.type}><Select value={form.data.type} onChange={(e) => form.setData((d) => ({ ...d, type: e.target.value, connection: e.target.value === 'printer' ? (d.connection === 'network' ? 'network' : 'browser') : d.connection }))}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                <Field label="الاسم" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={60} placeholder="مثل: طابعة الاستقبال" /></Field>
                <Field label="الشركة" error={form.errors.brand}><Input value={form.data.brand} onChange={(e) => form.setData('brand', e.target.value)} maxLength={60} /></Field>
                <Field label="الطراز" error={form.errors.model}><Input value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} maxLength={60} placeholder="مثل: TM-T20" /></Field>
                {printer && <Field label="الورق" error={form.errors.paper}><Select value={form.data.paper} onChange={(e) => form.setData('paper', e.target.value)}>{Object.entries(papers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>}
                <Field label="طريقة الاتصال" error={form.errors.connection} className={printer ? undefined : 'sm:col-span-1'}><Select value={form.data.connection} onChange={(e) => form.setData('connection', e.target.value)}>{Object.entries(choices).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                {form.data.connection === 'network' && <>
                    <Field label="عنوان IP على الشبكة المحلية" error={form.errors.address} required hint="مثل 192.168.1.50 — العنوان وحده، والمنفذ في حقله"><Input dir="ltr" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} placeholder="192.168.1.50" /></Field>
                    <Field label="المنفذ" error={form.errors.port} hint={`فارغ = ${defaultPort}، المنفذ الشائع للطباعة الخام`}><Input type="number" dir="ltr" value={form.data.port} onChange={(e) => form.setData('port', e.target.value)} placeholder={String(defaultPort)} /></Field>
                </>}
            </div>
            {!drivable.includes(form.data.type) && <Notice tone="amber">هذا النوع يُسجَّل للمتابعة والدعم فقط؛ التحكم فيه يحتاج تطبيقًا وسيطًا على الجهاز غير متوفر حاليًا. {form.data.type === 'drawer' && 'الدرج النقدي يُفتح عادةً عبر الطابعة بأمر خاص لا تدعمه الطباعة الحالية.'}</Notice>}
            {printer && form.data.connection === 'network' && thermal && <Notice tone="gray">يطبع تطبيق الجم على الهاتف أو الجهاز اللوحي مباشرةً على هذه الطابعة عبر الشبكة المحلية (ESC/POS)، ويقول إن تعذّر الاتصال. من متصفح عادي تُطبع الإيصالات عبر حوار الطباعة؛ الخادم لا يتصل بشبكتك المحلية.</Notice>}
            {printer && form.data.connection === 'network' && !thermal && <Notice tone="amber">الطباعة المباشرة للطابعات الحرارية فقط. طابعة {papers[form.data.paper]} تُطبع عبر حوار الطباعة في الجهاز (AirPrint أو Mopria أو الطابعة المثبتة)، والعنوان يُحفظ للمرجع.</Notice>}
            {printer && form.data.connection !== 'network' && <Notice tone="gray">تُطبع عبر حوار الطباعة: في المتصفح، أو في نظام الجهاز داخل تطبيق الجم. ما يظهر فيه من طابعات (USB أو Bluetooth أو AirPrint) يعتمد على نظام الجهاز وتعريفاته، لا على هذا الإعداد.</Notice>}
            {form.data.type === 'scanner' && <Notice tone="gray">ماسح الباركود الذي يعمل كلوحة مفاتيح يعمل مباشرة في خانة البحث بنقطة البيع.</Notice>}
            {printer && (
                <div className="space-y-2 rounded-[12px] border border-line p-4">
                    <Checkbox label="الطابعة الافتراضية لهذا الجهاز" checked={form.data.is_default} onChange={(e) => form.setData('is_default', e.target.checked)} />
                    <Checkbox label="طباعة الإيصال تلقائيًا بعد نجاح العملية (مرة واحدة لكل عملية)" checked={form.data.auto_print} onChange={(e) => form.setData('auto_print', e.target.checked)} />
                    <Checkbox label="طباعة طلب الموقع عند بدء تجهيزه" checked={form.data.print_website_orders} onChange={(e) => form.setData('print_website_orders', e.target.checked)} />
                </div>
            )}
            <Checkbox label="مفعّلة" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={onDone}>تراجع</Button><Button type="submit" loading={form.processing}>حفظ الملحق</Button></div>
        </form>
    );
}
