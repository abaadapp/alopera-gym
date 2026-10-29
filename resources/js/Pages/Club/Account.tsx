import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { KeyRound, UserRound } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Avatar } from '@/Components/Topbar';
import { Button, Card, CardHeader, Field, Input, PageHeader, Stat } from '@/Components/ui';

interface Props {
    account: {
        name: string;
        email: string;
        phone: string | null;
        role: string | null;
        branch: string | null;
        branch_locked: boolean;
        last_login_at: string | null;
        created_at: string | null;
    };
}

/** «حسابي»: the signed-in staff member's details, their name and phone, and their password. */
export default function Account({ account }: Props) {
    const profile = useForm({ name: account.name, phone: account.phone ?? '' });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveProfile = (e: FormEvent) => {
        e.preventDefault();
        profile.put(route('club.account.update'), { preserveScroll: true });
    };
    const savePassword = (e: FormEvent) => {
        e.preventDefault();
        password.put(route('club.account.password'), { preserveScroll: true, onSuccess: () => password.reset() });
    };

    return (
        <ClubLayout title="حسابي">
            <PageHeader title="حسابي" subtitle="بياناتك في النادي وكلمة المرور." />

            <div className="card mb-6 flex flex-wrap items-center gap-4 p-5">
                <Avatar name={account.name} size={56} />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-lg font-bold text-[#111]">{account.name}</p>
                    <p className="truncate text-[13px] text-[#6b7280]" dir="ltr">{account.email}</p>
                </div>
                <dl className="grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-4">
                    <Stat label="الدور" value={account.role} />
                    <Stat label="الفرع" value={account.branch ?? (account.branch_locked ? '—' : 'كل الفروع')} />
                    <Stat label="آخر دخول" value={account.last_login_at && <span className="num">{account.last_login_at}</span>} />
                    <Stat label="منذ" value={account.created_at && <span className="num">{account.created_at}</span>} />
                </dl>
            </div>

            <div className="grid gap-6 lg:grid-cols-2 [&>*]:min-w-0">
                <Card>
                    <CardHeader title={<span className="flex items-center gap-2"><UserRound className="size-[18px]" /> بياناتي</span>} subtitle="البريد والدور والفرع يحددها من يدير المستخدمين." />
                    <form onSubmit={saveProfile} className="space-y-4">
                        <Field label="الاسم" error={profile.errors.name} required>
                            <Input value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} required />
                        </Field>
                        <Field label="رقم الهاتف" error={profile.errors.phone} hint="تصلك عليه تنبيهات واتساب الخاصة بعملك، إن فُعّلت.">
                            <Input dir="ltr" value={profile.data.phone} onChange={(e) => profile.setData('phone', e.target.value)} placeholder="+968 9123 4567" />
                        </Field>
                        <Button type="submit" loading={profile.processing}>حفظ</Button>
                    </form>
                </Card>

                <Card>
                    <CardHeader title={<span className="flex items-center gap-2"><KeyRound className="size-[18px]" /> كلمة المرور</span>} subtitle="أدخل كلمة المرور الحالية ثم الجديدة مرتين." />
                    <form onSubmit={savePassword} className="space-y-4">
                        <Field label="كلمة المرور الحالية" error={password.errors.current_password} required>
                            <Input type="password" autoComplete="current-password" value={password.data.current_password} onChange={(e) => password.setData('current_password', e.target.value)} required />
                        </Field>
                        <Field label="كلمة المرور الجديدة" error={password.errors.password} required>
                            <Input type="password" autoComplete="new-password" value={password.data.password} onChange={(e) => password.setData('password', e.target.value)} required />
                        </Field>
                        <Field label="تأكيد كلمة المرور الجديدة" required>
                            <Input type="password" autoComplete="new-password" value={password.data.password_confirmation} onChange={(e) => password.setData('password_confirmation', e.target.value)} required />
                        </Field>
                        <Button type="submit" loading={password.processing}>تغيير كلمة المرور</Button>
                    </form>
                </Card>
            </div>
        </ClubLayout>
    );
}
