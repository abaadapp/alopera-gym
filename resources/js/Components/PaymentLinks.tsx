import { useForm } from '@inertiajs/react';
import { Copy, Link2, MessageCircle } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { toast } from 'sonner';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Notice, Select } from '@/Components/ui';
import { clientKey } from '@/Components/Inventory/parts';

export interface PaymentLinkRow {
    id: number;
    number: string | null;
    description: string;
    customer: string | null;
    phone: string | null;
    amount: string | null;
    currency: string | null;
    status: 'pending' | 'paid' | 'expired' | 'cancelled';
    by: string | null;
    at: string;
    expires_at: string;
    url: string | null;
    whatsapp: string | null;
}

export interface PaymentLinksData {
    on: boolean;
    can_cancel: boolean;
    valid_days: number[];
    currency: string;
    rows: PaymentLinkRow[];
}

const STATUS: Record<PaymentLinkRow['status'], { label: string; tone: 'green' | 'amber' | 'red' | 'gray' }> = {
    pending: { label: 'بانتظار الدفع', tone: 'amber' },
    paid: { label: 'مدفوع', tone: 'green' },
    expired: { label: 'منتهي', tone: 'gray' },
    cancelled: { label: 'ملغى', tone: 'red' },
};

const DAYS: Record<number, string> = { 1: 'يوم واحد', 3: '3 أيام', 7: '7 أيام', 30: '30 يومًا' };

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('تم نسخ الرابط');
    } catch {
        toast.error('تعذّر النسخ');
    }
}

/** «إنشاء رابط دفع»: an amount and a description make a club invoice whose secure link opens Paymob's payment page. */
export function CreatePaymentLink({ data }: { data: PaymentLinksData }) {
    const key = useRef(clientKey());
    const form = useForm({ amount: '', description: '', customer_name: '', customer_phone: '', valid_days: '7' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, client_key: key.current }));
        form.post(route('club.payment-links.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                key.current = clientKey();
            },
        });
    };

    return (
        <Card>
            <CardHeader title={<span className="flex items-center gap-2"><Link2 className="size-4" />إنشاء رابط دفع</span>} subtitle="مبلغ ووصف، فيُصدر النظام فاتورة برابط دفع آمن تنسخه أو ترسله بواتساب. تُسجَّل الدفعة فقط بإشعار موقّع من Paymob." />
            {!data.on && (
                <Notice tone="amber" className="mb-4">الدفع الإلكتروني غير مفعّل الآن: يُنشأ الرابط، لكن صفحته تعرض الفاتورة دون زر الدفع حتى يُفعَّل Paymob.</Notice>
            )}
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label={`المبلغ (${data.currency})`} error={form.errors.amount} required hint="بالريال العُماني بثلاث منازل، وآخر خانة صفر (مثل 12.340). هو ما يدفعه العميل شاملًا الضريبة إن وُجدت.">
                    <Input inputMode="decimal" dir="ltr" placeholder="0.000" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                </Field>
                <Field label="صالح لمدة" error={form.errors.valid_days} hint="بعدها لا يفتح الرابط صفحة دفع.">
                    <Select value={form.data.valid_days} onChange={(e) => form.setData('valid_days', e.target.value)}>
                        {data.valid_days.map((d) => <option key={d} value={String(d)}>{DAYS[d] ?? `${d} يومًا`}</option>)}
                    </Select>
                </Field>
                <Field label="وصف الدفع" error={form.errors.description} required className="sm:col-span-2">
                    <Input maxLength={250} placeholder="مثال: اشتراك شهر — حصص خاصة" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </Field>
                <Field label="اسم العميل (اختياري)" error={form.errors.customer_name}>
                    <Input maxLength={160} value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} />
                </Field>
                <Field label="رقم الهاتف (اختياري)" error={form.errors.customer_phone} hint="لفتح واتساب على محادثته مباشرة.">
                    <Input inputMode="tel" dir="ltr" placeholder="9XXXXXXX" value={form.data.customer_phone} onChange={(e) => form.setData('customer_phone', e.target.value)} />
                </Field>
                <div className="sm:col-span-2">
                    <Button type="submit" loading={form.processing} icon={<Link2 />}>إنشاء الرابط</Button>
                </div>
            </form>
        </Card>
    );
}

/** The links made and where each stands: waiting, paid, expired or cancelled; copy, WhatsApp or cancel a waiting one. */
export function PaymentLinksLog({ data }: { data: PaymentLinksData }) {
    return (
        <Card padded={false}>
            <div className="p-5 pb-0"><CardHeader title="سجل روابط الدفع" subtitle="آخر 50 رابطًا. الحالة من الفاتورة كما سُجّلت: «مدفوع» بعد إشعار Paymob الموقّع فقط." /></div>
            {data.rows.length === 0 ? (
                <EmptyState title="لا توجد روابط بعد" description="أنشئ رابطًا بالمبلغ والوصف، ثم أرسله للعميل." />
            ) : (
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>الرابط</th><th>العميل</th><th>المبلغ</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
                        <tbody>
                            {data.rows.map((r) => (
                                <tr key={r.id}>
                                    <td>
                                        <p className="font-medium">{r.description}</p>
                                        <p className="num text-[11px] text-muted">{r.number} · {r.at}{r.by ? ` · ${r.by}` : ''}</p>
                                    </td>
                                    <td>
                                        <p>{r.customer ?? '—'}</p>
                                        {r.phone && <p className="num text-[11px] text-muted" dir="ltr">{r.phone}</p>}
                                    </td>
                                    <td className="num">{Number(r.amount ?? 0).toFixed(3)} {r.currency}</td>
                                    <td>
                                        <Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>
                                        {r.status === 'pending' && <p className="num mt-0.5 text-[11px] text-muted">حتى {r.expires_at}</p>}
                                    </td>
                                    <td>
                                        {!(r.status === 'pending' && r.url) && <span className="text-muted">—</span>}
                                        {r.status === 'pending' && r.url && (
                                            <div className="flex flex-wrap gap-1.5">
                                                <Button variant="outline" size="sm" onClick={() => copy(r.url!)} icon={<Copy />}>نسخ</Button>
                                                {r.whatsapp && <Button variant="outline" size="sm" href={r.whatsapp} external icon={<MessageCircle />}>واتساب</Button>}
                                                {data.can_cancel && <ConfirmAction url={route('club.payment-links.cancel', r.id)} title="إلغاء رابط الدفع" message="يُلغى رابط الدفع فقط، فلا يفتح صفحة دفع بعد ذلك. تبقى الفاتورة ومستحقاتها وقيودها كما هي، وإلغاء الفاتورة إجراء منفصل من صفحتها." confirmLabel="إلغاء الرابط" variant="ghost" size="sm">إلغاء</ConfirmAction>}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}
