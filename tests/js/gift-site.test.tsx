import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import type { SiteChrome } from '@/types/site';

const chrome: SiteChrome = {
    theme: { accent: '#8A6700', ink: '#16130F', surface: '#F6F3EE' },
    announcement: null,
    header: { links: [], cta: { label: 'انضمي الآن', href: '/join', external: false }, show_login: true },
    footer: { line: '', links: [], show_hours: false, show_social: false },
    home_url: '/',
    preview: false,
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { chrome, tenant: { name: 'Alopera Gym', currency: 'OMR', logo_url: '/brand/alopera-gym-logo.png' }, member: null, flash: {}, errors: {} } }),
    useForm: (data: Record<string, string>) => ({ data, setData: () => {}, post: () => {}, reset: () => {}, processing: false, errors: {} }),
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => <a href={href} {...rest}>{children}</a>,
    Head: () => null,
    router: { on: () => () => {} },
}));
vi.mock('@/lib/cart', () => ({ useCart: () => ({ count: 0 }) }));
vi.mock('@/Components/ui', () => ({ Flash: () => null }));

import { PlanCard, type PlanData } from '@/Pages/Site/Plans';
import GiftBuy from '@/Pages/Site/GiftBuy';
import GiftCard from '@/Pages/Site/GiftCard';
import GiftClaim from '@/Pages/Site/GiftClaim';
import GiftVerify, { type GiftVerification } from '@/Pages/Site/GiftVerify';
import GiftRedeem from '@/Pages/Portal/GiftRedeem';
import { displayPhone, isWhatsAppNumber, normalizePhone, type GiftCardData } from '@/Components/Site/Gift';
import { hasEnglish } from '@/lib/i18n';

beforeAll(() => {
    // route('name') → '/name'; route() → the current route (PortalLayout's tabs ask it).
    (globalThis as unknown as { route: (name?: string) => unknown }).route = (name?: string) => (name ? `/${name}` : { current: () => false });
});

afterEach(cleanup);

const plan: PlanData = {
    id: 1, name: 'شهري', type: 'time', type_label: 'اشتراك', description: null, duration_value: 1, duration_unit: 'month', duration_label: 'شهر',
    price: '29.000', signup_fee: '0.000', sessions_limit: null, allows_gym: true, allows_classes: false, freeze_allowed: false, max_freeze_days: 0,
    features: null, color: null, is_featured: false, gift_url: '/gift/plan/1',
};

const site = {
    is_published: true, announcement: null, contact_phone: null, whatsapp_number: null, contact_email: null, address: null, map_url: null,
    working_hours: [], social_links: {}, logo_url: '/brand/alopera-gym-logo.png', signup_enabled: true, shop_open: false,
};

const card = (status: string, forNumber = false): GiftCardData => ({
    status, recipient_name: 'سارة', giver_name: 'نورة', message: 'كل عام وأنتِ بخير', plan_name: 'شهري', duration_label: 'شهر', expires_on: '2027-02-15', club: 'Alopera Gym',
    for_recipient_number: forNumber,
});

const terms = { redeem_days: 90, max_start_days: 30, expires_if_paid_today: '2027-02-15' };

const FOR_NUMBER = 'تُستلم هذه الهدية بحساب رقم جوال المستلمة.';

describe('«إهداء هذه الباقة» on a plan card', () => {
    it('shows the gift link under the button only when it is given', () => {
        const { rerender } = render(<PlanCard plan={plan} currency="OMR" cta={{ label: 'اشترك في هذه الباقة', href: '/join?plan=1' }} gift={{ label: 'إهداء هذه الباقة', href: '/gift/plan/1' }} />);
        const link = screen.getByText('إهداء هذه الباقة').closest('a');
        expect(link?.getAttribute('href')).toBe('/gift/plan/1');
        expect(screen.getByText('اشترك في هذه الباقة').closest('a')?.getAttribute('href')).toBe('/join?plan=1');

        rerender(<PlanCard plan={plan} currency="OMR" cta={{ label: 'اشترك في هذه الباقة', href: '/join?plan=1' }} />);
        expect(screen.queryByText('إهداء هذه الباقة')).toBeNull();

        // null: the same empty row as its neighbours, and no link.
        rerender(<PlanCard plan={plan} currency="OMR" cta={{ label: 'اشترك في هذه الباقة', href: '/join?plan=1' }} gift={null} />);
        expect(screen.queryByText('إهداء هذه الباقة')).toBeNull();
    });
});

