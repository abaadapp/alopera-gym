import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { FileText, Plus, Workflow, X } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Button, Card, CardHeader, Field, PageHeader, Toggle } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';

interface Rules {
    expiry_reminder_days: number[];
    expired_followup_days: number[];
    notify_class_changes: boolean;
}

export default function NotificationSettings({ rules }: { rules: Rules }) {
    const can = useCan();
    const form = useForm<Rules>({
        expiry_reminder_days: (rules.expiry_reminder_days ?? []).map(Number),
        expired_followup_days: (rules.expired_followup_days ?? []).map(Number),
        notify_class_changes: Boolean(rules.notify_class_changes),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.settings.notifications.update'), { preserveScroll: true });
    };

    return (
        <ClubLayout title="الإشعارات والقوالب">
            <PageHeader back={<SettingsBack />} title="الإشعارات والقوالب" subtitle="متى تُرسل رسائل واتساب التلقائية للأعضاء" />

            <div className="grid gap-6 lg:grid-cols-3">
                <form onSubmit={submit} className="lg:col-span-2">
                    <Card>
                        <CardHeader title="رسائل واتساب التلقائية" subtitle="قواعد التذكير والمتابعة (تتطلب قوالب معتمدة)" />
                        <DaysField label="تذكير قبل انتهاء العضوية بـ (أيام)" values={form.data.expiry_reminder_days} onChange={(v) => form.setData('expiry_reminder_days', v)} max={60} min={0} error={errors.expiry_reminder_days} />
                        <DaysField label="متابعة بعد الانتهاء بـ (أيام)" values={form.data.expired_followup_days} onChange={(v) => form.setData('expired_followup_days', v)} max={180} min={1} error={errors.expired_followup_days} />
                        <div className="mt-2">
                            <Toggle checked={form.data.notify_class_changes} onChange={(v) => form.setData('notify_class_changes', v)} label="إبلاغ أصحاب الحجوزات عند تعديل الحصة" hint="تغيير الموعد أو المدرب أو القاعة" />
                        </div>
                        <div className="mt-6 flex justify-end">
                            <Button type="submit" loading={form.processing}>حفظ</Button>
                        </div>
                    </Card>
                </form>

                {can('templates.manage') && (
                    <Card>
                        <CardHeader title="القوالب والردود" subtitle="ما تقوله الرسائل نفسها" />
                        <div className="space-y-2">
                            <Button variant="outline" className="w-full justify-start" href={route('club.templates.index')} icon={<FileText className="size-4" />}>قوالب واتساب</Button>
                            <Button variant="outline" className="w-full justify-start" href={route('club.automation.index')} icon={<Workflow className="size-4" />}>الأتمتة والردود الآلية</Button>
                        </div>
                        <p className="mt-3 text-xs leading-6 text-muted">لا تُرسل أي رسالة تلقائية إلا إذا كان قالبها معتمدًا من Meta ومفعّلًا، ووافق العضو على الرسائل.</p>
                    </Card>
                )}
            </div>
        </ClubLayout>
    );
}

function DaysField({ label, values, onChange, min, max, error }: { label: string; values: number[]; onChange: (v: number[]) => void; min: number; max: number; error?: string }) {
    const [draft, setDraft] = useState('');
    const add = () => {
        const n = Number(draft);
        if (draft === '' || !Number.isInteger(n) || n < min || n > max || values.includes(n) || values.length >= 5) return;
        onChange([...values, n].sort((a, b) => b - a));
        setDraft('');
    };

    return (
        <Field label={label} error={error} className="mb-4" hint={values.length === 0 ? 'لا يوجد — هذه الرسائل متوقفة' : `حتى 5 قيم، بين ${min} و${max}`}>
            <div className="flex flex-wrap items-center gap-1.5">
                {values.map((d) => (
                    <span key={d} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700">
                        <span className="num">{d}</span> يوم
                        <button type="button" onClick={() => onChange(values.filter((x) => x !== d))} aria-label="حذف"><X className="size-3" /></button>
                    </span>
                ))}
                {values.length < 5 && (
                    <span className="inline-flex items-center gap-1">
                        <input
                            type="number"
                            className="input h-8 w-20 py-1 text-xs"
                            value={draft}
                            min={min}
                            max={max}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    add();
                                }
                            }}
                        />
                        <button type="button" onClick={add} className="grid size-8 place-items-center rounded-lg border border-line hover:bg-brand-50" aria-label="إضافة"><Plus className="size-4" /></button>
                    </span>
                )}
            </div>
        </Field>
    );
}
