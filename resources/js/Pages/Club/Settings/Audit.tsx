import { Fragment, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, ScrollText } from 'lucide-react';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { SettingsScreen, SettingsTitle } from '@/Components/Settings';
import { Badge, Button, Card, EmptyState, Input, Pagination } from '@/Components/ui';
import type { Paginated } from '@/types';

interface Log {
    id: number;
    actor_type: string | null;
    actor_name: string | null;
    event: string;
    subject_type: string | null;
    subject_id: number | null;
    ip_address: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    properties: Record<string, unknown> | null;
    created_at: string;
}

const ACTORS: Record<string, string> = { user: 'موظف', member: 'عضو', system: 'النظام', gateway: 'بوابة الدفع', device: 'جهاز' };

const QUICK = [
    { key: '', label: 'الكل' },
    { key: 'auth.', label: 'الدخول' },
    { key: 'membership.', label: 'العضويات' },
    { key: 'payment', label: 'الدفعات' },
    { key: 'invoice', label: 'الفواتير' },
    { key: 'user.', label: 'المستخدمون' },
    { key: 'role.', label: 'الأدوار' },
    { key: 'settings.', label: 'الإعدادات' },
    { key: 'accounting.', label: 'المحاسبة والفترات' },
    { key: 'cash_shift.', label: 'الصندوق' },
    { key: 'employee.', label: 'الموظفون والرواتب' },
    { key: 'backup.', label: 'النسخ الاحتياطي' },
];

/** The events read in words; any other shows its key. */
const EVENTS: Record<string, string> = {
    'auth.login': 'تسجيل دخول', 'auth.logout': 'تسجيل خروج', 'auth.failed': 'محاولة دخول فاشلة',
    'settings.club_updated': 'تعديل إعدادات النادي', 'settings.notifications_updated': 'تعديل قواعد الإشعارات', 'settings.finance_updated': 'تعديل الإعدادات المالية',
    'accounting.period_closed': 'إقفال فترة محاسبية', 'accounting.period_reopened': 'إعادة فتح فترة محاسبية', 'accounting.year_closed': 'إقفال سنة مالية', 'accounting.year_reopened': 'إعادة فتح سنة مالية',
    'accounting.account_created': 'إضافة حساب بنكي أو صندوق', 'accounting.account_updated': 'تعديل حساب بنكي أو صندوق', 'accounting.opening_balance_set': 'رصيد افتتاحي',
    'accounting.chart_account_created': 'إضافة حساب في الشجرة', 'accounting.chart_account_updated': 'تعديل حساب في الشجرة',
    'journal.posted': 'قيد يدوي', 'journal.reversed': 'عكس قيد', 'money.transferred': 'تحويل بين الحسابات',
    'payment.refunded': 'رد مبلغ', 'payment.refund_requested': 'طلب رد بانتظار التنفيذ', 'payment.cancelled': 'إلغاء دفعة', 'payment.credit_applied': 'تخصيص رصيد عميل', 'payment.gateway_overpayment': 'دفعة إلكترونية زائدة',
    'cash_shift.opened': 'فتح وردية صندوق', 'cash_shift.moved': 'نقل نقد من الدرج أو إليه', 'cash_shift.closed': 'إقفال وردية صندوق',
    'invoice.credited': 'إشعار دائن', 'report.exported': 'تصدير تقرير',
    'user.created': 'إضافة مستخدم', 'user.updated': 'تعديل مستخدم', 'user.deactivated': 'إيقاف مستخدم', 'user.access_updated': 'تعديل صلاحيات الدخول', 'user.password_reset': 'إعادة تعيين كلمة مرور',
    'employee.created': 'إضافة موظف', 'employee.updated': 'تعديل موظف', 'payroll.approved': 'اعتماد مسيرة رواتب', 'payroll.paid': 'صرف رواتب',
    'role.created': 'إضافة دور', 'role.updated': 'تعديل دور', 'role.deleted': 'حذف دور',
    'tenant_integration.created': 'إضافة تكامل', 'tenant_integration.updated': 'تعديل تكامل',
};

