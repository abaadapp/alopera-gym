import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Check, Eye, EyeOff, KeyRound, Plus, Search, ShieldCheck, Sparkles, Trash2, UserRound, Wallet } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Field, Input, Modal, PageHeader, Select, Textarea, BackLink } from '@/Components/ui';
import { FormSection, Initials } from '@/Components/Payroll/parts';
import { cn } from '@/lib/cn';
import PermissionPicker, { type PermissionGroup } from '@/Components/Permissions/PermissionPicker';
import type { Allowance, Can, EmployeeRow } from './Employees';

type Option = { id: number; name: string };
interface Props {
    employee: (EmployeeRow & { notes: string | null; trainer_id: number | null }) | null;
    jobTitles: Option[];
    branches: Option[];
    trainers: (Option & { linked: boolean })[];
    statuses: Record<string, string>;
    access: {
        roles: { id: number; name: string; key: string; assignable: boolean; permissions: string[] }[];
        branches: Option[];
        users: { id: number; name: string; email: string; role: string | null; linkable: boolean; permissions: string[]; own_permissions: boolean }[];
        groups: PermissionGroup[];
        decides: string[];
        sensitive: string[];
        requires: Record<string, string>;
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
        account_password: '',
        account_password_confirmation: '',
        account_custom: false,
        account_permissions: [] as string[],
    });
    // What the account opens when it follows its role: the chosen role's list, or the linked account's as it stands.
    const roleList = (): string[] => form.data.account_mode === 'new'
        ? access?.roles.find((r) => String(r.id) === form.data.account_role_id)?.permissions ?? []
        : access?.users.find((u) => String(u.id) === form.data.account_user_id)?.permissions ?? [];
    const shownPermissions = form.data.account_custom ? form.data.account_permissions : roleList();
    // The sign-in email follows the email above until it is changed here, so the two never disagree by accident.
    const [ownLoginEmail, setOwnLoginEmail] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const setEmail = (email: string) => form.setData((d) => ({ ...d, email, account_email: ownLoginEmail ? d.account_email : email }));
    const generate = () => {
        const password = strongPassword();
        form.setData((d) => ({ ...d, account_password: password, account_password_confirmation: password }));
        setShowPassword(true);
    };
    const [addingTitle, setAddingTitle] = useState(false);
    const setAllowance = (i: number, patch: Partial<Allowance>) => form.setData('allowances', form.data.allowances.map((a, j) => (j === i ? { ...a, ...patch } : a)));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) form.put(route('club.employees.update', employee.id));
        else form.post(route('club.employees.store'));
    };

    return (
        <ClubLayout title={editing ? `تعديل ${employee.name}` : 'إضافة موظف'}>
            <PageHeader
                back={<BackLink href={editing ? route('club.employees.show', employee.id) : route('club.employees.index')}>{editing ? 'ملف الموظف' : 'الموظفون'}</BackLink>}
                title={editing ? 'تعديل ملف الموظف' : 'إضافة موظف'}
                subtitle={editing ? 'تعديل بيانات الموظف ووظيفته وراتبه حسب صلاحياتك' : 'أضف موظفًا جديدًا وحدّد وظيفته وفرعه'}
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
                        <Field label="المسمى الوظيفي" hint="للكشوف والتقارير — الصلاحيات من الدور في «الدخول والأمان»" error={form.errors.job_title_id}>
                            <div className="flex items-start gap-2">
                                <Select className="min-w-0 flex-1" value={form.data.job_title_id} onChange={(e) => form.setData('job_title_id', e.target.value)} placeholder="اختر المسمى…">
                                    {jobTitles.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                </Select>
                                {can.manage && <Button type="button" variant="outline" className="shrink-0 px-0 size-11" icon={<Plus className="size-4" />} aria-label="إضافة مسمى وظيفي" onClick={() => setAddingTitle(true)} />}
                            </div>
                        </Field>
                        <Field label="الفرع الأساسي" error={form.errors.branch_id}>
                            <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="الفرع الرئيسي">
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="رقم الهاتف" error={form.errors.phone}><Input dir="ltr" placeholder="+968 9xxxxxxx" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                        <Field label="البريد الإلكتروني" error={form.errors.email}><Input dir="ltr" type="email" value={form.data.email} onChange={(e) => setEmail(e.target.value)} /></Field>
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

                {access ? (
                    <FormSection icon={KeyRound} title="الدخول والأمان" hint="بالبريد وكلمة المرور يدخل الموظف اللوحة — الدور يحدد ما يفتحه">
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            {([['none', 'بلا حساب', 'موظف لا يدخل اللوحة'], ['link', 'ربط حساب موجود', 'مستخدم أُنشئ من قبل'], ['new', 'فتح حساب جديد', 'ببريد وكلمة مرور تحددهما الآن']] as const).map(([mode, title, hint]) => (
                                <button key={mode} type="button" onClick={() => form.setData('account_mode', mode)} className={cn('rounded-[10px] border p-3 text-start transition-colors', form.data.account_mode === mode ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]')}>
                                    <span className="text-sm font-medium text-ink">{title}</span>
                                    <span className="mt-1 block text-[12px] text-[#6b7280]">{hint}</span>
                                </button>
                            ))}
                        </div>
                        {form.data.account_mode === 'link' && (
                            <Field label="الحساب" className="mt-4" error={form.errors.account_user_id} required>
                                <AccountSearch users={access.users} value={form.data.account_user_id} onChange={(id) => form.setData('account_user_id', id)} />
                            </Field>
                        )}
                        {form.data.account_mode === 'new' && (
                            <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                                <Field label="بريد الدخول" hint={ownLoginEmail ? 'بريد للدخول غير البريد أعلاه' : 'هو البريد أعلاه — يتغير معه ما لم تغيّره هنا'} error={form.errors.account_email} required><Input dir="ltr" type="email" autoComplete="off" value={form.data.account_email} onChange={(e) => { setOwnLoginEmail(true); form.setData('account_email', e.target.value); }} /></Field>
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
                                <Field label="كلمة المرور" className="md:col-span-3" hint="ثمانية أحرف على الأقل، أو اتركها فارغة فتُولَّد كلمة مؤقتة وتُعرض لك مرة واحدة بعد الحفظ. تُحفظ مُجزّأة ولا تُعرض بعدها." error={form.errors.account_password}>
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                        <div className="relative">
                                            <Input dir="ltr" type={showPassword ? 'text' : 'password'} autoComplete="new-password" placeholder="كلمة المرور" aria-label="كلمة المرور" className="pl-10" value={form.data.account_password} onChange={(e) => form.setData('account_password', e.target.value)} />
                                            <button type="button" onClick={() => setShowPassword((v) => !v)} aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'} className="absolute inset-y-0 left-0 grid w-10 place-items-center text-[#9ca3af] hover:text-ink">{showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button>
                                        </div>
                                        <Input dir="ltr" type={showPassword ? 'text' : 'password'} autoComplete="new-password" placeholder="تأكيد كلمة المرور" aria-label="تأكيد كلمة المرور" value={form.data.account_password_confirmation} onChange={(e) => form.setData('account_password_confirmation', e.target.value)} />
                                        <Button type="button" variant="outline" icon={<Sparkles className="size-3.5" />} onClick={generate}>توليد كلمة قوية</Button>
                                    </div>
                                </Field>
                            </div>
                        )}
                    </FormSection>
                ) : editing && employee.user_id ? (
                    <p className="text-[12px] text-muted">للموظف حساب دخول؛ دوره وصلاحياته وكلمة مروره من ملف الموظف.</p>
                ) : null}

                <FormSection icon={Wallet} title="الراتب" hint="منه يُملأ مسيّر رواتب الشهر — سري، يُقرأ ويُكتب بصلاحية مستقلة، ولا يعدّل أحد راتبه بنفسه"
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


                {access && (
                    <FormSection icon={ShieldCheck} title="الصلاحيات" hint="حدّد ما يفتحه هذا الموظف — لا شيء يُفتح ما لم تُعلّمه">
                        {form.data.account_mode === 'none' ? (
                            <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">لا حساب دخول لهذا الموظف بعد، فلا صلاحيات تعمل له. اختر «ربط حساب موجود» أو «فتح حساب جديد» في «الدخول والأمان» لتحدّد ما يفتحه.</p>
                        ) : (
                            <div className="space-y-3">
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {[{ on: false, title: 'اتبع صلاحيات الدور', hint: 'تتبدّل صلاحياته كلما تبدّل دوره' }, { on: true, title: 'خصّص لهذا الموظف', hint: 'قائمة له وحده — لا تتبدّل مع الدور' }].map((opt) => (
                                        <button key={String(opt.on)} type="button" onClick={() => form.setData((d) => ({ ...d, account_custom: opt.on, account_permissions: opt.on ? roleList() : d.account_permissions }))}
                                            className={cn('rounded-[10px] border p-3 text-start transition-colors', form.data.account_custom === opt.on ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]')}>
                                            <span className="flex items-center gap-2">
                                                <span className={cn('flex size-4 shrink-0 items-center justify-center rounded-full border', form.data.account_custom === opt.on ? 'border-[#111]' : 'border-[#d1d5db]')}>{form.data.account_custom === opt.on && <span className="size-2 rounded-full bg-[#111]" />}</span>
                                                <span className="text-sm font-medium text-ink">{opt.title}</span>
                                            </span>
                                            <span className="mt-1 block ps-6 text-[12px] text-[#6b7280]">{opt.hint}</span>
                                        </button>
                                    ))}
                                </div>
                                {!form.data.account_custom && <p className="text-[12px] text-muted">{form.data.account_mode === 'new' && !form.data.account_role_id ? 'اختر الدور في «الدخول والأمان» لتظهر صلاحياته.' : 'ما يفتحه الدور — يُعرض ولا يُعدَّل من هنا. للإضافة إليه أو الحذف منه لهذا الموظف وحده اختر «خصّص لهذا الموظف»؛ الدور نفسه لا يتغير.'}</p>}
                                <PermissionPicker groups={access.groups} value={shownPermissions} onChange={(next) => form.setData('account_permissions', next)} editable={form.data.account_custom} mine={(p) => access.decides.includes(p)} sensitive={access.sensitive} requires={access.requires} />
                                {(form.errors as Record<string, string>).account_permissions && <p className="text-xs text-red-600">{(form.errors as Record<string, string>).account_permissions}</p>}
                            </div>
                        )}
                    </FormSection>
                )}

                <div className="flex items-center gap-3">
                    <Button type="submit" icon={<Check className="size-4" />} loading={form.processing}>{editing ? 'حفظ التغييرات' : 'حفظ الموظف'}</Button>
                    <Button variant="outline" href={editing ? route('club.employees.show', employee.id) : route('club.employees.index')}>إلغاء</Button>
                </div>
            </form>
            {addingTitle && <JobTitleModal onClose={() => setAddingTitle(false)} onAdded={(id) => form.setData('job_title_id', String(id))} />}
        </ClubLayout>
    );
}

type Account = NonNullable<Props['access']>['users'][number];

/**
 * The accounts that may be linked, searched by name or email in the page (they are already sent with it): each row
 * says its role; one that opens more than the actor stays in the list, disabled, with why.
 */
function AccountSearch({ users, value, onChange }: { users: Account[]; value: string; onChange: (id: string) => void }) {
    const [q, setQ] = useState('');
    const chosen = users.find((u) => String(u.id) === value);
    const term = q.trim().toLowerCase();
    const hits = users.filter((u) => term === '' || u.name.toLowerCase().includes(term) || u.email.toLowerCase().includes(term));

    return (
        <div className="space-y-2">
            {chosen && (
                <div className="flex items-center justify-between gap-3 rounded-[10px] border border-[#111] bg-[#f9fafb] px-3 py-2.5">
                    <span className="min-w-0"><span className="block truncate text-sm font-medium text-ink">{chosen.name}</span><span className="block truncate text-[12px] text-[#6b7280]" dir="ltr">{chosen.email}</span></span>
                    <button type="button" className="shrink-0 text-[12px] text-[#6b7280] underline-offset-2 hover:underline" onClick={() => onChange('')}>تغيير</button>
                </div>
            )}
            {!chosen && (
                <>
                    <label className="relative block">
                        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث بالاسم أو البريد الإلكتروني…" aria-label="ابحث عن الحساب"
                            className="h-11 w-full rounded-[10px] border border-[#e5e7eb] bg-white ps-9 pe-3 text-sm outline-none focus:border-[#111]" />
                    </label>
                    <div className="max-h-64 overflow-y-auto rounded-[10px] border border-[#e5e7eb] bg-white shadow-[0_8px_24px_rgba(0,0,0,0.06)]" role="listbox" aria-label="الحسابات">
                        {hits.length === 0 && <p className="px-3 py-4 text-center text-[13px] text-[#9ca3af]">لا حسابات مطابقة.</p>}
                        {hits.map((u) => (
                            <button key={u.id} type="button" role="option" aria-selected={false} disabled={!u.linkable} onClick={() => onChange(String(u.id))}
                                className="flex w-full items-center justify-between gap-3 border-b border-[#f2f2f0] px-3 py-2.5 text-start last:border-b-0 hover:bg-[#f9fafb] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white">
                                <span className="min-w-0"><span className="block truncate text-sm text-ink">{u.name}</span><span className="block truncate text-[12px] text-[#6b7280]" dir="ltr">{u.email}</span></span>
                                <span className="shrink-0 text-[11px] text-[#9ca3af]">{u.linkable ? u.role ?? '' : 'يفتح ما لا تفتحه'}</span>
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

/** A job title added from the form, by the same route as the job titles page (employees.manage), then chosen. */
function JobTitleModal({ onClose, onAdded }: { onClose: () => void; onAdded: (id: number) => void }) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const submit = () => router.post(route('club.job-titles.store'), { name }, {
        preserveState: true, preserveScroll: true,
        onStart: () => setBusy(true), onFinish: () => setBusy(false),
        onError: (errors) => setError(errors.name ?? Object.values(errors)[0] ?? null),
        onSuccess: (page) => {
            const added = (page.props.jobTitles as Option[] | undefined)?.find((t) => t.name === name.trim());
            if (added) onAdded(added.id);
            onClose();
        },
    });

    return (
        <Modal open onClose={onClose} size="confirm" title="مسمى وظيفي جديد" footer={<><Button variant="outline" onClick={onClose} disabled={busy}>إلغاء</Button><Button onClick={submit} loading={busy}>إضافة</Button></>}>
            <Field label="المسمى" error={error ?? undefined} required><Input autoFocus value={name} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } }} placeholder="مثال: مشرفة الصالة" /></Field>
        </Modal>
    );
}

/** Sixteen characters from upper and lower case, digits and symbols, each at least once, from the browser's secure random source. */
function strongPassword(): string {
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%*?-_'];
    const pick = (chars: string) => chars[crypto.getRandomValues(new Uint32Array(1))[0] % chars.length];
    const chars = [...sets.map(pick), ...Array.from({ length: 12 }, () => pick(sets.join('')))];
    for (let i = chars.length - 1; i > 0; i--) {
        const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}
