import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, Save, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, ConfirmAction, Input, PageHeader, Select } from '@/Components/ui';
import { COUNT_LABELS, Pill } from '@/Components/Inventory/parts';
import { useCan, useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';

interface Line { id: number; product: string | null; sku: string | null; system_quantity: string; counted_quantity: string | null; difference: string; unit_cost: string; reason: string | null; note: string | null }
interface Count { id: number; number: string; status: string; notes: string | null; branch: string | null; by: string | null; approver: string | null; at: string; approved_at: string | null; lines: Line[] }

const qty = (v: number | string) => number(v, Number(v) % 1 ? 3 : 0);

/** One count: the branch's book for each product, what was counted, the difference and why; approved once. */
export default function CountShow({ count, reasons }: { count: Count; reasons: Record<string, string> }) {
    const currency = useCurrency();
    const can = useCan();
    const draft = count.status === 'draft';
    const [entries, setEntries] = useState<Record<number, { counted: string; reason: string; note: string }>>(() => Object.fromEntries(count.lines.map((l) => [l.id, { counted: l.counted_quantity === null ? '' : String(Number(l.counted_quantity)), reason: l.reason ?? 'count', note: l.note ?? '' }])));
    const [busy, setBusy] = useState(false);
    const set = (id: number, patch: Partial<{ counted: string; reason: string; note: string }>) => setEntries((e) => ({ ...e, [id]: { ...e[id], ...patch } }));
    const diff = (l: Line) => (entries[l.id]?.counted === '' ? null : Number(entries[l.id].counted) - Number(l.system_quantity));
    const value = count.lines.reduce((t, l) => t + (diff(l) ?? 0) * Number(l.unit_cost), 0);
    const save = (approve: boolean) => router.put(route('club.inventory.counts.save', count.id), { counts: entries, approve }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <ClubLayout title={`الجرد ${count.number}`}>
            <PageHeader
                back={<Link href={route('club.inventory.counts')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> الجرد الفعلي</Link>}
                title={<span className="num">الجرد {count.number}</span>}
                subtitle={`${count.branch} · بدأه ${count.by ?? '—'} في ${count.at}${count.approver ? ` · اعتمده ${count.approver} في ${count.approved_at}` : ''}`}
                actions={<Pill tone={COUNT_LABELS[count.status]?.[1] ?? 'gray'}>{COUNT_LABELS[count.status]?.[0]}</Pill>}
            />
            <Card padded={false} className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>المنتج</th><th>رصيد النظام</th><th>الكمية المعدودة</th><th>الفرق</th><th>السبب</th><th>ملاحظة</th></tr></thead>
                        <tbody>
                            {count.lines.map((l) => {
                                const d = draft ? diff(l) : l.counted_quantity === null ? null : Number(l.difference);
                                return (
                                    <tr key={l.id}>
                                        <td><p className="font-medium">{l.product}</p><p className="text-[11px] text-[#9ca3af] num">{l.sku ?? '—'}</p></td>
                                        <td className="num">{qty(l.system_quantity)}</td>
                                        <td>{draft ? <Input type="number" min="0" step="any" dir="ltr" className="w-28" value={entries[l.id].counted} onChange={(e) => set(l.id, { counted: e.target.value })} aria-label={`المعدود من ${l.product}`} /> : <span className="num">{l.counted_quantity === null ? '—' : qty(l.counted_quantity)}</span>}</td>
                                        <td>{d === null ? <span className="text-[#9ca3af]">لم يُعد</span> : <span className={cn('font-semibold num', d > 0 ? 'text-emerald-700' : d < 0 ? 'text-red-700' : 'text-[#9ca3af]')}>{d > 0 ? '+' : ''}{qty(d)} <span className="text-[11px] font-normal text-[#9ca3af]">← {qty(Number(l.system_quantity) + d)}</span></span>}</td>
                                        <td>{draft ? (
                                            <Select className="w-40" value={entries[l.id].reason} onChange={(e) => set(l.id, { reason: e.target.value })} disabled={!d} aria-label={`سبب فرق ${l.product}`}>
                                                {Object.entries(reasons).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                                            </Select>
                                        ) : <span className="text-[13px]">{d ? reasons[l.reason ?? 'count'] : '—'}</span>}</td>
                                        <td>{draft ? <Input className="w-44" value={entries[l.id].note} onChange={(e) => set(l.id, { note: e.target.value })} maxLength={200} aria-label={`ملاحظة ${l.product}`} disabled={!d} /> : <span className="text-[13px] text-[#6b7280]">{l.note ?? '—'}</span>}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4">
                    <p className="text-[13px] text-[#4b4b4b]">أثر الفروقات على قيمة المخزون: <span className={cn('font-semibold num', value < 0 ? 'text-red-700' : 'text-emerald-700')}>{money(value, currency)}</span></p>
                    {draft && can('inventory.adjust') && (
                        <div className="flex flex-wrap gap-2">
                            <ConfirmAction url={route('club.inventory.counts.cancel', count.id)} title="إلغاء الجرد" message="يُلغى الجرد دون أي أثر على المخزون." confirmLabel="إلغاء الجرد" variant="ghost" icon={<X />}>إلغاء</ConfirmAction>
                            <Button variant="outline" icon={<Save />} loading={busy} onClick={() => save(false)}>حفظ</Button>
                            {can('inventory.approve') && <Button icon={<Check />} loading={busy} onClick={() => save(true)}>حفظ واعتماد الجرد</Button>}
                        </div>
                    )}
                </div>
            </Card>
            {draft && <p className="mt-3 text-[12px] text-[#9ca3af]">عند الاعتماد يُقرأ رصيد النظام من جديد وتُسجَّل الفروقات في سجل المخزون برقم هذا الجرد، مرة واحدة. الخانة الفارغة تعني أن المنتج لم يُعد ولا يتغير.</p>}
        </ClubLayout>
    );
}
