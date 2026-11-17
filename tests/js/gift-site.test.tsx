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
import GiftRedeem from '@/Pages/Portal/GiftRedeem';
import type { GiftCardData } from '@/Components/Site/Gift';
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
            <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('paid')} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp="https://wa.me/?text=x" claim_url="/gift/x/claim" pay_url={null} is_buyer={false} terms={terms} />,
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
            <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('redeemed')} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp={null} claim_url={null} pay_url={null} is_buyer={false} terms={terms} />,
        );
        expect(screen.queryByText('استلمي هديتك')).toBeNull();
        expect(screen.queryByText('مشاركة عبر واتساب')).toBeNull();
        expect(screen.getByText('استُخدمت هذه الهدية.')).toBeTruthy();
        expect(container.textContent).not.toContain('صالحة للاستلام حتى');
    });
});

describe('buying a gift: the policy before payment (#192)', () => {
    const buy = () => render(
        <GiftBuy site={site} seo={{ title: 'إهداء' }} plan={plan} summary={{ price: '29.000', tax: '0.000', tax_rate: 0, total: '29.000' }} onlinePayments terms={terms} />,
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
        const pay = screen.getByText('ادفع وأهدِ الآن');
        expect(policy.compareDocumentPosition(total) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(policy.compareDocumentPosition(pay) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('asks for the recipient\'s number as optional, left to right', () => {
        buy();
        const input = screen.getByText('رقم جوال المستلمة (اختياري)').closest('label')?.querySelector('input');
        expect(input?.getAttribute('dir')).toBe('ltr');
        expect(input?.hasAttribute('required')).toBe(false);
        expect(screen.getByText('إذا أدخلته فلا يستلم الهدية من الرابط إلا حساب بهذا الرقم. لا يظهر الرقم في بطاقة الهدية.')).toBeTruthy();
    });

    it('has the English of every new phrase', () => {
        for (const text of [
            'سياسة الهدية',
            'صالحة للاستلام :days من تاريخ الدفع (حتى :date إن تمّ الدفع اليوم).',
            'إذا انقضت المهلة دون استلامها تنتهي الهدية ولا تُردّ قيمتها.',
            'لا تُردّ قيمة الهدية بعد استلامها، ولا يُردّ جزء منها.',
            'لا تُردّ قيمة الهدية بعد انتهاء مهلتها أو بعد استلامها، ولا يُردّ جزء منها.',
            'أوافق على سياسة الهدية وشروطها و:terms',
            'رقم جوال المستلمة (اختياري)',
            'إذا أدخلته فلا يستلم الهدية من الرابط إلا حساب بهذا الرقم. لا يظهر الرقم في بطاقة الهدية.',
            FOR_NUMBER,
            'استخدمي رقم الجوال الذي أدخله المُهدي.',
            'هذه الهدية مخصصة لرقم جوال آخر. استلميها بالحساب المسجّل برقم المستلمة، أو اطلبي تفعيلها من استقبال النادي.',
        ]) expect(hasEnglish(text), text).toBe(true);
    });
});

describe('a gift tied to the recipient\'s number (#192)', () => {
    const show = (forNumber: boolean) => render(
        <GiftCard site={site} seo={{ title: 'هدية' }} gift={card('paid', forNumber)} token={'a'.repeat(48)} link="https://club.om/gift/x" whatsapp="https://wa.me/?text=x" claim_url="/gift/x/claim" pay_url={null} is_buyer={false} terms={terms} />,
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
