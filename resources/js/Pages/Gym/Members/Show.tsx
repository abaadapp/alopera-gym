import { Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import QRCode from 'react-qr-code';
import {
    ArrowRight, CalendarPlus, CreditCard, Download, FileText, KeyRound, MessageCircle, Pencil, Phone, Plus, QrCode, RefreshCw,
    Send, Snowflake, Trash2, UserRound, Wallet, Repeat, Play, Ban, Fingerprint,
} from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, Select, Stat, Tabs, Textarea } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money, whatsappUrl, dayCount } from '@/lib/format';
import { bookingStatus, checkinReason, invoiceStatus, invoiceType, label, memberStatus, membershipKind, membershipStatus, messageStatus, paymentMethod, paymentStatus } from '@/lib/labels';
import type { Option } from '@/types';
import { SOURCES } from './Form';

/* ───────────────────────── types ───────────────────────── */

interface MembershipItem {
    id: number;
    plan: string;
    plan_id: number;
    kind: string;
    status: string;
    starts_on: string;
    ends_on: string;
    days_left: number;
    sessions_total: number | null;
    sessions_used: number;
    sessions_left: number | null;
    total: string;
    discount: string;
    credit_applied: string;
    freeze_days_used: number;
    freezes_count: number;
    freeze_allowed: boolean;
    max_freeze_days: number;
    invoice_id: number | null;
    cancel_reason: string | null;
    activated_at: string | null;
    created_at: string | null;
}

interface PlanItem {
    id: number;
    name: string;
    type: string;
    type_label: string;
    duration_label: string;
    price: string;
    signup_fee: string;
    sessions_limit: number | null;
    is_active: boolean;
}

interface MemberDetail {
    id: number;
    member_no: string;
    full_name: string;
    phone: string;
    phone_display: string;
    email: string | null;
    status: string;
    photo_url: string | null;
    joined_on: string | null;
    last_check_in_at: string | null;
    membership: MembershipItem | null;
    gender: string | null;
    national_id: string | null;
    nationality: string | null;
    address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    goals: string | null;
    source: string | null;
    notes: string | null;
    whatsapp_opt_in: boolean;
    marketing_opt_in: boolean;
    phone_alt: string | null;
    branch_id: number | null;
    date_of_birth: string | null;
    health_notes: string | null;
    branch: string | null;
    has_portal_access: boolean;
    qr_token: string | null;
    balance: string;
}

interface Props {
    member: MemberDetail;
    memberships: MembershipItem[];
    events: { type: string; data: Record<string, unknown> | null; user: string | null; at: string; membership_id: number }[];
    invoices: { id: number; number: string; type: string; status: string; total: string; paid_total: string; issue_date: string; balance: string }[];
    payments: { id: number; receipt_number: string | null; kind: string; method: string; status: string; amount: string; reference: string | null; paid_at: string | null; invoice_id: number | null }[];
    bookings: { id: number; status: string; source: string; program: string | null; starts_at: string | null; session_id: number }[];
    checkins: { id: number; method: string; result: string; reason: string | null; at: string }[];
    documents: { id: number; type: string; title: string; original_name: string | null; size: number; uploaded_by: string | null; created_at: string; expires_on: string | null }[];
    notes: { id: number; channel: string; direction: string; body: string; user: string | null; at: string; follow_up_on: string | null }[];
    messages: { id: number; direction: string; type: string; template_key: string | null; body: string | null; status: string; error_code: string | null; at: string }[];
    credentials: { id: number; type: string; identifier: string; label: string | null; is_active: boolean }[];
    pt: { id: number; status: string; sessions_total: number; sessions_used: number; price: string; package: string | null; trainer: string | null; expires_on: string | null }[];
    plans: PlanItem[];
    ptPackages: { id: number; name: string; sessions_count: number; price: string }[];
    trainers: Option[];
    templates: { key: string; name: string; body: string }[];
    onlinePayments: boolean;
}

type TabKey = 'overview' | 'memberships' | 'finance' | 'attendance' | 'pt' | 'documents' | 'crm' | 'whatsapp' | 'access';

/* ───────────────────────── helpers ───────────────────────── */

const EVENT_LABELS: Record<string, string> = {
    created: 'إنشاء الطلب وإصدار الفاتورة',
    activated: 'تفعيل بعد تأكيد الدفع',
    started: 'بدء الفترة',
    frozen: 'تجميد',
    resumed: 'استئناف',
    cancelled: 'إلغاء',
    expired: 'انتهاء',
    upgraded: 'تغيير الباقة',
};

const DOC_TYPES: Record<string, string> = { contract: 'عقد', id: 'هوية', medical: 'تقرير طبي', waiver: 'إقرار وإخلاء مسؤولية', other: 'أخرى' };
const CHANNELS: Record<string, string> = { call: 'مكالمة', whatsapp: 'واتساب', sms: 'رسالة نصية', email: 'بريد', visit: 'زيارة', note: 'ملاحظة' };
const DIRECTIONS: Record<string, string> = { outbound: 'صادر', inbound: 'وارد', internal: 'داخلي' };
const CREDENTIAL_TYPES: Record<string, string> = { card: 'بطاقة RFID', fingerprint: 'بصمة (رقم المستخدم بالجهاز)', pin: 'رمز سري', barcode: 'باركود' };
const PT_STATUS: Record<string, [string, 'amber' | 'green' | 'gray' | 'red' | 'blue']> = {
    pending: ['بانتظار الدفع', 'amber'],
    active: ['نشطة', 'green'],
    completed: ['مكتملة', 'blue'],
    expired: ['منتهية', 'gray'],
    cancelled: ['ملغاة', 'red'],
};

