import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Ban, FileText, Printer, Receipt, Search } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Badge, Card, ConfirmAction, DataTable, Input, type Column } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { paymentMethod } from '@/lib/labels';
import { money } from '@/lib/format';

interface Row {
    id: number;
    receipt: string | null;
    invoice: string | null;
    invoice_id: number | null;
    kind: string;
    customer: string | null;
    method: string;
    amount: string;
    time: string | null;
    cashier: string | null;
    sale: { id: number; number: string; voided: boolean } | null;
}

/**
 * Today's receipts at the point of sale: every payment taken here — products, memberships, training packages — to
 * reprint one on the till's printer, or to void a product sale (sales.refund: stock back, money refunded by a credit
 * note). A membership or package is refunded from its invoice, by whoever may issue credit notes.
 */
export default function PosReceipts({ receipts, total, can }: { receipts: Row[]; total: string; can: { refund: boolean; print: boolean; invoices: boolean } }) {
    const currency = useCurrency();
    const [q, setQ] = useState('');
    const needle = q.trim().toLowerCase();
    const rows = receipts.filter((r) => !needle || [r.receipt, r.invoice, r.customer, r.sale?.number].some((v) => v?.toLowerCase().includes(needle)));

    const columns: Column<Row>[] = [
        { key: 'number', header: 'الإيصال', cell: (r) => <><span className="font-medium text-[#111] num">{r.receipt ?? '—'}</span><div className="text-[11px] text-[#9ca3af]">{r.kind}{r.sale && <> · <span className="num">{r.sale.number}</span></>}</div></> },
        { key: 'customer', header: 'العميل', cell: (r) => r.customer ?? 'عميل نقدي' },
        { key: 'cashier', header: 'الموظف', cell: (r) => <span className="text-[#6b7280]">{r.cashier ?? '—'}</span> },
        { key: 'method', header: 'طريقة الدفع', cell: (r) => paymentMethod[r.method] ?? r.method },
        { key: 'time', header: 'الوقت', cell: (r) => <span className="num text-[#6b7280]">{r.time}</span> },
        { key: 'amount', header: 'المبلغ', align: 'end', cell: (r) => (r.sale?.voided ? <Badge tone="gray">ملغاة</Badge> : <span className="font-semibold tabular-nums">{money(r.amount, currency)}</span>) },
        {
            key: 'actions',
            header: <span className="sr-only">إجراءات</span>,
            align: 'end',
            cell: (r) => (
                <div className="flex items-center justify-end gap-1">
                    {can.print && (
                        <a href={r.sale ? route('club.sales.receipt', r.sale.id) : route('club.payments.receipt', { payment: r.id, face: 'strip' })} target="_blank" rel="noreferrer" className="grid size-8 place-items-center rounded-[10px] text-[#4b4b4b] hover:bg-ink/[0.045]" title="طباعة الإيصال" aria-label="طباعة الإيصال">
                            <Printer className="size-4" />
                        </a>
                    )}
                    {can.invoices && r.invoice_id && (
                        <Link href={route('club.invoices.show', r.invoice_id)} className="grid size-8 place-items-center rounded-[10px] text-[#4b4b4b] hover:bg-ink/[0.045]" title="الفاتورة" aria-label="الفاتورة">
                            <FileText className="size-4" />
                        </Link>
                    )}
                    {can.refund && r.sale && !r.sale.voided && (
                        <ConfirmAction url={route('club.pos.void', r.sale.id)} title={`إلغاء عملية البيع ${r.sale.number}`} message="يعود المخزون ويُسجَّل رد المبلغ بإشعار دائن." reason="سبب الإلغاء" confirmLabel="إلغاء العملية" tone="danger" variant="ghost" size="sm" icon={<Ban />}>
                            إلغاء
                        </ConfirmAction>
                    )}
                </div>
            ),
        },
    ];

    return (
        <PosLayout title="إيصالات اليوم">
            <div className="mx-auto max-w-6xl p-4">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h1 className="text-[20px] font-bold text-[#111]">إيصالات اليوم</h1>
                    <span className="text-sm text-gray-500"><span className="num">{receipts.length}</span> إيصال · <span className="num">{money(total, currency)}</span></span>
                </div>
                <Card padded={false} className="mb-3 p-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                        <Input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="رقم الإيصال أو الفاتورة أو اسم العميل" className="ps-9" aria-label="بحث" />
                    </div>
                    {needle && <p className="mt-2 text-[12px] text-[#9ca3af]">النتائج: <span className="num">{rows.length}</span></p>}
                </Card>
                <Card padded={false} className="overflow-hidden">
                    <DataTable rows={rows} columns={columns} rowKey={(r) => r.id} params={{}} empty={<span className="inline-flex flex-col items-center gap-2"><Receipt className="size-8 text-[#d1d5db]" />لا إيصالات اليوم بعد</span>} />
                </Card>
            </div>
        </PosLayout>
    );
}
