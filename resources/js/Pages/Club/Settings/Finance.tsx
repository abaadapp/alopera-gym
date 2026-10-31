import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Landmark, Wallet } from 'lucide-react';
import { PageActions, SettingsScreen, SettingsSection } from '@/Components/Settings';
import { Field, Select } from '@/Components/ui';

interface Props {
    methods: Record<string, string>;
    receipt: Record<string, number>;
    spending: Record<string, number>;
    accounts: { id: number; code: string; name: string; kind: string }[];
    vatRate: number;
}

const PAYING = ['cash', 'bank'];

export default function FinanceSettings({ methods, receipt, spending, accounts, vatRate }: Props) {
    const form = useForm({ receipt, spending });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.settings.finance.update'), { preserveScroll: true });
    };
    const select = (purpose: 'receipt' | 'spending', method: string) => (
        <Select value={form.data[purpose][method] ?? ''} onChange={(e) => form.setData(purpose, { ...form.data[purpose], [method]: Number(e.target.value) })}>
            {accounts.filter((a) => purpose === 'receipt' || PAYING.includes(a.kind)).map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
        </Select>
    );

    return (
        <SettingsScreen title="الإعدادات المالية">
            <form onSubmit={submit} className="space-y-6">
                <SettingsSection icon={Wallet} title="التحصيل من الأعضاء" description="البطاقات وPaymob تنتظر في حسابات التسوية حتى تصل البنك">
                    <div className="space-y-4">
                        {Object.entries(methods).map(([method, label]) => <Field key={method} label={label}>{select('receipt', method)}</Field>)}
                    </div>
                </SettingsSection>
                <SettingsSection icon={Landmark} title="دفع المصروفات" description="من الصندوق أو حساب بنكي فقط">
                    <div className="space-y-4">
                        {Object.entries(methods).filter(([m]) => m !== 'paymob').map(([method, label]) => <Field key={method} label={label}>{select('spending', method)}</Field>)}
                    </div>
                    <p className="mt-4 text-[12px] text-muted">نسبة ضريبة القيمة المضافة على الفواتير: {vatRate}% — تُعدَّل من إعدادات النادي.</p>
                </SettingsSection>
                <PageActions processing={form.processing} note="إلى أي حساب يذهب كل تحصيل، ومن أي حساب يخرج كل مصروف — حسب طريقة الدفع. تُطبَّق على العمليات الجديدة." />
            </form>
        </SettingsScreen>
    );
}