function xsrf(): string {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

function kb(size: number): string {
    return size > 1024 * 1024 ? `${(size / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(size / 1024))} KB`;
}

function Info({ label: l, children }: { label: string; children: ReactNode }) {
    return <Stat label={l} value={children} />;
}

/* ───────────────────────── page ───────────────────────── */

export default function MemberShow(props: Props) {
    const { member, memberships } = props;
    const can = useCan();
    const currency = useCurrency();
    const [tab, setTab] = useState<TabKey>('overview');
    const [sellOpen, setSellOpen] = useState(false);
    const [qrOpen, setQrOpen] = useState(false);
    const [portalOpen, setPortalOpen] = useState(false);

    // The controller may not eager-load currentMembership; derive it from the list as a fallback.
    const current = member.membership ?? memberships.find((m) => m.status === 'active' || m.status === 'frozen') ?? null;
    const [statusLabel, statusTone] = current ? label(membershipStatus, current.status) : ['بدون عضوية سارية', 'gray' as const];
    const [personLabel, personTone] = label(memberStatus, member.status);
    const hasPending = memberships.some((m) => m.status === 'pending');

    const tabs = useMemo(() => {
        const list: { key: TabKey; label: string; count?: number | null }[] = [
            { key: 'overview', label: 'نظرة عامة' },
            { key: 'memberships', label: 'العضويات', count: memberships.length },
        ];
        if (can('invoices.view')) list.push({ key: 'finance', label: 'الفواتير والدفعات', count: props.invoices.length });
        list.push({ key: 'attendance', label: 'الحضور والحجوزات', count: props.bookings.length });
        if (can('pt.manage') || props.pt.length > 0) list.push({ key: 'pt', label: 'التدريب الشخصي', count: props.pt.length });
        if (can('members.documents')) list.push({ key: 'documents', label: 'المستندات', count: props.documents.length });
        list.push({ key: 'crm', label: 'سجل التواصل', count: props.notes.length });
        if (can('whatsapp.inbox') || can('whatsapp.send')) list.push({ key: 'whatsapp', label: 'واتساب', count: props.messages.length });
        if (can('members.manage')) list.push({ key: 'access', label: 'وسائل الدخول', count: props.credentials.length });
        return list;
    }, [can, memberships.length, props]);

    return (
        <ClubLayout title={member.full_name}>
            <Link href={route('club.members.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-brand">
                <ArrowRight className="size-4" /> الأعضاء
            </Link>

            {/* Header card */}
            <Card className="mb-6">
                <div className="flex flex-col gap-5 md:flex-row md:items-center">
                    <div className="flex flex-1 items-center gap-4">
                        {member.photo_url ? (
                            <img src={member.photo_url} alt="" className="size-20 rounded-2xl object-cover" />
                        ) : (
                            <span className="grid size-20 place-items-center rounded-2xl bg-brand-50 text-brand"><UserRound className="size-8" /></span>
                        )}
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold">{member.full_name}</h1>
                                {member.status !== 'active' && <Badge tone={personTone}>{personLabel}</Badge>}
                            </div>
                            <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                                <span className="num">{member.member_no}</span>
                                <a href={`tel:+${member.phone}`} className="inline-flex items-center gap-1 hover:text-brand"><Phone className="size-3.5" /><span className="num">{member.phone_display}</span></a>
                                {member.joined_on && <span>منذ {date(member.joined_on)}</span>}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <Badge tone={statusTone}>{statusLabel}</Badge>
                                {current && (
                                    <span className="text-sm text-ink/80">
                                        {current.plan} · حتى {date(current.ends_on)} (<span className="num">{dayCount(current.days_left)}</span>)
                                        {current.sessions_left !== null && <> · <span className="num">{current.sessions_left}</span> حصة متبقية</>}
                                    </span>
                                )}
                                {hasPending && <Badge tone="amber">طلب بانتظار الدفع</Badge>}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 md:items-end">
                        {can('invoices.view') && (
                            <div className={`rounded-xl px-4 py-2 text-sm ${Number(member.balance) > 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'}`}>
                                الرصيد المستحق: <span className="font-semibold num">{money(member.balance, currency)}</span>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {can('memberships.sell') && member.status !== 'blocked' && (
                                <Button size="sm" icon={<CalendarPlus className="size-4" />} onClick={() => setSellOpen(true)}>
                                    {memberships.length > 0 ? 'تجديد / بيع عضوية' : 'بيع عضوية'}
                                </Button>
                            )}
                            <Button size="sm" variant="outline" href={whatsappUrl(member.phone)} external icon={<MessageCircle className="size-4" />}>واتساب</Button>
                            {member.qr_token && <Button size="sm" variant="outline" icon={<QrCode className="size-4" />} onClick={() => setQrOpen(true)}>QR</Button>}
                            {can('members.manage') && (
                                <>
                                    <Button size="sm" variant="outline" icon={<KeyRound className="size-4" />} onClick={() => setPortalOpen(true)}>
                                        {member.has_portal_access ? 'كلمة مرور البوابة' : 'تفعيل البوابة'}
                                    </Button>
                                    <Button size="sm" variant="outline" href={route('club.members.edit', member.id)} icon={<Pencil className="size-4" />}>تعديل</Button>
                                </>
                            )}
                            {can('members.delete') && (
                                <ConfirmAction url={route('club.members.destroy', member.id)} method="delete" title="حذف العضو" message="سيتم حذف ملف العضو. لا يمكن حذف عضو لديه عضوية سارية." confirmLabel="حذف" icon={<Trash2 className="size-4" />}>
                                    حذف
                                </ConfirmAction>
                            )}
                        </div>
                    </div>
                </div>
            </Card>

            <Tabs tabs={tabs} value={tab} onChange={setTab} className="mb-6" />

            {tab === 'overview' && <OverviewTab member={member} />}
            {tab === 'memberships' && <MembershipsTab {...props} onSell={() => setSellOpen(true)} />}
            {tab === 'finance' && <FinanceTab {...props} />}
            {tab === 'attendance' && <AttendanceTab {...props} />}
            {tab === 'pt' && <PtTab {...props} />}
            {tab === 'documents' && <DocumentsTab {...props} />}
            {tab === 'crm' && <CrmTab {...props} />}
            {tab === 'whatsapp' && <WhatsAppTab {...props} />}
            {tab === 'access' && <AccessTab {...props} />}

            <SellModal open={sellOpen} onClose={() => setSellOpen(false)} member={member} current={current} plans={props.plans} hasMemberships={memberships.length > 0} />

            <Modal open={qrOpen} onClose={() => setQrOpen(false)} title="رمز الدخول QR" size="sm">
                {member.qr_token && (
                    <div className="flex flex-col items-center gap-4 py-2">
                        <div className="rounded-2xl border border-line bg-white p-4">
                            <QRCode value={member.qr_token} size={200} />
                        </div>
                        <p className="text-center text-sm text-muted">تظهر هذه الشيفرة أيضًا في بوابة العضو، وتُقرأ عند البوابة أو في الاستقبال.</p>
                        <ConfirmAction
                            url={route('club.members.rotate-qr', member.id)}
                            title="إصدار رمز جديد"
                            message="سيتوقف الرمز الحالي عن العمل فورًا (مثلًا عند مشاركته أو فقدان الجوال)."
                            confirmLabel="إصدار رمز جديد"
                            icon={<RefreshCw className="size-4" />}
                        >
                            إصدار رمز جديد
                        </ConfirmAction>
                    </div>
                )}
            </Modal>

            <PortalPasswordModal open={portalOpen} onClose={() => setPortalOpen(false)} memberId={member.id} hasAccess={member.has_portal_access} />
        </ClubLayout>
    );
}

/* ───────────────────────── overview ───────────────────────── */

function OverviewTab({ member }: { member: MemberDetail }) {
    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Card className="lg:col-span-2">
                <CardHeader title="البيانات الشخصية" />
                <dl className="grid gap-4 sm:grid-cols-3">
                    <Info label="البريد الإلكتروني">{member.email ? <span dir="ltr">{member.email}</span> : '—'}</Info>
                    <Info label="هاتف إضافي">{member.phone_alt ? <span className="num">{member.phone_alt}</span> : '—'}</Info>
                    <Info label="تاريخ الميلاد">{date(member.date_of_birth)}</Info>
                    <Info label="الرقم المدني">{member.national_id ? <span className="num">{member.national_id}</span> : '—'}</Info>
                    <Info label="الجنسية">{member.nationality ?? '—'}</Info>
                    <Info label="الفرع">{member.branch ?? '—'}</Info>
                    <Info label="العنوان">{member.address ?? '—'}</Info>
                    <Info label="مصدر التسجيل">{member.source ? (SOURCES[member.source] ?? member.source) : '—'}</Info>
                    <Info label="آخر دخول">{member.last_check_in_at ?? '—'}</Info>
                </dl>
                {member.goals && (
                    <div className="mt-5 rounded-xl bg-brand-50/60 p-4 text-sm">
                        <p className="mb-1 text-xs font-semibold text-brand-700">الأهداف</p>
                        <p className="whitespace-pre-line">{member.goals}</p>
                    </div>
                )}
                {member.notes && (
                    <div className="mt-3 rounded-xl bg-canvas p-4 text-sm">
                        <p className="mb-1 text-xs font-semibold text-muted">ملاحظات داخلية</p>
                        <p className="whitespace-pre-line">{member.notes}</p>
                    </div>
                )}
            </Card>
            <div className="space-y-6">
                <Card>
                    <CardHeader title="الطوارئ والصحة" />
                    <dl className="space-y-3">
                        <Info label="جهة اتصال الطوارئ">{member.emergency_contact_name ?? '—'}</Info>
                        <Info label="هاتف الطوارئ">{member.emergency_contact_phone ? <span className="num">{member.emergency_contact_phone}</span> : '—'}</Info>
                        <Info label="ملاحظات صحية">{member.health_notes ? <span className="whitespace-pre-line text-red-700">{member.health_notes}</span> : '—'}</Info>
                    </dl>
                </Card>
                <Card>
                    <CardHeader title="الموافقات والبوابة" />
                    <ul className="space-y-2 text-sm">
                        <li className="flex justify-between"><span>رسائل الخدمة (واتساب)</span><Badge tone={member.whatsapp_opt_in ? 'green' : 'gray'}>{member.whatsapp_opt_in ? 'موافق' : 'غير موافق'}</Badge></li>
                        <li className="flex justify-between"><span>الرسائل التسويقية</span><Badge tone={member.marketing_opt_in ? 'green' : 'gray'}>{member.marketing_opt_in ? 'موافق' : 'غير موافق'}</Badge></li>
                        <li className="flex justify-between"><span>بوابة العضو</span><Badge tone={member.has_portal_access ? 'green' : 'amber'}>{member.has_portal_access ? 'مفعّلة' : 'غير مفعّلة'}</Badge></li>
                    </ul>
                </Card>
            </div>
        </div>
    );
}

/* ───────────────────────── memberships ───────────────────────── */

function MembershipsTab({ memberships, events, plans, member, onSell }: Props & { onSell: () => void }) {
    const can = useCan();
    const currency = useCurrency();
    const [freezeFor, setFreezeFor] = useState<MembershipItem | null>(null);
    const [changeFor, setChangeFor] = useState<MembershipItem | null>(null);

    if (memberships.length === 0) {
        return (
            <Card>
                <EmptyState icon={<CalendarPlus className="size-6" />} title="لا توجد عضويات بعد" description="بيع أول عضوية للعضو؛ تُفعّل بعد تأكيد الدفع." action={can('memberships.sell') ? <Button onClick={onSell}>بيع عضوية</Button> : undefined} />
            </Card>
        );
    }

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <div className="space-y-4 lg:col-span-2">
                {memberships.map((m) => {
                    const [l, tone] = label(membershipStatus, m.status);
                    const used = m.sessions_total ? Math.min(100, (m.sessions_used / m.sessions_total) * 100) : 0;
                    return (
                        <Card key={m.id}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="font-semibold">{m.plan}</h3>
                                        <Badge tone={tone}>{l}</Badge>
                                        <Badge tone="gray">{membershipKind[m.kind] ?? m.kind}</Badge>
                                    </div>
                                    <p className="mt-1 text-sm text-muted">
                                        {date(m.starts_on)} ← {date(m.ends_on)}
                                        {['active', 'frozen'].includes(m.status) && <> · متبقٍ <span className="num">{dayCount(m.days_left)}</span></>}
                                    </p>
                                </div>
                                <div className="text-end">
                                    <p className="font-semibold num">{money(m.total, currency)}</p>
                                    {Number(m.discount) > 0 && <p className="text-xs text-muted">خصم <span className="num">{money(m.discount, currency)}</span></p>}
                                    {Number(m.credit_applied) > 0 && <p className="text-xs text-muted">رصيد مستخدم <span className="num">{money(m.credit_applied, currency)}</span></p>}
                                </div>
                            </div>

                            {m.sessions_total !== null && (
                                <div className="mt-4">
                                    <div className="mb-1 flex justify-between text-xs text-muted">
                                        <span>الحصص المستخدمة</span>
                                        <span className="num">{m.sessions_used} / {m.sessions_total}</span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-brand-50"><div className="h-full rounded-full bg-brand" style={{ width: `${used}%` }} /></div>
                                </div>
                            )}

                            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                                {m.freezes_count > 0 && <span>تجميد: <span className="num">{m.freezes_count}</span> مرة / <span className="num">{m.freeze_days_used}</span> يوم</span>}
                                {m.activated_at && <span>فُعّلت {m.activated_at}</span>}
                                {m.cancel_reason && <span className="text-red-600">سبب الإلغاء: {m.cancel_reason === 'upgraded' ? 'تغيير الباقة' : m.cancel_reason === 'invoice_voided' ? 'إلغاء الفاتورة' : m.cancel_reason}</span>}
                            </div>

                            <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                                {m.invoice_id && can('invoices.view') && (
                                    <Button size="sm" variant={m.status === 'pending' ? 'primary' : 'outline'} href={route('club.invoices.show', m.invoice_id)} icon={m.status === 'pending' ? <Wallet className="size-4" /> : <FileText className="size-4" />}>
                                        {m.status === 'pending' ? 'تحصيل الدفعة' : 'الفاتورة'}
                                    </Button>
                                )}
                                {m.status === 'active' && m.freeze_allowed && can('memberships.freeze') && (
                                    <Button size="sm" variant="outline" icon={<Snowflake className="size-4" />} onClick={() => setFreezeFor(m)}>تجميد</Button>
                                )}
                                {m.status === 'frozen' && can('memberships.freeze') && (
                                    <ConfirmAction url={route('club.memberships.resume', m.id)} title="استئناف العضوية" message="سيُمدَّد تاريخ الانتهاء بعدد أيام التجميد." confirmLabel="استئناف" tone="primary" icon={<Play className="size-4" />}>
                                        استئناف
                                    </ConfirmAction>
                                )}
                                {m.status === 'active' && can('memberships.upgrade') && (
                                    <Button size="sm" variant="outline" icon={<Repeat className="size-4" />} onClick={() => setChangeFor(m)}>تغيير الباقة</Button>
                                )}
                                {['pending', 'scheduled', 'active', 'frozen'].includes(m.status) && can('memberships.cancel') && (
                                    <ConfirmAction
                                        url={route('club.memberships.cancel', m.id)}
                                        title="إلغاء العضوية"
                                        message={m.status === 'pending' ? 'سيتم سحب الطلب وإلغاء فاتورته غير المدفوعة.' : 'تُلغى العضوية وحجوزاتها القادمة. رد المبلغ (إن وجد) يتم من المالية.'}
                                        reason="سبب الإلغاء"
                                        confirmLabel="إلغاء العضوية"
                                        icon={<Ban className="size-4" />}
                                    >
                                        إلغاء
                                    </ConfirmAction>
                                )}
                            </div>
                        </Card>
                    );
                })}
            </div>

            <Card>
                <CardHeader title="سجل العضويات" subtitle="كل تغيير في الحالة" />
                {events.length === 0 ? (
                    <p className="text-sm text-muted">لا أحداث بعد.</p>
                ) : (
                    <ol className="relative space-y-4 border-s border-line ps-5">
                        {events.map((e, i) => (
                            <li key={i} className="relative">
                                <span className="absolute -start-[25px] top-1 size-2.5 rounded-full bg-brand ring-4 ring-white" />
                                <p className="text-sm font-medium">{EVENT_LABELS[e.type] ?? e.type}</p>
                                <p className="text-xs text-muted">{e.at}{e.user ? ` · ${e.user}` : ''}</p>
                                {e.data && typeof e.data.reason === 'string' && <p className="mt-0.5 text-xs text-ink/70">{e.data.reason as string}</p>}
                                {e.data && e.data.days !== undefined && e.data.days !== null && <p className="mt-0.5 text-xs text-ink/70">الأيام: <span className="num">{String(e.data.days)}</span></p>}
                            </li>
                        ))}
                    </ol>
                )}
            </Card>

            <FreezeModal membership={freezeFor} onClose={() => setFreezeFor(null)} />
            <ChangePlanModal membership={changeFor} onClose={() => setChangeFor(null)} plans={plans} memberId={member.id} />
        </div>
    );
}

function FreezeModal({ membership, onClose }: { membership: MembershipItem | null; onClose: () => void }) {
    const form = useForm({ days: '', reason: '' });
    const left = membership && membership.max_freeze_days > 0 ? membership.max_freeze_days - membership.freeze_days_used : null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!membership) return;
        form.post(route('club.memberships.freeze', membership.id), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };

    return (
        <Modal
            open={membership !== null}
            onClose={onClose}
            title="تجميد العضوية"
            size="sm"
            footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button onClick={submit} loading={form.processing}>تجميد</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted">تُلغى الحجوزات القادمة وتُستعاد حصصها، ويُمدَّد تاريخ الانتهاء عند الاستئناف بعدد الأيام الفعلية.</p>
                <Field label="عدد أيام التجميد" error={form.errors.days} hint={left !== null ? `المتاح: ${dayCount(left)}` : 'اتركه فارغًا ليبقى التجميد حتى الاستئناف اليدوي'}>
                    <Input type="number" min={1} max={left ?? 365} value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} />
                </Field>
                <Field label="السبب" error={form.errors.reason}>
                    <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="سفر، ظرف صحي…" />
                </Field>
            </form>
        </Modal>
    );
}

interface Quote {
    price: string;
    signup_fee: string;
    credit: string;
    discount: string;
    total: string;
    coupon_error: string | null;
    ends_on: string;
}

function useQuote(params: { member_id: number; membership_plan_id: string; coupon_code: string; upgrade_from?: number | null }, enabled: boolean) {
    const [quote, setQuote] = useState<Quote | null>(null);
    const [loading, setLoading] = useState(false);
    const { member_id, membership_plan_id, coupon_code, upgrade_from } = params;

    useEffect(() => {
        if (!enabled || !membership_plan_id) {
            setQuote(null);
            return;
        }
        const controller = new AbortController();
        const id = setTimeout(async () => {
            setLoading(true);
            try {
                const query: Record<string, string | number> = { member_id, membership_plan_id };
                if (coupon_code.trim()) query.coupon_code = coupon_code.trim();
                if (upgrade_from) query.upgrade_from = upgrade_from;
                const res = await fetch(route('club.memberships.quote', query), {
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (res.ok) setQuote(await res.json());
            } catch {
                /* aborted */
            } finally {
                setLoading(false);
            }
        }, 300);
        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [enabled, member_id, membership_plan_id, coupon_code, upgrade_from]);

    return { quote, loading };
}

function QuoteBox({ quote, loading }: { quote: Quote | null; loading: boolean }) {
    const currency = useCurrency();
    if (!quote) return loading ? <p className="text-sm text-muted">جارٍ حساب السعر…</p> : null;
    return (
        <div className="rounded-2xl bg-brand-50/70 p-4 text-sm">
            <dl className="space-y-1.5">
                <div className="flex justify-between"><dt className="text-muted">سعر الباقة</dt><dd className="num">{money(quote.price, currency)}</dd></div>
                {Number(quote.signup_fee) > 0 && <div className="flex justify-between"><dt className="text-muted">رسوم التسجيل</dt><dd className="num">{money(quote.signup_fee, currency)}</dd></div>}
                {Number(quote.credit) > 0 && <div className="flex justify-between text-emerald-700"><dt>رصيد العضوية الحالية</dt><dd className="num">− {money(quote.credit, currency)}</dd></div>}
                {Number(quote.discount) > 0 && <div className="flex justify-between text-emerald-700"><dt>الخصم</dt><dd className="num">− {money(quote.discount, currency)}</dd></div>}
                <div className="flex justify-between border-t border-brand-200 pt-2 text-base font-semibold"><dt>الإجمالي</dt><dd className="num">{money(quote.total, currency)}</dd></div>
            </dl>
            {quote.coupon_error && <p className="mt-2 text-xs font-medium text-red-600">{quote.coupon_error}</p>}
        </div>
    );
}

function SellModal({ open, onClose, member, current, plans, hasMemberships }: { open: boolean; onClose: () => void; member: MemberDetail; current: MembershipItem | null; plans: PlanItem[]; hasMemberships: boolean }) {
    const can = useCan();
    const currency = useCurrency();
    const form = useForm({
        membership_plan_id: current ? String(current.plan_id) : '',
        mode: hasMemberships ? 'renewal' : 'new',
        starts_on: '',
        coupon_code: '',
        discount: '',
        notes: '',
    });
    const { quote, loading } = useQuote({ member_id: member.id, membership_plan_id: form.data.membership_plan_id, coupon_code: form.data.coupon_code }, open);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.memberships.store', member.id), { onSuccess: () => { form.reset(); onClose(); } });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="بيع / تجديد عضوية"
            size="lg"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>إلغاء</Button>
                    <Button onClick={submit} loading={form.processing} disabled={!form.data.membership_plan_id}>إصدار الفاتورة</Button>
                </>
            }
        >
            <form onSubmit={submit} className="grid gap-5 md:grid-cols-5">
                <div className="space-y-4 md:col-span-3">
                    {hasMemberships && (
                        <div className="grid grid-cols-2 gap-2">
                            {(['renewal', 'new'] as const).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => form.setData('mode', m)}
                                    className={`rounded-xl border px-3 py-2.5 text-sm ${form.data.mode === m ? 'border-brand bg-brand-50 text-brand-700' : 'border-line hover:bg-brand-50/50'}`}
                                >
                                    {m === 'renewal' ? 'تجديد (يبدأ بعد الحالية)' : 'عضوية بتاريخ محدد'}
                                </button>
                            ))}
                        </div>
                    )}
                    <Field label="الباقة" error={form.errors.membership_plan_id} required>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {plans.map((p) => (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => form.setData('membership_plan_id', String(p.id))}
                                    className={`rounded-xl border p-3 text-start transition ${form.data.membership_plan_id === String(p.id) ? 'border-brand bg-brand-50 ring-2 ring-brand/15' : 'border-line hover:border-brand-200'}`}
                                >
                                    <span className="block text-sm font-medium">{p.name}</span>
                                    <span className="block text-xs text-muted">{p.type_label} · {p.duration_label}{p.sessions_limit ? ` · ${p.sessions_limit} حصة` : ''}</span>
                                    <span className="mt-1 block text-sm font-semibold text-brand-700 num">{money(p.price, currency)}</span>
                                </button>
                            ))}
                        </div>
                    </Field>
                    {form.data.mode === 'new' && (
                        <Field label="تاريخ البدء" error={form.errors.starts_on} hint="اتركه فارغًا ليبدأ اليوم. إن تأخر الدفع تبدأ الفترة يوم التفعيل.">
                            <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                        </Field>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="كود الخصم" error={form.errors.coupon_code}>
                            <Input dir="ltr" value={form.data.coupon_code} onChange={(e) => form.setData('coupon_code', e.target.value.toUpperCase())} />
                        </Field>
                        {can('plans.manage') && (
                            <Field label="خصم يدوي" error={form.errors.discount}>
                                <Input type="number" step="0.001" min={0} value={form.data.discount} onChange={(e) => form.setData('discount', e.target.value)} />
                            </Field>
                        )}
                    </div>
                    <Field label="ملاحظات" error={form.errors.notes}>
                        <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                </div>
                <div className="space-y-3 md:col-span-2">
                    <QuoteBox quote={quote} loading={loading} />
                    {quote && Number(form.data.discount) > 0 && <p className="text-xs text-muted">يُطرح الخصم اليدوي (<span className="num">{money(form.data.discount, currency)}</span>) عند إصدار الفاتورة.</p>}
                    <p className="text-xs leading-6 text-muted">تُصدر فاتورة وتبقى العضوية «بانتظار الدفع». تُفعّل تلقائيًا عند اكتمال الدفع (نقدًا، أو تأكيد التحويل، أو الدفع الإلكتروني).</p>
                </div>
            </form>
        </Modal>
    );
}

