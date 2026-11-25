import { Banknote, CreditCard, Landmark, Link2 } from 'lucide-react';
import { ChoiceCard, Field, Input } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';

export type SaleMethod = 'cash' | 'card' | 'bank_transfer' | 'link';

/**
 * How a membership or a training package is paid as it is sold (owner's decision, 2026-11-25: sold only paid — no
 * invoice before the money, no «بانتظار الدفع»): in cash, by card with its reference, by a transfer already seen in the
 * account (who may confirm transfers), or — paying from afar — a payment link that sells it once paid.
 */
export default function PaymentPicker({ method, reference, onMethod, onReference, errors, allowLink = false }: {
    method: SaleMethod | '';
    reference: string;
    onMethod: (m: SaleMethod) => void;
    onReference: (v: string) => void;
    errors: Partial<Record<'payment_method' | 'payment_reference', string>>;
    /** A payment link (memberships only, with online payment on). */
    allowLink?: boolean;
}) {
    const can = useCan();
    const options: { key: SaleMethod; label: string; hint: string; icon: typeof Banknote }[] = [
        { key: 'cash', label: 'نقدًا', hint: 'يُسجَّل في درج الصندوق.', icon: Banknote },
        { key: 'card', label: 'بطاقة (الشبكة)', hint: 'مع رقم العملية من الجهاز.', icon: CreditCard },
        ...(can('payments.confirm') ? [{ key: 'bank_transfer' as const, label: 'تحويل بنكي', hint: 'بعد رؤية المبلغ في الحساب.', icon: Landmark }] : []),
        ...(allowLink ? [{ key: 'link' as const, label: 'رابط دفع للعضوة', hint: 'تدفع من هاتفها، ويُفعَّل بعد الدفع.', icon: Link2 }] : []),
    ];

    return (
        <div className="space-y-3">
            <Field label="طريقة الدفع" error={errors.payment_method} required>
                <div className="grid gap-2 sm:grid-cols-2">
                    {options.map((o) => (
                        <ChoiceCard key={o.key} selected={method === o.key} onClick={() => onMethod(o.key)} className="py-2.5">
                            <span className="flex items-center gap-2 text-sm font-medium"><o.icon className="size-4 shrink-0" />{o.label}</span>
                            <span className="mt-0.5 block text-[12px] text-muted">{o.hint}</span>
                        </ChoiceCard>
                    ))}
                </div>
            </Field>
            {method === 'card' && (
                <Field label="رقم العملية" error={errors.payment_reference} required>
                    <Input dir="ltr" value={reference} onChange={(e) => onReference(e.target.value)} autoFocus />
                </Field>
            )}
            {method === 'bank_transfer' && (
                <Field label="رقم التحويل (اختياري)" error={errors.payment_reference}>
                    <Input dir="ltr" value={reference} onChange={(e) => onReference(e.target.value)} />
                </Field>
            )}
        </div>
    );
}

/** The sale's one line of what happens next, by how it is paid. */
export function paymentNote(method: SaleMethod | ''): string {
    return method === 'link'
        ? 'لا يُسجَّل شيء الآن. يُنشأ رابط دفع ترسلينه للعضوة، وعند دفعها تُصدر الفاتورة مدفوعة وتُفعَّل العضوية تلقائيًا.'
        : 'يُسجَّل المبلغ كاملًا الآن، وتُصدر الفاتورة مدفوعة، وتُفعَّل مباشرة.';
}
