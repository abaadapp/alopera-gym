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
import GiftCard from '@/Pages/Site/GiftCard';
import type { GiftCardData } from '@/Components/Site/Gift';

beforeAll(() => {
    (globalThis as unknown as { route: (name: string) => string }).route = (name: string) => `/${name}`;
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

const card = (status: string): GiftCardData => ({
    status, recipient_name: 'سارة', giver_name: 'نورة', message: 'كل عام وأنتِ بخير', plan_name: 'شهري', duration_label: 'شهر', expires_on: '2027-02-15', club: 'Alopera Gym',
});

const terms = { redeem_days: 90, max_start_days: 30 };

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
