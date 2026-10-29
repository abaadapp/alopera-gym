import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, Button, Card, CardHeader, PageHeader, Toggle } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { dayCount } from '@/lib/format';

const CHOICES = [90, 60, 45, 30, 15, 7, 3, 1];

export default function DocumentSettings({ settings, whatsapp }: { settings: { alert_days: number[]; whatsapp: boolean }; whatsapp: { enabled: boolean; template: boolean } }) {
    const form = useForm({ alert_days: settings.alert_days, whatsapp: settings.whatsapp });
    const toggle = (d: number) => form.setData('alert_days', form.data.alert_days.includes(d) ? form.data.alert_days.filter((x) => x !== d) : [...form.data.alert_days, d].sort((a, b) => b - a));
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.settings.documents.update'), { preserveScroll: true });
    };
    const ready = whatsapp.enabled && whatsapp.template;

    return (
        <ClubLayout title="تنبيهات المستندات">
            <PageHeader back={<SettingsBack />} title="تنبيهات المستندات" subtitle="متى يُنبَّه قبل انتهاء مستندات النشاط. يظهر كل تنبيه مرة واحدة في لوحة التحكم وقسم المستندات." />
            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="مواعيد التنبيه" subtitle="قبل تاريخ الانتهاء، وتنبيه أخير عند الانتهاء" />
                    <div className="flex flex-wrap gap-2">
                        {CHOICES.map((d) => (
                            <button key={d} type="button" onClick={() => toggle(d)} className={cn('h-9 rounded-[10px] border px-3 text-sm transition', form.data.alert_days.includes(d) ? 'border-brand bg-brand-50 font-medium text-ink' : 'border-[#dcdcdc] text-muted hover:text-ink')}>
                                {dayCount(d)}
                            </button>
                        ))}
                    </div>
                    {form.errors.alert_days && <p className="mt-2 text-xs text-red-600">{form.errors.alert_days}</p>}
                    <p className="mt-3 text-xs text-muted">المستند يُعدّ «قريب الانتهاء» من أول موعد تنبيه ({dayCount(Math.max(...form.data.alert_days, 1))}).</p>
                </Card>
                <Card>
                    <CardHeader title="واتساب" subtitle="إرسال التنبيه إلى الموظف المسؤول عن المستند" />
                    <Toggle checked={form.data.whatsapp} onChange={(v) => form.setData('whatsapp', v)} label="إرسال التنبيهات عبر واتساب" hint="يُرسل فقط إذا كان ربط واتساب مفعّلًا وقالب «تنبيه انتهاء مستند» معتمدًا، وللموظف رقم هاتف" />
                    <div className="mt-3 flex flex-wrap gap-2 text-xs">
                        {whatsapp.enabled ? <Badge tone="green">واتساب مفعّل</Badge> : <Badge tone="amber">واتساب غير مفعّل</Badge>}
                        {whatsapp.template ? <Badge tone="green">القالب معتمد</Badge> : <Badge tone="amber">القالب غير معتمد بعد</Badge>}
                    </div>
                    {form.data.whatsapp && !ready && <p className="mt-2 text-xs text-amber-700">لن تُرسل رسائل حتى يكتمل الربط ويُعتمد القالب؛ تبقى التنبيهات داخل النظام.</p>}
                </Card>
                <div className="lg:col-span-2"><Button type="submit" loading={form.processing} disabled={form.data.alert_days.length === 0}>حفظ الإعدادات</Button></div>
            </form>
        </ClubLayout>
    );
}
