import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { Gift, Plus } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Checkbox, ChoiceCard, DataTable, Field, Input, Modal, Notice, PageHeader, Textarea, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import { clientKey } from '@/Components/Inventory/parts';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, dayCount, money } from '@/lib/format';
import { GIFT_DELIVERY_TONE, GIFT_FILTERS, GIFT_LANGUAGES, GIFT_PAYMENT, defaultGiftPayment, giftPaymentChoices, type GiftRow } from '@/lib/gifts';
import { giftStatus, label } from '@/lib/labels';
import type { Paginated } from '@/types';

export interface GiftPlan {
    id: number;
    name: string;
    price: string;
    duration_label: string;
    is_public: boolean;
}

/** A gift's status in Arabic, with the English words under it (the owner reads both). */
export function GiftStatusBadge({ gift, inline = false }: { gift: Pick<GiftRow, 'status' | 'status_label' | 'status_label_en'> & { refund?: GiftRow['refund'] }; inline?: boolean }) {
    const [, tone] = label(giftStatus, gift.status);
    // A credit note withdraws the gift; the money is back only once finance records (and, where it waits, confirms) it.
    const open = gift.refund && (gift.refund.state === 'due' || gift.refund.state === 'pending') ? gift.refund : null;
    return (
        <span className={inline ? 'inline-flex flex-wrap items-center gap-x-2 gap-y-0.5' : 'inline-flex flex-col items-start gap-0.5'}>
            <Badge tone={tone}>{gift.status_label}</Badge>
            {open && <Badge tone="amber">{open.label}</Badge>}
            <span lang="en" dir="ltr" className="text-[11px] text-[#9ca3af]">{gift.status_label_en}</span>
        </span>
    );
}

/*
 * «هدايا الاشتراكات» (#192) on the panel's list table: the status tabs with their counts and the search (number, giver,
 * recipient) are in the address and read by the server. A gift is issued here for a plan open to gifting.
 */
