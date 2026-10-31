import { useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Field, Input, Modal, Select } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { money } from '@/lib/format';
import { clientKey } from './parts';

/** Pay a supplier bill (or part of it) from a cash box or bank account — a payment actually made, never more than owed. */
export default function PayBill({ bill, accounts, onClose }: { bill: { id: number; number: string; reference: string; supplier: string | null; balance: string }; accounts: { id: number; name: string; kind: string }[]; onClose: () => void }) {
    const currency = useCurrency();
    const key = useMemo(clientKey, []);
    const form = useForm({ account_id: String(accounts[0]?.id ?? ''), amount: String(Number(bill.balance)), paid_on: new Date().toISOString().slice(0, 10), reference: '', notes: '', client_key: key });

    return (
        <Modal open onClose={onClose} title={`سداد ${bill.reference} — ${bill.supplier ?? ''}`} footer={<><Button variant="outline" onClick={onClose}>تراجع</Button><Button loading={form.processing} onClick={() => form.post(route('club.purchases.bills.pay', bill.id), { preserveScroll: true, onSuccess: onClose })}>تسجيل السداد</Button></>}>
            <div className="space-y-4">
                <p className="rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px]">المتبقي على السند: <span className="font-semibold num">{money(bill.balance, currency)}</span></p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="من حساب" error={form.errors.account_id} required>
                        <Select value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select>
                    </Field>
                    <Field label="المبلغ" error={form.errors.amount} required><Input type="number" min="0" step="0.001" dir="ltr" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                    <Field label="تاريخ السداد" error={form.errors.paid_on} required><Input type="date" value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} /></Field>
                    <Field label="رقم التحويل أو الشيك" error={form.errors.reference}><Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} maxLength={120} /></Field>
                </div>
                <p className="text-[12px] text-[#9ca3af]">يُخصم من الحساب المختار ويقل الالتزام للمورد بقيد واحد؛ الخطأ يُصحَّح بعكس الدفعة لا بحذفها.</p>
            </div>
        </Modal>
    );
}
