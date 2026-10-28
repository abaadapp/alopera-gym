import { Webhook } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Card, EmptyState, PageHeader, Pagination } from '@/Components/ui';
import type { Paginated } from '@/types';

interface Event {
    id: number;
    tenant_id: number | null;
    provider: string;
    transaction_id: string | null;
    reference: string | null;
    signature_valid: boolean;
    outcome: string;
    ip_address: string | null;
    created_at: string;
}

const OUTCOMES: Record<string, [string, 'green' | 'gray' | 'red' | 'amber']> = {
    processed: ['سُجّلت الدفعة', 'green'],
    duplicate: ['مكرر (تم تجاهله)', 'gray'],
    rejected_signature: ['توقيع غير صالح', 'red'],
    unknown_intent: ['عملية غير معروفة', 'amber'],
    amount_mismatch: ['مبلغ غير مطابق', 'red'],
    failed_payment: ['دفع فاشل', 'amber'],
};

export default function Webhooks({ events }: { events: Paginated<Event> }) {
    return (
        <PlatformLayout title="سجل بوابات الدفع">
            <PageHeader title="سجل إشعارات بوابات الدفع" subtitle="كل إشعار وصل من Paymob — صالحًا أو مرفوضًا — للتحقيق في أي دفعة" />
            <Card padded={false}>
                {events.data.length === 0 ? (
                    <EmptyState icon={<Webhook className="size-6" />} title="لم تصل إشعارات بعد" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>البوابة</th><th>النادي</th><th>رقم العملية</th><th>المرجع</th><th>التوقيع</th><th>النتيجة</th><th>IP</th></tr></thead>
                            <tbody>
                                {events.data.map((e) => {
                                    const [l, t] = OUTCOMES[e.outcome] ?? [e.outcome, 'gray' as const];
                                    return (
                                        <tr key={e.id}>
                                            <td className="whitespace-nowrap text-xs text-muted num">{e.created_at}</td>
                                            <td className="text-xs" dir="ltr">{e.provider}</td>
                                            <td className="num">{e.tenant_id ?? '—'}</td>
                                            <td className="text-xs" dir="ltr">{e.transaction_id ?? '—'}</td>
                                            <td className="text-xs" dir="ltr">{e.reference ?? '—'}</td>
                                            <td>{e.signature_valid ? <Badge tone="green">صالح</Badge> : <Badge tone="red">غير صالح</Badge>}</td>
                                            <td><Badge tone={t}>{l}</Badge></td>
                                            <td className="text-xs text-muted num">{e.ip_address ?? '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={events} />
            </Card>
        </PlatformLayout>
    );
}
