import { router, useForm } from '@inertiajs/react';
import { Copy, ExternalLink, Link2, MessageCircle } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { Badge, Button, Card, CardHeader, EmptyState, Field, Input, Modal, Notice, Select } from '@/Components/ui';
import { clientKey } from '@/Components/Inventory/parts';

export interface PaymentLinkRow {
    /** Unique in a list: the invoice's current link (l…) or an earlier one (h…). */
    key: string;
    /** The current link's id (cancel); an earlier link's own id. */
    id: number;
    invoice_id: number;
    number: string | null;
    description: string;
    customer: string | null;
    phone: string | null;
    amount: string | null;
    /** The invoice's, not the link's: the link asks what the invoice still owes when it is opened. */
    paid: string | null;
    remaining: string | null;
    currency: string | null;
    status: 'pending' | 'processing' | 'review' | 'paid' | 'expired' | 'cancelled' | 'replaced';
    /** The invoice's current link, not cancelled. */
    live: boolean;
    /** What the invoice owed when this link was issued (null for a link made before v2). */
    asked?: string | null;
    /** When it was cancelled or replaced. */
    ended_at: string | null;
    /** Paymob references of its pages that brought no recorded payment: looked up at Paymob before it is ended. */
    unanswered: string[];
    /** The confirmed Paymob payment of the invoice. */
    payment: { amount: string; at: string | null; receipt: string | null; transaction: string | null } | null;
    by: string | null;
    at: string;
    expires_at: string;
    url: string | null;
    whatsapp: string | null;
}

export interface PaymentLinksData {
    on: boolean;
    /** Why online payments are off (Paymob not linked, stopped, keys missing, test mode on production). */
    reason?: string | null;
    can_cancel: boolean;
    can_open_invoice?: boolean;
    valid_days: number[];
    currency: string;
    rows: PaymentLinkRow[];
}

const STATUS: Record<PaymentLinkRow['status'], { label: string; tone: 'green' | 'amber' | 'red' | 'gray' | 'blue' }> = {
    pending: { label: 'بانتظار الدفع', tone: 'amber' },
    processing: { label: 'قيد التنفيذ', tone: 'blue' },
    review: { label: 'يحتاج مراجعة', tone: 'red' },
    paid: { label: 'مدفوع', tone: 'green' },
    expired: { label: 'منتهي', tone: 'gray' },
    cancelled: { label: 'ملغى', tone: 'red' },
    replaced: { label: 'مستبدل', tone: 'gray' },
};

const CANCEL_MESSAGE = 'يُلغى رابط الدفع فقط، فلا يفتح صفحة دفع بعد ذلك. تبقى الفاتورة ومستحقاتها وقيودها كما هي، وإلغاء الفاتورة إجراء منفصل من صفحتها.';

/** A date and time inside Arabic text: kept left to right («2026-11-27 22:23», not reversed by the paragraph). */
const Ltr = ({ children }: { children: string | null }) => <bdi dir="ltr" className="num">{children}</bdi>;

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
                <Notice tone="amber" className="mb-4">الدفع الإلكتروني غير مفعّل الآن.{data.reason ? ` ${data.reason}` : ''} يُنشأ الرابط، لكن صفحته تعرض الفاتورة دون زر الدفع حتى يُفعَّل Paymob.</Notice>
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

