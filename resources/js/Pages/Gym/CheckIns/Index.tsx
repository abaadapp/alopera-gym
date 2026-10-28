import { Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock, XCircle } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Card, EmptyState, Input, PageHeader, Pagination, Tabs } from '@/Components/ui';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    method: string;
    direction: string;
    result: string;
    reason: string | null;
    reason_label: string | null;
    credential_hint: string | null;
    member: { id: number; full_name: string; member_no: string } | null;
    device: string | null;
    by: string | null;
    at: string;
}

const METHODS: Record<string, string> = { manual: 'يدوي (الاستقبال)', qr: 'رمز QR', card: 'بطاقة', fingerprint: 'بصمة', barcode: 'باركود', pin: 'رمز سري' };

export default function CheckInsIndex({ checkins, filters }: { checkins: Paginated<Row>; filters: { result?: string; date?: string } }) {
    const go = (params: Record<string, string | undefined>) =>
        router.get(route('club.checkins.index'), { result: filters.result || undefined, date: filters.date || undefined, ...params }, { preserveState: true, preserveScroll: true });

    return (
        <ClubLayout title="سجل الدخول">
            <PageHeader title="سجل الدخول" subtitle="كل قرار دخول — من الاستقبال أو QR أو أجهزة البوابة — مسموحًا أو مرفوضًا مع السبب" />

            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <Tabs
                        tabs={[{ key: '', label: 'الكل' }, { key: 'allowed', label: 'مسموح' }, { key: 'duplicate', label: 'مكرر' }, { key: 'denied', label: 'مرفوض' }]}
                        value={filters.result ?? ''}
                        onChange={(key) => go({ result: key || undefined })}
                        className="border-0"
                    />
                    <div className="w-full sm:w-48">
                        <Input type="date" value={filters.date ?? ''} onChange={(e) => go({ date: e.target.value || undefined })} />
                    </div>
                </div>

                {checkins.data.length === 0 ? (
                    <EmptyState icon={<Clock className="size-6" />} title="لا توجد عمليات دخول" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>المشتركة</th><th>الطريقة</th><th>الجهاز / الموظفة</th><th>النتيجة</th></tr></thead>
                            <tbody>
                                {checkins.data.map((c) => (
                                    <tr key={c.id}>
                                        <td className="whitespace-nowrap text-xs num">{c.at}</td>
                                        <td>
                                            {c.member ? (
                                                <Link href={route('club.members.show', c.member.id)} className="font-medium hover:text-brand">
                                                    {c.member.full_name}
                                                    <span className="block text-xs font-normal text-muted num">{c.member.member_no}</span>
                                                </Link>
                                            ) : (
                                                <span className="text-muted">غير معروفة{c.credential_hint && <span className="ms-1 num">({c.credential_hint})</span>}</span>
                                            )}
                                        </td>
                                        <td className="text-sm">{METHODS[c.method] ?? c.method}{c.direction === 'out' && <span className="ms-1 text-xs text-muted">(خروج)</span>}</td>
                                        <td className="text-xs text-muted">{c.device ?? c.by ?? '—'}</td>
                                        <td>
                                            {c.result === 'allowed' ? (
                                                <Badge tone="green"><CheckCircle2 className="size-3" /> مسموح</Badge>
                                            ) : c.result === 'duplicate' ? (
                                                <Badge tone="amber"><Clock className="size-3" /> مكرر — لم يُحتسب</Badge>
                                            ) : (
                                                <Badge tone="red"><XCircle className="size-3" /> {c.reason_label ?? 'مرفوض'}</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={checkins} />
            </Card>
        </ClubLayout>
    );
}
