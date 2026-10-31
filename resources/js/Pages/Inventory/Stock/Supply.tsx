import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Inbox, Plus } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Field, Input, Modal, PageHeader, Pagination, Select, Textarea } from '@/Components/ui';
import { Toolbar, visit } from '@/Components/Products/parts';
import { BranchPicker, LinesEditor, Pill, SUPPLY_LABELS, type BranchFilterState, type Line, type StockProduct } from '@/Components/Inventory/parts';
import { useCan } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';
import type { Paginated } from '@/types';

interface Row { id: number; number: string; status: string; progress: string; branch: string | null; quantity: number; by: string | null; at: string; needed_by: string | null }

/** طلبات تزويد الفروع: a branch asks for stock; the management approves it into transfers or a purchase, or rejects it. */
export default function Supply({ requests, filters, branch, products }: { requests: Paginated<Row>; filters: { status: string }; branch: BranchFilterState; products: StockProduct[] }) {
    const can = useCan();
    const [creating, setCreating] = useState(false);
    const go = (change: Record<string, string>) => visit('club.inventory.supply', { ...filters, branch: branch.locked ? undefined : branch.value }, change);

    return (
        <ClubLayout title="طلبات تزويد الفروع">
            <PageHeader title="طلبات تزويد الفروع" subtitle="يطلب الفرع ما يحتاجه، وتراجع الإدارة الطلب وتوفره بنقل من فرع آخر أو بالشراء" actions={can('inventory.transfer') && <Button icon={<Plus />} onClick={() => setCreating(true)}>طلب تزويد</Button>} />
            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <div className="flex items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="الحالة">
                        {([['', 'الكل'], ['pending', 'بانتظار المراجعة'], ['approved', 'معتمد'], ['rejected', 'مرفوض']] as const).map(([key, label]) => (
                            <button key={key || 'all'} type="button" onClick={() => go({ status: key })} aria-pressed={filters.status === key} className={cn('h-8 rounded-[8px] px-3 text-[13px] font-medium transition', filters.status === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>{label}</button>
                        ))}
                    </div>
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>
                {requests.data.length === 0 ? (
                    <EmptyState icon={<Inbox className="size-6" />} title="لا توجد طلبات تزويد" description="عندما يحتاج فرع منتجات يرسل طلبًا من هنا، وتراجعه الإدارة." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الطلب</th><th>الفرع الطالب</th><th>الكمية</th><th>مطلوب قبل</th><th>الحالة</th><th>طلبه</th></tr></thead>
                            <tbody>
                                {requests.data.map((r) => (
                                    <tr key={r.id}>
                                        <td><Link href={route('club.inventory.supply.show', r.id)} className="font-medium text-ink hover:underline num">{r.number}</Link><p className="text-[11px] text-[#9ca3af] num">{r.at}</p></td>
                                        <td className="text-[13px]">{r.branch}</td>
                                        <td className="num">{number(r.quantity, r.quantity % 1 ? 3 : 0)}</td>
                                        <td className="text-[13px] num">{r.needed_by ?? '—'}</td>
                                        <td><Pill tone={SUPPLY_LABELS[r.progress]?.[1] ?? 'gray'}>{SUPPLY_LABELS[r.progress]?.[0] ?? r.progress}</Pill></td>
                                        <td className="text-[13px] text-[#6b7280]">{r.by}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={requests} />
            </Card>
            {creating && <NewRequest branch={branch} products={products} onClose={() => setCreating(false)} />}
        </ClubLayout>
    );
}

function NewRequest({ branch, products, onClose }: { branch: BranchFilterState; products: StockProduct[]; onClose: () => void }) {
    const form = useForm({ branch_id: branch.value !== 'all' ? branch.value : String(branch.options[0]?.id ?? ''), lines: [{ product_id: '', quantity: '' }] as Line[], needed_by: '', notes: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, lines: d.lines.filter((l) => l.product_id && Number(l.quantity) > 0), needed_by: d.needed_by || null }));
        form.post(route('club.inventory.supply.store'));
    };
    const err = form.errors as Record<string, string>;

    return (
        <Modal open onClose={onClose} size="lg" title="طلب تزويد" footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="new-supply" loading={form.processing}>إرسال الطلب</Button></>}>
            <form id="new-supply" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="الفرع الطالب" error={form.errors.branch_id} required>
                        <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)}>
                            {branch.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="مطلوب قبل" error={form.errors.needed_by}><Input type="date" value={form.data.needed_by} onChange={(e) => form.setData('needed_by', e.target.value)} /></Field>
                </div>
                <Field label="المنتجات والكميات" required>
                    <LinesEditor lines={form.data.lines} onChange={(lines) => form.setData('lines', lines)} products={products} branchId={Number(form.data.branch_id) || null} error={err.lines} hint="يظهر تحت كل منتج رصيده الحالي في الفرع الطالب." />
                </Field>
                <Field label="ملاحظات" error={form.errors.notes}><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={500} /></Field>
            </form>
        </Modal>
    );
}