export default function Audit({ logs, filters, period }: { logs: Paginated<Log>; filters: { event?: string; actor?: string }; period: Period }) {
    const [f, setF] = useState({ event: filters.event ?? '', actor: filters.actor ?? '' });
    const [open, setOpen] = useState<number | null>(null);

    const apply = (next = f) => router.get(route('club.audit.index'), Object.fromEntries(Object.entries({ ...next, ...periodParams(period) }).filter(([, v]) => v)), { preserveState: true, replace: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        apply();
    };

    return (
        <SettingsScreen title="سجل العمليات" width="full">
            <SettingsTitle title="سجل العمليات الحساسة" description="من فعل ماذا ومتى — سجل غير قابل للتعديل أو الحذف" />

            <PeriodTabs period={period} params={{ ...filters }} />

            <Card padded={false}>
                <div className="space-y-3 p-4">
                    <div className="flex flex-wrap gap-1.5">
                        {QUICK.map((q) => (
                            <button
                                key={q.key}
                                onClick={() => {
                                    const next = { ...f, event: q.key };
                                    setF(next);
                                    apply(next);
                                }}
                                className={`rounded-full border px-3 py-1 text-xs ${f.event === q.key ? 'border-brand bg-brand text-white' : 'border-line hover:border-brand-300'}`}
                            >
                                {q.label}
                            </button>
                        ))}
                    </div>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                        <Input className="w-44" value={f.event} onChange={(e) => setF({ ...f, event: e.target.value })} placeholder="الحدث (مثل payment.)" dir="ltr" />
                        <Input className="w-44" value={f.actor} onChange={(e) => setF({ ...f, actor: e.target.value })} placeholder="اسم المنفّذ" />
                        <Button type="submit" variant="secondary">تصفية</Button>
                    </form>
                </div>

                {logs.data.length === 0 ? (
                    <EmptyState icon={<ScrollText className="size-6" />} title="لا توجد سجلات" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>المنفّذ</th><th>الحدث</th><th>السجل</th><th>IP</th><th /></tr></thead>
                            <tbody>
                                {logs.data.map((l) => (
                                    <Fragment key={l.id}>
                                        <tr className="cursor-pointer" onClick={() => setOpen(open === l.id ? null : l.id)}>
                                            <td className="whitespace-nowrap text-xs text-muted num">{l.created_at}</td>
                                            <td>
                                                <span className="text-sm">{l.actor_name ?? '—'}</span>
                                                <span className="block text-[11px] text-muted">{ACTORS[l.actor_type ?? ''] ?? l.actor_type}</span>
                                            </td>
                                            <td>
                                                <Badge tone={l.event.includes('deleted') || l.event.includes('refused') || l.event.includes('rejected') || l.event.includes('failed') || l.event.includes('reopened') || l.event.includes('cancelled') ? 'red' : 'gray'}>{EVENTS[l.event] ?? <span dir="ltr">{l.event}</span>}</Badge>
                                                {EVENTS[l.event] && <span className="mt-0.5 block text-[11px] text-muted" dir="ltr">{l.event}</span>}
                                            </td>
                                            <td className="text-xs" dir="ltr">{l.subject_type ? `${l.subject_type} #${l.subject_id}` : '—'}</td>
                                            <td className="text-xs text-muted num">{l.ip_address ?? '—'}</td>
                                            <td><ChevronDown className={`size-4 text-muted transition ${open === l.id ? 'rotate-180' : ''}`} /></td>
                                        </tr>
                                        {open === l.id && (
                                            <tr>
                                                <td colSpan={6} className="bg-canvas">
                                                    <Diff log={l} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={logs} />
            </Card>
        </SettingsScreen>
    );
}

function show(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
}

function Diff({ log }: { log: Log }) {
    const keys = Array.from(new Set([...Object.keys(log.old_values ?? {}), ...Object.keys(log.new_values ?? {})]));
    return (
        <div className="space-y-3 py-2">
            {keys.length > 0 && (
                <table className="w-full text-xs" dir="ltr">
                    <thead><tr className="text-muted"><th className="py-1 text-left">field</th><th className="py-1 text-left">before</th><th className="py-1 text-left">after</th></tr></thead>
                    <tbody>
                        {keys.map((k) => (
                            <tr key={k} className="border-t border-line/60">
                                <td className="py-1 pe-3 font-medium">{k}</td>
                                <td className="max-w-xs break-all py-1 pe-3 text-red-700">{show(log.old_values?.[k])}</td>
                                <td className="max-w-xs break-all py-1 text-emerald-700">{show(log.new_values?.[k])}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {log.properties && <pre className="overflow-x-auto rounded-lg bg-white p-2 text-[11px]" dir="ltr">{JSON.stringify(log.properties, null, 2)}</pre>}
            {keys.length === 0 && !log.properties && <p className="text-xs text-muted">لا توجد تفاصيل إضافية.</p>}
        </div>
    );
}
