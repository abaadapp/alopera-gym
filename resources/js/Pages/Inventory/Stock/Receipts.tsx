import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check, PackageOpen, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, EmptyState, Notice, PageHeader, Pagination } from '@/Components/ui';
import { Toolbar, visit } from '@/Components/Products/parts';
import { BranchPicker, Pill, RECEIPT_LABELS, ReasonDialog, type BranchFilterState } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface Row { id: number; number: string; status: string; total: string; lines_count: number; reason: string | null; delivery_reference: string | null; received_on: string; supplier: string | null; order: { id: number; number: string } | null; branch: string | null; by: string | null }

/** إشعارات استلام البضاعة: goods received against purchase orders; the branch's stock goes up only when approved. */
export default function Receipts({ receipts, pending, filters, branch, can }: { receipts: Paginated<Row>; pending: number; filters: { status: string }; branch: BranchFilterState; can: { approve: boolean } }) {
    const currency = useCurrency();
    const [rejecting, setRejecting] = useState<Row | null>(null);
    const go = (change: Record<string, string>) => visit('club.inventory.receipts', { ...filters, branch: branch.locked ? undefined : branch.value }, change);

    return (
        <ClubLayout title="إشعارات استلام البضاعة">
            <PageHeader title="إشعارات استلام البضاعة" subtitle="ما وصل من الموردين على أوامر الشراء؛ لا يزيد مخزون الفرع إلا بعد اعتماد الإشعار" />
            {pending > 0 && (
                <Notice tone="amber" className="mb-4" onClick={() => go({ status: 'pending' })}>
                    <span className="num">{number(pending)}</span> إشعار بانتظار الاعتماد — عرض الطابور
                </Notice>
            )}
            <Card padded={false} className="overflow-hidden">
                <Toolbar>
                    <div className="flex items-center gap-0.5 rounded-[10px] bg-[#f2f2f0] p-0.5" role="group" aria-label="الحالة">
                        {([['', 'الكل'], ['pending', 'بانتظار الاعتماد'], ['approved', 'معتمد'], ['rejected', 'مرفوض']] as const).map(([key, label]) => (
                            <button key={key || 'all'} type="button" onClick={() => go({ status: key })} aria-pressed={filters.status === key} className={cn('h-8 rounded-[8px] px-3 text-[13px] font-medium transition', filters.status === key ? 'bg-white text-ink shadow-[0_1px_2px_rgba(0,0,0,0.06)]' : 'text-[#6b7280] hover:text-ink')}>{label}</button>
                        ))}
                    </div>
                    <div className="ms-auto"><BranchPicker state={branch} onChange={(value) => go({ branch: value })} /></div>
                </Toolbar>
                {receipts.data.length === 0 ? (
                    <EmptyState icon={<PackageOpen className="size-6" />} title="لا إشعارات استلام بعد" description="تُنشأ عند تسجيل استلام بضاعة على أمر شراء معتمد." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الإشعار</th><th>المورد وأمر الشراء</th><th>الفرع</th><th>البنود</th><th>القيمة</th><th>الحالة</th><th>المستلم</th><th /></tr></thead>
                            <tbody>
                                {receipts.data.map((r) => (
                                    <tr key={r.id}>
                                        <td><Link href={route('club.inventory.receipts.show', r.id)} className="font-medium text-ink hover:underline num">{r.number}</Link><p className="text-[11px] text-[#9ca3af] num">{r.received_on}</p></td>
                                        <td className="text-[13px]">{r.supplier}{r.order && <Link href={route('club.purchases.show', r.order.id)} className="block text-[11px] text-[#6b7280] hover:underline num">{r.order.number}</Link>}</td>
                                        <td className="text-[13px]">{r.branch}</td>
                                        <td className="num">{number(r.lines_count)}</td>
                                        <td className="font-medium num">{money(r.total, currency)}</td>
                                        <td><Pill tone={RECEIPT_LABELS[r.status][1]}>{RECEIPT_LABELS[r.status][0]}</Pill>{r.reason && <p className="mt-1 max-w-40 truncate text-[11px] text-[#9ca3af]">{r.reason}</p>}</td>
                                        <td className="text-[13px] text-[#6b7280]">{r.by}</td>
                                        <td>
                                            {r.status === 'pending' && can.approve && (
                                                <div className="flex justify-end gap-1">
                                                    <Button size="sm" variant="ghost" icon={<X />} aria-label={`رفض ${r.number}`} onClick={() => setRejecting(r)} />
                                                    <Button size="sm" icon={<Check />} onClick={() => router.post(route('club.inventory.receipts.decide', { goodsReceipt: r.id, decision: 'approve' }), {}, { preserveScroll: true })}>اعتماد</Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={receipts} />
            </Card>
            <ReasonDialog open={rejecting !== null} title={`رفض ${rejecting?.number ?? ''}`} message="لا يتغير المخزون، ويبقى الإشعار مرفوضًا في السجل." confirm="رفض" url={rejecting ? route('club.inventory.receipts.decide', { goodsReceipt: rejecting.id, decision: 'reject' }) : ''} onClose={() => setRejecting(null)} />
        </ClubLayout>
    );
}