/** The links made and where each stands (waiting, at the bank, under review, paid, expired, cancelled), with the invoice's paid and left; copy, WhatsApp or cancel a waiting one, open its invoice. */
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
                                <tr key={r.key}>
                                    <td>
                                        <p className="font-medium">{r.description}</p>
                                        <p className="num text-[11px] text-muted">{r.number} · {r.at}{r.by ? ` · ${r.by}` : ''}</p>
                                    </td>
                                    <td>
                                        <p>{r.customer ?? '—'}</p>
                                        {r.phone && <p className="num text-[11px] text-muted" dir="ltr">{r.phone}</p>}
                                    </td>
                                    <td>
                                        <p className="num">{Number(r.amount ?? 0).toFixed(3)} {r.currency}</p>
                                        {r.status !== 'cancelled' && Number(r.paid ?? 0) > 0 && (
                                            <p className="num text-[11px] text-muted">مدفوع {Number(r.paid).toFixed(3)} · متبقٍّ {Number(r.remaining ?? 0).toFixed(3)}</p>
                                        )}
                                    </td>
                                    <td>
                                        <Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>
                                        {r.status === 'pending' && <p className="mt-0.5 text-[11px] text-muted">حتى <Ltr>{r.expires_at}</Ltr></p>}
                                        {(r.status === 'cancelled' || r.status === 'replaced') && r.ended_at && <p className="mt-0.5 text-[11px] text-muted"><Ltr>{r.ended_at}</Ltr></p>}
                                        {r.status === 'processing' && <p className="mt-0.5 text-[11px] text-muted">دفعة تنتظر نتيجة البنك.</p>}
                                        {r.status === 'review' && <p className="mt-0.5 text-[11px] text-muted">دفعة من Paymob لم تطابق: راجعها من الفاتورة.</p>}
                                        {r.payment && r.status !== 'cancelled' && (
                                            <p className="mt-0.5 text-[11px] text-muted">
                                                دُفع <Ltr>{Number(r.payment.amount).toFixed(3)}</Ltr>{r.payment.at && <> · <Ltr>{r.payment.at}</Ltr></>}{r.payment.transaction && <> · عملية <Ltr>{r.payment.transaction}</Ltr></>}
                                            </p>
                                        )}
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap gap-1.5">
                                            {r.status === 'pending' && r.url && <Button variant="outline" size="sm" onClick={() => copy(r.url!)} icon={<Copy />}>نسخ</Button>}
                                            {r.status === 'pending' && r.whatsapp && <Button variant="outline" size="sm" href={r.whatsapp} external icon={<MessageCircle />}>واتساب</Button>}
                                            {data.can_open_invoice && <Button variant="ghost" size="sm" href={route('club.invoices.show', r.invoice_id)} icon={<ExternalLink />}>الفاتورة</Button>}
                                            {/* A part paid: not cancelled (owner's decision). */}
                                            {r.status === 'pending' && r.url && data.can_cancel && Number(r.paid ?? 0) === 0 && <CancelLinkButton row={r} />}
                                            {!(r.status === 'pending' && r.url) && !data.can_open_invoice && <span className="text-muted">—</span>}
                                        </div>
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

/**
 * Ending a link (cancelling it, or a new one in its place) after its Paymob pages brought nothing: the references to look
 * up in Paymob's dashboard first, and the staff's word that they did (kept in the audit log). Its time running out is
 * not taken as proof that Paymob took nothing.
 */
function PaymobCheck({ pages, checked, onChange }: { pages: string[]; checked: boolean; onChange: (v: boolean) => void }) {
    if (pages.length === 0) return null;
    return (
        <div className="mt-3 rounded-[12px] bg-[#fffbeb] p-3 text-[12px] leading-relaxed text-[#92400e]">
            <p>فتح العميل صفحة دفع Paymob من هذا الرابط ولم تصل منها دفعة مسجّلة. ابحث عن المرجع في لوحة Paymob وتأكد أنه لا توجد عملية ناجحة به:</p>
            <ul className="mt-1.5 space-y-0.5">
                {pages.map((p) => <li key={p} dir="ltr" className="num break-all text-left">{p}</li>)}
            </ul>
            <label className="mt-2 flex cursor-pointer items-start gap-2 text-[#4b4b4b]">
                <input type="checkbox" className="mt-0.5 size-4 shrink-0" checked={checked} onChange={(e) => onChange(e.target.checked)} />
                <span>راجعتُ المراجع في لوحة Paymob، ولا توجد عملية ناجحة بها.</span>
            </label>
        </div>
    );
}