describe('the gift card at its link', () => {
    it('offers to claim a paid gift, with the official logo as it is', () => {
        const { container } = render(
            <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('paid')} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp="https://wa.me/?text=x" claim_url="/gift/x/claim" pay_url={null} is_buyer={false} delivery={null} terms={terms} />,
        );
        expect(screen.getByText('استلمي هديتك').closest('a')?.getAttribute('href')).toBe('/gift/x/claim');
        expect(screen.getByText('نسخ رابط الهدية')).toBeTruthy();
        expect(screen.getByText('مشاركة عبر واتساب').closest('a')?.getAttribute('target')).toBe('_blank');
        expect(screen.getByText('سارة')).toBeTruthy();
        expect(screen.getByText('نورة')).toBeTruthy();
        expect(container.textContent).toContain('صالحة للاستلام حتى');
        const logo = container.querySelector('article img');
        expect(logo?.getAttribute('src')).toBe('/brand/alopera-gym-logo.png');
        expect(logo?.className).toContain('object-contain');
        expect(logo?.className).toContain('w-auto');
    });

    it('says a redeemed gift was used and offers nothing to claim', () => {
        const { container } = render(
            <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('redeemed')} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp={null} claim_url={null} pay_url={null} is_buyer={false} delivery={null} terms={terms} />,
        );
        expect(screen.queryByText('استلمي هديتك')).toBeNull();
        expect(screen.queryByText('مشاركة عبر واتساب')).toBeNull();
        expect(screen.getByText('استُخدمت هذه الهدية.')).toBeTruthy();
        expect(container.textContent).not.toContain('صالحة للاستلام حتى');
    });
});

