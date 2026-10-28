import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Checkbox, Field, Input } from '@/Components/ui';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('login.store'), { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title="تسجيل الدخول" subtitle="لوحة إدارة النادي وإدارة المنصة">
            <form onSubmit={submit} className="space-y-4">
                <Field label="البريد الإلكتروني" error={form.errors.email}>
                    <Input type="email" dir="ltr" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus required />
                </Field>
                <Field label="كلمة المرور" error={form.errors.password}>
                    <Input type="password" dir="ltr" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required />
                </Field>
                <div className="flex items-center justify-between">
                    <Checkbox label="تذكّريني" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                    <a href={route('password.request')} className="text-sm text-brand hover:underline">نسيت كلمة المرور؟</a>
                </div>
                <Button type="submit" className="w-full" loading={form.processing}>دخول</Button>
            </form>
        </AuthLayout>
    );
}
