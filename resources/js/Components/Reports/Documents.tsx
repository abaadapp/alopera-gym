import { CalendarOff, FileCheck2, FileClock, FileX2, Lock } from 'lucide-react';
import { Badge, HBars, Pagination, StatGrid } from '@/Components/ui';
import { date } from '@/lib/format';
import type { Paginated } from '@/types';
import { ChartCard, Figure, Kpi, TableCard, useFormats, type Metric } from './parts';

const STATE_TONE: Record<string, 'green' | 'amber' | 'red' | 'gray'> = { valid: 'green', expiring: 'amber', expired: 'red', cancelled: 'gray' };

type Doc = { id: number; name: string; type: string | null; reference_number: string | null; issuer: string | null; branch: string | null; responsible: string | null; is_confidential: boolean; expiry_date: string | null; days_left: number | null; state: string; state_label: string };
export interface Documents {
    stats: { total: number; expiring: number; expired: number; none: number };
    byType: { type: string; total: number; expiring: number; expired: number }[];
    documents: Paginated<Doc>;
    types: { id: number; name: string }[];
    soon: number;
    options: { state: string | null; type: number | null };
}

/** المستندات وصلاحيتها (today's state; renewing stays in the documents section). */
export function DocumentsBody({ data }: { data: Documents }) {
    return (
        <>
            <StatGrid>
                <Figure label="مستندات سارية" value={data.stats.total} icon={FileCheck2} tone="brand" />
                <Figure label="قريبة الانتهاء" value={data.stats.expiring} icon={FileClock} tone="amber" hint={`خلال ${data.soon} يومًا`} />
                <Figure label="منتهية" value={data.stats.expired} icon={FileX2} tone="red" />
                <Figure label="بلا تاريخ انتهاء" value={data.stats.none} icon={CalendarOff} tone="gray" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="حسب النوع" empty="لا مستندات" head={['النوع', 'المستندات', 'قريبة الانتهاء', 'منتهية']}>
                    {data.byType.map((t) => (
                        <tr key={t.type}>
                            <td className="font-medium">{t.type}</td>
                            <td className="num">{t.total}</td>
                            <td>{t.expiring ? <Badge tone="amber">{t.expiring}</Badge> : <span className="text-muted">—</span>}</td>
                            <td>{t.expired ? <Badge tone="red">{t.expired}</Badge> : <span className="text-muted">—</span>}</td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="المستندات حسب النوع" has={data.byType.length > 0}>
                    <HBars data={data.byType.map((t) => ({ label: t.type, value: t.total }))} />
                </ChartCard>
            </div>
            <TableCard className="mt-4" title="المستندات" subtitle="الأقرب انتهاءً أولًا" empty="لا مستندات بهذه الفلاتر" head={['المستند', 'النوع', 'الفرع', 'المسؤول', 'تنتهي', 'الحالة']} footer={<Pagination data={data.documents} />}>
                {data.documents.data.map((d) => (
                    <tr key={d.id}>
                        <td>
                            <span className="flex items-center gap-1.5 font-medium">{d.is_confidential && <Lock className="size-3.5 text-muted" aria-label="سري" />}{d.name}</span>
                            {(d.reference_number || d.issuer) && <span className="block text-xs text-muted num">{[d.reference_number, d.issuer].filter(Boolean).join(' · ')}</span>}
                        </td>
                        <td>{d.type ?? '—'}</td>
                        <td className="text-muted">{d.branch ?? 'كل الفروع'}</td>
                        <td>{d.responsible ?? '—'}</td>
                        <td className="num">{d.expiry_date ? <>{date(d.expiry_date)}{d.days_left !== null && <span className="block text-xs text-muted">{d.days_left < 0 ? `منذ ${-d.days_left} يوم` : `بعد ${d.days_left} يوم`}</span>}</> : '—'}</td>
                        <td><Badge tone={STATE_TONE[d.state] ?? 'gray'}>{d.state_label}</Badge></td>
                    </tr>
                ))}
            </TableCard>
        </>
    );
}

type Renewal = { id: number; document: string; deleted: boolean; type: string | null; previous_expiry: string | null; new_expiry: string; previous_reference: string | null; new_reference: string | null; notes: string | null; by: string | null; at: string };
export interface Renewals { comparison: Record<string, Metric>; byType: { type: string; count: number }[]; renewals: Paginated<Renewal> }

/** سجل تجديد المستندات. */
export function RenewalsBody({ data }: { data: Renewals }) {
    const f = useFormats();
    return (
        <>
            <StatGrid cols={3}>
                <Kpi label="التجديدات" metric={data.comparison.renewals} format={f.num} metricKey="renewals" />
                <Kpi label="مستندات جُدّدت" metric={data.comparison.documents} format={f.num} metricKey="documents" />
                <Figure label="أنواع جُدّد منها" value={data.byType.length} icon={FileCheck2} tone="gray" />
            </StatGrid>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <TableCard className="lg:col-span-2" title="التجديدات" subtitle="الأحدث أولًا" empty="لا تجديدات في هذه الفترة" head={['المستند', 'الانتهاء السابق', 'الانتهاء الجديد', 'الرقم المرجعي', 'نفّذه', 'التاريخ']} footer={<Pagination data={data.renewals} />}>
                    {data.renewals.data.map((r) => (
                        <tr key={r.id}>
                            <td>
                                <span className="font-medium">{r.document}</span> {r.deleted && <Badge>محذوف</Badge>}
                                {(r.type || r.notes) && <span className="block max-w-64 truncate text-xs text-muted">{[r.type, r.notes].filter(Boolean).join(' · ')}</span>}
                            </td>
                            <td className="num text-sm text-muted">{r.previous_expiry ? date(r.previous_expiry) : '—'}</td>
                            <td className="num text-sm font-medium">{date(r.new_expiry)}</td>
                            <td className="num text-xs">{r.new_reference && r.new_reference !== r.previous_reference ? <>{r.previous_reference ?? '—'} ← {r.new_reference}</> : r.previous_reference ?? '—'}</td>
                            <td className="text-sm">{r.by ?? '—'}</td>
                            <td className="num text-xs text-muted">{r.at}</td>
                        </tr>
                    ))}
                </TableCard>
                <ChartCard title="حسب النوع" has={data.byType.length > 0}>
                    <HBars data={data.byType.map((t) => ({ label: t.type, value: t.count }))} />
                </ChartCard>
            </div>
        </>
    );
}
