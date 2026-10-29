import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
    CalendarPlus, CheckCircle2, CreditCard, FileText, LogIn, MessageCircle, QrCode, ScanLine, ShoppingBag, UserPlus, UserRound, Wallet, XCircle, CalendarCheck,
} from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, EmptyState, Field, Input, MemberPicker, Modal, PageHeader, Select, type MemberHit } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money, number, time, whatsappUrl, dayCount } from '@/lib/format';
import { checkinReason, label, membershipStatus } from '@/lib/labels';

interface SessionItem {
    id: number;
    program: string | null;
    color: string | null;
    trainer: string | null;
    time: string;
    end_time: string;
    capacity: number;
    booked: number;
    seats_left: number;
    is_full: boolean;
    starts_at: string;
    status: string;
}

interface PlanItem {
    id: number;
    name: string;
    type_label: string;
    duration_label: string;
    price: string;
    sessions_limit: number | null;
}

interface Props {
    todaySessions: SessionItem[];
    recentCheckins: { id: number; member: string | null; member_id: number | null; result: string; reason: string | null; at: string }[];
    expiringToday: { member_id: number; member: string | null; ends_on: string; plan: string }[];
    pendingPayments: { member_id: number; member: string | null; invoice_id: number | null; number: string | null; balance: string | null }[];
    plans: PlanItem[];
    stats: { checkins: number; denied: number; collected: number };
}

interface Decision {
    allowed: boolean;
    reason: string;
    message: string;
    member: { id: number; name: string; member_no: string; photo_url: string | null } | null;
    membership: { plan: string | null; ends_on: string; days_left: number; sessions_left: number | null } | null;
}

