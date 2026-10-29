import { Download, History } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, PageHeader, Pagination } from '@/Components/ui';
import { date } from '@/lib/format';
import type { Paginated } from '@/types';

interface Renewal {
    id: number;
    document: string | null;
    deleted: boolean;
    previous_expiry: string | null;
    new_expiry: string;
    previous_reference: string | null;
    new_reference: string | null;
    has_file: boolean;
    previous_file_name: string | null;
    notes: string | null;
    by: string | null;
    at: string;
}

export default function DocumentRenewals({ renewals }: { renewals: Paginated<Renewal> }) {
    return (
        <ClubLayout title="سجل التجديدات">
            <PageHeader title="سجل التجديدات" subtitle="كل تجديد: من نفّذه ومتى، والتواريخ قبل وبعد، والنسخة السابقة محفوظة للتنزيل" />
            <Card padded={false}>
                {renewals.data.length === 0 ? (
                    <EmptyState icon={<History />} title="لا تجديدات بعد" description="عند تجديد مستند تُحفظ نسخته السابقة هنا." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>المستند</th><th>الانتهاء السابق</th><th>الانتهاء الجديد</th><th>الرقم المرجعي</th><th>نفّذه</th><th>التاريخ</th><th>النسخة السابقة</th></tr></thead>
                            <tbody>
                                {renewals.data.map((r) => (
                                    <tr key={r.id}>
                                        <td>
                                            <p className="font-medium">{r.document ?? '—'} {r.deleted && <Badge>محذوف</Badge>}</p>
                                            {r.notes && <p className="max-w-64 truncate text-xs text-muted">{r.notes}</p>}
                                        </td>
                                        <td className="num text-sm text-muted">{r.previous_expiry ? date(r.previous_expiry) : '—'}</td>
                                        <td className="num text-sm font-medium">{date(r.new_expiry)}</td>
                                        <td className="num text-xs">{r.new_reference && r.new_reference !== r.previous_reference ? <>{r.previous_reference ?? '—'} ← {r.new_reference}</> : r.previous_reference ?? '—'}</td>
                                        <td className="text-sm">{r.by ?? '—'}</td>
                                        <td className="num text-xs text-muted">{r.at}</td>
                                        <td>{r.has_file ? <Button size="sm" variant="ghost" href={route('club.business-documents.renewals.download', r.id)} external icon={<Download />}>{r.previous_file_name ? 'تنزيل' : 'الملف'}</Button> : <span className="text-xs text-muted">—</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={renewals} />
            </Card>
        </ClubLayout>
    );
}
