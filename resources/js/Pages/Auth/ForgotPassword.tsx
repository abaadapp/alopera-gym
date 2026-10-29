import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';

export default function ForgotPassword() {
    const { flash } = useShared();
    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('password.email'), { preserveScroll: true });
    };

    return (
        <AuthLayout title="استعادة كلمة المرور" subtitle="أدخل بريدك وسنرسل لك رابطًا لتعيين كلمة مرور جديدة.">
            {flash?.success && (
                <p className="mb-4 flex items-start gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">
                    <MailCheck className="mt-0.5 size-4 shrink-0" /> {flash.success}
                </p>
            )}
            <form onSubmit={submit} className="space-y-4">
                <Field label="البريد الإلكتروني" error={form.errors.email}>
                    <Input type="email" dir="ltr" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus required />
                </Field>
                <Button type="submit" className="w-full" loading={form.processing}>إرسال رابط الاستعادة</Button>
            </form>
            <p className="mt-6 text-center text-sm">
                <Link href={route('login')} className="text-brand hover:underline">العودة لتسجيل الدخول</Link>
            </p>
        </AuthLayout>
    );
}
