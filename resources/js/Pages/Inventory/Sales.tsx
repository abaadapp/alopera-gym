import { Link, router } from '@inertiajs/react';
import { Globe, Printer, ReceiptText, ShoppingBag, Store, Undo2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, ConfirmAction, EmptyState, Input, PageHeader, Pagination, SearchInput, Select } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

export interface SaleRow {
    id: number;
    number: string;
    source: 'pos' | 'website';
    status: 'pending' | 'completed' | 'voided' | 'cancelled';
    fulfillment: string | null;
    total: string;
    discount: string;
    payment_method: string;
    invoice_id: number | null;
    buyer: string;
    cashier: string | null;
    at: string;
    paid: boolean;
    source_label: string;
    status_label: string;
    method_label: string;
    items?: { name: string; quantity: string; unit_price?: string; line_total: string; addons?: { name: string; quantity: string }[] }[];
}

type Filters = { q?: string; source?: string; status?: string; from?: string; to?: string };

export const saleStatusTone = { pending: 'amber', completed: 'green', voided: 'red', cancelled: 'gray' } as const;

export function SourceBadge({ source, label }: { source: string; label: string }) {
    return (
        <Badge tone={source === 'website' ? 'blue' : 'gray'}>
            {source === 'website' ? <Globe /> : <Store />}
            {label}
        </Badge>
    );
}

export default function Sales({ sales, filters, labels, can: allowed }: { sales: Paginated<SaleRow>; filters: Filters; labels: { sources: Record<string, string>; statuses: Record<string, string> }; can: { refund: boolean } }) {
    const can = useCan();
    const currency = useCurrency();
    const go = (next: Filters) => router.get(route('club.sales.index'), Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v)), { preserveState: true, replace: true, preserveScroll: true });

    return (
        <ClubLayout title="عمليات البيع">
            <PageHeader
                title="عمليات البيع"
                subtitle="كل عمليات البيع من نقطة البيع والموقع الإلكتروني، مع الإلغاء والاسترداد"
                actions={can('pos.sell') && <Button href={route('club.pos.index')} icon={<ShoppingBag />}>نقطة البيع</Button>}
            />

            <Card padded={false}>
                <div className="flex flex-wrap items-center gap-2 border-b border-line p-4">
                    <SearchInput value={filters.q} placeholder="رقم العملية، اسم العميل أو هاتفه" extra={{ source: filters.source, status: filters.status, from: filters.from, to: filters.to }} />
                    <Select className="w-40" value={filters.source ?? ''} onChange={(e) => go({ source: e.target.value })} aria-label="المصدر">
                        <option value="">كل المصادر</option>
                        {Object.entries(labels.sources).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </Select>
                    <Select className="w-40" value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value })} aria-label="الحالة">
                        <option value="">كل الحالات</option>
                        {Object.entries(labels.statuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </Select>
                    <Input type="date" className="w-40" value={filters.from ?? ''} onChange={(e) => go({ from: e.target.value })} aria-label="من" />
                    <Input type="date" className="w-40" value={filters.to ?? ''} onChange={(e) => go({ to: e.target.value })} aria-label="إلى" />
                </div>

                {sales.data.length === 0 ? (
                    <EmptyState icon={<ReceiptText />} title="لا توجد عمليات بيع" description="تظهر هنا مبيعات نقطة البيع وطلبات الموقع الإلكتروني." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الرقم</th><th>الوقت</th><th>المصدر</th><th>العميل</th><th>المنتجات</th><th>الدفع</th><th>الإجمالي</th><th>الموظف</th><th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {sales.data.map((s) => (
                                    <tr key={s.id} className={s.status === 'voided' || s.status === 'cancelled' ? 'opacity-60' : undefined}>
                                        <td>
                                            {s.invoice_id && can('invoices.view') ? <Link href={route('club.invoices.show', s.invoice_id)} className="font-medium text-brand num">{s.number}</Link> : <span className="font-medium num">{s.number}</span>}
                                        </td>
                                        <td className="whitespace-nowrap text-xs text-muted num">{s.at}</td>
                                        <td><SourceBadge source={s.source} label={s.source_label} /></td>
                                        <td className="text-sm">{s.buyer}</td>
                                        <td className="max-w-xs">
                                            <p className="truncate text-sm">{(s.items ?? []).map((i) => `${i.name} × ${number(i.quantity)}${i.addons?.length ? ` (+ ${i.addons.map((a) => a.name).join('، ')})` : ''}`).join('، ')}</p>
                                        </td>
                                        <td className="text-sm whitespace-nowrap">{s.method_label}{!s.paid && s.status === 'pending' && <p className="text-xs text-amber-700">غير مدفوع</p>}</td>
                                        <td className="num font-medium">
                                            {money(s.total, currency)}
                                            {Number(s.discount) > 0 && <p className="text-xs font-normal text-muted">خصم {money(s.discount, currency)}</p>}
                                        </td>
                                        <td className="text-xs text-muted">{s.cashier ?? '—'}</td>
                                        <td><Badge tone={saleStatusTone[s.status]}>{s.status_label}</Badge></td>
                                        <td>
                                            <div className="flex items-center justify-end gap-1">
                                                <Button variant="ghost" size="sm" href={route('club.sales.receipt', s.id)} external icon={<Printer />} aria-label={`إيصال ${s.number}`} title="الإيصال" />
                                                {s.status === 'completed' && allowed.refund && (
                                                    <ConfirmAction
                                                        url={route('club.pos.void', s.id)}
                                                        title={`إلغاء عملية البيع ${s.number}`}
                                                        message="تعود المنتجات إلى المخزون، ويصدر إشعار دائن ويُسجَّل رد المبلغ بطريقة الدفع نفسها. تسجيل الرد لا ينفّذ تحويلًا فعليًا."
                                                        reason="سبب الإلغاء"
                                                        confirmLabel="إلغاء البيع"
                                                        variant="ghost"
                                                        icon={<Undo2 />}
                                                    >
                                                        إلغاء
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
                <Pagination data={sales} />
            </Card>
        </ClubLayout>
    );
}
