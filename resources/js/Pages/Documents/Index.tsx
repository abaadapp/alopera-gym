import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Check, Download, FileClock, FileText, FileX, FolderOpen, Lock, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Pagination, SearchInput, Select, StatCard, Textarea } from '@/Components/ui';
import { date, dayCount, number } from '@/lib/format';
import type { Paginated } from '@/types';
import { useBranchDefault } from '@/hooks/useShared';

export interface DocumentRow {
    id: number;
    name: string;
    reference_number: string | null;
    issuer: string | null;
    status: 'active' | 'cancelled';
    is_confidential: boolean;
    notes: string | null;
    renewal_months: number | null;
    document_type_id: number | null;
    branch_id: number | null;
    responsible_user_id: number | null;
    issue_date: string | null;
    expiry_date: string | null;
    days_left: number | null;
    state: 'valid' | 'expiring' | 'expired' | 'cancelled';
    state_label: string;
    type: string | null;
    branch: string | null;
    responsible: string | null;
    file_name: string | null;
    has_file: boolean;
}

type Option = { id: number; name: string };
type View = 'all' | 'expiring' | 'expired';

interface Props {
    view: View;
    documents: Paginated<DocumentRow>;
    alerts: { id: number; document_id: number; name: string; expiry_date: string; days_left: number | null; threshold: number }[];
    stats: { total: number; expiring: number; expired: number; alerts: number };
    soon: number;
    filters: { q?: string; type?: string };
    types: (Option & { renewal_months: number | null })[];
    branches: Option[];
    users: Option[];
    can: { create: boolean; renew: boolean; manage: boolean; confidential: boolean };
    maxKb: number;
}

const stateTone = { valid: 'green', expiring: 'amber', expired: 'red', cancelled: 'gray' } as const;
const TITLES: Record<View, [string, string]> = {
    all: ['جميع المستندات', 'سجلات النشاط ورخصه وعقوده، بتواريخ انتهائها ومن يتابعها'],
    expiring: ['قريبة الانتهاء', 'مستندات تنتهي خلال فترة التنبيه — جدّدها قبل موعدها'],
    expired: ['منتهية', 'مستندات انتهت صلاحيتها ولم تُجدَّد بعد'],
};

export function daysText(days: number | null): string {
    if (days === null) return 'بلا تاريخ انتهاء';
    if (days < 0) return `انتهى منذ ${dayCount(-days)}`;
    if (days === 0) return 'ينتهي اليوم';
    return `بعد ${dayCount(days)}`;
}