export default function GiftsIndex({ gifts, counts, plans, methods, redeemDays, filters }: { gifts: Paginated<GiftRow>; counts: Record<string, number>; plans: GiftPlan[]; methods: string[]; redeemDays: number; filters: Record<string, string | undefined> }) {
    const can = useCan();
    const currency = useCurrency();
    const [issuing, setIssuing] = useState(false);
    const mayIssue = can('gifts.create') && plans.length > 0;

    const GiftNumber = ({ g }: { g: GiftRow }) => (
        <Link href={route('club.gifts.show', g.id)} className="font-medium text-[#111] hover:underline"><span className="num">{g.number}</span></Link>
    );
    const Plan = ({ g }: { g: GiftRow }) => (
        <span>
            {g.plan.name}
            {g.plan.duration_label && <span className="block text-xs text-muted">{g.plan.duration_label}</span>}
        </span>
    );

    const columns: Column<GiftRow>[] = [
        { key: 'number', header: 'رقم الهدية', cell: (g) => <GiftNumber g={g} /> },
        { key: 'recipient', header: 'المُهدى إليها', cell: (g) => <span className="font-medium">{g.recipient_name}</span> },
        { key: 'giver', header: 'المُهدي', cell: (g) => g.giver_name },
        { key: 'plan', header: 'الباقة', cell: (g) => <Plan g={g} /> },
        { key: 'status', header: 'الحالة', cell: (g) => <GiftStatusBadge gift={g} /> },
        // Where the gift's WhatsApp message is: «أُرسلت إلى المزود» is not «تم التسليم» (status webhooks decide it).
        { key: 'delivery', header: 'واتساب', cell: (g) => <Badge tone={GIFT_DELIVERY_TONE[g.delivery.state]}>{g.delivery.label}</Badge> },
        { key: 'expires_on', header: 'صالحة حتى', cell: (g) => <span className="whitespace-nowrap text-xs">{date(g.expires_on)}</span> },
        { key: 'price', header: 'السعر', align: 'end', cell: (g) => <span className="num">{money(g.price, currency)}</span> },
    ];

    const tableFilters: TableFilter[] = [
        { param: 'filter', label: 'الحالة', tabs: true, options: GIFT_FILTERS.map((f) => ({ ...f, label: counts[f.value] ? `${f.label} (${counts[f.value]})` : f.label })) },
        { param: 'per_page', label: 'عدد الصفوف', options: ['25', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="هدايا الاشتراكات">
            <PageHeader
                title="هدايا الاشتراكات"
                subtitle="باقات تُشترى هدية لشخص آخر: تصبح صالحة بعد اكتمال دفع فاتورتها، وتُفعَّل اشتراكًا مرة واحدة"
                actions={mayIssue ? <Button icon={<Plus className="size-4" />} onClick={() => setIssuing(true)}>إصدار هدية جديدة</Button> : undefined}
            />

            {can('gifts.create') && plans.length === 0 && (
                <Notice tone="gray" className="mb-4" href={can('plans.manage') ? route('club.plans.index') : undefined}>
                    لا توجد باقة نشطة متاحة للإهداء. فعّل «متاحة للإهداء» في الباقات والأسعار لإصدار هدية.
                </Notice>
            )}

            <DataTable
                rows={gifts.data}
                columns={columns}
                rowKey={(g) => g.id}
                params={filters}
                pagination={gifts as unknown as PageMeta}
                search={{ placeholder: 'رقم الهدية أو اسم المُهدي أو المُهدى إليها' }}
                filters={tableFilters}
                empty={<span className="inline-flex flex-col items-center gap-2"><Gift className="size-8 text-[#d1d5db]" />لا توجد هدايا بعد</span>}
                card={(g) => (
                    <div className="space-y-1.5 px-4 py-3 text-[13px]">
                        <div className="flex items-start justify-between gap-3">
                            <span className="min-w-0">
                                <GiftNumber g={g} />
                                <span className="block truncate text-muted">إلى {g.recipient_name} · من {g.giver_name}</span>
                            </span>
                            <GiftStatusBadge gift={g} />
                        </div>
                        <p className="text-muted">{g.plan.name}{g.plan.duration_label ? ` · ${g.plan.duration_label}` : ''}{g.expires_on ? <> · حتى {date(g.expires_on)}</> : null}</p>
                        <p><Badge tone={GIFT_DELIVERY_TONE[g.delivery.state]}>واتساب: {g.delivery.label}</Badge></p>
                        <p className="num text-end">{money(g.price, currency)}</p>
                    </div>
                )}
            />

            {mayIssue && <IssueGiftModal key={issuing ? 'open' : 'closed'} open={issuing} onClose={() => setIssuing(false)} plans={plans} methods={methods} redeemDays={redeemDays} />}
        </ClubLayout>
    );
}

function IssueGiftModal({ open, onClose, plans, methods, redeemDays }: { open: boolean; onClose: () => void; plans: GiftPlan[]; methods: string[]; redeemDays: number }) {
    const currency = useCurrency();
    const choices = giftPaymentChoices(methods);
    const form = useForm({
        membership_plan_id: plans.length === 1 ? String(plans[0].id) : '',
        giver_name: '',
        giver_phone: '',
        giver_email: '',
        recipient_name: '',
        recipient_phone: '',
        message: '',
        message_language: 'ar' as 'ar' | 'en',
        is_anonymous: false,
        payment_method: defaultGiftPayment(methods),
        payment_reference: '',
        client_key: '',
    });
    const err = form.errors as Record<string, string | undefined>;

    // A new key each time it opens: the same form sent twice issues one gift (SubmittedOnce).
    useEffect(() => {
        if (open) form.setData('client_key', clientKey());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.gifts.store'), { preserveScroll: true, onSuccess: onClose });
    };

    const card = form.data.payment_method === 'card';
    const note = GIFT_PAYMENT[form.data.payment_method]?.note;

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="إصدار هدية جديدة" size="large"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>إلغاء</Button>
                    <Button type="submit" form="issue-gift" loading={form.processing} disabled={!form.data.membership_plan_id}>إصدار الهدية</Button>
                </>
            }
        >
            <form id="issue-gift" onSubmit={submit} className="space-y-4">
                {err.error && <Notice tone="red">{err.error}</Notice>}
                <Field label="الباقة" error={err.membership_plan_id} required>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {plans.map((p) => (
                            <ChoiceCard key={p.id} selected={form.data.membership_plan_id === String(p.id)} onClick={() => form.setData('membership_plan_id', String(p.id))}>
                                <span className="flex items-start justify-between gap-2">
                                    <span className="block text-sm font-medium">{p.name}</span>
                                    {!p.is_public && <Badge tone="amber">غير ظاهرة بالموقع</Badge>}
                                </span>
                                <span className="block text-xs text-muted">{p.duration_label}</span>
                                <span className="mt-1 block text-sm font-semibold num">{money(p.price, currency)}</span>
                            </ChoiceCard>
                        ))}
                    </div>
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="اسم المُهدي" error={err.giver_name} required>
                        <Input value={form.data.giver_name} onChange={(e) => form.setData('giver_name', e.target.value)} maxLength={120} />
                        <Checkbox className="mt-2" label="إخفاء اسم المُهدي عن المستلمة" checked={form.data.is_anonymous} onChange={(e) => form.setData('is_anonymous', e.target.checked)} />
                    </Field>
                    <Field label="اسم المُهدى إليها" error={err.recipient_name} required>
                        <Input value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} maxLength={120} />
                    </Field>
                    <Field label="هاتف المُهدي" error={err.giver_phone}>
                        <Input dir="ltr" type="tel" value={form.data.giver_phone} onChange={(e) => form.setData('giver_phone', e.target.value)} placeholder="9XXXXXXX" />
                    </Field>
                    <Field label="رقم واتساب المستلمة" required error={err.recipient_phone} hint="تُرسل الهدية إلى واتساب هذا الرقم بعد اكتمال الدفع وبعد أن تؤكده المستلمة برسالة منه. لا يُستلم من الرابط إلا بحساب هذا الرقم.">
                        <Input dir="ltr" type="tel" value={form.data.recipient_phone} onChange={(e) => form.setData('recipient_phone', e.target.value)} placeholder="9XXXXXXX" maxLength={32} required />
                    </Field>
                    <Field label="بريد المُهدي" error={err.giver_email}>
                        <Input dir="ltr" type="email" value={form.data.giver_email} onChange={(e) => form.setData('giver_email', e.target.value)} />
                    </Field>
                    <Field label="لغة رسالة الإهداء" error={err.message_language} hint="لغة رسالة واتساب التي تصل المستلمة، كما يختارها المُهدي.">
                        <div className="grid grid-cols-2 gap-2">
                            {GIFT_LANGUAGES.map((l) => (
                                <ChoiceCard key={l.value} selected={form.data.message_language === l.value} onClick={() => form.setData('message_language', l.value)} className="py-2.5 text-center text-sm">
                                    <span lang={l.value}>{l.label}</span>
                                </ChoiceCard>
                            ))}
                        </div>
                    </Field>
                    <Field label="رسالة الهدية" error={err.message} className="sm:col-span-2" hint={<span className="num">{form.data.message.length}/300</span>}>
                        <Textarea rows={3} maxLength={300} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} placeholder="تظهر على بطاقة الهدية" />
                    </Field>
                </div>

                <Notice tone="gray">الهدية صالحة للاستلام {dayCount(redeemDays)} من الدفع، ولا تُردّ قيمتها بعد انتهائها أو استلامها، ولا يُردّ جزء منها.</Notice>

                <Field label="الدفع" error={err.payment_method} required>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {choices.map((m) => (
                            <ChoiceCard key={m} selected={form.data.payment_method === m} onClick={() => form.setData('payment_method', m)} className="py-2.5 text-center text-sm">
                                {GIFT_PAYMENT[m]?.label ?? m}
                            </ChoiceCard>
                        ))}
                    </div>
                </Field>
                {note && <p className={form.data.payment_method === 'later' ? 'rounded-xl bg-[#fafafa] px-3 py-2 text-xs text-[#4b4b4b]' : 'rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800'}>{note}</p>}
                {card && (
                    <Field label="رقم العملية من جهاز الشبكة" error={err.payment_reference} required hint="يربط الدفعة بإيصال جهاز البنك">
                        <Input dir="ltr" value={form.data.payment_reference} onChange={(e) => form.setData('payment_reference', e.target.value)} required maxLength={120} />
                    </Field>
                )}
            </form>
        </Modal>
    );
}
