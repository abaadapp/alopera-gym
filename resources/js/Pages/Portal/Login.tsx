import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { LogIn, UserRound } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button, Checkbox, Field, Input } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';

export default function Login({ site, seo }: { site: SiteInfo; seo: { title: string } }) {
    const { tenant } = useShared();
    const form = useForm({ login: '', password: '', remember: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('portal.login.store'), { onFinish: () => form.reset('password') });
    };

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="relative overflow-hidden">
                <div className="absolute inset-0 bg-gradient-to-b from-brand-50 to-[#fffafb]" />
                <div className="relative mx-auto flex max-w-md flex-col px-4 py-16">
                    <div className="rounded-[2rem] border border-line bg-white p-8 shadow-xl shadow-brand/5">
                        <div className="mx-auto grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand"><UserRound className="size-7" /></div>
                        <h1 className="mt-4 text-center font-display text-2xl font-semibold">بوابة الأعضاء</h1>
                        <p className="mt-1 text-center text-sm text-muted">تابع اشتراكك واحجز حصصك في {tenant?.name}</p>

                        <form onSubmit={submit} className="mt-8 space-y-4">
                            <Field label="رقم الهاتف أو البريد الإلكتروني" error={form.errors.login}>
                                <Input dir="ltr" value={form.data.login} onChange={(e) => form.setData('login', e.target.value)} autoComplete="username" placeholder="9XXXXXXX" autoFocus required />
                            </Field>
                            <Field label="كلمة المرور" error={form.errors.password}>
                                <Input type="password" dir="ltr" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="current-password" required />
                            </Field>
                            <Checkbox label="تذكّرني على هذا الجهاز" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                            <Button type="submit" size="lg" className="w-full rounded-full" loading={form.processing} icon={<LogIn className="size-4" />}>دخول</Button>
                        </form>

                        <div className="mt-6 space-y-2 border-t border-line pt-5 text-center text-sm text-muted">
                            {site.signup_enabled && (
                                <p>جديد في النادي؟ <Link href={route('site.join')} className="font-medium text-brand">اشترك الآن</Link></p>
                            )}
                            <p className="text-xs">عضو مسجّل في الاستقبال ولم تُفعّل الدخول بعد؟ اطلب من الاستقبال تفعيل حساب البوابة.</p>
                        </div>
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
