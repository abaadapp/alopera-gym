import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Banknote, CalendarClock, CalendarDays, ClipboardList, FilePlus2, History, Mail, MapPin, MoreVertical, Pencil, Phone, Receipt, ShoppingCart, Trash2, UserRound, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Modal, PageHeader, StatCard, Tabs, BackLink } from '@/Components/ui';
import { Dropdown, DropdownItem, DropdownSeparator } from '@/Components/ui/Dropdown';
import { BILL_STATES, ORDER_TONES, Pill } from '@/Components/Inventory/parts';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { dayCount, money, number } from '@/lib/format';
import { SupplierDialog, type SupplierRow } from './Suppliers';

type Order = { id: number; number: string; status: string; status_label: string; total: string; items_count: number; date: string; branch: string | null };
type Bill = { id: number; number: string; reference: string; status: string; total: string; paid_total: string; balance: string; state: string | null; issue_date: string; due_date: string | null };
type Payment = { id: number; number: string; amount: string; reference: string | null; date: string; account: string | null; bill_id: number; bill: string | null; reversed: boolean };
type Product = { id: number; name: string; quantity: number; last_cost: string; last_date: string };
type LogEntry = { kind: 'order' | 'bill' | 'payment' | 'reversed'; date: string; id: number; number: string; text: string; amount: string };

interface Props {
    supplier: SupplierRow & { created_at: string | null; can_delete: boolean };
    may: { edit: boolean; purchases: boolean; order: boolean; bills: boolean };
    orders?: Order[];
    bills?: Bill[];
    payments?: Payment[];
    products?: Product[];
    log?: LogEntry[];
    totals?: { purchased: number; orders: number; paid: number; due: number };
}

type Tab = 'orders' | 'bills' | 'payments' | 'products' | 'log';

/**
 * ملف المورّد: who they are and how to reach them on the side; what was bought from them, their bills, what was paid
 * and the whole record of dealings in tabs. Purchases and money show only with purchases.view (the server sends
 * nothing of them otherwise).
 */
