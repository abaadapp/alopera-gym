import { Link, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, EmptyState, PageHeader, Pagination, Select } from '@/Components/ui';
import { number } from '@/lib/format';
import { label, messageStatus } from '@/lib/labels';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    direction: string;
    type: string;
    to_phone: string | null;
    template_key: string | null;
    template_label: string | null;
    body: string | null;
    status: string;
    error_code: string | null;
    error_message: string | null;
    campaign_id: number | null;
    member: string | null;
    member_id: number | null;
    at: string;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
}

interface Props {
    messages: Paginated<Row>;
    stats: Record<string, number>;
    filters: { status?: string; direction?: string; template?: string };
}

const skipReasons: Record<string, string> = {
    template_missing: 'لا يوجد قالب لهذا الحدث',
    template_not_approved: 'القالب غير معتمد',
    opted_out: 'العضو أوقف رسائل واتساب',
    no_marketing_consent: 'لا توجد موافقة تسويقية',
    no_phone: 'لا يوجد رقم',
    not_configured: 'واتساب غير مفعّل',
    window_closed: 'انتهت نافذة الـ24 ساعة',
    campaign_cancelled: 'أُلغيت الحملة',
};

export default function Messages({ messages, stats, filters }: Props) {
    const apply = (patch: Record<string, string>) =>
        router.get(route('club.messages.index'), { ...filters, ...patch }, { preserveState: true, replace: true });

    const total = Object.values(stats).reduce((a, b) => a + Number(b), 0);
    const delivered = Number(stats.delivered ?? 0) + Number(stats.read ?? 0);

    return (
        <ClubLayout title="سجل الرسائل">
            <PageHeader title="سجل رسائل واتساب" subtitle="متابعة الإرسال والتسليم والقراءة لكل رسالة" />

            <div className="mb-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div className="card p-4">
                    <p className="text-xs text-muted">المرسلة (30 يومًا)</p>
                    <p className="mt-1 text-xl font-semibold num">{number(total)}</p>
                </div>
                <div className="card p-4">
                    <p className="text-xs text-muted">نسبة التسليم</p>
                    <p className="mt-1 text-xl font-semibold num">{total > 0 ? Math.round((delivered / total) * 100) : 0}%</p>
                </div>
                {['sent', 'delivered', 'read', 'failed'].map((s) => {
                    const [l, tone] = label(messageStatus, s);
                    return (
                        <button key={s} onClick={() => apply({ status: filters.status === s ? '' : s })} className={`card p-4 text-start transition hover:border-brand-200 ${filters.status === s ? 'border-brand' : ''}`}>
                            <Badge tone={tone}>{l}</Badge>
                            <p className="mt-2 text-xl font-semibold num">{number(stats[s] ?? 0)}</p>
                        </button>
                    );
                })}
            </div>

            <Card padded={false}>
                <div className="flex flex-wrap gap-3 p-4">
                    <Select className="w-44" value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value })} placeholder="كل الحالات">
                        {Object.entries(messageStatus).map(([k, [l]]) => (
                            <option key={k} value={k}>{l}</option>
                        ))}
                    </Select>
                    <Select className="w-40" value={filters.direction ?? ''} onChange={(e) => apply({ direction: e.target.value })} placeholder="الاتجاهان">
                        <option value="outbound">صادرة</option>
                        <option value="inbound">واردة</option>
                    </Select>
                </div>

                {messages.data.length === 0 ? (
                    <EmptyState icon={<ScrollText className="size-6" />} title="لا توجد رسائل" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr>
                                    <th>الوقت</th>
                                    <th>العضو</th>
                                    <th>النوع</th>
                                    <th>المحتوى</th>
                                    <th>الحالة</th>
                                    <th>التسليم</th>
                                </tr>
                            </thead>
                            <tbody>
                                {messages.data.map((m) => {
                                    const [l, tone] = label(messageStatus, m.status);
                                    return (
                                        <tr key={m.id}>
                                            <td className="whitespace-nowrap text-xs text-muted num">{m.at}</td>
                                            <td>
                                                {m.member_id ? (
                                                    <Link href={route('club.members.show', m.member_id)} className="font-medium hover:text-brand">{m.member}</Link>
                                                ) : (
                                                    <span className="text-muted num">{m.to_phone}</span>
                                                )}
                                            </td>
                                            <td className="text-xs">
                                                {m.direction === 'inbound' ? 'واردة' : m.type === 'template' ? `قالب: ${m.template_label ?? m.template_key}` : 'رد نصي'}
                                                {m.campaign_id && <Badge tone="violet" className="ms-1">حملة</Badge>}
                                            </td>
                                            <td className="max-w-xs">
                                                <p className="line-clamp-2 text-xs text-ink/80">{m.body || '—'}</p>
                                            </td>
                                            <td>
                                                <Badge tone={tone}>{l}</Badge>
                                                {(m.error_code || m.error_message) && (
                                                    <p className="mt-1 max-w-[14rem] text-[11px] text-red-600">{skipReasons[m.error_code ?? ''] ?? m.error_message ?? m.error_code}</p>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap text-[11px] text-muted">
                                                {m.sent_at && <span className="block">أُرسلت <span className="num">{m.sent_at}</span></span>}
                                                {m.delivered_at && <span className="block">وصلت <span className="num">{m.delivered_at}</span></span>}
                                                {m.read_at && <span className="block">قُرئت <span className="num">{m.read_at}</span></span>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={messages} />
            </Card>
        </ClubLayout>
    );
}
