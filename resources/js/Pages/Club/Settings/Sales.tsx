import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, Button, Card, CardHeader, Field, Input, PageHeader, Textarea, Toggle } from '@/Components/ui';

interface Settings {
    shop_enabled: boolean;
    pickup_enabled: boolean;
    online_payment_enabled: boolean;
    payment_window_minutes: number;
    pickup_note: string | null;
}

export default function SalesSettings({ settings, paymobReady }: { settings: Settings; paymobReady: boolean }) {
    const form = useForm({ ...settings, pickup_note: settings.pickup_note ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.settings.sales.update'), { preserveScroll: true });
    };

    return (
        <ClubLayout title="إعدادات المبيعات والمتجر">
            <PageHeader back={<SettingsBack />} title="إعدادات المبيعات والمتجر" subtitle="متجر الموقع الإلكتروني وطرق دفع الطلبات. المنتجات نفسها تُدار من قسم المبيعات." />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="متجر الموقع" subtitle="يظهر في موقع النادي ما دام فيه منتج واحد على الأقل مختار للعرض" />
                    <div className="space-y-4">
                        <Toggle checked={form.data.shop_enabled} onChange={(v) => form.setData('shop_enabled', v)} label="إظهار المتجر في موقع النادي" hint="عند إيقافه يختفي المتجر والسلة من الموقع، وتبقى الطلبات السابقة في اللوحة" />
                        <Field label="تعليمات الاستلام (اختياري)" hint="تظهر للعميل في السلة وصفحة الطلب، مثل مكان الاستلام وأوقاته" error={form.errors.pickup_note}>
                            <Textarea rows={3} maxLength={300} value={form.data.pickup_note} onChange={(e) => form.setData('pickup_note', e.target.value)} />
                        </Field>
                    </div>
                </Card>

                <Card>
                    <CardHeader title="دفع طلبات الموقع" subtitle="تظهر للعميل الطرق المتاحة فعلًا فقط" />
                    <div className="space-y-4">
                        <Toggle checked={form.data.pickup_enabled} onChange={(v) => form.setData('pickup_enabled', v)} label="الدفع عند الاستلام في النادي" hint="يُحصَّل المبلغ نقدًا أو بالبطاقة عند تسليم الطلب" />
                        <div>
                            <Toggle checked={form.data.online_payment_enabled} onChange={(v) => form.setData('online_payment_enabled', v)} label="الدفع الإلكتروني عبر Paymob" hint="يُعتبر الطلب مدفوعًا فقط بعد إشعار Paymob الموقّع ومطابقة المبلغ" />
                            <p className="mt-2 text-xs">
                                {paymobReady ? <Badge tone="green">Paymob جاهز</Badge> : <Badge tone="amber">Paymob غير مُعد — لن يظهر الدفع الإلكتروني للعملاء</Badge>}
                            </p>
                        </div>
                        <Field label="مهلة الدفع الإلكتروني (دقيقة)" hint="تُحجز الكميات للطلب خلالها؛ إن لم يُدفع يُلغى تلقائيًا وتعود الكميات" error={form.errors.payment_window_minutes} required>
                            <Input type="number" min={10} max={1440} dir="ltr" value={form.data.payment_window_minutes} onChange={(e) => form.setData('payment_window_minutes', Number(e.target.value))} />
                        </Field>
                    </div>
                </Card>

                <div className="lg:col-span-2"><Button type="submit" loading={form.processing}>حفظ الإعدادات</Button></div>
            </form>
        </ClubLayout>
    );
}