export default function SupplierShow({ supplier, may, orders = [], bills = [], payments = [], products = [], log = [], totals }: Props) {
    const currency = useCurrency();
    const m = (v: string | number) => money(v, currency);
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [tab, setTab] = useState<Tab>('orders');

    const contact: { icon: ReactNode; label: string; value: ReactNode; ltr?: boolean }[] = [
        { icon: <UserRound />, label: 'الشخص المسؤول', value: supplier.contact_name },
        { icon: <Phone />, label: 'الهاتف', value: supplier.phone && <a href={`tel:+${supplier.phone}`} className="hover:underline">+{supplier.phone}</a>, ltr: true },
        { icon: <Mail />, label: 'البريد', value: supplier.email, ltr: true },
        { icon: <MapPin />, label: 'العنوان', value: supplier.address },
        { icon: <Receipt />, label: 'الرقم الضريبي', value: supplier.tax_number, ltr: true },
        { icon: <CalendarClock />, label: 'مدة السداد', value: supplier.payment_terms_days != null ? dayCount(supplier.payment_terms_days) : null },
        { icon: <CalendarDays />, label: 'مورّد منذ', value: supplier.created_at, ltr: true },
    ];
    const menu = may.edit || Boolean(supplier.phone);

    return (
        <ClubLayout title={supplier.name}>
            <PageHeader
                back={<BackLink href={route('club.purchases.suppliers')}>الموردون</BackLink>}
                title="ملف المورّد"
                subtitle="بياناته وأوامر الشراء والسندات والمدفوعات وما بقي له."
                actions={<>
                    {may.purchases && <Button variant="outline" href={route('club.purchases.register', { supplier: supplier.id })} icon={<ShoppingCart />}>سجل المشتريات</Button>}
                    {may.order && supplier.is_active && <Button href={route('club.purchases.create', { supplier: supplier.id })} icon={<FilePlus2 />}>أمر شراء جديد</Button>}
                    {menu && (
                        <Dropdown label="المزيد" panelClassName="w-52" trigger={() => <span className="grid size-10 place-items-center rounded-[10px] border border-[#dcdcdc] bg-white text-ink transition-colors hover:bg-[#fafafa]"><MoreVertical className="size-[18px]" /></span>}>
                            {(close) => <>
                                {may.edit && <DropdownItem onClick={() => { close(); setEditing(true); }}><Pencil className="text-[#6b7280]" /> تعديل بيانات المورّد</DropdownItem>}
                                {supplier.phone && <DropdownItem href={`tel:+${supplier.phone}`} external><Phone className="text-[#6b7280]" /> اتصال</DropdownItem>}
                                {may.edit && supplier.can_delete && <>
                                    <DropdownSeparator />
                                    <DropdownItem destructive onClick={() => { close(); setDeleting(true); }}><Trash2 /> حذف المورّد</DropdownItem>
                                </>}
                            </>}
                        </Dropdown>
                    )}
                </>}
            />

            {totals && (
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="إجمالي المشتريات" value={m(totals.purchased)} icon={<Wallet />} tone="green" />
                    <StatCard label="المدفوع" value={m(totals.paid)} icon={<Banknote />} tone="blue" />
                    <StatCard label="المتبقي له" value={m(totals.due)} icon={<CalendarClock />} tone={totals.due > 0 ? 'amber' : 'gray'} />
                    <StatCard label="أوامر الشراء" value={number(totals.orders)} icon={<ClipboardList />} tone="violet" />
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6">
                    <Card className="p-6 text-center">
                        <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f5f3ff] text-[28px] font-bold text-[#6d28d9]">{supplier.name.slice(0, 1)}</span>
                        <h2 className="mt-4 text-[17px] font-bold text-ink">{supplier.name}</h2>
                        <p className="mt-1 font-mono text-[12px] text-[#9ca3af]">#{supplier.id}</p>
                        <Badge tone={supplier.is_active ? 'green' : 'amber'} className="mt-3">{supplier.is_active ? 'نشط' : 'موقوف'}</Badge>
                    </Card>
                    <Card className="p-6">
                        <h3 className="mb-4 text-sm font-bold text-ink">بيانات الاتصال</h3>
                        <ul className="space-y-3 text-sm">
                            {contact.map((c) => (
                                <li key={c.label} className="flex items-center justify-between gap-3">
                                    <span className="flex shrink-0 items-center gap-2 text-[#6b7280] [&_svg]:size-4 [&_svg]:text-[#9ca3af]">{c.icon}{c.label}</span>
                                    <span dir={c.ltr ? 'ltr' : undefined} className={cn('truncate text-ink', c.ltr && 'num')}>{c.value || '—'}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card className="p-6">
                        <h3 className="mb-3 text-sm font-bold text-ink">ملاحظات</h3>
                        <p className="whitespace-pre-line text-[13px] text-[#4b4b4b]">{supplier.notes || 'لا ملاحظات.'}</p>
                    </Card>
                </div>

                <Card padded={false} className="overflow-hidden lg:col-span-2">
                    {!may.purchases ? (
                        <p className="px-5 py-14 text-center text-sm text-[#9ca3af]">أوامر الشراء والسندات والمبالغ تظهر لمن يملك صلاحية عرض المشتريات.</p>
                    ) : (
                        <>
                            <Tabs<Tab>
                                tabs={[
                                    { key: 'orders', label: 'أوامر الشراء', count: orders.length },
                                    { key: 'bills', label: 'السندات والفواتير', count: bills.length },
                                    { key: 'payments', label: 'المدفوعات', count: payments.length },
                                    { key: 'products', label: 'المنتجات', count: products.length },
                                    { key: 'log', label: 'سجل التعاملات' },
                                ]}
                                value={tab}
                                onChange={setTab}
                                className="mx-0 px-5"
                            />
                            {tab === 'orders' && (
                                <SimpleTable empty="لا أوامر شراء لهذا المورّد بعد." head={['رقم الأمر', 'التاريخ', 'الفرع', 'الأصناف', 'الإجمالي', 'الحالة']} rows={orders.map((o) => [
                                    <Link key="n" href={route('club.purchases.show', o.id)} className="font-medium text-ink hover:underline num">{o.number}</Link>,
                                    <span key="d" dir="ltr" className="text-[#6b7280] num">{o.date}</span>,
                                    o.branch ?? '—',
                                    <span key="c" className="tabular-nums text-[#4b4b4b]">{number(o.items_count)}</span>,
                                    <span key="t" className="tabular-nums font-medium">{m(o.total)}</span>,
                                    <Pill key="s" tone={ORDER_TONES[o.status] ?? 'gray'}>{o.status_label}</Pill>,
                                ])} />
                            )}
                            {tab === 'bills' && (
                                <SimpleTable empty="لا سندات لهذا المورّد بعد." head={['السند', 'التاريخ', 'الاستحقاق', 'الإجمالي', 'المتبقي', 'الحالة']} rows={bills.map((b) => {
                                    const [label, tone] = BILL_STATES[b.status === 'approved' ? b.state ?? 'unpaid' : b.status] ?? ['—', 'gray'];
                                    return [
                                        <Link key="n" href={route('club.purchases.bills.show', b.id)} className="font-medium text-ink hover:underline num">{b.reference || b.number}</Link>,
                                        <span key="d" dir="ltr" className="text-[#6b7280] num">{b.issue_date}</span>,
                                        <span key="u" dir="ltr" className="text-[#6b7280] num">{b.due_date ?? '—'}</span>,
                                        <span key="t" className="tabular-nums">{m(b.total)}</span>,
                                        Number(b.balance) > 0 ? <span key="b" className="tabular-nums font-semibold text-[#b45309]">{m(b.balance)}</span> : <span key="b" className="text-[#d4d4d8]">—</span>,
                                        <Pill key="s" tone={tone}>{label}</Pill>,
                                    ];
                                })} />
                            )}
                            {tab === 'payments' && (
                                <SimpleTable empty="لا مدفوعات لهذا المورّد بعد." head={['الدفعة', 'التاريخ', 'من حساب', 'عن السند', 'المبلغ']} rows={payments.map((p) => [
                                    <span key="n" className={cn('font-medium num', p.reversed && 'text-[#9ca3af] line-through')}>{p.number}</span>,
                                    <span key="d" dir="ltr" className="text-[#6b7280] num">{p.date}</span>,
                                    p.account ?? '—',
                                    <Link key="b" href={route('club.purchases.bills.show', p.bill_id)} className="text-ink hover:underline num">{p.bill ?? '—'}</Link>,
                                    p.reversed ? <Badge key="a" tone="gray">أُلغيت</Badge> : <span key="a" className="tabular-nums font-medium text-[#047857]">{m(p.amount)}</span>,
                                ])} />
                            )}
                            {tab === 'products' && (
                                <SimpleTable empty="لم يُشترَ منه شيء بعد." head={['المنتج', 'الكمية بوحدة المخزون', 'آخر سعر', 'آخر شراء']} rows={products.map((p) => [
                                    <span key="n" className="font-medium">{p.name}</span>,
                                    <span key="q" className="tabular-nums">{number(p.quantity, p.quantity % 1 ? 3 : 0)}</span>,
                                    <span key="c" className="tabular-nums">{m(p.last_cost)}</span>,
                                    <span key="d" dir="ltr" className="text-[#6b7280] num">{p.last_date}</span>,
                                ])} />
                            )}
                            {tab === 'log' && (
                                log.length === 0 ? <p className="px-5 py-14 text-center text-sm text-[#9ca3af]">لا تعاملات بعد.</p> : (
                                    <ul className="divide-y divide-line">
                                        {log.map((e, i) => (
                                            <li key={i} className="flex items-center gap-3 px-5 py-3 text-sm">
                                                <span className={cn('grid size-8 shrink-0 place-items-center rounded-[10px] [&_svg]:size-4', { order: 'bg-[#f5f3ff] text-[#6d28d9]', bill: 'bg-[#fffbeb] text-[#d97706]', payment: 'bg-[#ecfdf5] text-[#047857]', reversed: 'bg-[#f2f2f0] text-[#9ca3af]' }[e.kind])}>
                                                    {{ order: <ClipboardList />, bill: <Receipt />, payment: <Banknote />, reversed: <History /> }[e.kind]}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-ink">{e.text} <span className="num text-[#6b7280]">{e.number}</span></span>
                                                    <span dir="ltr" className="block text-end text-[12px] text-[#9ca3af] num">{e.date}</span>
                                                </span>
                                                <span className={cn('tabular-nums', e.kind === 'payment' ? 'text-[#047857]' : e.kind === 'reversed' ? 'text-[#9ca3af] line-through' : 'text-ink')}>{m(e.amount)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )
                            )}
                        </>
                    )}
                </Card>
            </div>

            {editing && <SupplierDialog supplier={supplier} onClose={() => setEditing(false)} />}
            <Modal open={deleting} onClose={() => setDeleting(false)} size="xs" title="تأكيد الحذف" footer={<><Button variant="outline" onClick={() => setDeleting(false)}>إلغاء</Button><Button variant="danger" onClick={() => router.delete(route('club.purchases.suppliers.destroy', supplier.id))}>حذف</Button></>}>
                <p className="text-sm text-[#4b4b4b]">حذف «{supplier.name}»؟ لا أوامر شراء ولا سندات له.</p>
            </Modal>
        </ClubLayout>
    );
}

/** A tab's table: the list headers (12px semibold grey, 44px) and rows with a hairline between them. */
function SimpleTable({ head, rows, empty }: { head: string[]; rows: ReactNode[][]; empty: string }) {
    if (rows.length === 0) return <p className="px-5 py-14 text-center text-sm text-[#9ca3af]">{empty}</p>;
    return (
        <div className="w-full overflow-x-auto">
            <table className="table-base [&_tbody_tr:last-child_td]:border-0">
                <thead><tr>{head.map((h) => <th key={h} className="h-11 py-0 align-middle text-[12px] text-[#6b7280]">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j} className="border-line">{c}</td>)}</tr>)}</tbody>
            </table>
        </div>
    );
}