export default function DocumentsIndex(props: Props) {
    const { view, documents, alerts, stats, soon, filters, types, can } = props;
    const [editing, setEditing] = useState<DocumentRow | 'new' | null>(null);
    const [renewing, setRenewing] = useState<DocumentRow | null>(null);
    const [title, subtitle] = TITLES[view];

    return (
        <ClubLayout title={title}>
            <PageHeader title={title} subtitle={subtitle} actions={can.create && <Button icon={<Plus />} onClick={() => setEditing('new')}>مستند جديد</Button>} />

            <div className="mb-6 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="المستندات السارية" value={number(stats.total)} icon={<FileText />} tone="gray" href={route('club.business-documents.index', { view: 'all' })} />
                <StatCard label="قريبة الانتهاء" value={number(stats.expiring)} hint={`خلال ${dayCount(soon)}`} icon={<FileClock />} tone={stats.expiring ? 'amber' : 'gray'} href={route('club.business-documents.index', { view: 'expiring' })} />
                <StatCard label="منتهية" value={number(stats.expired)} icon={<FileX />} tone={stats.expired ? 'red' : 'gray'} href={route('club.business-documents.index', { view: 'expired' })} />
                <StatCard label="تنبيهات جديدة" value={number(stats.alerts)} icon={<AlertTriangle />} tone={stats.alerts ? 'amber' : 'gray'} />
            </div>

            {alerts.length > 0 && (
                <Card className="mb-6">
                    <p className="mb-2 flex items-center gap-2 text-[15px] font-semibold"><AlertTriangle className="size-4 text-amber-600" /> تنبيهات الانتهاء</p>
                    <ul className="divide-y divide-line">
                        {alerts.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                <span><span className="font-medium">{a.name}</span> <span className="text-muted">— {daysText(a.days_left)} (<span className="num">{date(a.expiry_date)}</span>)</span></span>
                                <Button size="sm" variant="ghost" icon={<Check />} onClick={() => router.post(route('club.business-documents.alerts.acknowledge', a.id), {}, { preserveScroll: true })}>تم الاطلاع</Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Card padded={false}>
                <div className="flex flex-wrap items-center gap-2 border-b border-line p-4">
                    <SearchInput value={filters.q} placeholder="اسم المستند، الرقم المرجعي، الجهة المصدرة" extra={{ view, type: filters.type }} />
                    <Select className="w-48" value={filters.type ?? ''} onChange={(e) => router.get(route('club.business-documents.index'), { view, q: filters.q || undefined, type: e.target.value || undefined }, { preserveState: true, replace: true })} aria-label="النوع">
                        <option value="">كل الأنواع</option>
                        {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </div>

                {documents.data.length === 0 ? (
                    <EmptyState icon={<FolderOpen />} title={view === 'all' ? 'لا توجد مستندات بعد' : 'لا شيء هنا'} description={view === 'all' ? 'أضف السجل التجاري والتراخيص والتأمين والعقود لتصلك تنبيهات قبل انتهائها.' : 'كل المستندات سارية.'} action={view === 'all' && can.create ? <Button size="sm" onClick={() => setEditing('new')}>إضافة مستند</Button> : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>المستند</th><th>الرقم المرجعي</th><th>الجهة المصدرة</th><th>تاريخ الانتهاء</th><th>الحالة</th><th>المسؤول</th><th /></tr></thead>
                            <tbody>
                                {documents.data.map((d) => (
                                    <tr key={d.id} className={d.status === 'cancelled' ? 'opacity-60' : undefined}>
                                        <td>
                                            <p className="flex items-center gap-1.5 font-medium">{d.name}{d.is_confidential && <Lock className="size-3.5 text-muted" aria-label="سري" />}</p>
                                            <p className="text-xs text-muted">{[d.type, d.branch].filter(Boolean).join(' · ') || '—'}</p>
                                        </td>
                                        <td className="num text-sm">{d.reference_number ?? '—'}</td>
                                        <td className="text-sm">{d.issuer ?? '—'}</td>
                                        <td>
                                            <p className="num text-sm">{d.expiry_date ? date(d.expiry_date) : '—'}</p>
                                            {d.expiry_date && d.status === 'active' && <p className={d.state === 'expired' ? 'text-xs text-red-700' : d.state === 'expiring' ? 'text-xs text-amber-700' : 'text-xs text-muted'}>{daysText(d.days_left)}</p>}
                                        </td>
                                        <td><Badge tone={stateTone[d.state]}>{d.state_label}</Badge></td>
                                        <td className="text-sm">{d.responsible ?? '—'}</td>
                                        <td>
                                            <div className="flex items-center justify-end gap-1">
                                                {d.has_file && <Button size="sm" variant="ghost" href={route('club.business-documents.download', d.id)} external icon={<Download />} aria-label={`تنزيل ${d.name}`} title={d.file_name ?? 'تنزيل'} />}
                                                {can.renew && d.status === 'active' && <Button size="sm" variant="outline" icon={<RefreshCw />} onClick={() => setRenewing(d)}>تجديد</Button>}
                                                {can.manage && <Button size="sm" variant="ghost" icon={<Pencil />} onClick={() => setEditing(d)} aria-label={`تعديل ${d.name}`} />}
                                                {can.manage && (
                                                    <ConfirmAction url={route('club.business-documents.destroy', d.id)} method="delete" title={`حذف «${d.name}»`} message="يُحذف المستند من القوائم والتنبيهات، ويبقى في سجل العمليات." confirmLabel="حذف" variant="ghost" icon={<Trash2 />}>
                                                        <span className="sr-only">حذف</span>
                                                    </ConfirmAction>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={documents} />
            </Card>

            {editing && <DocumentModal document={editing === 'new' ? null : editing} props={props} onClose={() => setEditing(null)} />}
            {renewing && <RenewModal document={renewing} maxKb={props.maxKb} onClose={() => setRenewing(null)} />}
        </ClubLayout>
    );
}

function FileInput({ onChange, current, error, maxKb }: { onChange: (f: File | null) => void; current?: string | null; error?: string; maxKb: number }) {
    return (
        <Field label="الملف (PDF أو صورة)" hint={`${current ? `الحالي: ${current} — ` : ''}الحد ${number(Math.round(maxKb / 1024))} ميغابايت`} error={error}>
            <Input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(e) => onChange(e.target.files?.[0] ?? null)} />
        </Field>
    );
}

function DocumentModal({ document, props, onClose }: { document: DocumentRow | null; props: Props; onClose: () => void }) {
    const { types, branches, users, can, maxKb } = props;
    const branchDefault = useBranchDefault();
    const form = useForm({
        name: document?.name ?? '',
        document_type_id: document?.document_type_id ? String(document.document_type_id) : '',
        reference_number: document?.reference_number ?? '',
        issuer: document?.issuer ?? '',
        issue_date: document?.issue_date ?? '',
        expiry_date: document?.expiry_date ?? '',
        renewal_months: document?.renewal_months ? String(document.renewal_months) : '',
        branch_id: document ? (document.branch_id ? String(document.branch_id) : '') : branchDefault,
        responsible_user_id: document?.responsible_user_id ? String(document.responsible_user_id) : '',
        status: document?.status ?? 'active',
        is_confidential: document?.is_confidential ?? false,
        notes: document?.notes ?? '',
        file: null as File | null,
    });

    const pickType = (id: string) => {
        form.setData('document_type_id', id);
        const type = types.find((t) => String(t.id) === id);
        if (type?.renewal_months && !form.data.renewal_months) form.setData((d) => ({ ...d, document_type_id: id, renewal_months: String(type.renewal_months) }));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(document ? route('club.business-documents.update', document.id) : route('club.business-documents.store'), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} size="lg" title={document ? `تعديل «${document.name}»` : 'مستند جديد'} footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button loading={form.processing} onClick={submit}>حفظ</Button></>}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="اسم المستند" error={form.errors.name} required className="sm:col-span-2">
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={160} placeholder="مثال: السجل التجاري — الفرع الرئيسي" />
                </Field>
                <Field label="النوع" error={form.errors.document_type_id}>
                    <Select value={form.data.document_type_id} onChange={(e) => pickType(e.target.value)} placeholder="بدون نوع">
                        {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </Field>
                <Field label="الرقم المرجعي" error={form.errors.reference_number}>
                    <Input dir="ltr" value={form.data.reference_number} onChange={(e) => form.setData('reference_number', e.target.value)} />
                </Field>
                <Field label="الجهة المصدرة" error={form.errors.issuer}>
                    <Input value={form.data.issuer} onChange={(e) => form.setData('issuer', e.target.value)} placeholder="مثال: وزارة التجارة والصناعة" />
                </Field>
                <Field label="مدة التجديد (شهر)" hint="إن كان يُجدَّد دوريًا" error={form.errors.renewal_months}>
                    <Input type="number" min={1} max={120} dir="ltr" value={form.data.renewal_months} onChange={(e) => form.setData('renewal_months', e.target.value)} />
                </Field>
                <Field label="تاريخ الإصدار" error={form.errors.issue_date}>
                    <Input type="date" value={form.data.issue_date} onChange={(e) => form.setData('issue_date', e.target.value)} />
                </Field>
                <Field label="تاريخ الانتهاء" hint="اتركه فارغًا إن كان لا ينتهي" error={form.errors.expiry_date}>
                    <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                </Field>
                <Field label="الفرع" error={form.errors.branch_id}>
                    <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="كل النادي">
                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                    </Select>
                </Field>
                <Field label="الموظف المسؤول عن المتابعة" error={form.errors.responsible_user_id}>
                    <Select value={form.data.responsible_user_id} onChange={(e) => form.setData('responsible_user_id', e.target.value)} placeholder="بدون">
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </Select>
                </Field>
                {document && (
                    <Field label="الحالة" error={form.errors.status}>
                        <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value as 'active' | 'cancelled')}>
                            <option value="active">ساري</option>
                            <option value="cancelled">ملغى (لم يعد مستخدمًا)</option>
                        </Select>
                    </Field>
                )}
                <div className="sm:col-span-2">
                    <FileInput onChange={(f) => form.setData('file', f)} current={document?.file_name} error={form.errors.file} maxKb={maxKb} />
                    {document?.has_file && <p className="mt-1 text-xs text-muted">رفع ملف هنا يصحّح النسخة الحالية. عند التجديد استخدم «تجديد» لتُحفظ النسخة السابقة في الأرشيف.</p>}
                </div>
                <Field label="ملاحظات" error={form.errors.notes} className="sm:col-span-2">
                    <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={2000} />
                </Field>
                {can.confidential && (
                    <div className="sm:col-span-2">
                        <Checkbox label="مستند سري: يراه من لديه صلاحية المستندات السرية والموظف المسؤول عنه فقط" checked={form.data.is_confidential} onChange={(e) => form.setData('is_confidential', e.target.checked)} />
                    </div>
                )}
            </form>
        </Modal>
    );
}

function addMonths(iso: string, months: number): string {
    const d = new Date(`${iso}T00:00:00`);
    d.setMonth(d.getMonth() + months);
    return d.toISOString().slice(0, 10);
}

function RenewModal({ document, maxKb, onClose }: { document: DocumentRow; maxKb: number; onClose: () => void }) {
    const suggested = document.expiry_date && document.renewal_months ? addMonths(document.expiry_date, document.renewal_months) : '';
    const form = useForm({ expiry_date: suggested, issue_date: '', reference_number: '', notes: '', file: null as File | null });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(route('club.business-documents.renew', document.id), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal open onClose={onClose} title={`تجديد «${document.name}»`} footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button loading={form.processing} icon={<RefreshCw />} onClick={submit}>تسجيل التجديد</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted">
                    {document.expiry_date ? <>ينتهي حاليًا في <span className="num">{date(document.expiry_date)}</span>. </> : null}
                    تُحفظ النسخة الحالية وتواريخها في سجل التجديدات، ويُسجَّل التجديد باسمك.
                </p>
                <Field label="تاريخ الانتهاء الجديد" error={form.errors.expiry_date} required>
                    <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="تاريخ الإصدار الجديد" error={form.errors.issue_date}>
                        <Input type="date" value={form.data.issue_date} onChange={(e) => form.setData('issue_date', e.target.value)} />
                    </Field>
                    <Field label="الرقم المرجعي الجديد" hint="إن تغيّر" error={form.errors.reference_number}>
                        <Input dir="ltr" value={form.data.reference_number} onChange={(e) => form.setData('reference_number', e.target.value)} />
                    </Field>
                </div>
                <FileInput onChange={(f) => form.setData('file', f)} error={form.errors.file} maxKb={maxKb} />
                <Field label="ملاحظات" error={form.errors.notes}>
                    <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={1000} />
                </Field>
            </form>
        </Modal>
    );
}