describe('buying a gift: the policy before payment (#192)', () => {
    const buy = () => render(
        <GiftBuy site={site} seo={{ title: 'إهداء' }} plan={plan} summary={{ price: '29.000', tax: '0.000', tax_rate: 0, total: '29.000' }} onlinePayments whatsapp={{ available: true, refusal: null }} terms={terms} />,
    );

    it('states the window with the last day if paid today, and no refund, above the total and the pay button', () => {
        const { container } = buy();
        const text = container.textContent ?? '';
        expect(screen.getByText('سياسة الهدية')).toBeTruthy();
        expect(text).toContain('صالحة للاستلام 90 يومًا من تاريخ الدفع (حتى 15 فبراير 2027 إن تمّ الدفع اليوم).');
        expect(text).toContain('إذا انقضت المهلة دون استلامها تنتهي الهدية ولا تُردّ قيمتها.');
        expect(text).toContain('لا تُردّ قيمة الهدية بعد استلامها، ولا يُردّ جزء منها.');
        // The conditions list says it too (the card page shows the same list).
        expect(text).toContain('لا تُردّ قيمة الهدية بعد انتهاء مهلتها أو بعد استلامها، ولا يُردّ جزء منها.');
        expect(text).toContain('أوافق على سياسة الهدية وشروطها و');

        const policy = screen.getByText('سياسة الهدية').closest('section') as HTMLElement;
        expect(policy.className).toContain('border-[var(--site-gold)]');
        // The agreement is in the policy box, and the box comes before the total and the button.
        expect(policy.querySelector('input[type="checkbox"]')).toBeTruthy();
        const total = screen.getByText('الإجمالي');
        const pay = screen.getByText('متابعة: تأكيد رقم المستلمة');
        expect(policy.compareDocumentPosition(total) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(policy.compareDocumentPosition(pay) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('asks for the recipient\'s WhatsApp number, required, left to right, and says where the gift goes', () => {
        buy();
        const input = screen.getByText('رقم واتساب المستلمة').closest('label')?.querySelector('input');
        expect(input?.getAttribute('dir')).toBe('ltr');
        expect(input?.hasAttribute('required')).toBe(true);
        expect(screen.getByText('نرسل الهدية إلى واتساب هذا الرقم. قبل الدفع تؤكد المستلمة الرقم برسالة منها.')).toBeTruthy();
        expect(screen.getByText('+968')).toBeTruthy();
    });

    it('says so and sells nothing while the recipient\'s number cannot be confirmed', () => {
        render(<GiftBuy site={site} seo={{ title: 'إهداء' }} plan={plan} summary={{ price: '29.000', tax: '0.000', tax_rate: 0, total: '29.000' }} onlinePayments
            whatsapp={{ available: false, refusal: 'تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.' }} terms={terms} />);
        expect(screen.getByText('تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.')).toBeTruthy();
        expect((screen.getByText('متابعة: تأكيد رقم المستلمة').closest('button') as HTMLButtonElement).disabled).toBe(true);
    });

    it('has the English of every new phrase', () => {
        for (const text of [
            'سياسة الهدية',
            'صالحة للاستلام :days من تاريخ الدفع (حتى :date إن تمّ الدفع اليوم).',
            'إذا انقضت المهلة دون استلامها تنتهي الهدية ولا تُردّ قيمتها.',
            'لا تُردّ قيمة الهدية بعد استلامها، ولا يُردّ جزء منها.',
            'لا تُردّ قيمة الهدية بعد انتهاء مهلتها أو بعد استلامها، ولا يُردّ جزء منها.',
            'أوافق على سياسة الهدية وشروطها و:terms',
            'رقم واتساب المستلمة',
            'نرسل الهدية إلى واتساب هذا الرقم. قبل الدفع تؤكد المستلمة الرقم برسالة منها.',
            'سنرسل إلى: :number. تأكد أنه رقم واتساب المستلمة.',
            'اكتب رقمًا عمانيًا يبدأ بـ 9 أو 7 (8 أرقام)، أو رقمًا دوليًا يبدأ بـ +.',
            'متابعة: تأكيد رقم المستلمة',
            'تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.',
            FOR_NUMBER,
            'استخدمي رقم الجوال الذي أدخله المُهدي.',
            'هذه الهدية مخصصة لرقم جوال آخر. استلميها بالحساب المسجّل برقم المستلمة، أو اطلبي تفعيلها من استقبال النادي.',
        ]) expect(hasEnglish(text), text).toBe(true);
    });
});

describe('a gift tied to the recipient\'s number (#192)', () => {
    const show = (forNumber: boolean) => render(
        <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('paid', forNumber)} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp="https://wa.me/?text=x" claim_url="/gift/x/claim" pay_url={null} is_buyer={false} delivery={null} terms={terms} />,
    );

    it('says so on the card only when it is, never with a number', () => {
        const { container, unmount } = show(true);
        expect(screen.getByText(FOR_NUMBER)).toBeTruthy();
        expect(container.textContent).not.toMatch(/\d{8}/);
        unmount();

        show(false);
        expect(screen.queryByText(FOR_NUMBER)).toBeNull();
    });

    it('shares the link without a referrer', () => {
        show(false);
        expect(screen.getByText('مشاركة عبر واتساب').closest('a')?.getAttribute('rel')).toBe('noopener noreferrer');
    });

    it('asks for the giver\'s number when creating the account from the claim page', () => {
        const { unmount } = render(<GiftClaim site={site} seo={{ title: 'استلام الهدية' }} gift={card('paid', true)} token={'a'.repeat(48)} login_url="/login" />);
        expect(screen.getByText(FOR_NUMBER)).toBeTruthy();
        expect(screen.getByText('استخدمي رقم الجوال الذي أدخله المُهدي.')).toBeTruthy();
        unmount();

        render(<GiftClaim site={site} seo={{ title: 'استلام الهدية' }} gift={card('paid')} token={'a'.repeat(48)} login_url="/login" />);
        expect(screen.queryByText(FOR_NUMBER)).toBeNull();
        expect(screen.queryByText('استخدمي رقم الجوال الذي أدخله المُهدي.')).toBeNull();
    });

    it('offers nothing to confirm in the portal to an account with another number', () => {
        const redeem = (notRecipient: boolean) => render(
            <GiftRedeem site={site} seo={{ title: 'استلام الهدية' }} gift={card('paid', true)} token={'a'.repeat(48)} min="2026-11-17" max="2027-02-15" suggested="2026-11-17" covered_until={null} member_name="سارة" not_recipient={notRecipient} />,
        );

        const { unmount } = redeem(true);
        expect(screen.getByText('هذه الهدية مخصصة لرقم جوال آخر. استلميها بالحساب المسجّل برقم المستلمة، أو اطلبي تفعيلها من استقبال النادي.')).toBeTruthy();
        expect(screen.queryByText('تأكيد استلام الهدية')).toBeNull();
        expect(document.querySelector('input[type="date"]')).toBeNull();
        unmount();

        redeem(false);
        expect(screen.getByText('تأكيد استلام الهدية')).toBeTruthy();
        expect(document.querySelector('input[type="date"]')).toBeTruthy();
        expect(screen.queryByText('هذه الهدية مخصصة لرقم جوال آخر. استلميها بالحساب المسجّل برقم المستلمة، أو اطلبي تفعيلها من استقبال النادي.')).toBeNull();
    });
});

describe('the recipient\'s WhatsApp number (#192)', () => {
    it('reads a number as the server does: Omani forms to one, its shape only', () => {
        for (const typed of ['9555 1234', '+968 9555 1234', '0096895551234', '٩٥٥٥١٢٣٤']) expect(normalizePhone(typed)).toBe('96895551234');
        expect(isWhatsAppNumber('96895551234')).toBe(true);
        expect(isWhatsAppNumber('96871234567')).toBe(true);
        expect(isWhatsAppNumber('96881234567')).toBe(false); // not a mobile
        expect(isWhatsAppNumber('96824123456')).toBe(false); // a landline
        expect(isWhatsAppNumber('447700900123')).toBe(true);
        expect(isWhatsAppNumber('12345')).toBe(false);
        expect(displayPhone('96895551234')).toBe('+968 9555 1234');
    });

    const verification = (over: Partial<GiftVerification> = {}): GiftVerification => ({
        state: 'waiting', phone: '96895551234', verified_at: null, expires_at: '2026-11-18T12:00:00+04:00', code: 'K7Q2-9MPL',
        text: 'أوافق على استلام رسائل Alopera Gym على هذا الرقم. رمز التأكيد: K7Q2-9MPL', link: 'https://wa.me/96824000000?text=x',
        club_number: '96824000000', refusal: null, refusal_text: null, ...over,
    });
    const page = (v: GiftVerification, canPay = false) => render(
        <GiftVerify site={site} seo={{ title: 'تأكيد' }} gift={card('pending')} token="t" recipient_phone="96895551234" verification={v} total="29.000" can_pay={canPay} online_payments terms={terms} />,
    );

    it('shows the number to check, the message she sends from it, and no payment before it is confirmed', () => {
        const { container } = page(verification());
        const text = container.textContent ?? '';
        expect(text).toContain('+968 9555 1234');
        expect(text).toContain('رمز التأكيد: K7Q2-9MPL');
        // The buyer sees the total; the message the recipient sends names no gift and no price.
        expect(screen.getByText('رمز التأكيد: K7Q2-9MPL', { exact: false }).textContent).not.toMatch(/هدية|29/);
        expect(screen.getByText('فتح واتساب وإرسال الرسالة').closest('a')?.getAttribute('href')).toBe('https://wa.me/96824000000?text=x');
        expect((screen.getByText('ادفع', { exact: false, selector: 'button' }) as HTMLButtonElement).disabled).toBe(true);
        expect(text).toContain('صحة شكل الرقم لا تعني أن له حسابًا على واتساب');
    });

    it('offers the payment once her message confirmed the number, and says it cannot be done when WhatsApp is off', () => {
        page(verification({ state: 'verified', code: null, text: null, link: null, verified_at: '2026-11-17T12:00:00+04:00' }), true);
        expect(screen.getByText('تم تأكيد الرقم برسالة من المستلمة. بعد تأكيد الدفع نرسل لها الهدية على واتساب.')).toBeTruthy();
        expect((screen.getByText('ادفع', { exact: false, selector: 'button' }) as HTMLButtonElement).disabled).toBe(false);
        cleanup();
        page(verification({ state: 'unavailable', code: null, text: null, link: null, refusal: 'not_connected', refusal_text: 'واتساب النادي غير مربوط بالنظام أو غير مفعّل.' }));
        expect(screen.getByText('واتساب النادي غير مربوط بالنظام أو غير مفعّل.')).toBeTruthy();
        expect(screen.queryByText('فتح واتساب وإرسال الرسالة')).toBeNull();
    });

    it('has the English of the confirmation page', () => {
        for (const text of ['تأكيد رقم واتساب المستلمة', 'كيف تؤكد المستلمة رقمها', 'فتح واتساب وإرسال الرسالة', 'نسخ رابط التأكيد', 'بانتظار رسالة المستلمة…',
            'يظهر الدفع بعد تأكيد رقم المستلمة.', 'ادفع :amount وأهدِ الآن', 'تم تأكيد الرقم برسالة من المستلمة. بعد تأكيد الدفع نرسل لها الهدية على واتساب.',
            'صحة شكل الرقم لا تعني أن له حسابًا على واتساب: يتأكد ذلك فقط عندما تصلنا رسالة من الرقم نفسه.']) expect(hasEnglish(text), text).toBe(true);
    });
});

describe('the buyer\'s view of the gift\'s WhatsApp message (#192)', () => {
    const view = (delivery: { state: string; label: string; note: string | null } | null) => render(
        <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('paid')} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp={null} claim_url={null} pay_url={null} is_buyer={!!delivery} delivery={delivery} terms={terms} />,
    );

    it('shows the buyer where it is, and what to do when it failed, without the provider\'s error', () => {
        view({ state: 'failed', label: 'فشل الإرسال', note: 'تعذّر إرسال الهدية إلى واتساب المستلمة. شارك رابط الهدية بنفسك أو تواصل مع النادي.' });
        expect(screen.getByText('فشل الإرسال')).toBeTruthy();
        expect(screen.getByText('تعذّر إرسال الهدية إلى واتساب المستلمة. شارك رابط الهدية بنفسك أو تواصل مع النادي.')).toBeTruthy();
        expect(hasEnglish('رسالة الهدية على واتساب المستلمة:')).toBe(true);
    });

    it('shows nothing about it to anyone else with the link', () => {
        const { container } = view(null);
        expect(container.textContent).not.toContain('رسالة الهدية على واتساب المستلمة');
    });
});

