import { useRef, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Lock, Paperclip, Pencil, Plus, Trash2, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { clientKey } from '@/Components/Inventory/parts';
import PeriodTabs, { periodParams, type Period } from '@/Components/PeriodTabs';
import { Button, ConfirmAction, DataTable, Field, Input, Modal, PageHeader, Select, StatCard, FileInput, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { useCurrency } from '@/hooks/useShared';
import { date, money, isoDate } from '@/lib/format';
import { paymentMethod } from '@/lib/labels';
import type { Option, Paginated } from '@/types';

interface ExpenseRow {
    id: number;
    number: string;
    description: string;
    amount: string;
    payment_method: string;
    payee: string | null;
    reference: string | null;
    expense_category_id: number | null;
    supplier_id: number | null;
    category: string | null;
    expense_date: string;
    has_attachment: boolean;
    account_id: number | null;
    /** The cash box or bank account that paid it. */
    account: string | null;
    /** Dated in a closed accounting period: final, corrected by a new expense in an open one. */
    locked: boolean;
}

type MoneyAccount = { id: number; name: string; kind: string };

type Filters = Record<string, string | undefined>;

interface Props {
    expenses: Paginated<ExpenseRow>;
    total: number;
    categories: Option[];
    suppliers: Option[];
    moneyAccounts: MoneyAccount[];
    filters: Filters;
    period: Period;
}

const today = () => isoDate();

export default function ExpensesIndex({ expenses, total, categories, suppliers, moneyAccounts, filters, period }: Props) {
    const currency = useCurrency();
    const [editing, setEditing] = useState<ExpenseRow | 'new' | null>(null);
    const [categoryOpen, setCategoryOpen] = useState(false);
    const params = { ...filters, ...periodParams(period) };

    const exportParams = { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), ...periodParams(period) } as Record<string, string>;

    const Actions = ({ e }: { e: ExpenseRow }) => (
        <div className="flex justify-end gap-1">
            {e.has_attachment && <Button variant="ghost" size="sm" href={route('club.expenses.attachment', e.id)} external icon={<Paperclip className="size-3.5" />} aria-label="المرفق" />}
            {e.locked ? (
                <span className="inline-flex items-center gap-1 px-2 text-xs text-muted" title="في فترة محاسبية مقفلة: لا يُعدّل ولا يُحذف. سجّل التصحيح بمصروف جديد في فترة مفتوحة."><Lock className="size-3.5" /> مقفل</span>
            ) : (
                <>
                    <Button variant="ghost" size="sm" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(e)} aria-label="تعديل" />
                    <ConfirmAction url={route('club.expenses.destroy', e.id)} method="delete" title="حذف المصروف" message={`حذف «${e.description}»؟`} confirmLabel="حذف" variant="ghost" icon={<Trash2 className="size-3.5 text-red-600" />}>
                        <span className="sr-only">حذف</span>
                    </ConfirmAction>
                </>
            )}
        </div>
    );

    const columns: Column<ExpenseRow>[] = [
        { key: 'number', header: 'الرقم', cell: (e) => <span className="text-xs text-muted num">{e.number}</span> },
        { key: 'date', header: 'التاريخ', cell: (e) => <span className="whitespace-nowrap">{date(e.expense_date)}</span> },
        { key: 'description', header: 'الوصف', cell: (e) => <span className="font-medium">{e.description}</span> },
        { key: 'category', header: 'التصنيف', cell: (e) => e.category ?? <span className="text-muted">غير مصنف</span> },
        { key: 'payee', header: 'المستفيد', cell: (e) => e.payee ?? '—' },
        { key: 'method', header: 'الطريقة والحساب', cell: (e) => <span className="text-muted">{paymentMethod[e.payment_method] ?? e.payment_method}{e.account && <span className="block text-xs">{e.account}</span>}</span> },
        { key: 'amount', header: 'المبلغ', align: 'end', cell: (e) => <span className="font-medium num">{money(e.amount, currency)}</span> },
        { key: 'actions', header: '', align: 'end', cell: (e) => <Actions e={e} /> },
    ];

    const tableFilters: TableFilter[] = [
        { param: 'category_id', label: 'التصنيف', options: categories.map((c) => ({ value: String(c.id), label: c.name })) },
        { param: 'payment_method', label: 'طريقة الدفع', options: Object.entries(paymentMethod).filter(([k]) => k !== 'paymob').map(([value, text]) => ({ value, label: text })) },
        { param: 'per_page', label: 'عدد الصفوف', options: ['30', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="المصروفات">
            <PageHeader
                title="المصروفات"
                subtitle="الإيجار والرواتب والصيانة وكل ما يُصرف، ومن أي صندوق أو حساب"
                actions={
                    <>
                        <ExportMenu href={route('club.expenses.export')} params={exportParams} title="المصروفات" formats={['xlsx', 'csv']} />
                        <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>مصروف جديد</Button>
                    </>
                }
            />

            <div className="mb-4 grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard label="إجمالي المصروفات (حسب الفترة والفلاتر)" value={money(total, currency)} tone="red" icon={<Wallet className="size-5" />} />
            </div>

            <PeriodTabs period={period} params={{ ...filters }} />

            <DataTable
                rows={expenses.data}
                columns={columns}
                rowKey={(e) => e.id}
                params={params}
                pagination={expenses as unknown as PageMeta}
                search={{ placeholder: 'الوصف أو المستفيد' }}
                filters={tableFilters}
                toolbar={<Button variant="ghost" size="sm" icon={<Plus className="size-3.5" />} onClick={() => setCategoryOpen(true)}>تصنيف</Button>}
                empty="لا توجد مصروفات"
                card={(e) => (
                    <div className="space-y-1 px-4 py-3 text-[13px]">
                        <div className="flex items-start justify-between gap-3"><span className="font-medium">{e.description}</span><span className="font-medium num">{money(e.amount, currency)}</span></div>
                        <p className="text-muted">{date(e.expense_date)} · {e.category ?? 'غير مصنف'} · {paymentMethod[e.payment_method] ?? e.payment_method}{e.account ? ` · ${e.account}` : ''}</p>
                        <div className="flex items-center justify-between"><span className="text-xs text-muted num">{e.number}</span><Actions e={e} /></div>
                    </div>
                )}
            />

            {editing && <ExpenseModal expense={editing === 'new' ? null : editing} categories={categories} suppliers={suppliers} accounts={moneyAccounts} onClose={() => setEditing(null)} />}
            <CategoryModal open={categoryOpen} onClose={() => setCategoryOpen(false)} />
        </ClubLayout>
    );
}

/** The accounts each method may pay from (as the server checks it): cash from a cash box, a transfer from a bank account. */
const PAYS_FROM: Record<string, string[]> = { cash: ['cash'], bank_transfer: ['bank'], card: ['cash', 'bank'], other: ['cash', 'bank'] };

function ExpenseModal({ expense, categories, suppliers, accounts, onClose }: { expense: ExpenseRow | null; categories: Option[]; suppliers: Option[]; accounts: MoneyAccount[]; onClose: () => void }) {
    // One key per form: a double click or a retry records one expense.
    const key = useRef(clientKey());
    const form = useForm<{
        description: string;
        amount: string;
        expense_date: string;
        expense_category_id: string;
        supplier_id: string;
        payment_method: string;
        payee: string;
        reference: string;
        attachment: File | null;
        account_id: string;
    }>({
        description: expense?.description ?? '',
        amount: expense?.amount ?? '',
        expense_date: expense?.expense_date ?? today(),
        expense_category_id: expense?.expense_category_id ? String(expense.expense_category_id) : '',
        supplier_id: expense?.supplier_id ? String(expense.supplier_id) : '',
        payment_method: expense?.payment_method ?? 'cash',
        payee: expense?.payee ?? '',
        reference: expense?.reference ?? '',
        attachment: null,
        account_id: expense?.account_id ? String(expense.account_id) : '',
    });
    const suiting = accounts.filter((a) => PAYS_FROM[form.data.payment_method]?.includes(a.kind));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: onClose };
        if (expense) {
            form.transform((d) => ({ ...d, _method: 'put' }));
            form.post(route('club.expenses.update', expense.id), options);
        } else {
            form.transform((d) => ({ ...d, client_key: key.current }));
            form.post(route('club.expenses.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={expense ? `تعديل ${expense.number}` : 'مصروف جديد'}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>إلغاء</Button>
                    <Button type="submit" form="expense-form" loading={form.processing}>حفظ</Button>
                </>
            }
        >
            <form id="expense-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="الوصف" error={form.errors.description} required className="sm:col-span-2">
                    <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} required />
                </Field>
                <Field label="المبلغ" error={form.errors.amount} required>
                    <Input type="number" step="0.001" min="0.001" dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                </Field>
                <Field label="التاريخ" error={form.errors.expense_date} required>
                    <Input type="date" max={today()} value={form.data.expense_date} onChange={(e) => form.setData('expense_date', e.target.value)} required />
                </Field>
                <Field label="التصنيف" error={form.errors.expense_category_id}>
                    <Select value={form.data.expense_category_id} onChange={(e) => form.setData('expense_category_id', e.target.value)} placeholder="بدون تصنيف">
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                </Field>
                <Field label="طريقة الدفع" error={form.errors.payment_method}>
                    <Select value={form.data.payment_method} onChange={(e) => form.setData((d) => ({ ...d, payment_method: e.target.value, account_id: '' }))}>
                        <option value="cash">نقدًا</option>
                        <option value="bank_transfer">تحويل بنكي</option>
                        <option value="card">بطاقة</option>
                        <option value="other">أخرى</option>
                    </Select>
                </Field>
                {suiting.length > 1 && (
                    <Field label="من حساب" error={form.errors.account_id}>
                        <Select value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>
                            <option value="">الحساب الافتراضي للطريقة</option>
                            {suiting.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </Select>
                    </Field>
                )}
                <Field label="المستفيد" error={form.errors.payee}>
                    <Input value={form.data.payee} onChange={(e) => form.setData('payee', e.target.value)} />
                </Field>
                {suppliers.length > 0 && <Field label="المورد" error={form.errors.supplier_id}>
                    <Select value={form.data.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)} placeholder="—">
                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </Field>}
                <Field label="المرجع" error={form.errors.reference}>
                    <Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                </Field>
                <Field label={expense?.has_attachment ? 'استبدال المرفق' : 'مرفق (فاتورة/إيصال)'} error={form.errors.attachment}>
                    <FileInput accept="image/*,application/pdf" file={form.data.attachment} onChange={(f) => form.setData('attachment', f)} />
                </Field>
            </form>
        </Modal>
    );
}

function CategoryModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const form = useForm({ name: '' });

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="تصنيف مصروفات جديد" size="compact"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>إلغاء</Button>
                    <Button
                        loading={form.processing}
                        onClick={() => form.post(route('club.expense-categories.store'), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } })}
                    >
                        إضافة
                    </Button>
                </>
            }
        >
            <Field label="اسم التصنيف" error={form.errors.name} required>
                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
            </Field>
        </Modal>
    );
}
