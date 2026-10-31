import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, Check, KeyRound, Plus, Trash2, UserRound, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Field, Input, PageHeader, Select, Textarea } from '@/Components/ui';
import { FormSection, Initials } from '@/Components/Payroll/parts';
import { cn } from '@/lib/cn';
import type { Allowance, Can, EmployeeRow } from './Employees';

type Option = { id: number; name: string };
interface Props {
    employee: (EmployeeRow & { notes: string | null; trainer_id: number | null }) | null;
    jobTitles: Option[];
    branches: Option[];
    trainers: (Option & { linked: boolean })[];
    statuses: Record<string, string>;
    access: {
        roles: { id: number; name: string; key: string; assignable: boolean }[];
        branches: Option[];
        users: { id: number; name: string; email: string; role: string | null; linkable: boolean }[];
    } | null;
    can: Can & { basic: boolean };
    confidential: boolean;
}

/** Adding or editing a staff file, section by section; each section is shown to whoever may write it. */
export default function EmployeeForm({ employee, jobTitles, branches, trainers, statuses, access, can, confidential }: Props) {
    const editing = employee !== null;
    const form = useForm({
        name: employee?.name ?? '',
        phone: employee?.phone ?? '',
        email: employee?.email ?? '',
        job_title_id: employee?.job_title_id ? String(employee.job_title_id) : '',
        branch_id: employee?.branch_id ? String(employee.branch_id) : '',
        hired_at: employee?.hired_at ?? '',
        status: employee?.status ?? 'active',
        trainer_id: employee?.trainer_id ? String(employee.trainer_id) : '',
        notes: employee?.notes ?? '',
        basic_salary: employee?.basic_salary ?? '0',
        allowances: (employee?.allowances ?? []) as Allowance[],
        payment_method: employee?.payment_method ?? 'bank',
        bank_name: employee?.bank_name ?? '',
        iban: employee?.iban ?? '',
        account_number: employee?.account_number ?? '',
        account_mode: 'none' as 'none' | 'link' | 'new',
        account_user_id: '',
        account_email: employee?.email ?? '',
        account_role_id: '',
        account_branch_id: '',
    });
    const setAllowance = (i: number, patch: Partial<Allowance>) => form.setData('allowances', form.data.allowances.map((a, j) => (j === i ? { ...a, ...patch } : a)));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) form.put(route('club.employees.update', employee.id));
        else form.post(route('club.employees.store'));
    };

    return (
        <ClubLayout title={editing ? `تعديل ${employee.name}` : 'إضافة موظف'}>
            <PageHeader
                back={<Link href={editing ? route('club.employees.show', employee.id) : route('club.employees.index')} className="mb-2 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><ArrowRight className="size-4" /> {editing ? 'ملف الموظف' : 'الموظفون'}</Link>}
                title={editing ? 'تعديل ملف الموظف' : 'إضافة موظف'}
                subtitle={editing ? 'تعديل بيانات الموظف ووظيفته وراتبه حسب صلاحياتك' : 'أضف موظفًا جديدًا وحدّد وظيفته وفرعه، وافتح له حساب دخول إن احتاج'}
            />

            <form onSubmit={submit} className="max-w-3xl space-y-5">
                {editing && (
                    <div className="card flex items-center gap-4 p-5">
                        <Initials name={employee.name} />
                        <div className="min-w-0">
                            <p className="truncate font-bold text-ink">{employee.name}</p>
                            <p className="truncate text-[12px] text-[#9ca3af]">{employee.job_title ?? 'بلا مسمى'} · {employee.branch ?? 'الفرع الرئيسي'}</p>
                        </div>
                    </div>
                )}

                <FormSection icon={UserRound} title="البيانات الأساسية" hint="ما يظهر في القوائم والتقارير والكشوف" locked={!can.basic && 'تعديل البيانات الأساسية صلاحية لا تملكها؛ يحفظ هذا النموذج ما تملكه فقط.'}>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="الاسم الكامل" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label="المسمى الوظيفي" error={form.errors.job_title_id}>
                            <Select value={form.data.job_title_id} onChange={(e) => form.setData('job_title_id', e.target.value)} placeholder="بلا مسمى">
                                {jobTitles.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="رقم الهاتف" error={form.errors.phone}><Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                        <Field label="البريد الإلكتروني" error={form.errors.email}><Input dir="ltr" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                        <Field label="الفرع" error={form.errors.branch_id}>
                            <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="الفرع الرئيسي">
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="تاريخ التعيين" error={form.errors.hired_at}><Input type="date" value={form.data.hired_at} onChange={(e) => form.setData('hired_at', e.target.value)} /></Field>
                        <Field label="الحالة الوظيفية" error={form.errors.status} required>
                            <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                {Object.entries(statuses).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </Select>
                        </Field>
                        <Field label="ملف المدرب" hint="يربط الموظف بملفه في فريق التدريب، فلا يتكرر" error={form.errors.trainer_id}>
                            <Select value={form.data.trainer_id} onChange={(e) => form.setData('trainer_id', e.target.value)} placeholder="ليس مدربًا">
                                {trainers.map((t) => <option key={t.id} value={t.id} disabled={t.linked}>{t.name}{t.linked ? ' — مرتبط بموظف آخر' : ''}</option>)}
                            </Select>
                        </Field>
                        <Field label="ملاحظات" error={form.errors.notes} className="md:col-span-2"><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                    </div>
                </FormSection>

                <FormSection icon={Wallet} title="الراتب والبدلات والصرف" hint="سري — يُقرأ ويُكتب بصلاحية مستقلة، ولا يعدّل أحد راتبه بنفسه"
                    locked={!can.compensation && (confidential ? 'تعديل الرواتب والبيانات البنكية صلاحية لا تملكها؛ تبقى القيم المسجلة كما هي.' : 'قراءة الرواتب صلاحية لا تملكها — يكتبها من يملكها، ولا يتغير ما هو مسجّل بحفظك.')}>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="الراتب الأساسي" error={form.errors.basic_salary} required><Input dir="ltr" inputMode="decimal" value={form.data.basic_salary} onChange={(e) => form.setData('basic_salary', e.target.value)} /></Field>
                        <Field label="طريقة الصرف" error={form.errors.payment_method}>
                            <Select value={form.data.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}>
                                <option value="bank">تحويل بنكي</option>
                                <option value="cash">نقدًا</option>
                            </Select>
                        </Field>
                    </div>
                    <div className="mt-4">
                        <p className="mb-2 text-[13px] font-medium text-ink">البدلات</p>
                        <div className="space-y-2">
                            {form.data.allowances.map((a, i) => (
                                <div key={i} className="flex items-start gap-2">
                                    <Input className="flex-1" placeholder="سكن، مواصلات…" value={a.name} onChange={(e) => setAllowance(i, { name: e.target.value })} />
                                    <Input className="w-32" dir="ltr" inputMode="decimal" placeholder="0.000" value={a.amount} onChange={(e) => setAllowance(i, { amount: e.target.value })} />
                                    <Button type="button" variant="ghost" icon={<Trash2 className="size-4" />} aria-label="حذف البدل" onClick={() => form.setData('allowances', form.data.allowances.filter((_, j) => j !== i))} />
                                </div>
                            ))}
                            <Button type="button" size="sm" variant="outline" icon={<Plus className="size-3.5" />} onClick={() => form.setData('allowances', [...form.data.allowances, { name: '', amount: '' }])}>إضافة بدل</Button>
                            {form.errors.allowances && <p className="text-xs text-red-600">{form.errors.allowances}</p>}
                        </div>
                    </div>
                    {form.data.payment_method === 'bank' && (
                        <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                            <Field label="البنك" error={form.errors.bank_name}><Input value={form.data.bank_name} onChange={(e) => form.setData('bank_name', e.target.value)} /></Field>
                            <Field label="الآيبان" error={form.errors.iban}><Input dir="ltr" value={form.data.iban} onChange={(e) => form.setData('iban', e.target.value)} /></Field>
                            <Field label="رقم الحساب" error={form.errors.account_number}><Input dir="ltr" value={form.data.account_number} onChange={(e) => form.setData('account_number', e.target.value)} /></Field>
                        </div>
                    )}
                </FormSection>

                {access ? (
                    <FormSection icon={KeyRound} title="حساب الدخول" hint="للموظف الذي يستخدم اللوحة — الدور يحدد ما يفتحه">
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            {([['none', 'بلا حساب', 'موظف لا يدخل اللوحة'], ['link', 'ربط حساب موجود', 'مستخدم أُنشئ من قبل'], ['new', 'فتح حساب جديد', 'تُعرض كلمة مرور مؤقتة مرة واحدة']] as const).map(([mode, title, hint]) => (
                                <button key={mode} type="button" onClick={() => form.setData('account_mode', mode)} className={cn('rounded-[10px] border p-3 text-start transition-colors', form.data.account_mode === mode ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]')}>
                                    <span className="text-sm font-medium text-ink">{title}</span>
                                    <span className="mt-1 block text-[12px] text-[#6b7280]">{hint}</span>
                                </button>
                            ))}
                        </div>
                        {form.data.account_mode === 'link' && (
                            <Field label="الحساب" className="mt-4" error={form.errors.account_user_id} required>
                                <Select value={form.data.account_user_id} onChange={(e) => form.setData('account_user_id', e.target.value)} placeholder="اختر الحساب">
                                    {access.users.map((u) => <option key={u.id} value={u.id} disabled={!u.linkable}>{u.name} — {u.email}{u.role ? ` (${u.role})` : ''}{!u.linkable ? ' — يفتح ما لا تفتحه' : ''}</option>)}
                                </Select>
                            </Field>
                        )}
                        {form.data.account_mode === 'new' && (
                            <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                                <Field label="بريد الدخول" error={form.errors.account_email} required><Input dir="ltr" type="email" value={form.data.account_email} onChange={(e) => form.setData('account_email', e.target.value)} /></Field>
                                <Field label="الدور" error={form.errors.account_role_id} required>
                                    <Select value={form.data.account_role_id} onChange={(e) => form.setData('account_role_id', e.target.value)} placeholder="اختر الدور">
                                        {access.roles.map((r) => <option key={r.id} value={r.id} disabled={!r.assignable}>{r.name}{!r.assignable ? ' — يفتح ما لا تملكه' : ''}</option>)}
                                    </Select>
                                </Field>
                                <Field label="الفروع المصرح بها" error={form.errors.account_branch_id}>
                                    <Select value={form.data.account_branch_id} onChange={(e) => form.setData('account_branch_id', e.target.value)}>
                                        <option value="">كل الفروع</option>
                                        {access.branches.map((b) => <option key={b.id} value={b.id}>{b.name} فقط</option>)}
                                    </Select>
                                </Field>
                            </div>
                        )}
                    </FormSection>
                ) : editing && employee.user_id ? (
                    <p className="text-[12px] text-muted">للموظف حساب دخول؛ دوره وصلاحياته وكلمة مروره من ملف الموظف.</p>
                ) : null}

                <div className="flex items-center gap-3">
                    <Button type="submit" icon={<Check className="size-4" />} loading={form.processing}>{editing ? 'حفظ التغييرات' : 'حفظ الموظف'}</Button>
                    <Button variant="outline" href={editing ? route('club.employees.show', employee.id) : route('club.employees.index')}>إلغاء</Button>
                </div>
            </form>
        </ClubLayout>
    );
}
