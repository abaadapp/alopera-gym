import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { Ban, Banknote, CalendarDays, CheckCircle2, Clock, Copy, CreditCard, Gift, MessageCircle, Receipt, RefreshCw, Smartphone, UserPlus, UserRound, Wallet, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { DocumentMeta } from '@/Components/Papers/PaperPanel';
import { clientKey } from '@/Components/Inventory/parts';
import { BackLink, Badge, Button, Card, ChoiceCard, ConfirmAction, Field, Input, MemberPicker, Modal, Notice, PageHeader, type MemberHit } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, isoDate, money, phone, time } from '@/lib/format';
import { GIFT_EVENTS, GIFT_PAYMENT, giftCancelReason, giftForAnotherNumber, giftSource, redeemErrorStep, redeemGeneralErrors, type GiftRow, type RedeemStep } from '@/lib/gifts';
import { invoiceStatus, label, membershipStatus } from '@/lib/labels';
import { GiftStatusBadge } from './Index';

interface GiftDetail extends GiftRow {
    created_by: string | null;
    redeemed_by: string | null;
    plan_snapshot: { type?: string; sessions_limit?: number | null; freeze_allowed?: boolean; max_freeze_days?: number; max_freezes?: number };
}

interface GiftEvent {
    id: number;
    event: string;
    by: string | null;
    at: string | null;
}

interface Props {
    gift: GiftDetail;
    link: string | null;
    whatsapp: string | null;
    pay_link: string | null;
    pending_transfer: boolean;
    events: GiftEvent[];
    methods: string[];
    redeem: { min: string; max: string; can_search: boolean };
}

/** A moment as the server sent it (ISO, the club's time): the day and the hour. */
function when(value: string | null | undefined): string {
    return value ? `${date(value)} ${time(value)}` : '—';
}

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('تم نسخ الرابط');
    } catch {
        toast.error('تعذّر النسخ');
    }
}

/*
 * One gift (#192): its card as the recipient sees it, its terms and invoice, the link to share once it is paid, its
 * collection while it waits, «تفعيل الهدية» for a member, the cancellation and its history.
 */
