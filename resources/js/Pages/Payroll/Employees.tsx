import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Lock, Pencil, Plus, UserCheck, UserPlus, Users, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, SearchInput, Select, StatCard, Textarea } from '@/Components/ui';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money, phone } from '@/lib/format';

type Allowance = { name: string; amount: string };
type Employee = {
    id: number; name: string; phone: string | null; email: string | null; hired_at: string | null; status: string; notes: string | null;
    job_title_id: number | null; job_title: string | null; branch_id: number | null; branch: string | null;
    user_id: number | null; user: { name: string; email: string; role: string | null } | null; trainer_id: number | null; trainer: string | null;
    basic_salary: string | null; allowances: Allowance[] | null; monthly: string | null; payment_method: string | null; bank_name: string | null; iban: string | null; account_number: string | null;
};
type Option = { id: number; name: string };

interface Props {
    employees: Employee[];
    stats: { active: number; on_leave: number; with_account: number; monthly: string | null };
    jobTitles: Option[];
    branches: Option[];
    users: { id: number; name: string; email: string; role: string | null; linked: boolean }[];
    trainers: { id: number; name: string; linked: boolean }[];
    unlinkedTrainers: number;
    statuses: Record<string, string>;
    confidential: boolean;
    filters: { q?: string; status?: string; job_title_id?: string };
}

const statusTone: Record<string, 'green' | 'amber' | 'gray'> = { active: 'green', on_leave: 'amber', terminated: 'gray' };