function ChangePlanModal({ membership, onClose, plans, memberId }: { membership: MembershipItem | null; onClose: () => void; plans: PlanItem[]; memberId: number }) {
    const form = useForm({ membership_plan_id: '', coupon_code: '' });
    const { quote, loading } = useQuote(
        { member_id: memberId, membership_plan_id: form.data.membership_plan_id, coupon_code: form.data.coupon_code, upgrade_from: membership?.id ?? null },
        membership !== null,
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!membership) return;
        form.post(route('club.memberships.upgrade', membership.id), { onSuccess: () => { form.reset(); onClose(); } });
    };

    return (
        <Modal
            open={membership !== null}
            onClose={onClose}
            title="تغيير / ترقية الباقة"
            footer={<><Button variant="ghost" onClick={onClose}>تراجع</Button><Button onClick={submit} loading={form.processing} disabled={!form.data.membership_plan_id}>إصدار فاتورة التغيير</Button></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-muted">تبدأ الباقة الجديدة اليوم، وتُحتسب قيمة الأيام غير المستخدمة من «{membership?.plan}» رصيدًا. تبقى العضوية الحالية فعّالة حتى دفع فاتورة التغيير.</p>
                <Field label="الباقة الجديدة" error={form.errors.membership_plan_id} required>
                    <Select value={form.data.membership_plan_id} onChange={(e) => form.setData('membership_plan_id', e.target.value)} placeholder="اختر الباقة">
                        {plans.filter((p) => p.id !== membership?.plan_id).map((p) => <option key={p.id} value={p.id}>{p.name} — {p.duration_label}</option>)}
                    </Select>
                </Field>
                <Field label="كود الخصم" error={form.errors.coupon_code}>
                    <Input dir="ltr" value={form.data.coupon_code} onChange={(e) => form.setData('coupon_code', e.target.value.toUpperCase())} />
                </Field>
                <QuoteBox quote={quote} loading={loading} />
            </form>
        </Modal>
    );
}

