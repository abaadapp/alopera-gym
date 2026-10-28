import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ArrowRight, Plus, Trash2, UserRound, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, MemberPicker, PageHeader, Textarea, type MemberHit } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money } from '@/lib/format';

interface Line {
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
}

const emptyLine = (): Line => ({ description: '', quantity: '1', unit_price: '', discount: '' });

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

    const lineTotal = (l: Line) => Math.max(0, Number(l.quantity || 0) * Number(l.unit_price || 0) - Number(l.discount || 0));
    const total = form.data.lines.reduce((sum, l) => sum + lineTotal(l), 0);

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData('lines', form.data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const pickMember = (m: MemberHit) => {
        form.setData((d) => ({ ...d, member_id: m.id, member_label: `${m.full_name} · ${m.member_no}`, customer_name: '' }));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            member_id: d.member_id,
            customer_name: d.member_id ? null : d.customer_name,
            due_date: d.due_date || null,
            notes: d.notes || null,
            lines: d.lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount || 0 })),
        }));
        form.post(route('club.invoices.store'));
    };

    const lineError = (i: number, field: string) => (form.errors as Record<string, string>)[`lines.${i}.${field}`];

    return (
        <ClubLayout title="فاتورة يدوية">
            <PageHeader
                title="فاتورة يدوية"
                subtitle="لخدمات أو بنود خارج العضويات ونقطة البيع"
                back={<a href={route('club.invoices.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-brand"><ArrowRight className="size-4" /> الفواتير</a>}
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="العميل" subtitle="اختاري مشتركة أو اكتبي اسم عميل نقدي" />
                        {form.data.member_id ? (
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3">
                                <span className="flex items-center gap-2 text-sm font-medium"><UserRound className="size-4 text-brand" /> {form.data.member_label}</span>
                                <button type="button" className="text-muted hover:text-ink" onClick={() => form.setData((d) => ({ ...d, member_id: null, member_label: '' }))} aria-label="إزالة">
                                    <X className="size-4" />
                                </button>
                            </div>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="مشتركة" error={form.errors.member_id}>
                                    <MemberPicker onSelect={pickMember} />
                                </Field>
                                <Field label="أو اسم العميل" error={form.errors.customer_name}>
                                    <Input value={form.data.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} placeholder="عميل نقدي" />
                                </Field>
                            </div>
                        )}
                    </Card>

                    <Card>
                        <CardHeader
                            title="البنود"
                            actions={<Button type="button" variant="secondary" size="sm" icon={<Plus className="size-4" />} onClick={() => form.setData('lines', [...form.data.lines, emptyLine()])}>بند</Button>}
                        />
                        {form.errors.lines && <p className="mb-3 text-sm text-red-600">{form.errors.lines}</p>}
                        <div className="space-y-3">
                            {form.data.lines.map((line, i) => (
                                <div key={i} className="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-12">
                                    <Field className="sm:col-span-5" label={i === 0 ? 'الوصف' : undefined} error={lineError(i, 'description')}>
                                        <Input value={line.description} onChange={(e) => setLine(i, { description: e.target.value })} required />
                                    </Field>
                                    <Field className="sm:col-span-2" label={i === 0 ? 'الكمية' : undefined} error={lineError(i, 'quantity')}>
                                        <Input type="number" min="0.001" step="0.001" dir="ltr" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} required />
                                    </Field>
                                    <Field className="sm:col-span-2" label={i === 0 ? 'سعر الوحدة' : undefined} error={lineError(i, 'unit_price')}>
                                        <Input type="number" min="0" step="0.001" dir="ltr" value={line.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} required />
                                    </Field>
                                    <Field className="sm:col-span-2" label={i === 0 ? 'خصم' : undefined} error={lineError(i, 'discount')}>
                                        <Input type="number" min="0" step="0.001" dir="ltr" value={line.discount} onChange={(e) => setLine(i, { discount: e.target.value })} />
                                    </Field>
                                    <div className="flex items-end justify-between gap-2 sm:col-span-1 sm:flex-col sm:items-center sm:justify-end">
                                        <span className="text-xs text-muted num sm:hidden">{money(lineTotal(line), currency)}</span>
                                        <button
                                            type="button"
                                            disabled={form.data.lines.length === 1}
                                            onClick={() => form.setData('lines', form.data.lines.filter((_, x) => x !== i))}
                                            className="grid size-10 place-items-center rounded-xl text-muted hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                                            aria-label="حذف البند"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="التفاصيل" />
                        <div className="space-y-4">
                            <Field label="تاريخ الاستحقاق" error={form.errors.due_date} hint="اتركيه فارغًا لاستخدام إعداد النادي">
                                <Input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                            </Field>
                            <Field label="ملاحظات" error={form.errors.notes}>
                                <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            </Field>
                        </div>
                    </Card>
                    <Card className="bg-brand-50/50">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted">الإجمالي قبل الضريبة</span>
                            <span className="text-xl font-semibold num">{money(total, currency)}</span>
                        </div>
                        <p className="mt-1 text-xs text-muted">تُضاف ضريبة القيمة المضافة حسب إعدادات النادي عند الإصدار.</p>
                        <Button type="submit" className="mt-4 w-full" loading={form.processing}>إصدار الفاتورة</Button>
                    </Card>
                </div>
            </form>
        </ClubLayout>
    );
}