/** «إلغاء الرابط»: the link only (never its invoice), confirmed, after the Paymob check when its pages brought nothing. */
export function CancelLinkButton({ row, size = 'sm' }: { row: PaymentLinkRow; size?: 'sm' | 'md' }) {
    const [open, setOpen] = useState(false);
    const [checked, setChecked] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const submit = () => {
        setBusy(true);
        router.post(route('club.payment-links.cancel', row.id), { paymob_checked: checked ? 1 : 0 }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onSuccess: () => setOpen(false),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Button type="button" variant="ghost" size={size} onClick={() => { setError(undefined); setChecked(false); setOpen(true); }}>إلغاء الرابط</Button>
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="إلغاء رابط الدفع"
                size="confirm"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setOpen(false)}>تراجع</Button>
                        <Button variant="danger" loading={busy} disabled={row.unanswered.length > 0 && !checked} onClick={submit}>إلغاء الرابط</Button>
                    </>
                }
            >
                <p className="text-sm text-[#4b4b4b]">{CANCEL_MESSAGE}</p>
                <PaymobCheck pages={row.unanswered} checked={checked} onChange={setChecked} />
                {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
            </Modal>
        </>
    );
}

export interface InvoicePaymentLinkData {
    on: boolean;
    valid_days: number[];
    can_manage: boolean;
    /** A new link may be made now: something is owed and no live link still runs. */
    can_open: boolean;
    /** Why no link can be made now (the invoice, the permission, Paymob), shown instead of the button; null when it can. */
    reason?: string | null;
    live: PaymentLinkRow | null;
    history: PaymentLinkRow[];
}

function Amounts({ row }: { row: PaymentLinkRow }) {
    const cell = (label: string, value: string | null, strong = false) => (
        <div className="rounded-[10px] bg-[#f9fafb] px-2.5 py-2">
            <p className="text-[11px] text-[#9ca3af]">{label}</p>
            <p className={`num ${strong ? 'font-bold text-ink' : 'text-[#4b4b4b]'}`}>{Number(value ?? 0).toFixed(3)}</p>
        </div>
    );
    return (
        <div className="grid grid-cols-3 gap-2 text-[13px]">
            {cell('المطلوب', row.amount)}
            {cell('المدفوع', row.paid)}
            {cell('المتبقي', row.remaining, true)}
        </div>
    );
}

/**
 * The invoice's «رابط الدفع»: its live link (state, what is owed, paid and left, copy, WhatsApp on the staff's phone —
 * nothing is sent by the system —, cancel), a new link while allowed (one in place of a link whose time is over), and
 * every link it had.
 */
