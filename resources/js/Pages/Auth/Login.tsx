import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdminScope, Button, Field, Flash, Input } from '@/Components/ui';

/**
 * Sign in to the panel: the club's official logo, then e-mail, password and «دخول», alone in the middle of a quiet
 * page. It has its own layout on purpose: AuthLayout stays as it is for the password-reset pages, whose routes and
 * flow are unchanged (the link to them is simply not shown here). `remember` is still sent, as false, as before.
 */
export default function Login() {
    const { logo } = usePage<{ logo: string }>().props;
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('login.store'), { onFinish: () => form.reset('password') });
    };

    return (
        <>
            <Head title="تسجيل الدخول" />
            <AdminScope className="flex min-h-dvh items-center justify-center bg-[#f7f8f9] px-4 py-10 sm:px-6">
                <main className="w-full max-w-[400px] rounded-[20px] border border-[#ececec] bg-white px-6 py-8 shadow-[0_24px_48px_-32px_rgba(17,17,17,0.28)] sm:px-8 sm:py-10">
                    <h1 className="sr-only">تسجيل الدخول</h1>
                    {/* The official logo as the system serves it: width set, height from its own proportions, never cropped. */}
                    <img src={logo} alt="Alopera Gym" width={150} height={113} className="mx-auto block h-auto w-[150px] max-w-full object-contain" />
                    <form onSubmit={submit} data-enter-submits className="mt-8 space-y-4">
                        <Field label="البريد الإلكتروني" error={form.errors.email}>
                            <Input type="email" dir="ltr" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus required />
                        </Field>
                        <Field label="كلمة المرور" error={form.errors.password}>
                            <Input type="password" dir="ltr" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required />
                        </Field>
                        <Button type="submit" className="w-full" loading={form.processing}>دخول</Button>
                    </form>
                </main>
            </AdminScope>
            <Flash />
        </>
    );
}