function PortalPasswordModal({ open, onClose, memberId, hasAccess }: { open: boolean; onClose: () => void; memberId: number; hasAccess: boolean }) {
    const form = useForm({ password: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.members.portal-password', memberId), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={hasAccess ? 'تغيير كلمة مرور البوابة' : 'تفعيل بوابة العضو'}
            size="sm"
            footer={<><Button variant="ghost" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}
        >
            <form onSubmit={submit} className="space-y-3">
                <p className="text-sm text-muted">يدخل العضو إلى البوابة برقم هاتفه وكلمة المرور هذه، ويمكنه تغييرها لاحقًا من ملفه.</p>
                <Field label="كلمة المرور (8 أحرف على الأقل)" error={form.errors.password}>
                    <Input type="text" dir="ltr" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}

/* ───────────────────────── finance ───────────────────────── */

function FinanceTab({ invoices, payments }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const [payFor, setPayFor] = useState<Props['invoices'][number] | null>(null);

    return (
        <div className="space-y-6">
            <Card padded={false}>
                <div className="p-5 pb-0"><CardHeader title="الفواتير" /></div>
                {invoices.length === 0 ? <EmptyState title="لا فواتير" /> : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الرقم</th><th>النوع</th><th>التاريخ</th><th>الإجمالي</th><th>المتبقي</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {invoices.map((i) => {
                                    const [l, tone] = label(invoiceStatus, i.status);
                                    const open = ['issued', 'partially_paid'].includes(i.status);
                                    return (
                                        <tr key={i.id}>
                                            <td><Link href={route('club.invoices.show', i.id)} className="font-medium hover:text-brand num">{i.number}</Link></td>
                                            <td>{invoiceType[i.type] ?? i.type}</td>
                                            <td>{date(i.issue_date)}</td>
                                            <td className="num">{money(i.total, currency)}</td>
                                            <td className={`num ${Number(i.balance) > 0 ? 'text-red-600' : ''}`}>{money(i.balance, currency)}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                            <td className="text-end">
                                                {open && can('payments.record') && <Button size="sm" variant="secondary" icon={<CreditCard className="size-3.5" />} onClick={() => setPayFor(i)}>تحصيل</Button>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card padded={false}>
                <div className="p-5 pb-0"><CardHeader title="الدفعات والإيصالات" /></div>
                {payments.length === 0 ? <EmptyState title="لا دفعات" /> : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الإيصال</th><th>التاريخ</th><th>الطريقة</th><th>المبلغ</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {payments.map((p) => {
                                    const [l, tone] = label(paymentStatus, p.status);
                                    return (
                                        <tr key={p.id}>
                                            <td className="num">{p.receipt_number ?? '—'}</td>
                                            <td className="text-xs">{p.paid_at ?? '—'}</td>
                                            <td>{paymentMethod[p.method] ?? p.method}{p.reference && <span className="block text-xs text-muted num">{p.reference}</span>}</td>
                                            <td className={`num ${p.kind === 'refund' ? 'text-red-600' : ''}`}>{p.kind === 'refund' ? '− ' : ''}{money(p.amount, currency)}</td>
                                            <td><Badge tone={tone}>{p.kind === 'refund' ? 'رد مبلغ' : l}</Badge></td>
                                            <td className="text-end">
                                                {p.status === 'completed' && <Link href={route('club.payments.receipt', p.id)} className="text-sm text-brand">الإيصال</Link>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <RecordPaymentModal invoice={payFor} onClose={() => setPayFor(null)} />
        </div>
    );
}

function RecordPaymentModal({ invoice, onClose }: { invoice: { id: number; number: string; balance: string | null } | null; onClose: () => void }) {
    const currency = useCurrency();
    const form = useForm({ amount: '', method: 'cash', reference: '', notes: '', proof: null as File | null });

    useEffect(() => {
        if (invoice) form.setData((d) => ({ ...d, amount: invoice.balance ?? '' }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [invoice?.id]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!invoice) return;
        form.post(route('club.payments.store', invoice.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };

    return (
        <Modal
            open={invoice !== null}
            onClose={onClose}
            title={`تحصيل دفعة — ${invoice?.number ?? ''}`}
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
                        <option value="bank_transfer">تحويل بنكي</option>
                        <option value="other">أخرى</option>
                    </Select>
                </Field>
                {form.data.method !== 'cash' && (
                    <Field label={form.data.method === 'card' ? 'رقم العملية من جهاز الشبكة' : 'المرجع'} error={form.errors.reference} required={form.data.method === 'card'}>
                        <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                    </Field>
                )}
                {form.data.method === 'bank_transfer' && (
                    <>
                        <Field label="إثبات التحويل" error={form.errors.proof} hint="يُسجَّل التحويل «بانتظار التأكيد» ولا تُفعّل العضوية حتى تؤكده المالية.">
                            <input type="file" accept="image/*,application/pdf" className="block w-full text-sm" onChange={(e) => form.setData('proof', e.target.files?.[0] ?? null)} />
                        </Field>
                    </>
                )}
                <Field label="ملاحظات" error={form.errors.notes}>
                    <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}

/* ───────────────────────── attendance ───────────────────────── */

function AttendanceTab({ bookings, checkins }: Props) {
    const attended = bookings.filter((b) => b.status === 'attended').length;
    const noShow = bookings.filter((b) => b.status === 'no_show').length;
    const rate = attended + noShow > 0 ? Math.round((attended / (attended + noShow)) * 100) : null;

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <Card padded={false}>
                <div className="p-5 pb-0">
                    <CardHeader title="الحجوزات" subtitle={rate !== null ? `نسبة الحضور ${rate}% (آخر ${bookings.length} حجز)` : undefined} />
                </div>
                {bookings.length === 0 ? <EmptyState title="لا حجوزات" /> : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الحصة</th><th>الموعد</th><th>المصدر</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {bookings.map((b) => {
                                    const [l, tone] = label(bookingStatus, b.status);
                                    return (
                                        <tr key={b.id}>
                                            <td><Link href={route('club.sessions.show', b.session_id)} className="hover:text-brand">{b.program ?? '—'}</Link></td>
                                            <td className="text-xs num">{b.starts_at}</td>
                                            <td className="text-xs text-muted">{{ reception: 'الاستقبال', portal: 'البوابة', website: 'الموقع', trainer: 'المدرب' }[b.source] ?? b.source}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            <Card padded={false}>
                <div className="p-5 pb-0"><CardHeader title="سجل الدخول" /></div>
                {checkins.length === 0 ? <EmptyState title="لا تسجيلات دخول" /> : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>الطريقة</th><th>النتيجة</th></tr></thead>
                            <tbody>
                                {checkins.map((c) => (
                                    <tr key={c.id}>
                                        <td className="text-xs num">{c.at}</td>
                                        <td className="text-xs">{{ manual: 'يدوي', qr: 'QR', card: 'بطاقة', fingerprint: 'بصمة', barcode: 'باركود', pin: 'رمز' }[c.method] ?? c.method}</td>
                                        <td>
                                            <Badge tone={c.result === 'allowed' ? 'green' : 'red'}>{c.result === 'allowed' ? 'مسموح' : checkinReason[c.reason ?? ''] ?? 'مرفوض'}</Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </div>
    );
}

/* ───────────────────────── PT ───────────────────────── */

function PtTab({ pt, ptPackages, trainers, member }: Props) {
    const can = useCan();
    const currency = useCurrency();
    const form = useForm({ pt_package_id: '', trainer_id: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.pt.store', member.id));
    };

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Card padded={false} className="lg:col-span-2">
                <div className="p-5 pb-0"><CardHeader title="باقات التدريب الشخصي" actions={can('pt.manage') ? <Link href={route('club.pt.index')} className="text-sm text-brand">جدولة الجلسات</Link> : undefined} /></div>
                {pt.length === 0 ? <EmptyState title="لا توجد باقات تدريب شخصي" /> : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الباقة</th><th>المدرب</th><th>الجلسات</th><th>تنتهي</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {pt.map((s) => {
                                    const [l, tone] = PT_STATUS[s.status] ?? [s.status, 'gray'];
                                    return (
                                        <tr key={s.id}>
                                            <td>{s.package}<span className="block text-xs text-muted num">{money(s.price, currency)}</span></td>
                                            <td>{s.trainer ?? '—'}</td>
                                            <td className="num">{s.sessions_used} / {s.sessions_total}</td>
                                            <td>{date(s.expires_on)}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            {can('pt.manage') && (
                <Card>
                    <CardHeader title="بيع باقة تدريب شخصي" subtitle="تُفعّل بعد دفع الفاتورة" />
                    <form onSubmit={submit} className="space-y-4">
                        <Field label="الباقة" error={form.errors.pt_package_id} required>
                            <Select value={form.data.pt_package_id} onChange={(e) => form.setData('pt_package_id', e.target.value)} placeholder="اختر الباقة">
                                {ptPackages.map((p) => <option key={p.id} value={p.id}>{p.name} — {p.sessions_count} جلسات — {money(p.price, currency)}</option>)}
                            </Select>
                        </Field>
                        <Field label="المدرب" error={form.errors.trainer_id}>
                            <Select value={form.data.trainer_id} onChange={(e) => form.setData('trainer_id', e.target.value)} placeholder="مدرب الباقة الافتراضي">
                                {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </Select>
                        </Field>
                        <Button type="submit" className="w-full" loading={form.processing} disabled={!form.data.pt_package_id}>إصدار الفاتورة</Button>
                    </form>
                </Card>
            )}
        </div>
    );
}

/* ───────────────────────── documents ───────────────────────── */

function DocumentsTab({ documents, member }: Props) {
    const form = useForm({ type: 'contract', title: '', expires_on: '', file: null as File | null });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.members.documents.store', member.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Card padded={false} className="lg:col-span-2">
                <div className="p-5 pb-0"><CardHeader title="المستندات والعقود" subtitle="محفوظة بشكل خاص ولا تُعرض إلا لمن لديه الصلاحية" /></div>
                {documents.length === 0 ? <EmptyState icon={<FileText className="size-6" />} title="لا مستندات" /> : (
                    <ul className="divide-y divide-line">
                        {documents.map((d) => (
                            <li key={d.id} className="flex items-center gap-3 px-5 py-3">
                                <span className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[#f2f2f0] text-ink"><FileText className="size-5" /></span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{d.title}</p>
                                    <p className="text-xs text-muted">
                                        {DOC_TYPES[d.type] ?? d.type} · {kb(d.size)} · {date(d.created_at)}{d.uploaded_by ? ` · ${d.uploaded_by}` : ''}
                                        {d.expires_on && <> · ينتهي {date(d.expires_on)}</>}
                                    </p>
                                </div>
                                <a href={route('club.documents.download', d.id)} className="grid size-9 place-items-center rounded-lg text-muted hover:bg-brand-50 hover:text-brand" title="تنزيل"><Download className="size-4" /></a>
                                <ConfirmAction url={route('club.documents.destroy', d.id)} method="delete" title="حذف المستند" message={`حذف «${d.title}» نهائيًا؟`} confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-4" />}>{''}</ConfirmAction>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Card>
                <CardHeader title="رفع مستند" />
                <form onSubmit={submit} className="space-y-4">
                    <Field label="النوع" error={form.errors.type}>
                        <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            {Object.entries(DOC_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <Field label="العنوان" error={form.errors.title} required>
                        <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="عقد اشتراك 2026" />
                    </Field>
                    <Field label="تاريخ الانتهاء (اختياري)" error={form.errors.expires_on}>
                        <Input type="date" value={form.data.expires_on} onChange={(e) => form.setData('expires_on', e.target.value)} />
                    </Field>
                    <Field label="الملف" error={form.errors.file} hint="PDF أو صورة أو Word — حتى 10MB" required>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" className="block w-full text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                    </Field>
                    {form.progress && <div className="h-1.5 overflow-hidden rounded-full bg-brand-50"><div className="h-full bg-brand" style={{ width: `${form.progress.percentage ?? 0}%` }} /></div>}
                    <Button type="submit" className="w-full" loading={form.processing} disabled={!form.data.file || !form.data.title}>رفع</Button>
                </form>
            </Card>
        </div>
    );
}

/* ───────────────────────── CRM ───────────────────────── */

function CrmTab({ notes, member }: Props) {
    const can = useCan();
    const form = useForm({ channel: 'call', direction: 'outbound', body: '', follow_up_on: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.members.notes.store', member.id), { preserveScroll: true, onSuccess: () => form.reset('body', 'follow_up_on') });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Card className="lg:col-span-2">
                <CardHeader title="سجل التواصل" subtitle="المكالمات والزيارات والمتابعات" />
                {notes.length === 0 ? <EmptyState title="لا يوجد تواصل مسجل" /> : (
                    <ul className="space-y-3">
                        {notes.map((n) => (
                            <li key={n.id} className="rounded-xl border border-line p-4">
                                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
                                    <Badge tone="brand">{CHANNELS[n.channel] ?? n.channel}</Badge>
                                    <Badge tone="gray">{DIRECTIONS[n.direction] ?? n.direction}</Badge>
                                    <span className="text-muted">{n.at}{n.user ? ` · ${n.user}` : ''}</span>
                                    {n.follow_up_on && <Badge tone="amber">متابعة {date(n.follow_up_on)}</Badge>}
                                    {can('members.manage') && (
                                        <span className="ms-auto">
                                            <ConfirmAction url={route('club.notes.destroy', n.id)} method="delete" title="حذف الملاحظة" confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-3.5" />}>{''}</ConfirmAction>
                                        </span>
                                    )}
                                </div>
                                <p className="whitespace-pre-line text-sm">{n.body}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            {can('members.manage') && (
                <Card>
                    <CardHeader title="تسجيل تواصل" />
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="القناة" error={form.errors.channel}>
                                <Select value={form.data.channel} onChange={(e) => form.setData('channel', e.target.value)}>
                                    {Object.entries(CHANNELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </Select>
                            </Field>
                            <Field label="الاتجاه" error={form.errors.direction}>
                                <Select value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                                    {Object.entries(DIRECTIONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </Select>
                            </Field>
                        </div>
                        <Field label="التفاصيل" error={form.errors.body} required>
                            <Textarea rows={4} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} placeholder="اتصلتُ به بخصوص التجديد…" />
                        </Field>
                        <Field label="موعد المتابعة" error={form.errors.follow_up_on}>
                            <Input type="date" value={form.data.follow_up_on} onChange={(e) => form.setData('follow_up_on', e.target.value)} />
                        </Field>
                        <Button type="submit" className="w-full" loading={form.processing} icon={<Plus className="size-4" />}>تسجيل</Button>
                    </form>
                </Card>
            )}
        </div>
    );
}

/* ───────────────────────── WhatsApp ───────────────────────── */

function WhatsAppTab({ messages, templates, member }: Props) {
    const can = useCan();
    const form = useForm({ template_key: '' });
    const selected = templates.find((t) => t.key === form.data.template_key);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.members.whatsapp', member.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Card className="lg:col-span-2">
                <CardHeader title="رسائل واتساب" subtitle="الرسائل الآلية واليدوية والواردة" actions={can('whatsapp.inbox') ? <Link href={route('club.inbox.index', { q: member.phone })} className="text-sm text-brand">فتح في صندوق المحادثات</Link> : undefined} />
                {messages.length === 0 ? <EmptyState icon={<MessageCircle className="size-6" />} title="لا رسائل بعد" /> : (
                    <ul className="space-y-3">
                        {messages.map((m) => {
                            const [l, tone] = label(messageStatus, m.status);
                            const inbound = m.direction === 'inbound';
                            return (
                                <li key={m.id} className={`flex ${inbound ? 'justify-start' : 'justify-end'}`}>
                                    <div className={`max-w-[85%] rounded-2xl px-4 py-3 text-sm ${inbound ? 'rounded-ss-sm bg-canvas' : 'rounded-se-sm bg-brand-50'}`}>
                                        <p className="whitespace-pre-line">{m.body ?? `[${m.template_key ?? m.type}]`}</p>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-muted">
                                            <span className="num">{m.at}</span>
                                            {!inbound && <Badge tone={tone} className="px-1.5 py-0 text-[10px]">{l}</Badge>}
                                            {m.error_code && <span className="text-red-600">{m.error_code}</span>}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>
            {can('whatsapp.send') && (
                <Card>
                    <CardHeader title="إرسال قالب معتمد" subtitle="القوالب المعتمدة من واتساب فقط" />
                    {templates.length === 0 ? (
                        <p className="text-sm text-muted">لا توجد قوالب معتمدة. أضفها من «واتساب ← القوالب».</p>
                    ) : (
                        <form onSubmit={submit} className="space-y-4">
                            <Field label="القالب" error={form.errors.template_key}>
                                <Select value={form.data.template_key} onChange={(e) => form.setData('template_key', e.target.value)} placeholder="اختر القالب">
                                    {templates.map((t) => <option key={t.key} value={t.key}>{t.name}</option>)}
                                </Select>
                            </Field>
                            {selected && <p className="whitespace-pre-line rounded-xl bg-canvas p-3 text-xs leading-6 text-ink/80">{selected.body}</p>}
                            <Button type="submit" className="w-full" loading={form.processing} disabled={!form.data.template_key} icon={<Send className="size-4" />}>إرسال</Button>
                        </form>
                    )}
                    {!member.whatsapp_opt_in && <p className="mt-3 text-xs text-amber-700">العضو غير موافق على رسائل واتساب؛ لن تُرسل الرسائل.</p>}
                </Card>
            )}
        </div>
    );
}

/* ───────────────────────── access credentials ───────────────────────── */

function AccessTab({ credentials, member }: Props) {
    const form = useForm({ type: 'card', identifier: '', label: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.members.credentials.store', member.id), { preserveScroll: true, onSuccess: () => form.reset('identifier', 'label') });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <Card padded={false} className="lg:col-span-2">
                <div className="p-5 pb-0">
                    <CardHeader title="وسائل الدخول" subtitle="ما تقرؤه أجهزة البوابة: بطاقة، بصمة (رقم المستخدم المسجل في الجهاز)، رمز. رمز QR يُدار من زر QR أعلى الصفحة." />
                </div>
                {credentials.length === 0 ? <EmptyState icon={<Fingerprint className="size-6" />} title="لا توجد وسائل دخول مرتبطة" description="الدخول بالـ QR يعمل دون أي ربط إضافي." /> : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>النوع</th><th>المعرّف</th><th>الوصف</th><th>الحالة</th><th /></tr></thead>
                            <tbody>
                                {credentials.map((c) => (
                                    <tr key={c.id}>
                                        <td>{CREDENTIAL_TYPES[c.type] ?? c.type}</td>
                                        <td className="num">{c.identifier}</td>
                                        <td>{c.label ?? '—'}</td>
                                        <td><Badge tone={c.is_active ? 'green' : 'gray'}>{c.is_active ? 'فعّالة' : 'موقوفة'}</Badge></td>
                                        <td className="text-end">
                                            <ConfirmAction url={route('club.credentials.destroy', c.id)} method="delete" title="حذف وسيلة الدخول" confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-4" />}>{''}</ConfirmAction>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            <Card>
                <CardHeader title="ربط وسيلة دخول" />
                <form onSubmit={submit} className="space-y-4">
                    <Field label="النوع" error={form.errors.type}>
                        <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            {Object.entries(CREDENTIAL_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Select>
                    </Field>
                    <Field label="المعرّف" error={form.errors.identifier} required hint="رقم البطاقة أو رقم المستخدم في جهاز البصمة">
                        <Input dir="ltr" value={form.data.identifier} onChange={(e) => form.setData('identifier', e.target.value)} />
                    </Field>
                    <Field label="وصف (اختياري)" error={form.errors.label}>
                        <Input value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="بطاقة رقم 2" />
                    </Field>
                    <Button type="submit" className="w-full" loading={form.processing} disabled={!form.data.identifier}>ربط</Button>
                </form>
                <p className="mt-4 text-xs text-muted">الدخول يُسمح تلقائيًا ما دامت العضوية سارية ومدفوعة، ويُمنع عند انتهائها أو تجميدها — دون الحاجة لتعديل الجهاز.</p>
            </Card>
        </div>
    );
}
