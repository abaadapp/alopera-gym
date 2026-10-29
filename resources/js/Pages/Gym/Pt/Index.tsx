import { useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { CalendarPlus, Package, UserCog, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PtCloseActions from '@/Components/PtCloseActions';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Pagination, Select, Tabs, Textarea } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, dayName, money, time } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Subscription = {
    id: number;
    status: string;
    sessions_total: number;
    sessions_used: number;
    price: string;
    trainer_id: number | null;
    invoice_id: number | null;
    member: { id: number; full_name: string; member_no: string } | null;
    package: string | null;
    trainer: string | null;
    expires_on: string | null;
    remaining: number;
};

type Upcoming = { id: number; member: string | null; trainer: string | null; starts_at: string; ends_at: string; status: string; started: boolean };

const STATUS: Record<string, [string, 'amber' | 'green' | 'gray' | 'red']> = {
    pending: ['بانتظار الدفع', 'amber'],
    active: ['نشطة', 'green'],
    completed: ['مكتملة', 'gray'],
    expired: ['منتهية', 'gray'],
    cancelled: ['ملغاة', 'red'],
};

const TABS = [
    { key: 'active', label: 'نشطة' },
    { key: 'pending', label: 'بانتظار الدفع' },
    { key: 'completed', label: 'مكتملة' },
    { key: 'expired', label: 'منتهية' },
    { key: 'all', label: 'الكل' },
];

