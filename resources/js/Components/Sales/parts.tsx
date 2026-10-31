import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Globe, Store } from 'lucide-react';
import { Badge, Button, Field, Input, Modal, Select, Textarea } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money } from '@/lib/format';

/** A sale as the list and its page get it (SalesController::row). */
export interface SaleRow {
    id: number;
    number: string;
    source: 'pos' | 'website';
    status: 'pending' | 'completed' | 'voided' | 'cancelled';
    fulfillment: string | null;
    state: SaleState;
    state_label: string;
    total: string;
    discount: string;
    payment_method: string;
    invoice_id: number | null;
    buyer: string;
    phone: string | null;
    member_id: number | null;
    cashier: string | null;
    branch: string | null;
    at: string;
    paid: boolean;
    items_count: number | null;
    items_preview: string | null;
    source_label: string;
    status_label: string;
    method_label: string;
}

export type SaleState = 'new' | 'preparing' | 'ready' | 'completed' | 'voided' | 'cancelled';

/** Each state's colour: the dot of its tab and its badge in the rows, the same everywhere. */
export const SALE_STATES: Record<SaleState, { tone: 'amber' | 'blue' | 'violet' | 'green' | 'red' | 'gray'; dot: string }> = {
    new: { tone: 'amber', dot: '#d97706' },
    preparing: { tone: 'blue', dot: '#2563eb' },
    ready: { tone: 'violet', dot: '#7c3aed' },
    completed: { tone: 'green', dot: '#059669' },
    voided: { tone: 'red', dot: '#dc2626' },
    cancelled: { tone: 'gray', dot: '#9ca3af' },
};

export function StateBadge({ sale }: { sale: Pick<SaleRow, 'state' | 'state_label'> }) {
    return <Badge tone={SALE_STATES[sale.state]?.tone ?? 'gray'}>{sale.state_label}</Badge>;
}

/** Where the sale came from: the website in violet, the till in grey. `short` (a list's column) says «الموقع». */
export function SourceBadge({ source, label, short = false }: { source: string; label: string; short?: boolean }) {
    return (
        <Badge tone={source === 'website' ? 'violet' : 'gray'}>
            {source === 'website' ? <Globe /> : <Store />}
            {short && source === 'website' ? 'الموقع' : label}
        </Badge>
    );
}

/** Void a completed sale: stock goes back, the money is refunded by a credit note (sales.refund). */
export function VoidDialog({ sale, onClose }: { sale: Pick<SaleRow, 'id' | 'number'>; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string>();
    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        router.post(route('club.pos.void', sale.id), { reason }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="xs"
            title={`إلغاء عملية البيع ${sale.number}`}
            description="تعود المنتجات إلى المخزون، ويصدر إشعار دائن ويُسجَّل رد المبلغ بطريقة الدفع نفسها. تسجيل الرد لا ينفّذ تحويلًا فعليًا."
            footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button variant="danger" loading={busy} disabled={reason.trim() === ''} onClick={() => submit()}>إلغاء البيع</Button></>}
        >
            <form onSubmit={submit}>
                <Field label="سبب الإلغاء" error={error} required>
                    <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
                </Field>
            </form>
        </Modal>
    );
}

/** Hand a website order over: collected now in full when unpaid, then the sale is complete. */
export function DeliverDialog({ sale, onClose }: { sale: Pick<SaleRow, 'id' | 'number' | 'paid' | 'total'>; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm<{ method: '' | 'cash' | 'card'; reference: string }>({ method: sale.paid ? '' : 'cash', reference: '' });
    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.post(route('club.sales.orders.deliver', sale.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`تسليم الطلب ${sale.number}`}
            description={sale.paid ? 'الطلب مدفوع. سيُسجَّل تسليمه باسمك ويكتمل البيع.' : 'الطلب غير مدفوع: يُحصَّل المبلغ كاملًا الآن ويُسجَّل على فاتورة الطلب، ثم يكتمل البيع.'}
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button loading={form.processing} onClick={() => submit()}>{sale.paid ? 'تأكيد التسليم' : `تحصيل ${money(sale.total, currency)} والتسليم`}</Button></>}
        >
            {!sale.paid && (
                <form onSubmit={submit} className="space-y-4">
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
                </form>
            )}
        </Modal>
    );
}

/** Cancel a website order: stock back; a paid one is refunded by a credit note (needs sales.refund). */
export function CancelOrderDialog({ sale, onClose }: { sale: Pick<SaleRow, 'id' | 'number' | 'paid' | 'payment_method'>; onClose: () => void }) {
    const form = useForm<{ reason: string; refund_method: string }>({ reason: '', refund_method: sale.payment_method === 'cash' ? 'cash' : 'other' });
    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.post(route('club.sales.orders.cancel', sale.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="sm"
            title={`إلغاء الطلب ${sale.number}`}
            description={<>تعود الكميات إلى المخزون. {sale.paid ? 'الطلب مدفوع: يصدر إشعار دائن بكامل المبلغ ويُسجَّل رده. تسجيل الرد لا ينفّذ تحويلًا فعليًا؛ نفّذه من Paymob أو الصندوق.' : 'تُلغى فاتورة الطلب غير المدفوعة.'}</>}
            footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button variant="danger" loading={form.processing} disabled={form.data.reason.trim() === ''} onClick={() => submit()}>إلغاء الطلب</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label="سبب الإلغاء" error={form.errors.reason} required>
                    <Textarea rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} maxLength={250} />
                </Field>
                {sale.paid && (
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
