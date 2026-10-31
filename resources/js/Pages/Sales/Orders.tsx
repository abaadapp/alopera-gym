import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Ban, CheckCheck, Globe, PackageCheck, Phone, Printer, Timer } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, Field, Input, Modal, PageHeader, Pagination, SearchInput, Select, Tabs, Textarea } from '@/Components/ui';
import { useCurrency, useCan } from '@/hooks/useShared';
import { money, number } from '@/lib/format';
import { saleStatusTone, type SaleRow } from '@/Pages/Inventory/Sales';
import type { Paginated } from '@/types';

interface Order extends SaleRow {
    phone: string | null;
    email: string | null;
    notes: string | null;
    step: 'new' | 'preparing' | 'ready' | 'delivered' | null;
    step_label: string | null;
    reserved_until: string | null;
    delivered_at: string | null;
    delivered_by: string | null;
    cancel_reason: string | null;
    invoice_number: string | null;
}

type Tab = 'open' | 'completed' | 'cancelled' | 'all';

const stepTone = { new: 'amber', preparing: 'blue', ready: 'violet', delivered: 'green' } as const;

export default function Orders({ orders, tab, counts, filters, can, printOrders = false }: { orders: Paginated<Order>; tab: Tab; counts: { open: number; new: number }; filters: { q?: string }; can: { refund: boolean }; printOrders?: boolean }) {
    const mayPrint = useCan()('papers.print');
    const currency = useCurrency();
    const [delivering, setDelivering] = useState<Order | null>(null);
    const [cancelling, setCancelling] = useState<Order | null>(null);
    const advance = (o: Order, status: 'preparing' | 'ready') => {
        // The device's printer prints the order once, when its preparation starts (opened on the click, filled on success).
        const sheet = status === 'preparing' && printOrders ? window.open('', '_blank') : null;
        router.post(route('club.sales.orders.status', o.id), { status }, { preserveScroll: true, onSuccess: () => sheet?.location.replace(route('club.sales.receipt', o.id)), onError: () => sheet?.close() });
    };

    return (
        <ClubLayout title="طلبات الموقع الإلكتروني">
            <PageHeader title="طلبات الموقع الإلكتروني" subtitle={counts.new ? `${number(counts.new)} طلب جديد بانتظار المراجعة` : 'الطلبات القادمة من متجر موقع النادي: التجهيز والتسليم والإلغاء'} />

            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <Tabs<Tab>
                        tabs={[
                            { key: 'open', label: 'المفتوحة', count: counts.open },
                            { key: 'completed', label: 'المسلّمة' },
                            { key: 'cancelled', label: 'الملغاة' },
                            { key: 'all', label: 'الكل' },
                        ]}
                        value={tab}
                        onChange={(key) => router.get(route('club.sales.orders.index'), { tab: key, q: filters.q || undefined }, { preserveState: true, replace: true })}
                        className="border-0"
                    />
                    <SearchInput value={filters.q} placeholder="رقم الطلب، الاسم أو الهاتف" extra={{ tab }} />
                </div>

                {orders.data.length === 0 ? (
                    <EmptyState icon={<Globe />} title="لا توجد طلبات هنا" description="عند طلب منتج من متجر الموقع يظهر الطلب في هذه الصفحة." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الطلب</th><th>العميل</th><th>المنتجات</th><th>الإجمالي</th><th>الدفع</th><th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {orders.data.map((o) => (
                                    <tr key={o.id} className={o.status === 'cancelled' ? 'opacity-60' : undefined}>
                                        <td>
                                            <p className="font-medium num">{o.number}</p>
                                            <p className="text-xs text-muted num">{o.at}</p>
                                        </td>
                                        <td>
                                            <p className="text-sm font-medium">{o.buyer}</p>
                                            {o.phone && <a href={`tel:+${o.phone}`} className="inline-flex items-center gap-1 text-xs text-muted hover:text-ink num" dir="ltr"><Phone className="size-3.5" />+{o.phone}</a>}
                                            {o.notes && <p className="mt-1 max-w-56 text-xs text-muted">«{o.notes}»</p>}
                                        </td>
                                        <td className="max-w-xs">
                                            <ul className="space-y-0.5 text-sm">
                                                {(o.items ?? []).map((i, idx) => <li key={idx} className="truncate">{i.name} <span className="text-muted num">× {number(i.quantity)}</span>{i.addons?.length ? <span className="block text-xs text-muted">+ {i.addons.map((a) => a.name).join('، ')}</span> : null}</li>)}
                                            </ul>
                                        </td>
                                        <td className="num font-medium">{money(o.total, currency)}</td>
                                        <td>
                                            <p className="text-sm">{o.method_label}</p>
                                            {o.paid ? <Badge tone="green">مدفوع</Badge> : o.status === 'pending' ? <Badge tone="amber">غير مدفوع</Badge> : null}
                                            {o.reserved_until && !o.paid && <p className="mt-1 flex items-center gap-1 text-xs text-muted"><Timer className="size-3.5" />مهلة الدفع حتى <span className="num">{o.reserved_until}</span></p>}
                                        </td>
                                        <td>
                                            {o.status === 'pending' && o.step ? <Badge tone={stepTone[o.step]}>{o.step_label}</Badge> : <Badge tone={saleStatusTone[o.status]}>{o.status_label}</Badge>}
                                            {o.delivered_by && <p className="mt-1 text-xs text-muted">سلّمه {o.delivered_by}</p>}
                                            {o.cancel_reason && <p className="mt-1 max-w-48 text-xs text-muted">{o.cancel_reason}</p>}
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap items-center justify-end gap-1">
                                                {o.status === 'pending' && o.step === 'new' && <Button size="sm" variant="outline" onClick={() => advance(o, 'preparing')}>بدء التجهيز</Button>}
                                                {o.status === 'pending' && (o.step === 'new' || o.step === 'preparing') && <Button size="sm" variant="outline" icon={<PackageCheck />} onClick={() => advance(o, 'ready')}>جاهز للاستلام</Button>}
                                                {o.status === 'pending' && <Button size="sm" icon={<CheckCheck />} onClick={() => setDelivering(o)}>تسليم</Button>}
                                                {o.status === 'pending' && (!o.paid || can.refund) && <Button size="sm" variant="ghost" icon={<Ban />} onClick={() => setCancelling(o)}>إلغاء</Button>}
                                                {mayPrint && <Button size="sm" variant="ghost" href={route('club.sales.receipt', o.id)} external icon={<Printer />} aria-label={`إيصال ${o.number}`} title="الإيصال" />}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={orders} />
            </Card>

            {delivering && <DeliverModal order={delivering} onClose={() => setDelivering(null)} />}
            {cancelling && <CancelModal order={cancelling} onClose={() => setCancelling(null)} />}
        </ClubLayout>
    );
}

function DeliverModal({ order, onClose }: { order: Order; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm<{ method: '' | 'cash' | 'card'; reference: string }>({ method: order.paid ? '' : 'cash', reference: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.sales.orders.deliver', order.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`تسليم الطلب ${order.number}`}
            footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button loading={form.processing} onClick={submit}>{order.paid ? 'تأكيد التسليم' : `تحصيل ${money(order.total, currency)} والتسليم`}</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                {order.paid ? (
                    <p className="text-sm text-muted">الطلب مدفوع. سيُسجَّل تسليمه باسمك ويكتمل البيع.</p>
                ) : (
                    <>
                        <p className="text-sm text-muted">الطلب غير مدفوع: يُحصَّل المبلغ كاملًا الآن ويُسجَّل على فاتورة الطلب، ثم يكتمل البيع.</p>
                        <Field label="طريقة التحصيل" error={form.errors.method} required>
                            <Select value={form.data.method} onChange={(e) => form.setData('method', e.target.value as 'cash' | 'card')}>
                                <option value="cash">نقدًا</option>
                                <option value="card">بطاقة (جهاز الشبكة)</option>
                            </Select>
                        </Field>
                        {form.data.method === 'card' && (
                            <Field label="رقم العملية من جهاز الشبكة" error={form.errors.reference} required>
                                <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                            </Field>
                        )}
                    </>
                )}
            </form>
        </Modal>
    );
}

function CancelModal({ order, onClose }: { order: Order; onClose: () => void }) {
    const form = useForm<{ reason: string; refund_method: string }>({ reason: '', refund_method: order.method_label && order.payment_method === 'cash' ? 'cash' : 'other' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.sales.orders.cancel', order.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`إلغاء الطلب ${order.number}`}
            footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button variant="danger" loading={form.processing} disabled={form.data.reason.trim() === ''} onClick={submit}>إلغاء الطلب</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted">
                    تعود الكميات إلى المخزون.{' '}
                    {order.paid ? 'الطلب مدفوع: يصدر إشعار دائن بكامل المبلغ ويُسجَّل رده. تسجيل الرد لا ينفّذ تحويلًا فعليًا؛ نفّذه من Paymob أو الصندوق.' : 'تُلغى فاتورة الطلب غير المدفوعة.'}
                </p>
                <Field label="سبب الإلغاء" error={form.errors.reason} required>
                    <Textarea rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} maxLength={250} />
                </Field>
                {order.paid && (
                    <Field label="طريقة رد المبلغ" error={form.errors.refund_method} required>
                        <Select value={form.data.refund_method} onChange={(e) => form.setData('refund_method', e.target.value)}>
                            <option value="cash">نقدًا من الصندوق</option>
                            <option value="card">بطاقة (جهاز الشبكة)</option>
                            <option value="bank_transfer">تحويل بنكي</option>
                            <option value="other">أخرى (مثل الاسترداد من Paymob)</option>
                        </Select>
                    </Field>
                )}
            </form>
        </Modal>
    );
}