export default function PtIndex({ subscriptions, upcoming, trainers, filters }: { subscriptions: Paginated<Subscription>; upcoming: Upcoming[]; trainers: Option[]; filters: { status?: string } }) {
    const can = useCan();
    const currency = useCurrency();
    const [target, setTarget] = useState<Subscription | null>(null);
    const form = useForm({ starts_at: '', trainer_id: '' as number | string, notes: '' });

    const openSchedule = (s: Subscription) => {
        const d = new Date(Date.now() + 24 * 3600 * 1000);
        form.clearErrors();
        form.setData({ starts_at: `${d.toLocaleDateString('en-CA')}T10:00`, trainer_id: s.trainer_id ?? '', notes: '' });
        setTarget(s);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!target) return;
        form.transform((d) => ({ ...d, trainer_id: d.trainer_id || null, starts_at: d.starts_at.replace('T', ' ') }));
        form.post(route('club.pt.schedule', target.id), { preserveScroll: true, onSuccess: () => setTarget(null) });
    };

    const grouped = upcoming.reduce<Record<string, Upcoming[]>>((acc, s) => {
        (acc[s.starts_at.slice(0, 10)] ??= []).push(s);
        return acc;
    }, {});

    return (
        <ClubLayout title="التدريب الشخصي">
            <PageHeader
                title="التدريب الشخصي (PT)"
                subtitle="باقات الجلسات الفردية وجدولة مواعيدها مع المدربين"
                actions={<Button variant="outline" href={route('club.pt-packages.index')} icon={<Package className="size-4" />}>الباقات</Button>}
            />

            <div className="grid gap-6 xl:grid-cols-3">
                <Card className="xl:col-span-2" padded={false}>
                    <div className="px-4 pt-3">
                        <Tabs tabs={TABS} value={filters.status || 'active'} onChange={(key) => router.get(route('club.pt.index'), { status: key }, { preserveState: true })} className="border-0" />
                    </div>
                    {subscriptions.data.length === 0 ? (
                        <EmptyState icon={<UserCog className="size-6" />} title="لا توجد اشتراكات" description="يُباع التدريب الشخصي من ملف العضو (تبويب التدريب الشخصي)، ويُفعّل بعد الدفع." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead>
                                    <tr><th>العضو</th><th>الباقة</th><th>المدرب</th><th>الجلسات</th><th>تنتهي</th><th>الحالة</th><th /></tr>
                                </thead>
                                <tbody>
                                    {subscriptions.data.map((s) => {
                                        const [sl, st] = STATUS[s.status] ?? [s.status, 'gray'];
                                        return (
                                            <tr key={s.id}>
                                                <td>
                                                    {s.member ? <Link href={route('club.members.show', s.member.id)} className="font-medium hover:text-brand">{s.member.full_name}</Link> : '—'}
                                                    <p className="text-xs text-muted num">{s.member?.member_no}</p>
                                                </td>
                                                <td>
                                                    {s.package}
                                                    <p className="text-xs text-muted">{money(s.price, currency)}</p>
                                                </td>
                                                <td>{s.trainer ?? '—'}</td>
                                                <td>
                                                    <span className="num">{s.sessions_used}/{s.sessions_total}</span>
                                                    <div className="mt-1 h-1.5 w-20 overflow-hidden rounded-full bg-brand-50">
                                                        <div className="h-full rounded-full bg-brand" style={{ width: `${(s.sessions_used / Math.max(1, s.sessions_total)) * 100}%` }} />
                                                    </div>
                                                </td>
                                                <td>{date(s.expires_on)}</td>
                                                <td><Badge tone={st}>{sl}</Badge></td>
                                                <td className="text-end">
                                                    {s.status === 'active' && s.remaining > 0 && (
                                                        <Button size="sm" variant="secondary" icon={<CalendarPlus className="size-3.5" />} onClick={() => openSchedule(s)}>جدولة جلسة</Button>
                                                    )}
                                                    {s.status === 'pending' && s.invoice_id && can('invoices.view') && (
                                                        <Button size="sm" variant="outline" href={route('club.invoices.show', s.invoice_id)}>الفاتورة</Button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination data={subscriptions} />
                </Card>

                <Card>
                    <CardHeader title="الجلسات القادمة" subtitle="سجّل إتمام الجلسة أو الغياب (كلاهما يُستهلك من الباقة)" />
                    {upcoming.length === 0 ? (
                        <EmptyState title="لا جلسات مجدولة" />
                    ) : (
                        <div className="space-y-4">
                            {Object.entries(grouped).map(([day, items]) => (
                                <div key={day}>
                                    <p className="mb-1.5 text-xs font-semibold text-muted">{dayName(day)}</p>
                                    <ul className="space-y-2">
                                        {items.map((s) => (
                                            <li key={s.id} className="rounded-xl border border-line p-3">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">{s.member}</p>
                                                        <p className="text-xs text-muted">{time(s.starts_at)} – {time(s.ends_at)} · {s.trainer}</p>
                                                    </div>
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                    <PtCloseActions id={s.id} started={s.started} />
                                                    <ConfirmAction url={route('club.pt.close', s.id)} data={{ status: 'cancelled' }} title="إلغاء الجلسة" message="الجلسة الملغاة لا تُستهلك من الباقة." confirmLabel="إلغاء الجلسة" variant="ghost" icon={<X className="size-3.5" />}>
                                                        إلغاء
                                                    </ConfirmAction>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>

            <Modal
                open={target !== null}
                onClose={() => setTarget(null)}
                title={`جدولة جلسة — ${target?.member?.full_name ?? ''}`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setTarget(null)}>إلغاء</Button>
                        <Button type="submit" form="pt-form" loading={form.processing}>جدولة</Button>
                    </>
                }
            >
                {target && (
                    <p className="mb-4 rounded-xl bg-canvas p-3 text-sm text-muted">
                        {target.package} · متبقي <span className="num font-medium text-ink">{target.remaining}</span> جلسة · تنتهي {date(target.expires_on)}
                    </p>
                )}
                <form id="pt-form" onSubmit={submit} className="space-y-4">
                    <Field label="الموعد" error={form.errors.starts_at} hint="يجب أن يكون ضمن أوقات توفر المدرب ودون تعارض">
                        <Input type="datetime-local" dir="ltr" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="المدرب" error={form.errors.trainer_id}>
                        <Select value={form.data.trainer_id} onChange={(e) => form.setData('trainer_id', e.target.value)} placeholder="مدرب الباقة">
                            {trainers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="ملاحظات" error={form.errors.notes}>
                        <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} />
                    </Field>
                </form>
            </Modal>
        </ClubLayout>
    );
}
