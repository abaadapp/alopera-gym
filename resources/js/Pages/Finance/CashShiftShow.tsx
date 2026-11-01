import { Printer } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { BackLink, Badge, Button, Card, Notice, PageHeader } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { Difference, ShiftFigures, type ShiftRow, type ShiftSummary } from './CashShifts';

type Movement = { id: number; direction: 'in' | 'out'; amount: string; account: string | null; reason: string; by: string | null; at: string };

/** One shift's closing report: who opened and closed it, what passed through it by method, the count and the difference. */
export default function CashShiftShow({ shift }: { shift: ShiftRow & { summary: ShiftSummary | null; movements: Movement[] } }) {
    const currency = useCurrency();
    const closed = shift.status === 'closed';

    return (
        <ClubLayout title={`الوردية ${shift.number}`}>
            <PageHeader
                back={<span className="no-print"><BackLink href={route('club.cash-shifts.index')}>ورديات الصندوق</BackLink></span>}
                title={<span className="flex items-center gap-2"><span className="num">{shift.number}</span><Badge tone={closed ? 'gray' : 'green'}>{closed ? 'مقفلة' : 'مفتوحة'}</Badge></span>}
                subtitle={`تقرير ${closed ? 'إقفال' : 'حالة'} الوردية — ${shift.drawer ?? ''}${shift.branch ? ` · ${shift.branch}` : ''}`}
                actions={<span className="no-print"><Button variant="outline" icon={<Printer className="size-4" />} onClick={() => window.print()}>طباعة</Button></span>}
            />
            <Card className="space-y-5">
                <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                    <div className="flex justify-between gap-2"><dt className="text-muted">فتحها</dt><dd>{shift.opened_by} · <span className="num">{shift.opened_at}</span></dd></div>
                    <div className="flex justify-between gap-2"><dt className="text-muted">أقفلها</dt><dd>{closed ? <>{shift.closed_by} · <span className="num">{shift.closed_at}</span></> : '—'}</dd></div>
                </dl>
                {shift.summary && <ShiftFigures shift={shift} summary={shift.summary} />}
                {closed && (
                    <dl className="grid gap-x-6 gap-y-2 rounded-[12px] border border-line p-4 text-[14px] sm:grid-cols-3">
                        <div><dt className="text-[12px] text-muted">المتوقع</dt><dd className="font-semibold num">{money(shift.expected_cash, currency)}</dd></div>
                        <div><dt className="text-[12px] text-muted">المعدود</dt><dd className="font-semibold num">{money(shift.counted_cash, currency)}</dd></div>
                        <div><dt className="text-[12px] text-muted">الفرق</dt><dd className="font-semibold"><Difference value={shift.difference} note={null} /></dd></div>
                    </dl>
                )}
                {shift.close_note && <Notice tone="amber">سبب الفرق: {shift.close_note}</Notice>}
                {shift.movements.length > 0 && (
                    <div>
                        <p className="mb-1.5 text-[12px] font-semibold text-muted">نقل النقد</p>
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>البيان</th><th>الحساب</th><th>بواسطة</th><th className="text-end">المبلغ</th></tr></thead>
                            <tbody>
                                {shift.movements.map((m) => (
                                    <tr key={m.id}>
                                        <td className="num text-xs">{m.at}</td><td>{m.reason}</td><td>{m.account}</td><td className="text-xs text-muted">{m.by}</td>
                                        <td className={cn('text-end num', m.direction === 'out' && 'text-red-600')}>{m.direction === 'out' ? '− ' : '+ '}{money(m.amount, currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </ClubLayout>
    );
}
