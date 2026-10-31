import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Banknote, Fingerprint } from 'lucide-react';
import { PageActions, SettingsScreen, SettingsSection } from '@/Components/Settings';
import { Field, Input, Select } from '@/Components/ui';

interface Props {
    settings: { work_hours: number; overtime_rate: number | null; payout_account_id: number | null; punch_window_seconds: number };
    accounts: { id: number; code: string; name: string; kind: string }[];
}

export default function PayrollSettings({ settings, accounts }: Props) {
    const form = useForm({
        work_hours: String(settings.work_hours ?? 8),
        overtime_rate: settings.overtime_rate === null ? '' : String(settings.overtime_rate),
        payout_account_id: settings.payout_account_id ? String(settings.payout_account_id) : '',
        punch_window_seconds: String(settings.punch_window_seconds ?? 60),
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.settings.payroll.update'), { preserveScroll: true });
    };

    return (
        <SettingsScreen title="إعدادات الرواتب والحضور">
            <form onSubmit={submit} className="space-y-6">
                <SettingsSection icon={Banknote} title="الرواتب">
                    <div className="space-y-4">
                        <Field label="ساعات العمل اليومية" error={form.errors.work_hours} hint="تُستخدم لقراءة ساعات الحضور في المسيرة"><Input type="number" step="0.5" min="1" max="16" dir="ltr" value={form.data.work_hours} onChange={(e) => form.setData('work_hours', e.target.value)} /></Field>
                        <Field label="أجر الساعة الإضافية (ر.ع)" error={form.errors.overtime_rate} hint="مرجع عند إدخال الإضافي في سطور المسيرة؛ اتركه فارغًا إن اختلف بين الموظفين"><Input type="number" step="0.001" min="0" dir="ltr" value={form.data.overtime_rate} onChange={(e) => form.setData('overtime_rate', e.target.value)} /></Field>
                        <Field label="حساب صرف الرواتب الافتراضي" error={form.errors.payout_account_id} hint="يُقترح عند الصرف؛ الصندوق أو حساب بنكي فقط">
                            <Select value={form.data.payout_account_id} onChange={(e) => form.setData('payout_account_id', e.target.value)} placeholder="بدون">
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                            </Select>
                        </Field>
                    </div>
                </SettingsSection>
                <SettingsSection icon={Fingerprint} title="حضور الموظفين" description="منفصل عن دخول الأعضاء">
                    <Field label="مهلة البصمة المكررة (ثانية)" error={form.errors.punch_window_seconds} hint="بصمة ثانية خلال هذه المهلة تُتجاهل، فلا تُسجَّل انصرافًا فور الحضور. 0 لإيقافها.">
                        <Input type="number" min="0" max="900" dir="ltr" value={form.data.punch_window_seconds} onChange={(e) => form.setData('punch_window_seconds', e.target.value)} />
                    </Field>
                </SettingsSection>
                <PageActions processing={form.processing} note="القيم الافتراضية لمسيرات الرواتب وتسجيل حضور الموظفين" />
            </form>
        </SettingsScreen>
    );
}
