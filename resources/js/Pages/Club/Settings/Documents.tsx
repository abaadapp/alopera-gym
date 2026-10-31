import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { BellRing, MessageCircle } from 'lucide-react';
import { PageActions, SettingsScreen, SettingsSection } from '@/Components/Settings';
import { Badge, Toggle } from '@/Components/ui';
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
        <SettingsScreen title="تنبيهات المستندات">
            <form onSubmit={submit} className="space-y-6">
                <SettingsSection icon={BellRing} title="مواعيد التنبيه" description="قبل تاريخ الانتهاء، وتنبيه أخير عند الانتهاء">
                    <div className="flex flex-wrap gap-2">
                        {CHOICES.map((d) => (
                            <button key={d} type="button" onClick={() => toggle(d)} className={cn('h-9 rounded-[10px] border px-3 text-sm transition', form.data.alert_days.includes(d) ? 'border-[#111] bg-[#fafafa] font-medium text-ink' : 'border-[#dcdcdc] text-muted hover:text-ink')}>
                                {dayCount(d)}
                            </button>
                        ))}
                    </div>
                    {form.errors.alert_days && <p className="mt-2 text-xs text-red-600">{form.errors.alert_days}</p>}
                    <p className="mt-3 text-xs text-muted">المستند يُعدّ «قريب الانتهاء» من أول موعد تنبيه ({dayCount(Math.max(...form.data.alert_days, 1))}).</p>
                </SettingsSection>
                <SettingsSection icon={MessageCircle} title="واتساب" description="إرسال التنبيه إلى الموظف المسؤول عن المستند">
                    <Toggle checked={form.data.whatsapp} onChange={(v) => form.setData('whatsapp', v)} label="إرسال التنبيهات عبر واتساب" hint="يُرسل فقط إذا كان ربط واتساب مفعّلًا وقالب «تنبيه انتهاء مستند» معتمدًا، وللموظف رقم هاتف" />
                    <div className="mt-3 flex flex-wrap gap-2 text-xs">
                        {whatsapp.enabled ? <Badge tone="green">واتساب مفعّل</Badge> : <Badge tone="amber">واتساب غير مفعّل</Badge>}
                        {whatsapp.template ? <Badge tone="green">القالب معتمد</Badge> : <Badge tone="amber">القالب غير معتمد بعد</Badge>}
                    </div>
                    {form.data.whatsapp && !ready && <p className="mt-2 text-xs text-amber-700">لن تُرسل رسائل حتى يكتمل الربط ويُعتمد القالب؛ تبقى التنبيهات داخل النظام.</p>}
                </SettingsSection>
                <PageActions processing={form.processing} note="يظهر كل تنبيه مرة واحدة في لوحة التحكم وقسم المستندات." />
            </form>
        </SettingsScreen>
    );
}
