import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Button, Card, CardHeader, Field, Input, PageHeader, Select } from '@/Components/ui';
import { money } from '@/lib/format';

interface Plan {
    id: number;
    name: string;
    price_monthly: string;
    price_yearly: string;
}

export default function TenantCreate({ plans }: { plans: Plan[] }) {
    const form = useForm({
        name: '',
        slug: '',
        email: '',
        phone: '',
        city: '',
        owner_name: '',
        owner_email: '',
        owner_password: '',
        platform_plan_id: plans[0] ? String(plans[0].id) : '',
        billing_cycle: 'monthly',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('platform.tenants.store'));
    };

    return (
        <PlatformLayout title="نادٍ جديد">
            <PageHeader
                back={<Link href={route('platform.tenants.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight className="size-4" /> الأندية</Link>}
                title="إنشاء نادٍ جديد"
                subtitle="يُنشأ النادي جاهزًا: فرع رئيسي، الأدوار الافتراضية، حساب المالك، الموقع، وقوالب واتساب، مع فترة تجريبية"
            />
            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader title="بيانات النادي" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="اسم النادي" error={form.errors.name} required className="sm:col-span-2">
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field label="المعرّف في الرابط" error={form.errors.slug} hint={`/c/${form.data.slug || 'club-name'} — يُولّد تلقائيًا إن تُرك فارغًا`}>
                            <Input dir="ltr" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} placeholder="alopera" />
                        </Field>
                        <Field label="المدينة" error={form.errors.city}>
                            <Input value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                        </Field>
                        <Field label="بريد النادي" error={form.errors.email}>
                            <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                        <Field label="هاتف النادي" error={form.errors.phone}>
                            <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        </Field>
                    </div>
                </Card>
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="حساب المالك" subtitle="يدخل به إلى لوحة النادي" />
                        <div className="grid gap-4">
                            <Field label="الاسم" error={form.errors.owner_name} required>
                                <Input value={form.data.owner_name} onChange={(e) => form.setData('owner_name', e.target.value)} />
                            </Field>
                            <Field label="البريد الإلكتروني" error={form.errors.owner_email} required>
                                <Input type="email" dir="ltr" value={form.data.owner_email} onChange={(e) => form.setData('owner_email', e.target.value)} />
                            </Field>
                            <Field label="كلمة المرور المؤقتة" error={form.errors.owner_password} required hint="شاركها مع المالك بشكل آمن واطلب تغييرها">
                                <Input type="password" dir="ltr" autoComplete="new-password" value={form.data.owner_password} onChange={(e) => form.setData('owner_password', e.target.value)} />
                            </Field>
                        </div>
                    </Card>
                    <Card>
                        <CardHeader title="اشتراك المنصة" subtitle="يبدأ بفترة تجريبية حسب الباقة" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="الباقة" error={form.errors.platform_plan_id} required>
                                <Select value={form.data.platform_plan_id} onChange={(e) => form.setData('platform_plan_id', e.target.value)} placeholder="اختر">
                                    {plans.map((p) => <option key={p.id} value={p.id}>{p.name} — {money(p.price_monthly, 'OMR')}/شهر</option>)}
                                </Select>
                            </Field>
                            <Field label="دورة الفوترة" error={form.errors.billing_cycle}>
                                <Select value={form.data.billing_cycle} onChange={(e) => form.setData('billing_cycle', e.target.value)}>
                                    <option value="monthly">شهري</option>
                                    <option value="yearly">سنوي</option>
                                </Select>
                            </Field>
                        </div>
                    </Card>
                    <Button type="submit" size="lg" className="w-full" loading={form.processing}>إنشاء النادي</Button>
                </div>
            </form>
        </PlatformLayout>
    );
}
