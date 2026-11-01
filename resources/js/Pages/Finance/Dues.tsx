import { Link, router } from '@inertiajs/react';
import { AlertTriangle, BellRing, CalendarClock, CircleAlert, HandCoins, Hourglass, MessageCircle } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, CardHeader, Checkbox, ConfirmAction, EmptyState, Notice, PageHeader, StatCard } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { invoiceStatus, invoiceType, label, messageStatus } from '@/lib/labels';

interface DueRow {
    id: number;
    number: string;
    type: string;
    status: string;
    customer_name: string;
    customer_phone: string | null;
    total: string;
    paid_total: string;
    balance: string;
    issue_date: string;
    due_date: string | null;
    billable_type: string | null;
    billable_id: number | null;
    days_overdue: number;
}

interface Props {
    invoices: DueRow[];
    aging: { current: number; '1_30': number; '31_60': number; '60_plus': number };
    total: number;
    filters: { overdue: boolean };
    reminders: {
        enabled: boolean;
        whatsapp: boolean;
        log: { id: number; at: string; member: string | null; invoice_id: number | null; invoice: string | null; kind: string; status: string; reason: string | null; by_hand: boolean }[];
    };
}

/** Collection follow-up (spec 1.5): outstanding balances and arrears by age. */
export default function Dues({ invoices, aging, total, filters, reminders }: Props) {
    const currency = useCurrency();
    const can = useCan();
    const mayRemind = can('whatsapp.send');

    return (
        <ClubLayout title="المستحقات">
            <PageHeader title="المستحقات والمتأخرات" subtitle={`إجمالي غير المحصّل: ${money(total, currency)}`} />

            <div className="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="غير مستحقة بعد" value={money(aging.current, currency)} icon={<CalendarClock />} tone="blue" />
                <StatCard label="متأخرة 1–30 يومًا" value={money(aging['1_30'], currency)} icon={<Hourglass />} tone="amber" />
                <StatCard label="متأخرة 31–60 يومًا" value={money(aging['31_60'], currency)} icon={<AlertTriangle />} tone="red" />
                <StatCard label="أكثر من 60 يومًا" value={money(aging['60_plus'], currency)} icon={<CircleAlert />} tone="red" />
            </div>

            <Notice tone={reminders.enabled && reminders.whatsapp ? 'green' : 'gray'} icon={<BellRing />} className="mt-6" href={can('settings.manage') ? route('club.settings.notifications') : undefined}>
                {reminders.enabled && reminders.whatsapp
                    ? 'تذكيرات السداد الآلية مفعّلة: تُرسل عبر واتساب قبل الاستحقاق وبعده كما في قواعد الإشعارات، مرة لكل مرحلة، وبحد أقصى لكل فاتورة.'
                    : reminders.enabled
                        ? 'تذكيرات السداد مفعّلة في الإعدادات، لكن واتساب غير مربوط بعد؛ لن يُرسل شيء حتى يكتمل الربط واعتماد القوالب.'
                        : 'تذكيرات السداد الآلية متوقفة. تُفعَّل من قواعد الإشعارات، ولا تُرسل إلا بعد ربط واتساب واعتماد قالبي التذكير من Meta وموافقة العضو.'}
            </Notice>

            <Card className="mt-4" padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <p className="text-sm text-muted"><span className="num">{invoices.length}</span> فاتورة مفتوحة</p>
                    <Checkbox
                        label="المتأخرة فقط"
                        checked={filters.overdue}
                        onChange={(e) => router.get(route('club.dues'), e.target.checked ? { overdue: 1 } : {}, { preserveState: true, replace: true })}
                    />
                </div>
                {invoices.length === 0 ? (
                    <EmptyState icon={<HandCoins className="size-6" />} title="لا توجد مستحقات" description="كل الفواتير مسددة." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الفاتورة</th><th>العميل</th><th>النوع</th><th>الاستحقاق</th><th>التأخير</th><th>الإجمالي</th><th>المتبقي</th><th>الحالة</th>{mayRemind && <th />}</tr>
                            </thead>
                            <tbody>
                                {invoices.map((inv) => {
                                    const [statusLabel, tone] = label(invoiceStatus, inv.status);
                                    return (
                                        <tr key={inv.id}>
                                            <td><Link href={route('club.invoices.show', inv.id)} className="font-medium text-brand num hover:underline">{inv.number}</Link></td>
                                            <td>
                                                {inv.billable_type === 'member' && inv.billable_id ? (
                                                    <Link href={route('club.members.show', inv.billable_id)} className="font-medium hover:text-brand">{inv.customer_name}</Link>
                                                ) : (
                                                    <span className="font-medium">{inv.customer_name}</span>
                                                )}
                                                {inv.customer_phone && <p className="text-xs text-muted num">{inv.customer_phone}</p>}
                                            </td>
                                            <td className="text-muted">{invoiceType[inv.type] ?? inv.type}</td>
                                            <td>{date(inv.due_date)}</td>
                                            <td>
                                                {inv.days_overdue > 0 ? (
                                                    <Badge tone={inv.days_overdue > 30 ? 'red' : 'amber'}><span className="num">{inv.days_overdue}</span> يوم</Badge>
                                                ) : (
                                                    <span className="text-xs text-muted">—</span>
                                                )}
                                            </td>
                                            <td className="num">{money(inv.total, currency)}</td>
                                            <td className="font-semibold text-red-600 num">{money(inv.balance, currency)}</td>
                                            <td><Badge tone={tone}>{statusLabel}</Badge></td>
                                            {mayRemind && (
                                                <td className="text-end">
                                                    {inv.billable_type === 'member' && (
                                                        <ConfirmAction url={route('club.dues.remind', inv.id)} title="تذكير بالسداد" message={`يُرسل للعضو تذكير بالفاتورة ${inv.number} عبر واتساب بالقالب المعتمد، مرة واحدة في اليوم على الأكثر.`} confirmLabel="إرسال" tone="primary" variant="ghost" icon={<MessageCircle className="size-3.5" />}>تذكير</ConfirmAction>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card className="mt-4" padded={false}>
                <div className="p-4 pb-0"><CardHeader title="سجل التذكيرات" subtitle="آخر محاولات الإرسال وحالتها؛ ما لم يُرسل يذكر سببه." /></div>
                {reminders.log.length === 0 ? (
                    <EmptyState icon={<BellRing className="size-6" />} title="لا تذكيرات بعد" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>العضو</th><th>الفاتورة</th><th>التذكير</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {reminders.log.map((r) => {
                                    const [text, tone] = label(messageStatus, r.status);
                                    return (
                                        <tr key={r.id}>
                                            <td className="text-xs text-muted num">{r.at}</td>
                                            <td>{r.member ?? '—'}</td>
                                            <td>{r.invoice_id ? <Link href={route('club.invoices.show', r.invoice_id)} className="text-[#1d4ed8] num hover:underline">{r.invoice}</Link> : '—'}</td>
                                            <td className="text-[13px]">{r.kind}{r.by_hand && <span className="text-muted"> · يدوي</span>}</td>
                                            <td><Badge tone={tone}>{text}</Badge>{r.reason && <p className="mt-0.5 text-xs text-muted">{r.reason}</p>}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