function xsrf(): string {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

async function checkIn(body: { member_id?: number; qr?: string }): Promise<Decision | { error: string }> {
    const res = await fetch(route('club.checkins.store'), {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    if (res.ok) return res.json();
    const data = await res.json().catch(() => ({}));
    return { error: (data && (data.message as string)) || 'تعذّر تسجيل الدخول' };
}

export default function Reception({ todaySessions, recentCheckins, expiringToday, pendingPayments, plans, stats }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const [selected, setSelected] = useState<MemberHit | null>(null);
    const [decision, setDecision] = useState<Decision | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [qr, setQr] = useState('');
    const [busy, setBusy] = useState(false);
    const [sellOpen, setSellOpen] = useState(false);
    const [bookOpen, setBookOpen] = useState(false);
    const [payFor, setPayFor] = useState<{ id: number; number: string; balance: string | null } | null>(null);
    const scanRef = useRef<HTMLInputElement>(null);

    // Keep the scanner field focused so USB/Bluetooth QR readers (keyboard wedge) work without clicking.
    useEffect(() => {
        scanRef.current?.focus();
    }, []);

    const run = async (body: { member_id?: number; qr?: string }) => {
        setBusy(true);
        setScanError(null);
        const result = await checkIn(body);
        setBusy(false);
        if ('error' in result) {
            setDecision(null);
            setScanError(result.error);
        } else {
            setDecision(result);
            router.reload({ only: ['recentCheckins', 'stats'] });
        }
        setQr('');
        scanRef.current?.focus();
    };

    const onScan = (e: FormEvent) => {
        e.preventDefault();
        if (qr.trim()) run({ qr: qr.trim() });
    };

    const ms = selected?.membership ?? null;
    const [msLabel, msTone] = ms ? label(membershipStatus, ms.status) : ['بدون عضوية سارية', 'gray' as const];
    const selectedPending = selected ? pendingPayments.find((p) => p.member_id === selected.id) : undefined;

    return (
        <ClubLayout title="الاستقبال">
            <PageHeader
                title="الاستقبال"
                subtitle="بحث، تسجيل دخول، بيع وتجديد، تحصيل، حجز، وتواصل — من شاشة واحدة"
                actions={
                    <>
                        {can('members.manage') && <Button href={route('club.members.create')} icon={<UserPlus className="size-4" />}>عضو جديد</Button>}
                        {can('pos.sell') && <Button variant="outline" href={route('club.pos.index')} icon={<ShoppingBag className="size-4" />}>نقطة البيع</Button>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-3 gap-3">
                <div className="card p-4"><p className="text-xs text-muted">دخول اليوم</p><p className="mt-1 text-xl font-semibold text-emerald-700"><span className="num">{number(stats.checkins)}</span></p></div>
                <div className="card p-4"><p className="text-xs text-muted">مرفوض اليوم</p><p className="mt-1 text-xl font-semibold text-red-600"><span className="num">{number(stats.denied)}</span></p></div>
                <div className="card p-4"><p className="text-xs text-muted">حصّلتُ اليوم</p><p className="mt-1 text-xl font-semibold"><span className="num">{money(stats.collected, currency)}</span></p></div>
            </div>

            <div className="grid gap-6 lg:grid-cols-5">
                {/* Search, scan and the selected member */}
                <div className="space-y-6 lg:col-span-3">
                    <Card>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="label">البحث عن عضو</p>
                                <MemberPicker onSelect={(m) => { setSelected(m); setDecision(null); setScanError(null); }} autoFocus={false} />
                            </div>
                            {can('checkin.perform') && (
                                <form onSubmit={onScan}>
                                    <p className="label">مسح رمز QR</p>
                                    <div className="relative">
                                        <QrCode className="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-muted" />
                                        <input ref={scanRef} className="input ps-9" value={qr} onChange={(e) => setQr(e.target.value)} placeholder="وجّه القارئ إلى الرمز…" dir="ltr" autoComplete="off" />
                                    </div>
                                </form>
                            )}
                        </div>
                    </Card>

                    {(decision || scanError) && (
                        <div className={`card flex items-center gap-4 border-2 p-5 ${decision?.allowed ? 'border-emerald-300 bg-emerald-50' : 'border-red-300 bg-red-50'}`} role="status" aria-live="polite">
                            {decision?.allowed ? <CheckCircle2 className="size-12 shrink-0 text-emerald-600" /> : <XCircle className="size-12 shrink-0 text-red-600" />}
                            {decision?.member?.photo_url && <img src={decision.member.photo_url} alt="" className="size-14 rounded-xl object-cover" />}
                            <div className="min-w-0 flex-1">
                                <p className={`text-lg font-semibold ${decision?.allowed ? 'text-emerald-800' : 'text-red-800'}`}>
                                    {decision ? (decision.allowed ? (decision.reason === 'duplicate_scan' ? 'دخول مسموح (مسجّل قبل قليل — لم يُحتسب مرة أخرى)' : 'دخول مسموح') : `مرفوض: ${decision.message}`) : scanError}
                                </p>
                                {decision?.member && (
                                    <p className="text-sm text-ink/80">
                                        {decision.member.name} · <span className="num">{decision.member.member_no}</span>
                                    </p>
                                )}
                                {decision?.membership && (
                                    <p className="text-xs text-muted">
                                        {decision.membership.plan} · حتى {date(decision.membership.ends_on)} (<span className="num">{dayCount(decision.membership.days_left)}</span>)
                                        {decision.membership.sessions_left !== null && <> · <span className="num">{decision.membership.sessions_left}</span> حصة</>}
                                    </p>
                                )}
                            </div>
                            {decision?.member && (
                                <Button size="sm" variant="outline" href={route('club.members.show', decision.member.id)}>الملف</Button>
                            )}
                        </div>
                    )}

                    {selected ? (
                        <Card>
                            <div className="flex flex-wrap items-center gap-4">
                                {selected.photo_url ? <img src={selected.photo_url} alt="" className="size-16 rounded-2xl object-cover" /> : <span className="grid size-16 place-items-center rounded-2xl bg-brand-50 text-brand"><UserRound className="size-7" /></span>}
                                <div className="min-w-0 flex-1">
                                    <p className="text-lg font-semibold">{selected.full_name}</p>
                                    <p className="text-sm text-muted"><span className="num">{selected.member_no}</span> · <span className="num">{selected.phone_display}</span></p>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                        <Badge tone={msTone}>{msLabel}</Badge>
                                        {ms && <span className="text-sm">{ms.plan} · حتى {date(ms.ends_on)} (<span className="num">{dayCount(ms.days_left)}</span>)</span>}
                                        {selected.status === 'blocked' && <Badge tone="red">الحساب موقوف</Badge>}
                                    </div>
                                </div>
                            </div>

                            <div className="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {can('checkin.perform') && (
                                    <Button variant="success" icon={<LogIn className="size-4" />} loading={busy} onClick={() => run({ member_id: selected.id })}>تسجيل دخول</Button>
                                )}
                                {can('memberships.sell') && (
                                    <Button icon={<CalendarPlus className="size-4" />} onClick={() => setSellOpen(true)}>{ms ? 'تجديد' : 'بيع عضوية'}</Button>
                                )}
                                {can('bookings.manage') && (
                                    <Button variant="secondary" icon={<CalendarCheck className="size-4" />} onClick={() => setBookOpen(true)}>حجز حصة</Button>
                                )}
                                {selectedPending?.invoice_id && can('payments.record') && (
                                    <Button variant="secondary" icon={<Wallet className="size-4" />} onClick={() => setPayFor({ id: selectedPending.invoice_id as number, number: selectedPending.number ?? '', balance: selectedPending.balance })}>
                                        تحصيل <span className="num">{money(selectedPending.balance, currency)}</span>
                                    </Button>
                                )}
                                <Button variant="outline" href={whatsappUrl(selected.phone)} external icon={<MessageCircle className="size-4" />}>واتساب</Button>
                                <Button variant="outline" href={route('club.members.show', selected.id)} icon={<FileText className="size-4" />}>الملف الكامل</Button>
                            </div>
                        </Card>
                    ) : (
                        <Card>
                            <EmptyState icon={<ScanLine className="size-6" />} title="ابحث عن عضو أو امسح رمزه" description="تظهر حالة العضوية فورًا مع الإجراءات المتاحة." />
                        </Card>
                    )}

                    <Card>
                        <CardHeader title="حصص اليوم" actions={can('classes.view') ? <Link href={route('club.sessions.index')} className="text-sm text-brand">الجدول</Link> : undefined} />
                        {todaySessions.length === 0 ? <p className="text-sm text-muted">لا حصص اليوم.</p> : (
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {todaySessions.map((s) => (
                                    <li key={s.id}>
                                        <Link href={route('club.sessions.show', s.id)} className="flex items-center gap-3 rounded-xl border border-line p-3 hover:border-brand-200">
                                            <span className="h-10 w-1 rounded-full" style={{ background: s.color ?? 'var(--brand)' }} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">{s.program}</span>
                                                <span className="text-xs text-muted">{time(s.time)} · {s.trainer ?? '—'}</span>
                                            </span>
                                            {s.status === 'cancelled' ? <Badge tone="red">ملغاة</Badge> : s.is_full ? <Badge tone="red">مكتملة</Badge> : <Badge tone="green">متاح <span className="num">{s.seats_left}</span></Badge>}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                {/* Side lists */}
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="آخر عمليات الدخول" actions={can('checkin.view') ? <Link href={route('club.checkins.index')} className="text-sm text-brand">السجل</Link> : undefined} />
                        {recentCheckins.length === 0 ? <p className="text-sm text-muted">لا شيء بعد.</p> : (
                            <ul className="divide-y divide-line">
                                {recentCheckins.map((c) => (
                                    <li key={c.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="flex min-w-0 items-center gap-2">
                                            {c.result === 'allowed' ? <CheckCircle2 className="size-4 shrink-0 text-emerald-600" /> : c.result === 'duplicate' ? <CheckCircle2 className="size-4 shrink-0 text-amber-500" /> : <XCircle className="size-4 shrink-0 text-red-600" />}
                                            {c.member_id ? <Link href={route('club.members.show', c.member_id)} className="truncate hover:text-brand">{c.member}</Link> : <span className="text-muted">غير معروف</span>}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted">
                                            {c.result === 'denied' && <span className="me-2 text-red-600">{checkinReason[c.reason ?? ''] ?? c.reason}</span>}
                                            {c.result === 'duplicate' && <span className="me-2 text-amber-600">مكرر</span>}
                                            <span className="num">{c.at}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="بانتظار الدفع" subtitle="تُفعّل العضوية فور اكتمال الدفع" />
                        {pendingPayments.length === 0 ? <p className="text-sm text-muted">لا توجد طلبات معلّقة.</p> : (
                            <ul className="divide-y divide-line">
                                {pendingPayments.map((p, i) => (
                                    <li key={i} className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <Link href={route('club.members.show', p.member_id)} className="truncate hover:text-brand">{p.member}</Link>
                                        <span className="flex shrink-0 items-center gap-2">
                                            <span className="num text-red-600">{money(p.balance, currency)}</span>
                                            {p.invoice_id && can('payments.record') && (
                                                <button onClick={() => setPayFor({ id: p.invoice_id as number, number: p.number ?? '', balance: p.balance })} className="grid size-8 place-items-center rounded-lg text-brand hover:bg-brand-50" title="تحصيل">
                                                    <CreditCard className="size-4" />
                                                </button>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="تنتهي خلال 3 أيام" />
                        {expiringToday.length === 0 ? <p className="text-sm text-muted">لا شيء.</p> : (
                            <ul className="divide-y divide-line">
                                {expiringToday.map((m, i) => (
                                    <li key={i} className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0">
                                            <Link href={route('club.members.show', m.member_id)} className="block truncate hover:text-brand">{m.member}</Link>
                                            <span className="text-xs text-muted">{m.plan}</span>
                                        </span>
                                        <Badge tone="amber">{date(m.ends_on)}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            {selected && <QuickSellModal open={sellOpen} onClose={() => setSellOpen(false)} member={selected} plans={plans} />}
            {selected && <QuickBookModal open={bookOpen} onClose={() => setBookOpen(false)} memberId={selected.id} sessions={todaySessions} />}
            <QuickPayModal invoice={payFor} onClose={() => setPayFor(null)} />
        </ClubLayout>
    );
}

function QuickSellModal({ open, onClose, member, plans }: { open: boolean; onClose: () => void; member: MemberHit; plans: PlanItem[] }) {
    const currency = useCurrency();
    const form = useForm({ membership_plan_id: '', mode: member.membership ? 'renewal' : 'new', coupon_code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.memberships.store', member.id), { onSuccess: onClose });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`${member.membership ? 'تجديد' : 'بيع عضوية'} — ${member.full_name}`}
            footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing} disabled={!form.data.membership_plan_id}>إصدار الفاتورة</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-2 sm:grid-cols-2">
                    {plans.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => form.setData('membership_plan_id', String(p.id))}
                            className={`rounded-xl border p-3 text-start ${form.data.membership_plan_id === String(p.id) ? 'border-brand bg-brand-50 ring-2 ring-brand/15' : 'border-line hover:border-brand-200'}`}
                        >
                            <span className="block text-sm font-medium">{p.name}</span>
                            <span className="block text-xs text-muted">{p.type_label} · {p.duration_label}</span>
                            <span className="mt-1 block text-sm font-semibold text-brand-700 num">{money(p.price, currency)}</span>
                        </button>
                    ))}
                </div>
                {form.errors.membership_plan_id && <p className="text-xs text-red-600">{form.errors.membership_plan_id}</p>}
                <Field label="كود الخصم" error={form.errors.coupon_code}>
                    <Input dir="ltr" value={form.data.coupon_code} onChange={(e) => form.setData('coupon_code', e.target.value.toUpperCase())} />
                </Field>
                <p className="text-xs text-muted">{member.membership ? 'يبدأ التجديد بعد انتهاء العضوية الحالية، فلا يخسر العضو أي يوم.' : 'تبدأ العضوية اليوم.'} تُفعّل بعد اكتمال الدفع، وستنتقل لصفحة الفاتورة للتحصيل.</p>
            </form>
        </Modal>
    );
}

function QuickBookModal({ open, onClose, memberId, sessions }: { open: boolean; onClose: () => void; memberId: number; sessions: SessionItem[] }) {
    const [sessionId, setSessionId] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const upcoming = sessions.filter((s) => s.status === 'scheduled' && new Date(s.starts_at.replace(' ', 'T')) > new Date());

    const submit = () => {
        if (!sessionId) return;
        setBusy(true);
        setError(null);
        router.post(route('club.bookings.store', Number(sessionId)), { member_id: memberId }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onSuccess: () => { setSessionId(''); onClose(); },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Modal open={open} onClose={onClose} title="حجز حصة اليوم" size="sm" footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={busy} disabled={!sessionId}>حجز</Button></>}>
            {upcoming.length === 0 ? (
                <p className="text-sm text-muted">لا توجد حصص متبقية اليوم. للحجز في أيام أخرى افتح «الحصص والحجوزات».</p>
            ) : (
                <div className="space-y-2">
                    {upcoming.map((s) => (
                        <label key={s.id} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-3 ${s.is_full ? 'cursor-not-allowed opacity-50' : sessionId === String(s.id) ? 'border-brand bg-brand-50' : 'border-line'}`}>
                            <input type="radio" name="session" disabled={s.is_full} checked={sessionId === String(s.id)} onChange={() => setSessionId(String(s.id))} className="accent-[var(--brand)]" />
                            <span className="flex-1 text-sm">
                                <span className="font-medium">{s.program}</span> · {time(s.time)}
                                <span className="block text-xs text-muted">{s.trainer ?? '—'}</span>
                            </span>
                            {s.is_full ? <Badge tone="red">مكتملة</Badge> : <Badge tone="green"><span className="num">{s.seats_left}</span> مقعد</Badge>}
                        </label>
                    ))}
                </div>
            )}
            {error && <p className="mt-3 text-sm font-medium text-red-600">{error}</p>}
        </Modal>
    );
}

function QuickPayModal({ invoice, onClose }: { invoice: { id: number; number: string; balance: string | null } | null; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm({ amount: '', method: 'cash', reference: '' });

    useEffect(() => {
        if (invoice) form.setData((d) => ({ ...d, amount: invoice.balance ?? '' }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [invoice?.id]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!invoice) return;
        form.post(route('club.payments.store', invoice.id), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); router.reload(); } });
    };

    return (
        <Modal
            open={invoice !== null}
            onClose={onClose}
            title={`تحصيل — ${invoice?.number ?? ''}`}
            size="sm"
            footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>تسجيل الدفعة</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted">المتبقي: <span className="font-semibold text-ink num">{money(invoice?.balance ?? 0, currency)}</span></p>
                <Field label="المبلغ" error={form.errors.amount} required>
                    <Input type="number" step="0.001" min="0.001" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                </Field>
                <Field label="طريقة الدفع" error={form.errors.method}>
                    <Select value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                        <option value="cash">نقدًا</option>
                        <option value="card">بطاقة (جهاز الشبكة)</option>
                        <option value="bank_transfer">تحويل بنكي (بانتظار تأكيد المالية)</option>
                    </Select>
                </Field>
                {form.data.method !== 'cash' && (
                    <Field label={form.data.method === 'card' ? 'رقم العملية من جهاز الشبكة' : 'مرجع التحويل'} error={form.errors.reference} required={form.data.method === 'card'}>
                        <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                    </Field>
                )}
                {invoice && <Link href={route('club.invoices.show', invoice.id)} className="block text-xs text-brand">فتح الفاتورة كاملة (مع إرفاق إثبات تحويل)</Link>}
            </form>
        </Modal>
    );
}
