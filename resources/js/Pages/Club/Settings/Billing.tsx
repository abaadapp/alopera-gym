import { CalendarRange, Layers } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, Card, CardHeader, EmptyState, PageHeader, Stat } from '@/Components/ui';
import { date, money, number } from '@/lib/format';
import { label, tenantStatus } from '@/lib/labels';

interface Props {
    subscription: { plan: string | null; cycle: string; status: string; price: string; starts_on: string; ends_on: string } | null;
    usage: Record<'members' | 'staff' | 'branches', { used: number; max: number | null }>;
    invoices: { id: number; number: string; status: string; amount: string; currency: string; period: string; due_on: string }[];
    status: string;
}

const SUB_STATUS: Record<string, [string, 'blue' | 'green' | 'amber' | 'gray' | 'red']> = {
    trialing: ['فترة تجريبية', 'blue'],
    active: ['نشط', 'green'],
    past_due: ['بانتظار السداد', 'amber'],
    cancelled: ['ملغى', 'gray'],
    expired: ['منتهٍ', 'red'],
};

const INV_STATUS: Record<string, [string, 'amber' | 'green' | 'gray']> = { unpaid: ['غير مدفوعة', 'amber'], paid: ['مدفوعة', 'green'], void: ['ملغاة', 'gray'] };

const USAGE_LABELS = { members: 'المشتركات', staff: 'المستخدمات', branches: 'الفروع' } as const;

export default function Billing({ subscription, usage, invoices, status }: Props) {
    const [tLabel, tTone] = label(tenantStatus, status);

    return (
        <ClubLayout title="اشتراك المنصة">
            <PageHeader back={status !== 'suspended' ? <SettingsBack /> : undefined} title="اشتراك النادي في المنصة" subtitle="منفصل تمامًا عن اشتراكات المشتركات داخل النادي" actions={<Badge tone={tTone}>حالة الحساب: {tLabel}</Badge>} />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title={<span className="flex items-center gap-2"><Layers className="size-4" /> الباقة الحالية</span>} />
                    {subscription ? (
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <Stat label="الباقة" value={<span className="text-base font-semibold">{subscription.plan}</span>} />
                            <Stat label="الحالة" value={<Badge tone={(SUB_STATUS[subscription.status] ?? [, 'gray'])[1] as 'gray'}>{(SUB_STATUS[subscription.status] ?? [subscription.status])[0]}</Badge>} />
                            <Stat label="دورة الفوترة" value={subscription.cycle === 'yearly' ? 'سنوي' : 'شهري'} />
                            <Stat label="السعر" value={<span className="num">{money(subscription.price, 'OMR')}</span>} />
                            <Stat label="من" value={date(subscription.starts_on)} />
                            <Stat label="حتى" value={date(subscription.ends_on)} />
                        </dl>
                    ) : (
                        <EmptyState title="لا يوجد اشتراك فعّال" description="تواصلي مع إدارة المنصة لتفعيل الاشتراك." />
                    )}
                    <p className="mt-5 text-xs text-muted">لتغيير الباقة أو الدورة أو سداد فاتورة، تواصلي مع إدارة المنصة؛ يُسجَّل السداد وتُحدَّث الحالة من لوحة المنصة.</p>
                </Card>

                <Card>
                    <CardHeader title="الاستخدام" />
                    <div className="space-y-4">
                        {(Object.keys(USAGE_LABELS) as (keyof typeof USAGE_LABELS)[]).map((k) => {
                            const u = usage[k];
                            const pct = u.max ? Math.min(100, (u.used / u.max) * 100) : 0;
                            return (
                                <div key={k}>
                                    <div className="mb-1 flex justify-between text-sm">
                                        <span>{USAGE_LABELS[k]}</span>
                                        <span className="num text-muted">{number(u.used)} / {u.max ? number(u.max) : '∞'}</span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-brand-50">
                                        <div className={`h-full rounded-full ${pct >= 90 ? 'bg-red-500' : 'bg-brand'}`} style={{ width: `${u.max ? pct : 6}%` }} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </div>

            <Card padded={false} className="mt-6">
                <div className="p-5 pb-0">
                    <CardHeader title={<span className="flex items-center gap-2"><CalendarRange className="size-4" /> فواتير المنصة</span>} />
                </div>
                {invoices.length === 0 ? (
                    <EmptyState title="لا توجد فواتير" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الرقم</th><th>الفترة</th><th>المبلغ</th><th>الاستحقاق</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {invoices.map((i) => {
                                    const [l, t] = INV_STATUS[i.status] ?? [i.status, 'gray' as const];
                                    return (
                                        <tr key={i.id}>
                                            <td className="num">{i.number}</td>
                                            <td className="text-xs num">{i.period}</td>
                                            <td className="num">{money(i.amount, i.currency)}</td>
                                            <td>{date(i.due_on)}</td>
                                            <td><Badge tone={t}>{l}</Badge></td>
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
