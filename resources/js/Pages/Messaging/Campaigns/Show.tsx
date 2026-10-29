import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCheck, Eye, Send, Users, XCircle } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, CardHeader, ConfirmAction, EmptyState, PageHeader, StatCard } from '@/Components/ui';
import { number } from '@/lib/format';
import { label, messageStatus } from '@/lib/labels';
import { CAMPAIGN_STATUS, type Audience } from './Index';

interface Props {
    campaign: {
        id: number;
        name: string;
        status: string;
        audience: Audience;
        recipients_count: number;
        sent_count: number;
        delivered_count: number;
        read_count: number;
        failed_count: number;
        template: { id: number; name: string; body: string; status: string } | null;
        scheduled_at: string | null;
        started_at: string | null;
        completed_at: string | null;
        audience_size: number | null;
    };
    messages: { id: number; member: string | null; status: string; error: string | null; sent_at: string | null }[];
}

const SEGMENT_LABELS: Record<string, string> = {
    all: 'كل الموافقين على التسويق',
    active: 'العضويات النشطة',
    expiring: 'تنتهي عضويته خلال',
    expired: 'انتهت عضويته خلال آخر',
    inactive: 'لم يزر النادي منذ',
    new: 'انضم خلال آخر',
};

export default function CampaignShow({ campaign, messages }: Props) {
    const [st, tone] = CAMPAIGN_STATUS[campaign.status] ?? [campaign.status, 'gray'];
    const total = campaign.recipients_count || 0;
    const pct = (n: number) => (total > 0 ? Math.round((n / total) * 100) : 0);
    const canLaunch = ['draft', 'scheduled'].includes(campaign.status);
    const canCancel = ['draft', 'scheduled', 'sending'].includes(campaign.status);

    return (
        <ClubLayout title={campaign.name}>
            <PageHeader
                back={<Link href={route('club.campaigns.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> الحملات</Link>}
                title={campaign.name}
                subtitle={<span className="inline-flex items-center gap-2"><Badge tone={tone}>{st}</Badge>{campaign.scheduled_at && <span>موعد الإرسال <span className="num">{campaign.scheduled_at}</span></span>}</span>}
                actions={
                    <>
                        {canLaunch && (
                            <ConfirmAction
                                url={route('club.campaigns.launch', campaign.id)}
                                title={campaign.scheduled_at ? 'جدولة الحملة' : 'إطلاق الحملة الآن'}
                                message={`سيتم إرسال القالب إلى ${number(campaign.audience_size ?? 0)} عضو موافق على التسويق. لا يمكن التراجع عن الرسائل المرسلة.`}
                                confirmLabel={campaign.scheduled_at ? 'جدولة' : 'إطلاق'}
                                variant="primary"
                                size="md"
                                tone="primary"
                                icon={<Send className="size-4" />}
                            >
                                {campaign.scheduled_at ? 'جدولة' : 'إطلاق الحملة'}
                            </ConfirmAction>
                        )}
                        {canCancel && (
                            <ConfirmAction url={route('club.campaigns.cancel', campaign.id)} title="إيقاف الحملة" message="ستُلغى الرسائل التي لم تُرسل بعد." size="md" icon={<XCircle className="size-4" />}>
                                إيقاف
                            </ConfirmAction>
                        )}
                    </>
                }
            />

            <div className="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="المستلمون" value={number(total || campaign.audience_size || 0)} icon={<Users className="size-5" />} hint={total === 0 && campaign.audience_size !== null ? 'الجمهور المتوقع قبل الإطلاق' : undefined} />
                <StatCard label="أُرسلت" value={number(campaign.sent_count)} tone="blue" icon={<Send className="size-5" />} hint={`${pct(campaign.sent_count)}%`} />
                <StatCard label="وصلت" value={number(campaign.delivered_count)} tone="green" icon={<CheckCheck className="size-5" />} hint={`${pct(campaign.delivered_count)}%`} />
                <StatCard label="قُرئت" value={number(campaign.read_count)} tone="violet" icon={<Eye className="size-5" />} hint={`${pct(campaign.read_count)}% · فشلت ${number(campaign.failed_count)}`} />
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader title="تفاصيل الحملة" />
                    <dl className="space-y-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted">الجمهور</dt>
                            <dd>
                                {SEGMENT_LABELS[campaign.audience?.segment] ?? campaign.audience?.segment}
                                {campaign.audience?.days ? <> <span className="num">{campaign.audience.days}</span> يوم</> : null}
                                {campaign.audience?.plan_ids?.length ? <span className="block text-xs text-muted">مقيّدة بـ <span className="num">{campaign.audience.plan_ids.length}</span> باقة</span> : null}
                                {campaign.audience?.program_ids?.length ? <span className="block text-xs text-muted">حجزوا <span className="num">{campaign.audience.program_ids.length}</span> برنامجًا محددًا</span> : null}
                            </dd>
                        </div>
                        {campaign.started_at && <div><dt className="text-xs text-muted">بدأ الإرسال</dt><dd className="num">{campaign.started_at}</dd></div>}
                        {campaign.completed_at && <div><dt className="text-xs text-muted">اكتملت</dt><dd className="num">{campaign.completed_at}</dd></div>}
                        {campaign.template && (
                            <div>
                                <dt className="text-xs text-muted">القالب <span dir="ltr">({campaign.template.name})</span></dt>
                                <dd className="mt-1 whitespace-pre-wrap rounded-xl bg-[#fbf6f8] p-3 leading-6">{campaign.template.body}</dd>
                                {campaign.template.status !== 'approved' && <p className="mt-1 text-xs text-amber-700">القالب غير معتمد بعد — لن يُسمح بالإطلاق.</p>}
                            </div>
                        )}
                    </dl>
                </Card>

                <Card padded={false} className="lg:col-span-2">
                    <div className="p-5 pb-0">
                        <CardHeader title="المستلمون" subtitle="آخر 200 رسالة في هذه الحملة" />
                    </div>
                    {messages.length === 0 ? (
                        <EmptyState title="لم تُرسل رسائل بعد" />
                    ) : (
                        <div className="max-h-[32rem] overflow-auto">
                            <table className="table-base">
                                <thead><tr><th>العضو</th><th>الحالة</th><th>وقت الإرسال</th></tr></thead>
                                <tbody>
                                    {messages.map((m) => {
                                        const [l, t] = label(messageStatus, m.status);
                                        return (
                                            <tr key={m.id}>
                                                <td>{m.member ?? '—'}</td>
                                                <td>
                                                    <Badge tone={t}>{l}</Badge>
                                                    {m.error && <span className="ms-2 text-[11px] text-red-600">{m.error}</span>}
                                                </td>
                                                <td className="text-xs text-muted num">{m.sent_at ?? '—'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </ClubLayout>
    );
}
