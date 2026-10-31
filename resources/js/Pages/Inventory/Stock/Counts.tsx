import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ClipboardCheck, Plus } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Field, Modal, PageHeader, Pagination, Select, Textarea } from '@/Components/ui';
import { Toolbar, visit } from '@/Components/Products/parts';
import { BranchPicker, COUNT_LABELS, Pill, type BranchFilterState } from '@/Components/Inventory/parts';
import { useCan } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

interface Row { id: number; number: string; status: string; lines_count: number; counted: number; differences: number; branch: string | null; by: string | null; approver: string | null; at: string; approved_at: string | null }

/** الجرد الفعلي: counts of a branch's shelves — started, counted, then approved once by someone allowed to. */
export default function Counts({ counts, filters, branch, categories }: { counts: Paginated<Row>; filters: { status: string }; branch: BranchFilterState; categories: Option[] }) {
    const can = useCan();
    const [starting, setStarting] = useState(false);
    const go = (change: Record<string, string>) => visit('club.inventory.counts', { ...filters, branch: branch.locked ? undefined : branch.value }, change);

    return (
        <ClubLayout title="الجرد الفعلي">
            <PageHeader title="الجرد الفعلي" subtitle="عُدّ ما على الرف في فرع، وقارنه برصيد النظام، واعتمد الفروقات بسببها" actions={can('inventory.adjust') && <Button icon={<Plus />} onClick={() => setStarting(true)}>جرد جديد</Button>} />
            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <div className="flex items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="الحالة">
                        {([['', 'الكل'], ['draft', 'قيد العد'], ['approved', 'معتمد'], ['cancelled', 'ملغى']] as const).map(([key, label]) => (
                            <button key={key || 'all'} type="button" onClick={() => go({ status: key })} aria-pressed={filters.status === key} className={cn('h-8 rounded-[8px] px-3 text-[13px] font-medium transition', filters.status === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>{label}</button>
                        ))}
                    </div>
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>
                {counts.data.length === 0 ? (
                    <EmptyState icon={<ClipboardCheck className="size-6" />} title="لا توجد عمليات جرد بعد" description="ابدأ جردًا لفرع، وأدخل الكميات المعدودة؛ لا يتغير المخزون حتى يُعتمد الجرد." action={can('inventory.adjust') ? <Button size="sm" icon={<Plus />} onClick={() => setStarting(true)}>جرد جديد</Button> : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الجرد</th><th>الفرع</th><th>المنتجات</th><th>المعدود</th><th>بفرق</th><th>الحالة</th><th>بواسطة</th></tr></thead>
                            <tbody>
                                {counts.data.map((c) => (
                                    <tr key={c.id}>
                                        <td><Link href={route('club.inventory.counts.show', c.id)} className="font-medium text-ink hover:underline num">{c.number}</Link><p className="text-[11px] text-[#9ca3af] num">{c.at}</p></td>
                                        <td className="text-[13px]">{c.branch}</td>
                                        <td className="num">{number(c.lines_count)}</td>
                                        <td className="num">{number(c.counted)}</td>
                                        <td className={cn('num', c.differences > 0 && 'font-semibold text-[#b45309]')}>{number(c.differences)}</td>
                                        <td><Pill tone={COUNT_LABELS[c.status]?.[1] ?? 'gray'}>{COUNT_LABELS[c.status]?.[0] ?? c.status}</Pill></td>
                                        <td className="text-[13px] text-[#6b7280]">{c.by}{c.approver && <span className="block text-[11px]">اعتمده {c.approver}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={counts} />
            </Card>
            {starting && <StartCount branch={branch} categories={categories} onClose={() => setStarting(false)} />}
        </ClubLayout>
    );
}

function StartCount({ branch, categories, onClose }: { branch: BranchFilterState; categories: Option[]; onClose: () => void }) {
    const form = useForm({ branch_id: branch.value !== 'all' ? branch.value : String(branch.options[0]?.id ?? ''), category_id: '', notes: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.inventory.counts.store'));
    };

    return (
        <Modal open onClose={onClose} title="جرد جديد" footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button type="submit" form="start-count" loading={form.processing}>بدء الجرد</Button></>}>
            <form id="start-count" onSubmit={submit} className="space-y-4">
                <Field label="الفرع" error={form.errors.branch_id} required>
                    <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)}>
                        {branch.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                </Field>
                <Field label="المنتجات" hint="كل المنتجات المخزنية، أو تصنيف واحد" error={form.errors.category_id || (form.errors as Record<string, string>).products}>
                    <Select value={form.data.category_id} onChange={(e) => form.setData('category_id', e.target.value)}>
                        <option value="">كل المنتجات المخزنية</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                </Field>
                <Field label="ملاحظات" error={form.errors.notes}>
                    <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={500} />
                </Field>
            </form>
        </Modal>
    );
}
