import { type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Check } from 'lucide-react';
import { Badge, Button, Card, Field, Select, Textarea } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { money } from '@/lib/format';

/** Paymob money the books do not hold for this invoice (PaymentReview); payments.review closes it on Paymob's own evidence. */
export interface PaymentReviewRow {
    id: number;
    status: 'open' | 'recorded' | 'refunded' | 'not_collected' | string;
    outcome: string | null;
    reason: string;
    expected: string;
    expected_currency: string;
    received: string | null;
    received_currency: string | null;
    transaction_id: string;
    order_id: string | null;
    integration_id: string | null;
    card_brand: string | null;
    reference: string | null;
    at: string | null;
    closed_at: string | null;
    closed_by: string | null;
    close_note: string | null;
}

const OUTCOMES: { value: string; label: string; hint: string }[] = [
    { value: 'refunded', label: 'رُدّ المبلغ كاملًا للعميل من Paymob', hint: 'يلزم إشعار رد موقّع من Paymob بالمبلغ نفسه.' },
    { value: 'not_collected', label: 'لم يُحصَّل: رفضته Paymob أو ألغته', hint: 'يلزم إشعار موقّع من Paymob بالرفض أو الإلغاء.' },
    { value: 'recorded', label: 'سُجّل المبلغ في الدفاتر', hint: 'يلزم أن تكون في الدفاتر دفعة لعملية Paymob نفسها.' },
];

function CloseReview({ review }: { review: PaymentReviewRow }) {
    const form = useForm({ outcome: '', note: '' });
    const chosen = OUTCOMES.find((o) => o.value === form.data.outcome);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.payment-reviews.close', review.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <form onSubmit={submit} className="mt-2 space-y-2">
            <Field label="ما الذي حدث للمبلغ؟" error={form.errors.outcome} hint={chosen?.hint ?? 'تُغلق المراجعة على دليل من Paymob فقط، ولا يتغير بها أي مبلغ.'} required>
                <Select value={form.data.outcome} onChange={(e) => form.setData('outcome', e.target.value)}>
                    <option value="">اختر</option>
                    {OUTCOMES.map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                </Select>
            </Field>
            <Field label="ما الذي تم بشأنها؟" error={form.errors.note} required>
                <Textarea rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={400} />
            </Field>
            <Button type="submit" size="sm" variant="outline" loading={form.processing} icon={<Check />}>إغلاق المراجعة</Button>
        </form>
    );
}

export default function PaymentReviews({ reviews }: { reviews: PaymentReviewRow[] }) {
    const can = useCan();
    if (reviews.length === 0) return null;
    return (
        <Card className="p-4">
            <h2 className="mb-1 flex items-center gap-2 font-bold text-ink">
                <AlertTriangle className="size-4 text-amber-600" />
                دفعات Paymob تحتاج مراجعة
            </h2>
            <p className="mb-3 text-[12px] text-[#9ca3af]">عمليات Paymob لم تُسجَّل على هذه الفاتورة: مؤكدة لا تطابقها، أو قيد التنفيذ لدى البنك منذ يوم. لم يُفعَّل شيء. ابحث عنها في لوحة Paymob برقم العملية.</p>
            {reviews.map((r) => (
                <div key={r.id} className="border-t border-line py-2 text-[13px]">
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <span className="text-ink">{r.reason}</span>
                        <Badge tone={r.status === 'open' ? 'amber' : 'gray'}>{r.status === 'open' ? 'قيد المراجعة' : (r.outcome ?? 'أُغلقت')}</Badge>
                    </div>
                    <dl className="mt-1 grid grid-cols-1 gap-x-4 gap-y-0.5 text-[12px] text-[#6b7280] sm:grid-cols-2">
                        <div>المطلوب: <span className="tabular-nums text-ink">{money(r.expected, r.expected_currency)}</span></div>
                        <div>المستلم: <span className="tabular-nums text-ink">{r.received !== null ? money(r.received, r.received_currency || r.expected_currency) : 'غير معروف'}</span>{r.received_currency && r.received_currency !== r.expected_currency ? ` (${r.received_currency})` : ''}</div>
                        <div>رقم عملية Paymob: <span dir="ltr" className="num">{r.transaction_id}</span></div>
                        {r.reference && <div>المرجع: <span dir="ltr" className="num">{r.reference}</span></div>}
                        {r.order_id && <div>رقم الطلب الموقّع: <span dir="ltr" className="num">{r.order_id}</span></div>}
                        {r.integration_id && <div>التكامل: <span dir="ltr" className="num">{r.integration_id}</span></div>}
                        {r.card_brand && <div>شبكة البطاقة: {r.card_brand}</div>}
                        {r.at && <div>وصلت: <span className="num">{r.at}</span></div>}
                    </dl>
                    {r.status !== 'open' && (
                        <p className="mt-1 text-[12px] text-[#6b7280]">أغلقها {r.closed_by ?? '—'} في <span className="num">{r.closed_at}</span>: {r.close_note}</p>
                    )}
                    {r.status === 'open' && can('payments.review') && <CloseReview review={r} />}
                </div>
            ))}
        </Card>
    );
}