export function InvoicePaymentLink({ invoiceId, data }: { invoiceId: number; data: InvoicePaymentLinkData }) {
    const live = data.live;
    const replacing = live !== null && live.status === 'expired';
    const pages = replacing ? live.unanswered : [];
    const [days, setDays] = useState(String(data.valid_days.includes(7) ? 7 : data.valid_days[0]));
    const [checked, setChecked] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const key = useRef(clientKey());
    const mayCreate = data.can_manage && data.can_open && data.on;

    // Never hidden without a word: with no link and none to make, the panel says why (draft, paid, permission, Paymob).
    const why = !mayCreate && (!live || live.status === 'expired') ? (data.reason ?? null) : null;

    const create = () => {
        setBusy(true);
        setError(undefined);
        router.post(route('club.payment-links.open', invoiceId), { valid_days: days, paymob_checked: checked ? 1 : 0, client_key: key.current }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onSuccess: () => {
                key.current = clientKey();
                setChecked(false);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card className="p-4">
            <h2 className="mb-1 flex items-center gap-2 font-bold text-ink"><Link2 className="size-4" />رابط الدفع</h2>
            <p className="mb-3 text-[12px] text-[#9ca3af]">رابط آمن لهذه الفاتورة يطلب ما تبقى منها عند فتحه. تُسجَّل الدفعة فقط بإشعار موقّع ومطابق من Paymob.</p>
            {why && <Notice tone="amber" className="mb-3">{why}</Notice>}
            {!why && !data.on && live && <Notice tone="amber" className="mb-3">الدفع الإلكتروني غير مفعّل، فلا يفتح الرابط صفحة دفع. فعّل Paymob من التطبيقات التكاملية ← Paymob.</Notice>}

            {live && (
                <div className="space-y-3 text-[13px]">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={STATUS[live.status].tone}>{STATUS[live.status].label}</Badge>
                        {live.status === 'pending' && <span className="text-[12px] text-[#6b7280]">صالح حتى <Ltr>{live.expires_at}</Ltr></span>}
                        {live.status === 'expired' && <span className="text-[12px] text-[#6b7280]">انتهى في <Ltr>{live.expires_at}</Ltr></span>}
                    </div>
                    <Amounts row={live} />
                    {live.status === 'processing' && <p className="text-[12px] text-[#1d4ed8]">دفعة تنتظر نتيجة البنك النهائية. لا يُلغى الرابط ولا يُستبدل حتى تصل.</p>}
                    {live.status === 'review' && <p className="text-[12px] text-[#b91c1c]">دفعة من Paymob لم تطابق الفاتورة وهي قيد المراجعة أدناه. لا يُلغى الرابط حتى تُغلق بدليل.</p>}
                    {live.payment && (
                        <p className="rounded-[10px] bg-[#f0fdf4] px-3 py-2 text-[12px] text-[#166534]">
                            دُفع <Ltr>{Number(live.payment.amount).toFixed(3)}</Ltr>{live.payment.at && <> · <Ltr>{live.payment.at}</Ltr></>}{live.payment.receipt && <> · إيصال <Ltr>{live.payment.receipt}</Ltr></>}{live.payment.transaction && <> · عملية Paymob <Ltr>{live.payment.transaction}</Ltr></>}
                        </p>
                    )}
                    {live.url && (
                        <div className="flex gap-2">
                            <Input readOnly dir="ltr" value={live.url} className="min-w-0 text-xs" onFocus={(e) => e.currentTarget.select()} aria-label="رابط الدفع" />
                            <Button type="button" variant="outline" onClick={() => copy(live.url!)} icon={<Copy />} aria-label="نسخ الرابط" />
                        </div>
                    )}
                    {(live.url || (data.can_manage && live.status === 'pending')) && (
                        <div className="flex flex-wrap gap-2">
                            {live.url && <Button variant="outline" size="sm" onClick={() => copy(live.url!)} icon={<Copy />}>نسخ</Button>}
                            {live.whatsapp && <Button variant="outline" size="sm" href={live.whatsapp} external icon={<MessageCircle />}>مشاركة بواتساب</Button>}
                            {data.can_manage && live.status === 'pending' && Number(live.paid ?? 0) === 0 && <CancelLinkButton row={live} />}
                        </div>
                    )}
                </div>
            )}

            {mayCreate && (
                <div className={live ? 'mt-3 rounded-[12px] border border-line p-3' : ''}>
                    {replacing && <p className="mb-2 text-[12px] text-[#6b7280]">انتهت صلاحية الرابط الحالي. الرابط الجديد يحل محله، ولا يعود القديم يفتح صفحة دفع.</p>}
                    <div className="flex flex-wrap items-end gap-2">
                        <Field label="صالح لمدة" className="min-w-[140px] flex-1 sm:flex-none">
                            <Select value={days} onChange={(e) => setDays(e.target.value)}>
                                {data.valid_days.map((d) => <option key={d} value={String(d)}>{DAYS[d] ?? `${d} يومًا`}</option>)}
                            </Select>
                        </Field>
                        <Button loading={busy} disabled={pages.length > 0 && !checked} icon={<Link2 />} onClick={create}>{replacing ? 'إنشاء رابط جديد' : 'إنشاء رابط دفع'}</Button>
                    </div>
                    <PaymobCheck pages={pages} checked={checked} onChange={setChecked} />
                    {error && <p className="mt-2 text-[12px] text-red-600">{error}</p>}
                </div>
            )}

            {data.history.length > (live ? 1 : 0) && (
                <div className="mt-4">
                    <p className="mb-1 text-[12px] font-medium text-[#6b7280]">سجل روابط الفاتورة</p>
                    {data.history.map((r) => (
                        <div key={r.key} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-line py-2 text-[12px]">
                            <span className="text-[#6b7280]"><Ltr>{r.at}</Ltr>{r.by ? ` · ${r.by}` : ''}</span>
                            <span className="text-[#9ca3af]">{r.ended_at ? <>انتهى <Ltr>{r.ended_at}</Ltr></> : <>حتى <Ltr>{r.expires_at}</Ltr></>}</span>
                            <Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}
