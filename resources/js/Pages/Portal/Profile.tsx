import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Lock, Save } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { Button, Field, Input, Toggle } from '@/Components/ui';
import { date, phone } from '@/lib/format';

interface Props {
    site: SiteInfo;
    seo: { title: string };
    profile: {
        full_name: string;
        phone: string;
        email: string | null;
        marketing_opt_in: boolean;
        emergency_contact_name: string | null;
        emergency_contact_phone: string | null;
        date_of_birth: string | null;
    };
}

export default function Profile({ site, seo, profile }: Props) {
    const form = useForm({
        email: profile.email ?? '',
        emergency_contact_name: profile.emergency_contact_name ?? '',
        emergency_contact_phone: profile.emergency_contact_phone ?? '',
        marketing_opt_in: profile.marketing_opt_in,
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('portal.profile.update'), { preserveScroll: true, onSuccess: () => form.reset('current_password', 'password', 'password_confirmation') });
    };

    return (
        <PortalLayout site={site} title={seo.title}>
            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-2xl border border-line bg-white p-6">
                    <h2 className="font-display text-lg font-semibold">بياناتي</h2>
                    <dl className="mt-4 grid grid-cols-2 gap-4 rounded-xl bg-canvas p-4 text-sm">
                        <div><dt className="text-xs text-muted">الاسم</dt><dd className="mt-0.5 font-medium">{profile.full_name}</dd></div>
                        <div><dt className="text-xs text-muted">الهاتف</dt><dd className="mt-0.5 num">{phone(profile.phone)}</dd></div>
                        <div><dt className="text-xs text-muted">تاريخ الميلاد</dt><dd className="mt-0.5">{date(profile.date_of_birth)}</dd></div>
                    </dl>
                    <p className="mt-2 text-xs text-muted">لتغيير الاسم أو رقم الهاتف تواصلي مع الاستقبال.</p>
                    <div className="mt-5 space-y-4">
                        <Field label="البريد الإلكتروني" error={form.errors.email}>
                            <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="جهة اتصال للطوارئ" error={form.errors.emergency_contact_name}>
                                <Input value={form.data.emergency_contact_name} onChange={(e) => form.setData('emergency_contact_name', e.target.value)} />
                            </Field>
                            <Field label="هاتف الطوارئ" error={form.errors.emergency_contact_phone}>
                                <Input type="tel" dir="ltr" value={form.data.emergency_contact_phone} onChange={(e) => form.setData('emergency_contact_phone', e.target.value)} />
                            </Field>
                        </div>
                        <div className="rounded-xl border border-line p-4">
                            <Toggle
                                checked={form.data.marketing_opt_in}
                                onChange={(v) => form.setData('marketing_opt_in', v)}
                                label="العروض والأخبار عبر واتساب"
                                hint="رسائل الخدمة (تأكيد الاشتراك، الحجوزات، الفواتير) تصلك دائمًا."
                            />
                        </div>
                    </div>
                </section>

                <section className="h-fit rounded-2xl border border-line bg-white p-6">
                    <h2 className="flex items-center gap-2 font-display text-lg font-semibold"><Lock className="size-5 text-brand" /> تغيير كلمة المرور</h2>
                    <div className="mt-5 space-y-4">
                        <Field label="كلمة المرور الحالية" error={form.errors.current_password}>
                            <Input type="password" dir="ltr" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} autoComplete="current-password" />
                        </Field>
                        <Field label="كلمة المرور الجديدة" error={form.errors.password} hint="8 أحرف على الأقل">
                            <Input type="password" dir="ltr" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" />
                        </Field>
                        <Field label="تأكيد كلمة المرور" error={form.errors.password_confirmation}>
                            <Input type="password" dir="ltr" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" />
                        </Field>
                    </div>
                </section>

                <div className="lg:col-span-2">
                    <Button type="submit" size="lg" className="rounded-full" loading={form.processing} icon={<Save className="size-4" />}>حفظ التغييرات</Button>
                </div>
            </form>
        </PortalLayout>
    );
}
