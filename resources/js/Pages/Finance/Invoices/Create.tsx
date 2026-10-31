import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Send, Trash2, UserRound, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, Field, Input, MemberPicker, PageHeader, Textarea, type MemberHit, BackLink } from '@/Components/ui';
import PaperFrame from '@/Components/Papers/PaperFrame';
import { useCurrency } from '@/hooks/useShared';
import { money } from '@/lib/format';
import { postJson } from '@/lib/http';

interface Line {
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
}

type Totals = { subtotal: string; discount_total: string; tax_rate: string; tax_total: string; total: string };

const emptyLine = (): Line => ({ description: '', quantity: '1', unit_price: '', discount: '' });

/**
 * إنشاء فاتورة — a manual invoice for a service or item outside memberships and sales. The details on one side and,
 * beside them, the invoice paper as it will be issued, redrawn by the server as the form changes (the same arithmetic
 * and the same paper; nothing is saved until «إصدار الفاتورة»). The buttons sit in the header so they stay in sight
 * however long the invoice grows.
 */
export default function InvoiceCreate() {
    const currency = useCurrency();
    const form = useForm<{
        member_id: number | null;
        member_label: string;
        customer_name: string;
        due_date: string;
        notes: string;
        lines: Line[];
    }>({ member_id: null, member_label: '', customer_name: '', due_date: '', notes: '', lines: [emptyLine()] });
    const [paper, setPaper] = useState<{ html: string; size: string } | null>(null);
    const [totals, setTotals] = useState<Totals | null>(null);
    const ticket = useRef(0);

    const payload = useCallback(() => ({
        member_id: form.data.member_id,
        customer_name: form.data.member_id ? null : form.data.customer_name || null,
        due_date: form.data.due_date || null,
        notes: form.data.notes || null,
        lines: form.data.lines.map((l) => ({ description: l.description, quantity: l.quantity || 0, unit_price: l.unit_price || 0, discount: l.discount || 0 })),
    }), [form.data]);

    // Redraw the paper a moment after typing stops; an answer to an older request is dropped.
    useEffect(() => {
        const id = window.setTimeout(async () => {
            const mine = ++ticket.current;
            try {
                const res = await postJson(route('club.invoices.preview'), payload());
                const body = await res.json();
                if (mine !== ticket.current || !res.ok) return;
                setPaper(body.html ? { html: body.html, size: body.size } : null);
                setTotals(body.totals);
            } catch {
                // offline or refused: the form still issues; the preview waits for the next change
            }
        }, 350);
        return () => window.clearTimeout(id);
    }, [payload]);

    const setLine = (index: number, patch: Partial<Line>) => form.setData('lines', form.data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));
    const pickMember = (m: MemberHit) => form.setData((d) => ({ ...d, member_id: m.id, member_label: `${m.full_name} · ${m.member_no}`, customer_name: '' }));
    const lineError = (i: number, field: string) => (form.errors as Record<string, string>)[`lines.${i}.${field}`];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform(() => ({ ...payload(), lines: form.data.lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount || 0 })) }));
        form.post(route('club.invoices.store'));
    };

    return (
        <ClubLayout title="إنشاء فاتورة">
            <PageHeader
                back={<BackLink href={route('club.invoices.index')}>فواتير العملاء</BackLink>}
                title="إنشاء فاتورة"
                subtitle="فاتورة يدوية لخدمة أو بند خارج العضويات والمبيعات — تُرقَّم عند الإصدار."
                actions={<>
                    <span className="rounded-full bg-[#eff6ff] px-3 py-1 text-[12px] font-medium text-[#1d4ed8]">مسودة</span>
                    <Button variant="outline" href={route('club.invoices.index')} disabled={form.processing}>إلغاء</Button>
                    <Button type="submit" form="invoice-form" icon={<Send />} loading={form.processing}>إصدار الفاتورة</Button>
                </>}
            />

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                <form id="invoice-form" onSubmit={submit} className="min-w-0 space-y-4">
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-ink">معلومات الفاتورة</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {form.data.member_id ? (
                                <Field label="العضو" className="sm:col-span-2">
                                    <div className="flex items-center justify-between gap-3 rounded-[10px] border border-line bg-[#fafafa] px-3 py-2.5">
                                        <span className="flex items-center gap-2 text-sm font-medium"><UserRound className="size-4 text-[#6b7280]" /> {form.data.member_label}</span>
                                        <button type="button" className="text-muted hover:text-ink" onClick={() => form.setData((d) => ({ ...d, member_id: null, member_label: '' }))} aria-label="إزالة العضو"><X className="size-4" /></button>
                                    </div>
                                </Field>
                            ) : (
                                <>
                                    <Field label="عضو" error={form.errors.member_id}><MemberPicker onSelect={pickMember} /></Field>
                                    <Field label="أو اسم العميل" error={form.errors.customer_name}><Input value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} placeholder="عميل نقدي" maxLength={160} /></Field>
                                </>
                            )}
                            <Field label="تاريخ الاستحقاق" error={form.errors.due_date} hint="اتركه فارغًا لاستخدام مدة السداد في إعدادات النادي"><Input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} /></Field>
                        </div>
                    </Card>

                    <Card className="p-5">
                        <div className="mb-4 flex items-center gap-2">
                            <h2 className="me-auto text-[15px] font-bold text-ink">الأصناف</h2>
                            <Button type="button" variant="outline" size="sm" icon={<Plus />} onClick={() => form.setData('lines', [...form.data.lines, emptyLine()])} disabled={form.data.lines.length >= 50}>بند</Button>
                        </div>
                        {form.errors.lines && <p className="mb-3 text-[13px] text-[#b91c1c]">{form.errors.lines}</p>}
                        <div className="space-y-3">
                            {form.data.lines.map((line, i) => (
                                <div key={i} className="grid gap-3 rounded-[12px] border border-line p-3 sm:grid-cols-12">
                                    <Field className="sm:col-span-5" label="البيان" error={lineError(i, 'description')}><Input value={line.description} onChange={(e) => setLine(i, { description: e.target.value })} maxLength={250} required /></Field>
                                    <Field className="sm:col-span-2" label="الكمية" error={lineError(i, 'quantity')}><Input type="number" min="0.001" step="0.001" dir="ltr" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} required /></Field>
                                    <Field className="sm:col-span-2" label="سعر الوحدة" error={lineError(i, 'unit_price')}><Input type="number" min="0" step="0.001" dir="ltr" value={line.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} required /></Field>
                                    <Field className="sm:col-span-2" label="الخصم" error={lineError(i, 'discount')}><Input type="number" min="0" step="0.001" dir="ltr" value={line.discount} onChange={(e) => setLine(i, { discount: e.target.value })} /></Field>
                                    <div className="flex items-end justify-end sm:col-span-1">
                                        <button type="button" disabled={form.data.lines.length === 1} onClick={() => form.setData('lines', form.data.lines.filter((_, x) => x !== i))} className="grid size-10 place-items-center rounded-[10px] text-[#6b7280] transition-colors hover:bg-[#fef2f2] hover:text-[#b91c1c] disabled:opacity-30" aria-label="حذف البند"><Trash2 className="size-4" /></button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-ink">ملخص الفاتورة</h2>
                        <dl className="space-y-1.5 text-[13px]">
                            <SumRow label="المجموع الفرعي" value={money(totals?.subtotal ?? 0, currency)} />
                            {Number(totals?.discount_total ?? 0) > 0 && <SumRow label="الخصم" value={`− ${money(totals!.discount_total, currency)}`} />}
                            {Number(totals?.tax_total ?? 0) > 0 && <SumRow label={`ضريبة القيمة المضافة (${Number(totals!.tax_rate)}%)`} value={money(totals!.tax_total, currency)} />}
                            <SumRow label="الإجمالي" value={money(totals?.total ?? 0, currency)} strong />
                        </dl>
                        <p className="mt-3 text-[12px] text-[#9ca3af]">تُحسب الضريبة حسب إعدادات النادي، كما ستُصدر.</p>
                    </Card>

                    <Card className="p-5">
                        <h2 className="mb-1 text-[15px] font-bold text-ink">ملاحظة للعميل</h2>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">تُطبع على الفاتورة.</p>
                        <Field error={form.errors.notes}><Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={1000} /></Field>
                    </Card>
                </form>

                <div className="min-w-0 space-y-2 xl:sticky xl:top-4 xl:self-start">
                    <h2 className="text-[15px] font-bold text-ink">معاينة الفاتورة</h2>
                    {paper ? (
                        <PaperFrame html={paper.html} paper={paper.size} height="min(80svh, 1000px)" title="معاينة الفاتورة" />
                    ) : (
                        <div className="grid h-64 place-items-center rounded-2xl border border-dashed border-line bg-white px-6 text-center text-[13px] text-[#9ca3af]">اكتب بندًا لتظهر الفاتورة كما ستُصدر.</div>
                    )}
                </div>
            </div>
        </ClubLayout>
    );
}

function SumRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={strong ? 'flex justify-between gap-4 border-t border-line pt-2 font-bold' : 'flex justify-between gap-4'}>
            <dt className="text-[#71717a]">{label}</dt>
            <dd className="num">{value}</dd>
        </div>
    );
}