export default function GiftShow({ gift, link, whatsapp, pay_link, pending_transfer, events, methods, redeem }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const [payOpen, setPayOpen] = useState(false);
    const [redeemOpen, setRedeemOpen] = useState(false);
    const pending = gift.status === 'pending';
    // As the pay route asks (gifts.create and payments.record); `methods` already carries the second.
    const mayCollect = pending && methods.length > 0 && !pending_transfer && can('gifts.create');
    const mayRedeem = gift.status === 'paid' && can('gifts.redeem');
    const mayCancel = (gift.status === 'pending' || gift.status === 'paid') && can('gifts.manage');
    // «رابط جديد» while the link can still be used, as the link route allows (gifts.manage).
    const mayRotate = mayCancel;
    const [invoiceLabel, invoiceTone] = label(invoiceStatus, gift.invoice?.status);
    const reason = giftCancelReason(gift.cancel_reason);

    return (
        <ClubLayout title={`هدية ${gift.number}`}>
            <PageHeader
                back={<BackLink href={route('club.gifts.index')}>هدايا الاشتراكات</BackLink>}
                title={<>هدية <span className="num">{gift.number}</span></>}
                subtitle={<GiftStatusBadge gift={gift} inline />}
                actions={
                    <>
                        {mayRedeem && <Button icon={<CheckCircle2 />} onClick={() => setRedeemOpen(true)}>تفعيل الهدية</Button>}
                        {mayCollect && <Button icon={<Wallet />} onClick={() => setPayOpen(true)}>تحصيل المبلغ</Button>}
                        {mayRotate && (
                            <ConfirmAction
                                url={route('club.gifts.link', gift.id)}
                                title="إصدار رابط جديد"
                                message="يتوقف الرابط الحالي فورًا ويُصدر رابط جديد. استخدمه إذا وصل الرابط إلى غير المستلمة."
                                confirmLabel="إصدار رابط جديد"
                                tone="primary"
                                size="md"
                                icon={<RefreshCw />}
                            >
                                رابط جديد
                            </ConfirmAction>
                        )}
                        {mayCancel && (
                            <ConfirmAction
                                url={route('club.gifts.cancel', gift.id)}
                                title="إلغاء الهدية"
                                message="الإلغاء لا يحذف شيئًا. إن كانت مدفوعة يبقى المبلغ على فاتورتها لتردّه المالية."
                                reason="سبب الإلغاء"
                                confirmLabel="إلغاء الهدية"
                                size="md"
                                icon={<Ban />}
                            >
                                إلغاء الهدية
                            </ConfirmAction>
                        )}
                    </>
                }
            />

            {pending && (
                <Notice tone="amber" icon={<Clock />} className="mb-6">
                    الهدية بانتظار الدفع، ولا يمكن استخدامها قبل سداد فاتورتها كاملة.
                    {pending_transfer && <> على الفاتورة تحويل بنكي ينتظر تأكيد المالية.</>}
                </Notice>
            )}

            {gift.status === 'redeemed' && (
                <Notice tone="green" icon={<CheckCircle2 />} className="mb-6">
                    <p className="font-semibold">تم استخدامها</p>
                    <p>
                        {gift.member ? (
                            <>
                                للعضو{' '}
                                {can('members.view')
                                    ? <Link href={route('club.members.show', gift.member.id)} className="font-medium underline-offset-2 hover:underline">{gift.member.full_name}</Link>
                                    : <span className="font-medium">{gift.member.full_name}</span>}
                                {' '}(<span className="num">{gift.member.member_no}</span>)
                            </>
                        ) : 'لعضو'}
                        {' '}في <span className="num">{when(gift.redeemed_at)}</span>
                        {' · '}{gift.redeemed_via === 'link' ? 'المستلمة من رابط الهدية' : gift.redeemed_by ? `بواسطة ${gift.redeemed_by}` : 'من اللوحة'}
                    </p>
                </Notice>
            )}

            {gift.status === 'cancelled' && (
                <Notice tone="red" icon={<Ban />} className="mb-6">
                    أُلغيت الهدية في <span className="num">{when(gift.cancelled_at)}</span>{reason ? `: ${reason}` : '.'}
                    {gift.invoice && Number(gift.invoice.paid_total) > 0 && <> المبلغ المدفوع باقٍ على فاتورتها لتردّه المالية بإشعار دائن.</>}
                </Notice>
            )}

            {gift.status === 'refunded' && (
                <Notice tone={gift.refund?.state === 'due' || gift.refund?.state === 'pending' ? 'amber' : 'gray'} className="mb-6">
                    <p className="font-semibold">
                        أُلغيت الهدية بالإشعار الدائن{gift.refund?.credit_note ? <> <span className="num">{gift.refund.credit_note}</span></> : null} في <span className="num">{when(gift.refunded_at)}</span>، فلا يمكن استخدامها.{reason ? ` ${reason}` : ''}
                    </p>
                    {gift.refund?.state === 'due' && (
                        <p>
                            الإشعار الدائن لا يعني أن المبلغ رُدّ: لم يُسجَّل ردّ <span className="font-semibold num">{money(gift.refund.due, currency)}</span> للمشتري بعد.
                            {gift.invoice && can('invoices.view') && <> سجّل الرد من <Link href={route('club.invoices.show', gift.invoice.id)} className="font-medium underline-offset-2 hover:underline">فاتورة الهدية</Link>.</>}
                        </p>
                    )}
                    {gift.refund?.state === 'pending' && (
                        <p>ردّ <span className="font-semibold num">{money(gift.refund.pending, currency)}</span> بانتظار تأكيد المالية؛ لا يُعدّ مردودًا حتى يُؤكَّد.</p>
                    )}
                    {gift.refund?.state === 'done' && <p>رُدّ للمشتري <span className="font-semibold num">{money(gift.refund.refunded, currency)}</span>.</p>}
                </Notice>
            )}

            {gift.status === 'expired' && (
                <Notice tone="gray" icon={<CalendarDays />} className="mb-6">
                    انتهت مهلة استرداد الهدية دون استخدام؛ كانت صالحة حتى <span className="num">{date(gift.expires_on)}</span>.{reason ? ` ${reason}` : ''}
                </Notice>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:order-2 xl:col-span-2">
                    <GiftCardPreview gift={gift} />

                    {link && (
                        <Card className="p-4">
                            <h2 className="mb-3 font-bold text-ink">مشاركة الهدية</h2>
                            <div className="flex gap-2">
                                <Input readOnly dir="ltr" value={link} className="text-xs" onFocus={(e) => e.currentTarget.select()} />
                                <Button type="button" variant="outline" onClick={() => copy(link)} icon={<Copy />} aria-label="نسخ رابط الهدية" />
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" onClick={() => copy(link)} icon={<Copy />}>نسخ رابط الهدية</Button>
                                {whatsapp && <Button size="sm" href={whatsapp} external icon={<MessageCircle />}>مشاركة عبر واتساب</Button>}
                            </div>
                            <p className="mt-2 text-[12px] text-[#9ca3af]">الرابط يعرض بطاقة الهدية فقط، دون السعر أو أرقام الهواتف.</p>
                        </Card>
                    )}

                    {pending && pay_link && (
                        <Card className="p-4">
                            <h2 className="mb-3 font-bold text-ink">رابط الدفع الإلكتروني للمُهدي</h2>
                            <div className="flex gap-2">
                                <Input readOnly dir="ltr" value={pay_link} className="text-xs" onFocus={(e) => e.currentTarget.select()} />
                                <Button type="button" variant="outline" onClick={() => copy(pay_link)} icon={<Copy />} aria-label="نسخ رابط الدفع" />
                            </div>
                            <p className="mt-2 text-[12px] text-[#9ca3af]">تصبح الهدية صالحة تلقائيًا عند اكتمال الدفع.</p>
                        </Card>
                    )}
                </div>

                <div className="min-w-0 space-y-4 xl:order-1 xl:col-span-3">
                    <DocumentMeta cells={[
                        { label: 'السعر', value: <span className="num">{money(gift.price, currency)}</span> },
                        {
                            label: 'الفاتورة',
                            value: gift.invoice ? (
                                <span className="flex flex-wrap items-center gap-1.5">
                                    {can('invoices.view')
                                        ? <Link href={route('club.invoices.show', gift.invoice.id)} className="font-medium text-[#1d4ed8] hover:underline"><span className="num">{gift.invoice.number}</span></Link>
                                        : <span className="num">{gift.invoice.number}</span>}
                                    <Badge tone={invoiceTone}>{invoiceLabel}</Badge>
                                </span>
                            ) : '—',
                        },
                        pending && gift.invoice && Number(gift.invoice.balance) > 0 ? { label: 'المتبقي', value: <span className="num">{money(gift.invoice.balance, currency)}</span> } : null,
                        gift.plan_snapshot?.sessions_limit ? { label: 'الحصص', value: <><span className="num">{gift.plan_snapshot.sessions_limit}</span> حصة</> } : null,
                        { label: 'المصدر', value: giftSource(gift.source) },
                        gift.created_by ? { label: 'أصدرها', value: gift.created_by } : null,
                        { label: 'تاريخ الإصدار', value: <span className="num">{when(gift.created_at)}</span> },
                        gift.paid_at ? { label: 'اكتمل الدفع', value: <span className="num">{when(gift.paid_at)}</span> } : null,
                        gift.expires_on ? { label: 'صالحة للاستلام حتى', value: <span className="num">{date(gift.expires_on)}</span> } : null,
                        gift.giver_phone ? { label: 'هاتف المُهدي', value: gift.giver_phone, ltr: true } : null,
                        gift.giver_email ? { label: 'بريد المُهدي', value: gift.giver_email, ltr: true } : null,
                        gift.recipient_phone ? { label: 'جوال المستلمة', value: phone(gift.recipient_phone), ltr: true } : null,
                    ]} />

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-ink">سجل الهدية</h2>
                        {events.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">لا أحداث مسجلة بعد.</p>
                        ) : (
                            <ol className="space-y-3">
                                {events.map((e) => (
                                    <li key={e.id} className="flex gap-3 text-[13px]">
                                        <span className="mt-2 size-2 shrink-0 rounded-full bg-[#d1d5db]" />
                                        <span className="min-w-0">
                                            <span className="block font-medium text-ink">{GIFT_EVENTS[e.event] ?? e.event}</span>
                                            <span className="block text-[12px] text-[#9ca3af]">{e.by ?? 'النظام'} · <span className="num">{when(e.at)}</span></span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>
                </div>
            </div>

            {mayCollect && <PayGiftModal open={payOpen} onClose={() => setPayOpen(false)} gift={gift} methods={methods} />}
            {mayRedeem && <RedeemGiftModal key={redeemOpen ? 'open' : 'closed'} open={redeemOpen} onClose={() => setRedeemOpen(false)} gift={gift} redeem={redeem} />}
        </ClubLayout>
    );
}

/** The gift's card as its link shows it: names, plan, duration, message and the last day — never the price. */
function GiftCardPreview({ gift }: { gift: GiftDetail }) {
    return (
        <div className="overflow-hidden rounded-2xl bg-[#1A1612] p-5 text-white">
            <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2 text-[12px] font-semibold text-[#C9A042]"><Gift className="size-4" /> هدية اشتراك</span>
                <span dir="ltr" className="num text-[11px] text-white/45">{gift.number}</span>
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-3">
                <div className="min-w-0">
                    <dt className="text-[11px] text-white/50">إلى</dt>
                    <dd className="truncate text-[16px] font-bold">{gift.recipient_name}</dd>
                </div>
                <div className="min-w-0">
                    <dt className="text-[11px] text-white/50">من</dt>
                    <dd className="truncate text-[14px] font-medium text-white/90">{gift.giver_name}</dd>
                </div>
            </dl>
            <div className="mt-4 rounded-xl border border-[#C9A042]/40 bg-white/[0.03] px-3 py-2.5">
                <p className="text-[14px] font-semibold text-[#C9A042]">{gift.plan.name}</p>
                {gift.plan.duration_label && <p className="text-[12px] text-white/70">{gift.plan.duration_label}</p>}
            </div>
            {gift.message && <p className="mt-3 whitespace-pre-line break-words text-[13px] leading-6 text-white/85">«{gift.message}»</p>}
            {gift.expires_on && (
                <p className="mt-4 border-t border-white/10 pt-3 text-[12px] text-white/60">
                    صالحة للاستلام حتى <span className="num text-white">{date(gift.expires_on)}</span>
                </p>
            )}
        </div>
    );
}

const METHOD_ICONS: Record<string, ReactNode> = {
    cash: <Banknote className="size-4" />,
    card: <CreditCard className="size-4" />,
    bank_transfer: <Wallet className="size-4" />,
    other: <Receipt className="size-4" />,
};

/** «تحصيل المبلغ»: the invoice's balance in full with a desk method (PaymentService on the server). */
function PayGiftModal({ open, onClose, gift, methods }: { open: boolean; onClose: () => void; gift: GiftDetail; methods: string[] }) {
    const currency = useCurrency();
    const form = useForm({ method: methods.includes('cash') ? 'cash' : methods[0], reference: '', client_key: '' });
    const err = form.errors as Record<string, string | undefined>;

    // A new key each time it opens: the same form sent twice records one payment (SubmittedOnce).
    useEffect(() => {
        if (open) form.setData((d) => ({ ...d, client_key: clientKey() }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.gifts.pay', gift.id), {
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
            title="تحصيل مبلغ الهدية"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>إلغاء</Button>
                    <Button type="submit" form="pay-gift" loading={form.processing}>تسجيل</Button>
                </>
            }
        >
            <form id="pay-gift" onSubmit={submit} className="space-y-4">
                {err.error && <Notice tone="red">{err.error}</Notice>}
                <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm">
                    المبلغ المستحق: <span className="font-semibold num">{money(gift.invoice?.balance ?? gift.price, currency)}</span>
                </div>
                <div className="grid grid-cols-2 gap-2">
                    {methods.map((m) => (
                        <ChoiceCard key={m} selected={form.data.method === m} onClick={() => form.setData('method', m)} className="flex items-center gap-2 py-2.5 text-sm">
                            {METHOD_ICONS[m]} {GIFT_PAYMENT[m]?.label ?? m}
                        </ChoiceCard>
                    ))}
                </div>
                {err.method && <p className="text-xs text-red-600">{err.method}</p>}
                {form.data.method === 'bank_transfer' && <div className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">{GIFT_PAYMENT.bank_transfer.note}.</div>}
                <Field
                    label={form.data.method === 'card' ? 'رقم العملية من جهاز الشبكة' : form.data.method === 'bank_transfer' ? 'رقم مرجع التحويل' : 'مرجع (اختياري)'}
                    error={err.reference}
                    required={form.data.method === 'card'}
                    hint={form.data.method === 'card' ? 'يربط الدفعة بإيصال جهاز البنك' : undefined}
                >
                    <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} required={form.data.method === 'card'} maxLength={120} />
                </Field>
            </form>
        </Modal>
    );
}

/** The day after a date (YYYY-MM-DD), on the device's calendar. */
function dayAfter(day: string): string {
    const d = new Date(`${day}T00:00:00`);
    d.setDate(d.getDate() + 1);
    return isoDate(d);
}

/**
 * «تفعيل الهدية» in three steps: who (a member found by search, or a new one), the first day (from today up to 90 days,
 * not over another period), then the summary — the plan as bought, at no further charge. A server error on a field of
 * an earlier step brings that step back.
 */
function RedeemGiftModal({ open, onClose, gift, redeem }: { open: boolean; onClose: () => void; gift: GiftDetail; redeem: Props['redeem'] }) {
    const [step, setStep] = useState<RedeemStep>('who');
    const [member, setMember] = useState<MemberHit | null>(null);
    const form = useForm<{ mode: 'existing' | 'new'; member_id: string; full_name: string; phone: string; email: string; starts_on: string }>({
        mode: redeem.can_search ? 'existing' : 'new',
        member_id: '',
        full_name: '',
        phone: '',
        email: '',
        starts_on: redeem.min,
    });
    const err = form.errors as Record<string, string | undefined>;
    const general = redeemGeneralErrors(err);
    const d = form.data;
    const whoReady = d.mode === 'existing' ? d.member_id !== '' : d.full_name.trim() !== '' && d.phone.trim() !== '';
    const dateReady = d.starts_on !== '' && d.starts_on >= redeem.min && d.starts_on <= redeem.max;

    // A member with a period running or waiting: the day after it, when it falls in the window.
    const after = member?.membership?.ends_on ? dayAfter(member.membership.ends_on) : null;
    const suggest = after && after > redeem.min && after <= redeem.max ? after : null;

    const pick = (hit: MemberHit) => {
        setMember(hit);
        form.setData((x) => ({ ...x, member_id: String(hit.id) }));
        form.clearErrors('member_id');
    };

    const submit = () => {
        form.transform((x) => (x.mode === 'existing' ? { ...x, full_name: '', phone: '', email: '' } : { ...x, member_id: '' }));
        form.post(route('club.gifts.redeem', gift.id), {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errors) => {
                const back = redeemErrorStep(errors);
                if (back) setStep(back);
            },
        });
    };

    const footer = step === 'who' ? (
        <>
            <Button variant="outline" onClick={onClose}>إلغاء</Button>
            <Button onClick={() => setStep('date')} disabled={!whoReady}>التالي</Button>
        </>
    ) : step === 'date' ? (
        <>
            <Button variant="outline" onClick={() => setStep('who')}>رجوع</Button>
            <Button onClick={() => setStep('summary')} disabled={!dateReady}>التالي</Button>
        </>
    ) : (
        <>
            <Button variant="outline" onClick={() => setStep('date')} disabled={form.processing}>رجوع</Button>
            <Button icon={<CheckCircle2 />} onClick={submit} loading={form.processing}>تفعيل الهدية</Button>
        </>
    );

    const steps: { key: RedeemStep; label: string }[] = [
        { key: 'who', label: 'العضو' },
        { key: 'date', label: 'تاريخ البدء' },
        { key: 'summary', label: 'التأكيد' },
    ];

    return (
        <Modal open={open} onClose={onClose} title="تفعيل الهدية" description={<>هدية <span className="num">{gift.number}</span> إلى {gift.recipient_name}</>} dismissible={!form.processing} footer={footer}>
            <ol className="mb-4 flex items-center gap-2 text-[12px]">
                {steps.map((s, i) => (
                    <li key={s.key} className={`flex items-center gap-1.5 ${s.key === step ? 'font-semibold text-ink' : 'text-[#9ca3af]'}`}>
                        <span className={`grid size-5 place-items-center rounded-full text-[11px] ${s.key === step ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#6b7280]'}`}><span className="num">{i + 1}</span></span>
                        {s.label}
                        {i < steps.length - 1 && <span className="mx-1 h-px w-4 bg-line" />}
                    </li>
                ))}
            </ol>

            {general.length > 0 && <Notice tone="red" className="mb-4">{general.map((m, i) => <p key={i}>{m}</p>)}</Notice>}

            {/* Tied to another number: staff see who is in front of them, so a warning, not a refusal. */}
            {d.mode === 'existing' && member && giftForAnotherNumber(gift.recipient_phone, member.phone) && (
                <Notice tone="amber" icon={<Smartphone />} className="mb-4">
                    هذه الهدية مخصصة لرقم جوال آخر (<span dir="ltr" className="num [unicode-bidi:isolate]">{phone(gift.recipient_phone)}</span>). تأكّد من هوية المستلمة قبل التفعيل.
                </Notice>
            )}

            {step === 'who' && (
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-2">
                        {redeem.can_search && (
                            <ChoiceCard selected={d.mode === 'existing'} onClick={() => form.setData('mode', 'existing')} className="flex items-center justify-center gap-2 py-2.5 text-sm">
                                <UserRound className="size-4" /> عضو موجود
                            </ChoiceCard>
                        )}
                        <ChoiceCard selected={d.mode === 'new'} onClick={() => form.setData('mode', 'new')} className="flex items-center justify-center gap-2 py-2.5 text-sm">
                            <UserPlus className="size-4" /> عضو جديد
                        </ChoiceCard>
                    </div>

                    {d.mode === 'existing' ? (
                        <Field label="العضو" error={err.member_id} required>
                            {member ? (
                                <div className="flex items-center justify-between gap-3 rounded-[10px] border border-line bg-[#fafafa] px-3 py-2">
                                    <span className="min-w-0">
                                        <span className="block truncate text-[13px] font-medium text-ink">{member.full_name}</span>
                                        <span className="block text-[11px] text-[#6b7280]"><span className="num">{member.member_no}</span> · <span className="num">{member.phone_display}</span></span>
                                    </span>
                                    {member.membership && <Badge tone={label(membershipStatus, member.membership.status)[1]}>{member.membership.plan}</Badge>}
                                    <button
                                        type="button"
                                        onClick={() => { setMember(null); form.setData('member_id', ''); }}
                                        className="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-[#4b4b4b] hover:text-ink"
                                    >
                                        <X className="size-3.5" /> تغيير
                                    </button>
                                </div>
                            ) : (
                                <MemberPicker url={route('club.gifts.members')} onSelect={pick} autoFocus placeholder="ابحث عن العضو بالاسم أو الهاتف أو الرقم" />
                            )}
                        </Field>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="الاسم الكامل" error={err.full_name} required className="sm:col-span-2">
                                <Input value={d.full_name} onChange={(e) => form.setData('full_name', e.target.value)} maxLength={160} />
                            </Field>
                            <Field label="الهاتف" error={err.phone} required>
                                <Input dir="ltr" type="tel" value={d.phone} onChange={(e) => form.setData('phone', e.target.value)} placeholder="9XXXXXXX" />
                            </Field>
                            <Field label="البريد الإلكتروني" error={err.email}>
                                <Input dir="ltr" type="email" value={d.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </Field>
                        </div>
                    )}
                </div>
            )}

            {step === 'date' && (
                <div className="space-y-3">
                    <Field label="تاريخ بدء الاشتراك" error={err.starts_on} required hint="من اليوم حتى 90 يومًا. لا تتداخل مع اشتراك قائم.">
                        <Input type="date" min={redeem.min} max={redeem.max} value={d.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                    </Field>
                    {d.mode === 'existing' && member?.membership && (
                        <p className="text-[12px] text-[#6b7280]">
                            لها {member.membership.plan} حتى <span className="num">{date(member.membership.ends_on)}</span>.
                            {suggest && d.starts_on !== suggest && (
                                <> <button type="button" className="font-medium text-[#1d4ed8] hover:underline" onClick={() => form.setData('starts_on', suggest)}>البدء بعد انتهائها (<span className="num">{date(suggest)}</span>)</button></>
                            )}
                        </p>
                    )}
                </div>
            )}

            {step === 'summary' && (
                <dl className="space-y-0 rounded-[12px] border border-line text-[13px]">
                    {[
                        ['الباقة', gift.plan.name],
                        ['المدة', gift.plan.duration_label ?? '—'],
                        ['العضو', d.mode === 'existing' ? (member ? `${member.full_name} · ${member.member_no}` : '—') : `${d.full_name} (عضو جديد)`],
                        ['تاريخ البدء', date(d.starts_on)],
                        ['المبلغ', 'دون أي مبلغ إضافي'],
                    ].map(([k, v]) => (
                        <div key={k} className="flex items-center justify-between gap-3 border-t border-line px-3 py-2.5 first:border-t-0">
                            <dt className="text-[#6b7280]">{k}</dt>
                            <dd className="min-w-0 truncate font-medium text-ink">{v}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </Modal>
    );
}
