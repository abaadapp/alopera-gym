import { describe, expect, it } from 'vitest';
import { GIFT_DELIVERY_TONE, GIFT_EVENTS, GIFT_FILTERS, defaultGiftPayment, giftCancelReason, giftForAnotherNumber, giftPaymentChoices, giftSource, redeemErrorStep, redeemGeneralErrors } from '@/lib/gifts';
import { giftStatus, invoiceType, label } from '@/lib/labels';

const STATUSES = ['pending', 'paid', 'redeemed', 'expired', 'cancelled', 'refunded'];

describe('«هدايا الاشتراكات» in the panel (#192)', () => {
    it('names and colours each of the six statuses, and has a tab for each', () => {
        expect(Object.keys(giftStatus).sort()).toEqual([...STATUSES].sort());
        expect(GIFT_FILTERS.map((f) => f.value).sort()).toEqual([...STATUSES].sort());
        expect(label(giftStatus, 'paid')).toEqual(['مدفوعة — لم تُستخدم', 'green']);
        expect(label(giftStatus, 'refunded')).toEqual(['أُلغيت بإشعار دائن', 'violet']);
        expect(invoiceType.gift).toBe('هدية اشتراك');
    });

    it('never says a credit-note withdrawal paid the money back: the refund has its own state from the server', () => {
        const refunded = GIFT_FILTERS.find((f) => f.value === 'refunded');
        expect(refunded?.label).toBe('أُلغيت بإشعار دائن');
        expect(GIFT_EVENTS['gift.refunded']).toBe('أُلغيت بإشعار دائن');
        for (const words of [refunded?.label, GIFT_EVENTS['gift.refunded'], label(giftStatus, 'refunded')[0]]) {
            expect(words).not.toMatch(/مسترد|رُدّ/);
        }
    });

    it('always offers «لاحقًا», then only the methods the server allows; cash first when allowed', () => {
        expect(giftPaymentChoices([])).toEqual(['later']);
        expect(defaultGiftPayment([])).toBe('later');
        expect(giftPaymentChoices(['cash', 'card', 'bank_transfer', 'other'])).toEqual(['later', 'cash', 'card', 'bank_transfer', 'other']);
        expect(defaultGiftPayment(['card', 'cash'])).toBe('cash');
        expect(defaultGiftPayment(['card'])).toBe('later');
        expect(giftPaymentChoices(['paymob', 'cash'])).toEqual(['later', 'cash']);
    });

    it('brings the redeem dialog back to the step whose field failed; other errors stay on top', () => {
        expect(redeemErrorStep({ starts_on: 'تداخل' })).toBe('date');
        expect(redeemErrorStep({ phone: 'مطلوب', starts_on: 'تداخل' })).toBe('who');
        expect(redeemErrorStep({ error: 'استُخدمت هذه الهدية من قبل.' })).toBeNull();
        expect(redeemGeneralErrors({ error: 'أ', membership_plan_id: 'ب', phone: 'ج', starts_on: undefined })).toEqual(['أ', 'ب']);
    });

    it('reads the history, the source and a voided invoice in words', () => {
        expect(Object.keys(GIFT_EVENTS)).toHaveLength(14);
        expect(GIFT_EVENTS['gift.unpaid']).toBe('أُلغيت دفعتها فعادت بانتظار الدفع');
        expect(GIFT_EVENTS['gift.link_rotated']).toBe('أُصدر لها رابط جديد');
        expect(giftCancelReason('invoice_voided')).toBe('أُلغيت فاتورتها');
        expect(giftCancelReason('invoice_credited')).toBe('رُدّت قيمة فاتورتها كاملة قبل اكتمال دفعها');
        expect(giftCancelReason('طلب المُهدي')).toBe('طلب المُهدي');
        expect(giftCancelReason(null)).toBeNull();
        expect(giftSource('site')).toBe('الموقع');
        expect(giftSource('staff')).toBe('الموظف');
    });

    it('warns when a gift tied to a number is redeemed for a member with another one', () => {
        expect(giftForAnotherNumber(null, '96891234567')).toBe(false);
        expect(giftForAnotherNumber('96891234567', '96891234567')).toBe(false);
        // The same number written as typed: local, with 00, spaced or in Arabic digits.
        expect(giftForAnotherNumber('96891234567', '91234567')).toBe(false);
        expect(giftForAnotherNumber('96891234567', '00968 9123 4567')).toBe(false);
        expect(giftForAnotherNumber('96891234567', '٩١٢٣٤٥٦٧')).toBe(false);
        expect(giftForAnotherNumber('96891234567', '96899999999')).toBe(true);
        expect(giftForAnotherNumber('96891234567', '')).toBe(true);
    });

    it('colours each delivery state, never «sent» as delivered, and names the WhatsApp events in the history', () => {
        expect(Object.keys(GIFT_DELIVERY_TONE).sort()).toEqual(['closed', 'delivered', 'failed', 'not_due', 'read', 'sending', 'sent', 'waiting']);
        expect(GIFT_DELIVERY_TONE.sent).not.toBe(GIFT_DELIVERY_TONE.delivered);
        expect(GIFT_DELIVERY_TONE.failed).toBe('red');
        for (const e of ['gift.phone_verified', 'gift.recipient_changed', 'gift.whatsapp_queued', 'gift.whatsapp_resent', 'gift.whatsapp_failed']) expect(GIFT_EVENTS[e]).toBeTruthy();
    });
});
