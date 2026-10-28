import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarCheck, Info, X } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { Badge, Button, Modal } from '@/Components/ui';
import { dayName, time } from '@/lib/format';
import { bookingStatus, label } from '@/lib/labels';
import { DayChips, groupByDay, SessionLine, type SessionData } from '../Site/Schedule';
import type { MembershipData } from './Dashboard';

type PortalSession = SessionData & { booking_id: number | null };

interface Props {
    site: SiteInfo;
    seo: { title: string };
    sessions: PortalSession[];
    membership: MembershipData | null;
    cutoffHours: number;
    history: { id: number; status: string; program: string | null; starts_at: string | null }[];
}

export default function Classes({ site, seo, sessions, membership, cutoffHours, history }: Props) {
    const days = useMemo(() => groupByDay(sessions), [sessions]);
    const [active, setActive] = useState(days[0]?.[0] ?? '');
    const [busy, setBusy] = useState<number | null>(null);
    const [cancelling, setCancelling] = useState<PortalSession | null>(null);
    const current = days.find(([d]) => d === active)?.[1] ?? [];

    const book = (s: PortalSession) => {
        setBusy(s.id);
        router.post(route('portal.classes.book', { session: s.id }), {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const cancel = () => {
        if (!cancelling?.booking_id) return;
        setBusy(cancelling.id);
        router.post(route('portal.bookings.cancel', { booking: cancelling.booking_id }), {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(null);
                setCancelling(null);
            },
        });
    };

    const canCancel = (s: PortalSession) => new Date(s.starts_at.replace(' ', 'T')).getTime() - Date.now() > cutoffHours * 3600 * 1000;

    return (
        <PortalLayout site={site} title={seo.title}>
            <div className="mb-6 grid gap-3 sm:grid-cols-2">
                <div className="rounded-2xl border border-line bg-white p-4 text-sm">
                    {membership ? (
                        <p>
                            باقتك: <span className="font-medium">{membership.plan}</span> · الحصص المتبقية{' '}
                            <span className="font-display text-base font-semibold text-brand num">{membership.sessions_left ?? '∞'}</span>
                        </p>
                    ) : (
                        <p className="text-amber-700">لا توجد عضوية نشطة حاليًا — الحجز يتطلب عضوية سارية بتاريخ الحصة.</p>
                    )}
                </div>
                <p className="flex items-start gap-2 rounded-2xl bg-brand-50/70 p-4 text-sm text-brand-900">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>يمكنك إلغاء الحجز قبل الحصة بـ <span className="num font-semibold">{cutoffHours}</span> ساعات على الأقل لاستعادة الحصة.</span>
                </p>
            </div>

            {days.length === 0 ? (
                <p className="rounded-2xl border border-line bg-white p-10 text-center text-sm text-muted">لا توجد حصص متاحة للحجز حاليًا.</p>
            ) : (
                <>
                    <DayChips days={days.map(([d]) => d)} value={active} onChange={setActive} />
                    <h2 className="mt-6 mb-3 font-display text-lg font-semibold">{dayName(active)}</h2>
                    <div className="space-y-3">
                        {current.map((s) => (
                            <SessionLine
                                key={s.id}
                                session={s}
                                action={
                                    s.booking_id ? (
                                        <span className="flex items-center gap-2">
                                            <Badge tone="green"><CalendarCheck className="size-3.5" /> محجوزة</Badge>
                                            {canCancel(s) && (
                                                <button onClick={() => setCancelling(s)} className="grid size-8 place-items-center rounded-full border border-line text-muted hover:bg-red-50 hover:text-red-600" title="إلغاء الحجز">
                                                    <X className="size-4" />
                                                </button>
                                            )}
                                        </span>
                                    ) : s.is_full ? (
                                        <span className="rounded-full border border-line px-4 py-2 text-xs text-muted">مكتملة</span>
                                    ) : (
                                        <Button size="sm" className="rounded-full" loading={busy === s.id} onClick={() => book(s)}>احجزي</Button>
                                    )
                                }
                            />
                        ))}
                    </div>
                </>
            )}

            {history.length > 0 && (
                <section className="mt-10">
                    <h2 className="mb-3 font-display text-lg font-semibold">سجل حجوزاتي</h2>
                    <ul className="divide-y divide-line rounded-2xl border border-line bg-white">
                        {history.map((b) => {
                            const [statusLabel, tone] = label(bookingStatus, b.status);
                            return (
                                <li key={b.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <span>
                                        <span className="font-medium">{b.program}</span>
                                        {b.starts_at && <span className="text-muted"> · {dayName(b.starts_at)} {time(b.starts_at)}</span>}
                                    </span>
                                    <Badge tone={tone}>{statusLabel}</Badge>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}

            <Modal
                open={cancelling !== null}
                onClose={() => setCancelling(null)}
                title="إلغاء الحجز"
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setCancelling(null)}>تراجع</Button>
                        <Button variant="danger" loading={busy !== null} onClick={cancel}>إلغاء الحجز</Button>
                    </>
                }
            >
                {cancelling && (
                    <p className="text-sm text-muted">
                        هل تريدين إلغاء حجز <span className="font-medium text-ink">{cancelling.program}</span> يوم {dayName(cancelling.date)} الساعة {time(cancelling.time)}؟ ستُعاد الحصة إلى رصيدك.
                    </p>
                )}
            </Modal>
        </PortalLayout>
    );
}
