import { useMemo, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Clock, PackageCheck, Plus, Send, Truck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, Checkbox, EmptyState, Field, Modal, PageHeader, Pagination, Select, StatCard, Textarea } from '@/Components/ui';
import { Toolbar, ToolbarSearch, visit } from '@/Components/Products/parts';
import { BranchPicker, clientKey, LinesEditor, Pill, TRANSFER_TONES, type BranchFilterState, type Line, type StockProduct } from '@/Components/Inventory/parts';
import { useCan } from '@/hooks/useShared';
import { number } from '@/lib/format';
import type { Paginated } from '@/types';

interface Row { id: number; number: string; status: string; status_label: string; from: string | null; to: string | null; quantity: number; by: string | null; at: string }
interface Props {
    transfers: Paginated<Row>;
    summary: { pending: number; approved: number; in_transit: number; discrepancy: number };
    filters: { status: string; q: string };
    labels: Record<string, string>;
    branch: BranchFilterState;
    branches: { id: number; name: string }[];
    products: StockProduct[];
}

/** نقل المخزون بين الفروع: requested → approved → sent (in transit) → received, or received short and settled. */
export default function Transfers({ transfers, summary, filters, labels, branch, branches, products }: Props) {
    const can = useCan();
    const [creating, setCreating] = useState(false);
    const go = (change: Record<string, string>) => visit('club.inventory.transfers', { ...filters, branch: branch.locked ? undefined : branch.value }, change);

    return (
        <ClubLayout title="نقل المخزون بين الفروع">
            <PageHeader title="نقل المخزون بين الفروع" subtitle="طلب ← موافقة ← إرسال ← استلام؛ يخرج المخزون من المرسل عند الإرسال ويدخل المستقبل عند الاستلام" actions={can('inventory.transfer') && <Button icon={<Plus />} onClick={() => setCreating(true)}>نقل جديد</Button>} />
            <div className="mb-4 grid auto-rows-fr grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="بانتظار الموافقة" value={number(summary.pending)} icon={<Clock />} tone={summary.pending ? 'amber' : 'gray'} href={route('club.inventory.transfers', { status: 'pending' })} />
                <StatCard label="معتمد بانتظار الإرسال" value={number(summary.approved)} icon={<Send />} tone="blue" href={route('club.inventory.transfers', { status: 'approved' })} />
                <StatCard label="قيد النقل" value={number(summary.in_transit)} icon={<Truck />} tone="violet" href={route('club.inventory.transfers', { status: 'in_transit' })} />
                <StatCard label="استُلم بفرق" value={number(summary.discrepancy)} icon={<AlertTriangle />} tone={summary.discrepancy ? 'red' : 'gray'} href={route('club.inventory.transfers', { status: 'discrepancy' })} />
            </div>
            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <ToolbarSearch value={filters.q} placeholder="ابحث برقم السند أو المنتج" onSearch={(q) => go({ q })} />
                    <Select className="h-9 w-48 py-1.5 text-[13px]" value={filters.status} onChange={(e) => go({ status: e.target.value })} aria-label="الحالة">
                        <option value="">كل الحالات</option>
                        {Object.entries(labels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </Select>
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>
                {transfers.data.length === 0 ? (
                    <EmptyState icon={<Truck className="size-6" />} title="لا توجد عمليات نقل" description="انقل مخزونًا من فرع إلى آخر؛ يعتمده المسؤول ثم يرسله الفرع ويستلمه الآخر." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>السند</th><th>من ← إلى</th><th>الكمية</th><th>الحالة</th><th>طلبه</th></tr></thead>
                            <tbody>
                                {transfers.data.map((t) => (
                                    <tr key={t.id}>
                                        <td><Link href={route('club.inventory.transfers.show', t.id)} className="font-medium text-ink hover:underline num">{t.number}</Link><p className="text-[11px] text-[#9ca3af] num">{t.at}</p></td>
                                        <td className="text-[13px]"><span className="inline-flex items-center gap-1.5">{t.from} <ArrowLeft className="size-3.5 text-[#9ca3af]" /> {t.to}</span></td>
                                        <td className="num">{number(t.quantity, t.quantity % 1 ? 3 : 0)}</td>
                                        <td><Pill tone={TRANSFER_TONES[t.status] ?? 'gray'}>{t.status_label}</Pill></td>
                                        <td className="text-[13px] text-[#6b7280]">{t.by}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={transfers} />
            </Card>
            {creating && <NewTransfer branch={branch} branches={branches} products={products} onClose={() => setCreating(false)} />}
        </ClubLayout>
    );
}

function NewTransfer({ branch, branches, products, onClose }: { branch: BranchFilterState; branches: { id: number; name: string }[]; products: StockProduct[]; onClose: () => void }) {
    const can = useCan();
    const key = useMemo(clientKey, []);
    const from = branch.value !== 'all' ? branch.value : String(branch.options[0]?.id ?? '');
    const form = useForm({ from_branch_id: from, to_branch_id: '', lines: [{ product_id: '', quantity: '' }] as Line[], notes: '', direct: false, client_key: key });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, lines: d.lines.filter((l) => l.product_id && Number(l.quantity) > 0) }));
        form.post(route('club.inventory.transfers.store'), { preserveScroll: true });
    };
    const err = form.errors as Record<string, string>;

    return (
        <Modal open onClose={onClose} size="lg" title="نقل مخزون جديد" footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="new-transfer" loading={form.processing}>{form.data.direct ? 'اعتماد وإرسال' : 'إرسال الطلب'}</Button></>}>
            <form id="new-transfer" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="من الفرع" error={form.errors.from_branch_id} required>
                        <Select value={form.data.from_branch_id} onChange={(e) => form.setData('from_branch_id', e.target.value)}>
                            {branch.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="إلى الفرع" error={form.errors.to_branch_id} required>
                        <Select value={form.data.to_branch_id} onChange={(e) => form.setData('to_branch_id', e.target.value)} placeholder="اختر الفرع المستقبل">
                            {branches.map((b) => <option key={b.id} value={b.id} disabled={String(b.id) === form.data.from_branch_id}>{b.name}</option>)}
                        </Select>
                    </Field>
                </div>
                <Field label="المنتجات والكميات" required>
                    <LinesEditor lines={form.data.lines} onChange={(lines) => form.setData('lines', lines)} products={products} branchId={Number(form.data.from_branch_id) || null} error={err.lines || err['lines.0.product_id'] || err['lines.0.quantity']} />
                </Field>
                <Field label="ملاحظات" error={form.errors.notes}><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={500} /></Field>
                {can('inventory.approve') && <Checkbox label="تنفيذ مباشر: اعتماد الطلب وإرساله الآن (يخرج من الفرع المرسل ويصبح قيد النقل)" checked={form.data.direct} onChange={(e) => form.setData('direct', e.target.checked)} />}
                <p className="flex items-center gap-1.5 text-[12px] text-[#9ca3af]"><PackageCheck className="size-3.5" /> لا يتغير أي رصيد قبل الإرسال؛ والاستلام وحده يضيف إلى الفرع المستقبل.</p>
            </form>
        </Modal>
    );
}