export default function Employees({ employees, stats, jobTitles, branches, users, trainers, unlinkedTrainers, statuses, confidential, filters }: Props) {
    const currency = useCurrency();
    const can = useCan();
    const manage = can('employees.manage');
    const [editing, setEditing] = useState<Employee | 'new' | null>(null);
    const filter = (patch: Record<string, string>) =>
        router.get(route('club.employees.index'), Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v)) as Record<string, string>, { preserveState: true, replace: true });

    return (
        <ClubLayout title="الموظفون">
            <PageHeader
                title="الموظفون"
                subtitle="ملفات فريق النادي: الوظيفة والفرع وحساب الدخول، والراتب والبيانات البنكية لمن لديها الصلاحية"
                actions={manage && (
                    <>
                        {unlinkedTrainers > 0 && (
                            <ConfirmAction url={route('club.employees.import-trainers')} title="ملفات للمدربات" message={`ستُنشأ ملفات وظيفية لـ ${unlinkedTrainers} مدربة مرتبطة بملفاتهن الحالية دون تكرار.`} icon={<UserPlus className="size-4" />}>
                                إضافة المدربات ({unlinkedTrainers})
                            </ConfirmAction>
                        )}
                        <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>موظفة جديدة</Button>
                    </>
                )}
            />

            <div className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <StatCard label="على رأس العمل" value={stats.active} icon={<Users className="size-5" />} tone="green" />
                <StatCard label="في إجازة" value={stats.on_leave} icon={<Users className="size-5" />} tone="amber" />
                <StatCard label="لهن حساب دخول" value={stats.with_account} icon={<UserCheck className="size-5" />} tone="blue" />
                <StatCard label="الرواتب الشهرية" value={stats.monthly !== null ? money(stats.monthly, currency) : <span className="inline-flex items-center gap-1 text-base text-muted"><Lock className="size-4" /> سري</span>} icon={<Wallet className="size-5" />} tone="brand" />
            </div>

            <Card padded={false}>
                <div className="flex flex-wrap items-center gap-2 border-b border-line p-4">
                    <div className="w-full max-w-xs"><SearchInput value={filters.q} placeholder="الاسم أو الهاتف…" extra={{ status: filters.status, job_title_id: filters.job_title_id }} /></div>
                    <Select className="h-9 w-40 py-1.5 text-[13px]" value={filters.status ?? ''} onChange={(e) => filter({ status: e.target.value })} aria-label="الحالة">
                        <option value="">كل الحالات</option>
                        {Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </Select>
                    <Select className="h-9 w-44 py-1.5 text-[13px]" value={filters.job_title_id ?? ''} onChange={(e) => filter({ job_title_id: e.target.value })} aria-label="المسمى">
                        <option value="">كل المسميات</option>
                        {jobTitles.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </div>
                {employees.length ? (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الموظفة</th><th>المسمى</th><th>الفرع</th><th>حساب الدخول</th><th>الحالة</th><th>التعيين</th>{confidential && <th className="text-end">الشهري</th>}<th /></tr></thead>
                            <tbody>
                                {employees.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            <span className="font-medium">{e.name}</span>
                                            {e.trainer && <Badge tone="violet" className="ms-2">مدربة</Badge>}
                                            {e.phone && <span className="block text-xs text-muted num">{phone(e.phone)}</span>}
                                        </td>
                                        <td>{e.job_title ?? <span className="text-muted">—</span>}</td>
                                        <td className="text-muted">{e.branch ?? '—'}</td>
                                        <td>{e.user ? <span className="text-[13px]">{e.user.role ?? e.user.name}<span className="block text-xs text-muted" dir="ltr">{e.user.email}</span></span> : <span className="text-[13px] text-muted">بدون حساب</span>}</td>
                                        <td><Badge tone={statusTone[e.status]}>{statuses[e.status]}</Badge></td>
                                        <td className="num text-muted">{e.hired_at ? date(e.hired_at) : '—'}</td>
                                        {confidential && <td className="text-end font-medium num">{money(e.monthly, currency)}</td>}
                                        <td className="text-end">{manage && <button type="button" onClick={() => setEditing(e)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" aria-label={`تعديل ${e.name}`}><Pencil className="size-4" /></button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState icon={<Users className="size-6" />} title="لا موظفات بعد" description="أضيفي ملفات الفريق، أو أنشئي ملفات المدربات الحاليات بضغطة واحدة." action={manage && <Button variant="outline" onClick={() => setEditing('new')}>موظفة جديدة</Button>} />
                )}
            </Card>
            {!confidential && <p className="mt-3 flex items-center gap-1.5 text-[12px] text-muted"><Lock className="size-3.5" /> الرواتب والبيانات البنكية مخفية: تحتاج صلاحية «عرض الرواتب والبيانات البنكية».</p>}

            {editing && <EmployeeForm employee={editing === 'new' ? null : editing} {...{ jobTitles, branches, users, trainers, statuses, confidential }} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function EmployeeForm({ employee, jobTitles, branches, users, trainers, statuses, confidential, onClose }: Pick<Props, 'jobTitles' | 'branches' | 'users' | 'trainers' | 'statuses' | 'confidential'> & { employee: Employee | null; onClose: () => void }) {
    const form = useForm({
        name: employee?.name ?? '', phone: employee?.phone ?? '', email: employee?.email ?? '', hired_at: employee?.hired_at ?? '', status: employee?.status ?? 'active',
        job_title_id: employee?.job_title_id ? String(employee.job_title_id) : '', branch_id: employee?.branch_id ? String(employee.branch_id) : '',
        user_id: employee?.user_id ? String(employee.user_id) : '', trainer_id: employee?.trainer_id ? String(employee.trainer_id) : '', notes: employee?.notes ?? '',
        basic_salary: employee?.basic_salary ?? '', allowances: (employee?.allowances ?? []) as Allowance[], payment_method: employee?.payment_method ?? 'bank',
        bank_name: employee?.bank_name ?? '', iban: employee?.iban ?? '', account_number: employee?.account_number ?? '',
    });
    const errors = form.errors as Record<string, string>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => (confidential ? d : { ...d, basic_salary: undefined, allowances: undefined, payment_method: undefined, bank_name: undefined, iban: undefined, account_number: undefined }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        employee ? form.put(route('club.employees.update', employee.id), opts) : form.post(route('club.employees.store'), opts);
    };
    const setAllowance = (i: number, patch: Partial<Allowance>) => form.setData('allowances', form.data.allowances.map((a, j) => (j === i ? { ...a, ...patch } : a)));

    return (
        <Modal open onClose={onClose} size="lg" title={employee ? `ملف ${employee.name}` : 'موظفة جديدة'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-6">
                <section>
                    <CardHeader title="البيانات الأساسية" className="mb-3" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="الاسم" error={errors.name} required className="sm:col-span-2"><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                        <Field label="الهاتف" error={errors.phone}><Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                        <Field label="البريد" error={errors.email}><Input dir="ltr" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                    </div>
                </section>
                <section>
                    <CardHeader title="الوظيفة" className="mb-3" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="المسمى الوظيفي" error={errors.job_title_id}><Select value={form.data.job_title_id} onChange={(e) => form.setData('job_title_id', e.target.value)} placeholder="—">{jobTitles.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</Select></Field>
                        <Field label="الفرع" error={errors.branch_id}><Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="—">{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</Select></Field>
                        <Field label="تاريخ التعيين" error={errors.hired_at}><Input type="date" value={form.data.hired_at} onChange={(e) => form.setData('hired_at', e.target.value)} /></Field>
                        <Field label="الحالة الوظيفية" error={errors.status}><Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>{Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    </div>
                </section>
                <section>
                    <CardHeader title="الربط" subtitle="حساب الدخول يحدد صلاحياتها (من الإعدادات ← المستخدمون). ربط المدربة يمنع تكرار ملفها." className="mb-3" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="حساب الدخول" error={errors.user_id}>
                            <Select value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)} placeholder="بدون حساب">
                                {users.filter((u) => !u.linked || String(u.id) === String(employee?.user_id)).map((u) => <option key={u.id} value={u.id}>{u.name}{u.role ? ` — ${u.role}` : ''}</option>)}
                            </Select>
                        </Field>
                        <Field label="ملف المدربة" error={errors.trainer_id}>
                            <Select value={form.data.trainer_id} onChange={(e) => form.setData('trainer_id', e.target.value)} placeholder="ليست مدربة">
                                {trainers.filter((t) => !t.linked || String(t.id) === String(employee?.trainer_id)).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </Select>
                        </Field>
                    </div>
                </section>
                {confidential ? (
                    <>
                        <section>
                            <CardHeader title="الراتب والبدلات" subtitle="بالريال العماني حتى 3 منازل عشرية" className="mb-3" />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="الراتب الأساسي" error={errors.basic_salary} required><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.basic_salary} onChange={(e) => form.setData('basic_salary', e.target.value)} /></Field>
                            </div>
                            <div className="mt-3 space-y-2">
                                {form.data.allowances.map((a, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <Input value={a.name} onChange={(e) => setAllowance(i, { name: e.target.value })} placeholder="اسم البدل (مواصلات، سكن…)" aria-label="اسم البدل" />
                                        <Input className="w-36" type="number" step="0.001" min="0" dir="ltr" value={a.amount} onChange={(e) => setAllowance(i, { amount: e.target.value })} placeholder="المبلغ" aria-label="مبلغ البدل" />
                                        <Button type="button" variant="ghost" size="sm" onClick={() => form.setData('allowances', form.data.allowances.filter((_, j) => j !== i))}>حذف</Button>
                                    </div>
                                ))}
                                <Button type="button" variant="outline" size="sm" icon={<Plus className="size-3.5" />} onClick={() => form.setData('allowances', [...form.data.allowances, { name: '', amount: '' }])}>بدل</Button>
                            </div>
                        </section>
                        <section>
                            <CardHeader title="الدفع" subtitle="تُحفظ أرقام الحسابات مشفرة" className="mb-3" />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="طريقة صرف الراتب" error={errors.payment_method}><Select value={form.data.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}><option value="bank">تحويل بنكي</option><option value="cash">نقدًا</option></Select></Field>
                                <Field label="البنك" error={errors.bank_name}><Input value={form.data.bank_name} onChange={(e) => form.setData('bank_name', e.target.value)} /></Field>
                                <Field label="IBAN" error={errors.iban}><Input dir="ltr" value={form.data.iban} onChange={(e) => form.setData('iban', e.target.value)} placeholder="OM…" /></Field>
                                <Field label="رقم الحساب" error={errors.account_number}><Input dir="ltr" value={form.data.account_number} onChange={(e) => form.setData('account_number', e.target.value)} /></Field>
                            </div>
                        </section>
                    </>
                ) : (
                    <p className="flex items-center gap-1.5 rounded-xl bg-amber-50 p-3 text-[13px] text-amber-800"><Lock className="size-4" /> الراتب والبيانات البنكية سرية، ولا تظهر ولا تُعدَّل بدون صلاحيتها.</p>
                )}
                <Field label="ملاحظات" error={errors.notes}><Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
            </form>
        </Modal>
    );
}
