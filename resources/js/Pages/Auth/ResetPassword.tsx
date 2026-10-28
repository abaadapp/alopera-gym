import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('password.update'), { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="تعيين كلمة مرور جديدة">
            <form onSubmit={submit} className="space-y-4">
                <Field label="البريد الإلكتروني" error={form.errors.email}>
                    <Input type="email" dir="ltr" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                </Field>
                <Field label="كلمة المرور الجديدة" error={form.errors.password}>
                    <Input type="password" dir="ltr" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoFocus required />
                </Field>
                <Field label="تأكيد كلمة المرور" error={form.errors.password_confirmation}>
                    <Input type="password" dir="ltr" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} required />
                </Field>
                <Button type="submit" className="w-full" loading={form.processing}>حفظ كلمة المرور</Button>
            </form>
        </AuthLayout>
    );
}
