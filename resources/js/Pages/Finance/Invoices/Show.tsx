import { useEffect, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { AlertTriangle, ArrowRight, Banknote, Check, Clock, Copy, CreditCard, ExternalLink, FileDown, MessageCircle, Printer, Receipt, Undo2, Wallet, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PaperPanel, { DocumentMeta, LtrDates, PaperAside, type PaperData } from '@/Components/Papers/PaperPanel';
import { Badge, Button, Card, ChoiceCard, ConfirmAction, Field, Input, Modal, Notice, PageHeader, Select, Textarea } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { date, dayCount, money } from '@/lib/format';
import { invoiceStatus, invoiceType, label, paymentMethod, paymentStatus } from '@/lib/labels';
import { INVOICE_STATES } from './Index';

interface Item {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
    line_total: string;
}

interface PaymentRow {
    id: number;
    receipt_number: string | null;
    kind: 'payment' | 'refund';
    method: string;
    status: string;
    amount: string;
    reference: string | null;
    notes: string | null;
    rejection_reason: string | null;
    paid_at: string | null;
    receiver: string | null;
    has_proof: boolean;
}

interface CreditNoteRow {
    id: number;
    number: string;
    amount: string;
    refunded_total: string;
    refundable: string;
    reason: string;
    issued_at: string;
    creator: string | null;
}

interface Invoice {
    id: number;
    number: string;
    type: string;
    status: string;
    customer_name: string;
    customer_phone: string | null;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    tax_rate: string;
    total: string;
    credited_total: string;
    net_total: string;
    paid_total: string;
    refund_due: string;
    currency: string;
    billable_type: string | null;
    billable_id: number | null;
    balance: string;
    issue_date: string;
    due_date: string | null;
    overdue: boolean;
    days_overdue: number;
    state: string;
    notes: string | null;
    void_reason: string | null;
    creator: string | null;
    paid_at: string | null;
    items: Item[];
    payments: PaymentRow[];
    credit_notes: CreditNoteRow[];
    pay_link: string | null;
    public_link: string;
}

interface Intent {
    id: number;
    reference: string;
    amount: string;
    status: string;
    channel: string;
    failure_reason: string | null;
    created_at: string;
}

interface Props {
    invoice: Invoice;
    intents: Intent[];
    onlinePayments: boolean;
    whatsappEnabled: boolean;
    paper: PaperData;
}

const intentStatus: Record<string, ['green' | 'amber' | 'red' | 'gray', string]> = {
    pending: ['amber', 'بانتظار الدفع'],
    paid: ['green', 'مدفوع'],
    failed: ['red', 'فشل'],
    expired: ['gray', 'منتهي'],
};

const channelLabel: Record<string, string> = { portal: 'البوابة', website: 'الموقع', whatsapp: 'رابط واتساب', reception: 'الاستقبال' };

export default function InvoiceShow({ invoice, intents, onlinePayments, whatsappEnabled, paper }: Props) {
    const can = useCan();
    const currency = useCurrency() || invoice.currency;
    const [payOpen, setPayOpen] = useState(false);
    const [creditOpen, setCreditOpen] = useState(false);
    const [refundNote, setRefundNote] = useState<CreditNoteRow | null>(null);
    const canCredit = can('invoices.credit') && invoice.status !== 'void' && Number(invoice.net_total) > 0;
    const [statusLabel, statusTone] = label(invoiceStatus, invoice.status);
    const isOpen = invoice.status === 'issued' || invoice.status === 'partially_paid';
    const discounted = invoice.items.some((i) => Number(i.discount) > 0);
    const overdueBadge = invoice.overdue && <Badge tone="red">متأخرة {dayCount(invoice.days_overdue)}</Badge>;
    const canVoid = can('invoices.void') && invoice.status !== 'void' && Number(invoice.paid_total) === 0
        && !invoice.payments.some((p) => p.status === 'pending');

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success('تم نسخ الرابط');
        } catch {
            toast.error('تعذّر النسخ');
        }
    };

    return (
        <ClubLayout title={`فاتورة ${invoice.number}`}>
            <PageHeader
                back={<Link href={route('club.invoices.index')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> فواتير العملاء</Link>}
                title={<span className="num">{invoice.number}</span>}
                subtitle={`${invoice.customer_name} — ${INVOICE_STATES[invoice.state]?.[0] ?? statusLabel} · ${invoiceType[invoice.type] ?? invoice.type}`}
                actions={
                    <>
                        {isOpen && can('payments.record') && <Button icon={<Wallet />} onClick={() => setPayOpen(true)}>تسجيل دفعة</Button>}
                        {canCredit && <Button variant="outline" icon={<Undo2 />} onClick={() => setCreditOpen(true)}>إشعار دائن</Button>}
                        {can('whatsapp.send') && invoice.billable_type === 'member' && isOpen && whatsappEnabled && (
                            <ConfirmAction url={route('club.invoices.send', invoice.id)} title="إرسال عبر واتساب" message="ستُرسل الفاتورة ورابط الدفع للعضو بقالب «إرسال الفاتورة» المعتمد." confirmLabel="إرسال" tone="primary" variant="outline" size="md" icon={<MessageCircle />}>
                                إرسال عبر واتساب
                            </ConfirmAction>
                        )}
                        {/* On a narrow screen the paper sits under the details: a way to it from the top. */}
                        <Button variant="outline" className="xl:hidden" href={route('club.invoices.print', invoice.id)} external icon={<Printer />}>طباعة</Button>
                    </>
                }
            />

            {invoice.overdue ? (
                <Notice tone="red" icon={<AlertTriangle />} className="mb-6">
                    متأخرة السداد منذ {dayCount(invoice.days_overdue)} — استحقت في <span className="num">{date(invoice.due_date)}</span>.{' '}
                    {Number(invoice.paid_total) > 0 ? <>دُفع <b className="num">{money(invoice.paid_total, currency)}</b> من <span className="num">{money(invoice.net_total, currency)}</span>، والمتبقي <b className="num">{money(invoice.balance, currency)}</b>.</> : <>لم يُدفع منها شيء، والمتبقي <b className="num">{money(invoice.balance, currency)}</b>.</>}
                </Notice>
            ) : invoice.status === 'partially_paid' && (
                <Notice tone="blue" icon={<Clock />} className="mb-6">
                    مدفوعة جزئيًا — دُفع <b className="num">{money(invoice.paid_total, currency)}</b> من <span className="num">{money(invoice.net_total, currency)}</span>، والمتبقي <b className="num">{money(invoice.balance, currency)}</b>{invoice.due_date ? <> يستحق في <span className="num">{date(invoice.due_date)}</span></> : null}.
                </Notice>
            )}

            {invoice.status === 'void' && (
                <Notice tone="red" className="mb-6">
                    هذه الفاتورة ملغاة{invoice.void_reason ? `: ${invoice.void_reason}` : '.'}
                </Notice>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    <DocumentMeta cells={[
                        { label: 'الحالة', value: <span className="flex flex-wrap items-center gap-1"><Badge tone={statusTone}>{statusLabel}</Badge>{overdueBadge}</span> },
                        { label: invoice.billable_type === 'member' ? 'العضو' : 'العميل', value: invoice.billable_type === 'member' && invoice.billable_id ? <Link href={route('club.members.show', invoice.billable_id)} className="font-medium hover:text-brand">{invoice.customer_name}</Link> : invoice.customer_name },
                        invoice.customer_phone ? { label: 'الهاتف', value: invoice.customer_phone, ltr: true } : null,
                        { label: 'نوع الفاتورة', value: invoiceType[invoice.type] ?? invoice.type },
                        { label: 'تاريخ الإصدار', value: invoice.issue_date, ltr: true },
                        invoice.due_date ? { label: 'الاستحقاق', value: invoice.due_date, ltr: true } : null,
                        invoice.paid_at ? { label: 'تاريخ السداد', value: invoice.paid_at, ltr: true } : null,
                        invoice.creator ? { label: 'أصدرها', value: invoice.creator } : null,
                        invoice.notes ? { label: 'ملاحظات تُطبع', value: invoice.notes, wide: true } : null,
                    ]} />

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-ink">البنود</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[520px] text-[13px]">
                                <thead className="text-[12px] text-[#71717a]">
                                    <tr>
                                        <th className="p-2 text-start font-medium">البيان</th>
                                        <th className="p-2 text-end font-medium">الكمية</th>
                                        <th className="p-2 text-end font-medium">السعر</th>
                                        {discounted && <th className="p-2 text-end font-medium">الخصم</th>}
                                        <th className="p-2 text-end font-medium">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoice.items.map((item) => (
                                        <tr key={item.id} className="border-t border-line">
                                            <td className="p-2"><LtrDates text={item.description} /></td>
                                            <td className="p-2 text-end tabular-nums">{Number(item.quantity)}</td>
                                            <td className="p-2 text-end tabular-nums">{money(item.unit_price, currency)}</td>
                                            {discounted && <td className="p-2 text-end tabular-nums text-[#6b7280]">{Number(item.discount) > 0 ? `− ${money(item.discount, currency)}` : '—'}</td>}
                                            <td className="p-2 text-end font-semibold tabular-nums">{money(item.line_total, currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className="mt-4 ms-auto max-w-xs space-y-1.5 border-t border-line pt-4 text-[13px]">
                            <Row label="المجموع الفرعي" value={money(invoice.subtotal, currency)} />
                            {Number(invoice.discount_total) > 0 && <Row label="الخصم" value={`− ${money(invoice.discount_total, currency)}`} />}
                            {Number(invoice.tax_total) > 0 && <Row label={`ضريبة القيمة المضافة (${Number(invoice.tax_rate)}%)`} value={money(invoice.tax_total, currency)} />}
                            <Row label="الإجمالي" value={money(invoice.total, currency)} strong />
                            {Number(invoice.credited_total) > 0 && (
                                <>
                                    <Row label="الإشعارات الدائنة" value={`− ${money(invoice.credited_total, currency)}`} />
                                    <Row label="صافي الفاتورة" value={money(invoice.net_total, currency)} strong />
                                </>
                            )}
                            <Row label="المدفوع" value={money(invoice.paid_total, currency)} />
                            {invoice.status !== 'void' && <Row label="المتبقي" value={money(invoice.balance, currency)} strong />}
                            {Number(invoice.refund_due) > 0 && <Row label="مستحق ردّه للعميل" value={money(invoice.refund_due, currency)} />}
                        </dl>
                    </Card>

                    {invoice.payments.length > 0 && (
                        <Card className="p-4">
                            <h3 className="mb-2 text-[13px] font-bold">التحصيلات</h3>
                            {invoice.payments.map((p) => {
                                const [pLabel, pTone] = label(paymentStatus, p.status);
                                const title = <>{p.receipt_number ?? (p.kind === 'refund' ? 'رد مبلغ' : 'دفعة')} — {p.kind === 'refund' ? 'رد مبلغ' : paymentMethod[p.method] ?? p.method}</>;
                                return (
                                    <div key={p.id} className="border-t border-line py-2 text-[13px]">
                                        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                            {p.status === 'completed'
                                                ? <a href={route('club.payments.receipt', p.id)} target="_blank" rel="noreferrer" className="min-w-0 truncate text-[#1d4ed8] hover:underline">{title}</a>
                                                : <span className="min-w-0 truncate">{title}</span>}
                                            <span dir="ltr" className="text-[#6b7280] num">{p.paid_at ?? '—'}</span>
                                            {p.status !== 'completed' && <Badge tone={pTone}>{pLabel}</Badge>}
                                            <span className={cn('tabular-nums', p.kind === 'refund' && 'text-[#b91c1c]')}>{p.kind === 'refund' ? '− ' : ''}{money(p.amount, currency)}</span>
                                        </div>
                                        {(p.reference || p.receiver || p.notes) && <p className="mt-0.5 text-[12px] text-[#9ca3af]">{[p.reference && `مرجع ${p.reference}`, p.receiver, p.notes].filter(Boolean).join(' · ')}</p>}
                                        {p.rejection_reason && <p className="mt-0.5 text-[12px] text-[#b91c1c]">سبب الرفض: {p.rejection_reason}</p>}
                                        {(p.has_proof || (p.status === 'pending' && can('payments.confirm'))) && (
                                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                {p.has_proof && <Button variant="ghost" size="sm" href={route('club.payments.proof', p.id)} external icon={<FileDown />}>الإثبات</Button>}
                                                {p.status === 'pending' && can('payments.confirm') && (
                                                    <>
                                                        <ConfirmAction url={route('club.payments.confirm', p.id)} title="تأكيد التحويل" message="تأكد من وصول المبلغ في كشف الحساب. سيُطبَّق على الفاتورة وتُفعَّل العضوية إن اكتمل السداد." confirmLabel="تأكيد الاستلام" tone="primary" variant="success" icon={<Check />}>تأكيد</ConfirmAction>
                                                        <ConfirmAction url={route('club.payments.reject', p.id)} title="رفض التحويل" reason="سبب الرفض" confirmLabel="رفض" icon={<X />}>رفض</ConfirmAction>
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </Card>
                    )}

                    {invoice.credit_notes.length > 0 && (
                        <Card className="p-4">
                            <h3 className="mb-2 text-[13px] font-bold">إشعارات دائنة</h3>
                            {invoice.credit_notes.map((n) => (
                                <div key={n.id} className="border-t border-line py-2 text-[13px]">
                                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                        <a href={route('club.credit-notes.print', n.id)} target="_blank" rel="noreferrer" className="min-w-0 truncate text-[#1d4ed8] hover:underline"><span className="num">{n.number}</span>{n.reason ? ` — ${n.reason}` : ''}</a>
                                        <span dir="ltr" className="text-[#6b7280] num">{n.issued_at}</span>
                                        <span className="tabular-nums">− {money(n.amount, currency)}</span>
                                    </div>
                                    {Number(n.refunded_total) > 0 && <p className="mt-0.5 text-[12px] text-[#047857]">رُدّ للعميل: <span className="num">{money(n.refunded_total, currency)}</span></p>}
                                    {Number(n.refundable) > 0 && Number(invoice.refund_due) > 0 && can('payments.refund') && (
                                        <Button variant="outline" size="sm" className="mt-1.5" icon={<Undo2 />} onClick={() => setRefundNote(n)}>رد المبلغ</Button>
                                    )}
                                </div>
                            ))}
                        </Card>
                    )}

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-ink">المشاركة والدفع الإلكتروني</h2>
                        <div className="space-y-3 text-[13px]">
                            {invoice.pay_link && (
                                <div>
                                    <p className="mb-1 text-[12px] text-[#6b7280]">رابط الدفع</p>
                                    {onlinePayments ? (
                                        <div className="flex gap-2">
                                            <Input readOnly dir="ltr" value={invoice.pay_link} className="text-xs" onFocus={(e) => e.currentTarget.select()} />
                                            <Button type="button" variant="outline" onClick={() => copy(invoice.pay_link!)} icon={<Copy />} aria-label="نسخ" />
                                        </div>
                                    ) : (
                                        <p className="rounded-[12px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">الدفع الإلكتروني غير مفعّل. فعّل Paymob من الإعدادات ← الربط.</p>
                                    )}
                                </div>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" href={invoice.public_link} external icon={<ExternalLink />}>عرض نسخة العضو</Button>
                                <Button variant="ghost" size="sm" onClick={() => copy(invoice.public_link)} icon={<Copy />}>نسخ الرابط</Button>
                            </div>
                            {can('whatsapp.send') && invoice.billable_type === 'member' && isOpen && !whatsappEnabled && <p className="text-[12px] text-[#9ca3af]">واتساب غير مفعّل لهذا النادي.</p>}
                        </div>
                    </Card>

                    {intents.length > 0 && (
                        <Card className="p-4">
                            <h2 className="mb-1 font-bold text-ink">محاولات الدفع الإلكتروني</h2>
                            <p className="mb-3 text-[12px] text-[#9ca3af]">تُؤكَّد الدفعة فقط بإشعار موقَّع من البوابة.</p>
                            {intents.map((it) => {
                                const [tone, text] = intentStatus[it.status] ?? ['gray', it.status];
                                return (
                                    <div key={it.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-line py-2 text-[13px]">
                                        <span dir="ltr" className="min-w-0 truncate text-[#6b7280] num">{it.reference}</span>
                                        <span className="text-[#6b7280]">{it.created_at} · {channelLabel[it.channel] ?? it.channel}</span>
                                        <Badge tone={tone}>{text}</Badge>
                                        <span className="tabular-nums">{money(it.amount, currency)}</span>
                                        {it.failure_reason && <p className="w-full text-[12px] text-[#b91c1c]">{it.failure_reason}</p>}
                                    </div>
                                );
                            })}
                        </Card>
                    )}

                    {canVoid && <VoidCard invoice={invoice} />}
                </div>

                <PaperAside className="xl:col-span-2">
                    <PaperPanel paper={paper} printUrl={route('club.invoices.print', invoice.id)} pdfUrl={route('club.papers.pdf', { type: 'invoice', id: invoice.id })} note="الورقة نفسها التي يراها العميل في رابطه وتُطبع وتُنزَّل؛ شكلها من «قوالب الأوراق» في الإعدادات." />
                </PaperAside>
            </div>

            <RecordPaymentModal open={payOpen} onClose={() => setPayOpen(false)} invoice={invoice} currency={currency} />
            <CreditNoteModal open={creditOpen} onClose={() => setCreditOpen(false)} invoice={invoice} currency={currency} canRefund={can('payments.refund')} />
            <CreditRefundModal note={refundNote} invoice={invoice} onClose={() => setRefundNote(null)} currency={currency} />
        </ClubLayout>
    );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={cn('flex justify-between gap-4', strong && 'font-bold')}>
            <dt className="text-[#71717a]">{label}</dt>
            <dd className="num">{value}</dd>
        </div>
    );
}

/** Cancelling: the reason, then the red button — open only while nothing is paid on it (refund first). */
function VoidCard({ invoice }: { invoice: Invoice }) {
    const form = useForm({ reason: '' });
    return (
        <Card className="p-4">
            <form onSubmit={(e) => { e.preventDefault(); form.post(route('club.invoices.void', invoice.id), { preserveScroll: true }); }} className="flex flex-wrap items-end gap-2">
                <label className="min-w-48 flex-1 text-[13px]">
                    سبب الإلغاء
                    <Input className="mt-1" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} maxLength={255} required />
                    {form.errors.reason && <span className="mt-1 block text-[12px] text-[#b91c1c]">{form.errors.reason}</span>}
                </label>
                <Button type="submit" variant="danger" loading={form.processing}>إلغاء الفاتورة</Button>
            </form>
            <p className="mt-2 text-[12px] text-[#9ca3af]">العضوية المرتبطة بانتظار الدفع تُلغى أيضًا.</p>
        </Card>
    );
}

const methods = [
    { key: 'cash', label: 'نقدًا', icon: Banknote },
    { key: 'card', label: 'بطاقة (جهاز الشبكة)', icon: CreditCard },
    { key: 'bank_transfer', label: 'تحويل بنكي', icon: Wallet },
    { key: 'other', label: 'أخرى', icon: Receipt },
] as const;

function RecordPaymentModal({ open, onClose, invoice, currency }: { open: boolean; onClose: () => void; invoice: Invoice; currency: string }) {
    const form = useForm<{ amount: string; method: string; reference: string; notes: string; proof: File | null }>({
        amount: invoice.balance,
        method: 'cash',
        reference: '',
        notes: '',
        proof: null,
    });

    // The balance shrinks after each payment; reopening the modal must offer what is left now.
    useEffect(() => {
        if (open) form.setData((d) => ({ ...d, amount: invoice.balance }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, invoice.balance]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.payments.store', invoice.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="تسجيل دفعة"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="record-payment" loading={form.processing}>تسجيل</Button>
                </>
            }
        >
            <form id="record-payment" onSubmit={submit} className="space-y-4">
                <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm">
                    المتبقي على الفاتورة: <span className="font-semibold num">{money(invoice.balance, currency)}</span>
                </div>
                <div className="grid grid-cols-2 gap-2">
                    {methods.map(({ key, label: text, icon: Icon }) => (
                        <ChoiceCard key={key} selected={form.data.method === key} onClick={() => form.setData('method', key)} className="flex items-center gap-2 py-2.5 text-sm">
                            <Icon className="size-4" /> {text}
                        </ChoiceCard>
                    ))}
                </div>
                {form.errors.method && <p className="text-xs text-red-600">{form.errors.method}</p>}
                <Field label="المبلغ" error={form.errors.amount} required>
                    <Input type="number" step="0.001" min="0.001" max={invoice.balance} dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                </Field>
                {form.data.method === 'card' && (
                    <Field label="رقم العملية من جهاز الشبكة" error={form.errors.reference} required hint="يربط الدفعة بإيصال جهاز البنك">
                        <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} required />
                    </Field>
                )}
                {form.data.method === 'bank_transfer' && (
                    <>
                        <div className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">التحويل يُسجَّل «بانتظار التأكيد» ولا تُفعَّل العضوية حتى تؤكده المالية.</div>
                        <Field label="رقم مرجع التحويل" error={form.errors.reference}>
                            <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                        </Field>
                        <Field label="إثبات التحويل (صورة أو PDF)" error={form.errors.proof}>
                            <Input type="file" accept="image/*,application/pdf" onChange={(e) => form.setData('proof', e.target.files?.[0] ?? null)} />
                        </Field>
                    </>
                )}
                {(form.data.method === 'cash' || form.data.method === 'other') && (
                    <Field label="مرجع (اختياري)" error={form.errors.reference}>
                        <Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                    </Field>
                )}
                <Field label="ملاحظات" error={form.errors.notes}>
                    <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}

const refundMethods = [
    { key: 'cash', label: 'نقدًا' },
    { key: 'bank_transfer', label: 'تحويل بنكي' },
    { key: 'card', label: 'بطاقة (جهاز الشبكة)' },
    { key: 'other', label: 'أخرى' },
];

function CreditNoteModal({ open, onClose, invoice, currency, canRefund }: { open: boolean; onClose: () => void; invoice: Invoice; currency: string; canRefund: boolean }) {
    const paid = Number(invoice.paid_total) > 0;
    const form = useForm({ amount: '', reason: '', refund_now: paid && canRefund, method: 'cash', reference: '' });
    // What the customer would be owed after this note: paid minus the new net total.
    const owed = Math.max(0, Number(invoice.paid_total) - (Number(invoice.net_total) - Number(form.data.amount || 0)));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.credit-notes.store', invoice.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="إصدار إشعار دائن"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="credit-form" loading={form.processing}>إصدار الإشعار</Button>
                </>
            }
        >
            <form id="credit-form" onSubmit={submit} className="space-y-4">
                <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm">
                    صافي الفاتورة الحالي: <span className="font-semibold num">{money(invoice.net_total, currency)}</span>
                    {paid && <> · المدفوع: <span className="num">{money(invoice.paid_total, currency)}</span></>}
                </div>
                <Field label="قيمة الإشعار" error={form.errors.amount} required>
                    <Input type="number" step="0.001" min="0.001" max={invoice.net_total} dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                </Field>
                <Field label="السبب" error={form.errors.reason} required>
                    <Textarea rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
                {paid && canRefund && (
                    <div className="space-y-3 rounded-xl border border-line p-3">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={form.data.refund_now} onChange={(e) => form.setData('refund_now', e.target.checked)} />
                            رد المبلغ للعميل الآن
                            {owed > 0 && <span className="text-muted">(<span className="num">{money(owed, currency)}</span>)</span>}
                        </label>
                        {form.data.refund_now && (
                            <>
                                <Field label="طريقة الرد" error={form.errors.method}>
                                    <Select value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                                        {refundMethods.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
                                    </Select>
                                </Field>
                                <Field label={form.data.method === 'card' ? 'رقم العملية من جهاز الشبكة' : 'مرجع (اختياري)'} error={form.errors.reference} required={form.data.method === 'card'}>
                                    <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} required={form.data.method === 'card'} />
                                </Field>
                            </>
                        )}
                        {!form.data.refund_now && <p className="text-xs text-muted">يمكن رد المبلغ لاحقًا من قائمة الإشعارات الدائنة.</p>}
                    </div>
                )}
                <p className="text-xs text-muted">الإشعار لا يلغي العضوية تلقائيًا. ألغِ العضوية من ملف العضو إذا لزم.</p>
            </form>
        </Modal>
    );
}

function CreditRefundModal({ note, invoice, onClose, currency }: { note: CreditNoteRow | null; invoice: Invoice; onClose: () => void; currency: string }) {
    const form = useForm({ amount: '', method: 'cash', reference: '' });
    const available = note ? Math.min(Number(note.refundable), Number(invoice.refund_due)).toFixed(3) : '0';

    useEffect(() => {
        if (note) form.setData((d) => ({ ...d, amount: available }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [note?.id, available]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!note) return;
        form.post(route('club.credit-notes.refund', note.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            open={note !== null}
            onClose={onClose}
            title="رد مبلغ"
            size="sm"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>تراجع</Button>
                    <Button type="submit" form="credit-refund-form" variant="danger" loading={form.processing}>تسجيل الرد</Button>
                </>
            }
        >
            {note && (
                <form id="credit-refund-form" onSubmit={submit} className="space-y-4">
                    <p className="text-sm text-muted">
                        الإشعار <span className="num">{note.number}</span> · المتاح للرد: <span className="num font-medium text-ink">{money(available, currency)}</span>
                    </p>
                    <Field label="مبلغ الرد" error={form.errors.amount} required>
                        <Input type="number" step="0.001" min="0.001" max={available} dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                    </Field>
                    <Field label="طريقة الرد" error={form.errors.method}>
                        <Select value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                            {refundMethods.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
                        </Select>
                    </Field>
                    <Field label={form.data.method === 'card' ? 'رقم العملية من جهاز الشبكة' : 'مرجع (اختياري)'} error={form.errors.reference} required={form.data.method === 'card'}>
                        <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} required={form.data.method === 'card'} />
                    </Field>
                </form>
            )}
        </Modal>
    );
}
