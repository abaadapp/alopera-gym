import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { cleanup, render } from '@testing-library/react';
import type { ImageData, RenderedSection, SiteChrome } from '@/types/site';

const chrome: SiteChrome = {
    theme: { accent: '#8A6700', ink: '#16130F', surface: '#F6F3EE' },
    announcement: null,
    header: { links: [{ label: 'الباقات', href: '/#pricing', external: false }], cta: { label: 'انضمي الآن', href: '/join', external: false }, show_login: true },
    footer: { line: 'نادٍ نسائي', links: [], show_hours: true, show_social: true },
    home_url: '/',
    preview: false,
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { chrome, tenant: { name: 'Alopera Gym', currency: 'OMR' }, member: null, flash: {}, errors: {} } }),
    useForm: (data: Record<string, string>) => ({ data, setData: () => {}, post: () => {}, reset: () => {}, processing: false, errors: {} }),
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => <a href={href} {...rest}>{children}</a>,
    Head: () => null,
    router: { on: () => () => {} },
}));
vi.mock('@/lib/cart', () => ({ useCart: () => ({ count: 0 }) }));
vi.mock('@/Components/ui', () => ({ Flash: () => null }));

import Home from '@/Pages/Site/Home';

beforeAll(() => {
    (globalThis as unknown as { route: (name: string) => string }).route = (name: string) => `/${name}`;
});

afterEach(cleanup);

const site = {
    is_published: true, announcement: null, contact_phone: '96890000000', whatsapp_number: '96890000000', contact_email: 'hello@club.om', address: 'مسقط',
    map_url: 'https://maps.example/x', working_hours: [{ days: 'كل الأيام', hours: '6ص – 11م' }], social_links: { instagram: 'https://instagram.com/club' },
    logo_url: '/brand/alopera-gym-logo.png', signup_enabled: true, shop_open: false,
};

const image = (width: number, height: number, fit: ImageData['fit'] = 'auto'): ImageData => ({ url: `/storage/tenants/1/site/${width}x${height}.webp`, width, height, orientation: null, alt: 'صورة النادي', fit });
const sec = (type: string, data: Record<string, unknown>, feed?: Record<string, unknown>, tone: RenderedSection['tone'] = 'white'): RenderedSection => ({ id: type, type, tone, data, feed });

const plan = { id: 1, name: 'شهري', description: 'وصف', price: '29.000', signup_fee: '5.000', duration_label: 'شهر', type_label: 'اشتراك', features: ['ميزة'], is_featured: true, join_url: '/join?plan=1' };

function page(sections: RenderedSection[]) {
    return render(<Home site={site} seo={{ title: 'Alopera Gym' }} page={{ sections }} />);
}

describe('the official home page', () => {
    it('draws every section type, in order, and ignores an unknown one', () => {
        const sections = [
            sec('hero', { title: 'قوّتكِ', title_2: 'تبدأ هنا.', subtitle: 'وصف', primary: { label: 'ابدئي', href: '/join', external: false }, secondary: null, image: null, facts: [{ id: 'a', title: 'خصوصية', text: 'تامة' }], show_next_class: true }, { next_class: { name: 'يوغا', date: '2026-11-10', time: '06:30', day_label: 'الثلاثاء' } }, 'dark'),
            sec('intro', { eyebrow: 'من نحن', title: 'عن النادي', body: 'نص', button: null, align: 'center' }),
            sec('image_text', { title: 'صورة ونص', body: 'نص', image: image(800, 1200), image_side: 'start', button: null }),
            sec('gallery', { title: 'المعرض', layout: 'auto', items: [{ id: 'g1', image: image(1600, 900), caption: 'القاعة' }, { id: 'g2', image: image(900, 1600), caption: '' }] }),
            sec('services', { title: 'المرافق', style: 'cards', items: [{ id: 's1', title: 'الساونا', text: 'وصف', image: null }] }),
            sec('video', { title: 'جولة', body: '', url: 'https://youtu.be/x' }, { embed_url: 'https://www.youtube-nocookie.com/embed/x' }, 'beige'),
            sec('testimonials', { title: 'آراء', items: [{ id: 't1', quote: 'رائع', name: 'سارة', meta: '' }, { id: 't2', quote: 'ممتاز', name: '', meta: '' }] }, undefined, 'beige'),
            sec('stats', { title: 'أرقام', items: [{ id: 'n1', value: '+500', label: 'عضوة' }] }, undefined, 'dark'),
            sec('faq', { title: 'أسئلة', items: [{ id: 'f1', q: 'متى تفتحون؟', a: 'كل يوم' }] }),
            sec('cta', { title: 'ابدئي اليوم', body: '', primary: { label: 'سجّلي', href: '/join', external: false }, secondary: null }, undefined, 'dark'),
            sec('programs', { title: 'البرامج', button: null }, { programs: [{ id: 1, name: 'يوغا', description: 'وصف', category: 'class', level: 'all', duration_minutes: 60, image_url: null }] }),
            sec('schedule', { title: 'الجدول', show_trainer: true }, { days: [{ date: '2026-11-10', label: 'الثلاثاء ١٠ نوفمبر', sessions: [{ id: 9, program: 'يوغا', trainer: 'نورة', date: '2026-11-10', time: '06:30', end_time: '07:30', duration_minutes: 60, spots_left: 2, full: false }] }], booking_url: '/portal/classes', schedule_url: '/schedule' }, 'beige'),
            sec('trainers', { title: 'المدربات' }, { trainers: [{ id: 1, name: 'نورة', bio: null, specialties: ['لياقة', 'يوغا'], photo_url: null }, { id: 2, name: 'ريم', bio: 'نبذة', specialties: [], photo_url: '/storage/t.jpg' }] }),
            sec('plans', { title: 'الباقات', featured_label: 'الأكثر طلباً', button_label: 'اختاري الباقة', button: null }, { plans: [plan], currency: 'OMR' }),
            sec('products', { title: 'المتجر' }, { products: [{ id: 1, name: 'قارورة', price: '3.500', category: null, image_url: null, in_stock: true, url: '/shop/1' }], shop_url: '/shop', currency: 'OMR' }),
            sec('posts', { title: 'الأخبار' }, { posts: [{ id: 1, type: 'offer', title: 'عرض', excerpt: null, image_url: null, published_at: '2026-11-01', ends_on: null, url: '/news/x' }], all_url: '/news' }),
            sec('join', { title: 'سجّلي', steps: [{ id: 'j1', text: 'بياناتكِ' }], button_label: 'ابدئي التسجيل', show_plans: true }, { plans: [plan], join_url: '/join', currency: 'OMR' }),
            sec('contact', { title: 'تواصلي', show_form: true, show_hours: true, show_map: true }, { send_url: '/contact' }),
            sec('nonsense', { title: 'لا يظهر' }),
        ];
        const { container } = page(sections);
        const ids = [...container.querySelectorAll('main > section')].map((s) => s.id);
        expect(ids).toEqual(sections.filter((s) => s.type !== 'nonsense').map((s) => s.id));
        expect(container.textContent).not.toContain('لا يظهر');
        expect(container.querySelector('h1')?.textContent).toBe('قوّتكِتبدأ هنا.');
        expect(container.querySelector('iframe')?.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/x');
        expect(container.querySelector('a[href="/join?plan=1"]')).not.toBeNull();
    });

    it('never draws an image that is not there: no image, no frame, no placeholder', () => {
        const { container } = page([
            sec('hero', { title: 'قوّتكِ', title_2: '', subtitle: '', primary: null, secondary: null, image: null, facts: [], show_next_class: false }, { next_class: null }, 'dark'),
            sec('image_text', { title: 'بلا صورة', body: 'نص', image: null, image_side: 'end', button: null }),
        ]);
        // Only the logo (header and footer).
        expect([...container.querySelectorAll('main img')]).toHaveLength(0);
        expect(container.innerHTML).not.toMatch(/placeholder|أضف صورة|Drop/);
    });

    it('shows an uploaded image whole, at its own proportions, unless the owner chose to fill the space', () => {
        const { container } = page([
            sec('gallery', { title: 'المعرض', layout: 'auto', items: [{ id: 'g1', image: image(1600, 900), caption: '' }, { id: 'g2', image: image(900, 1600, 'cover'), caption: '' }] }),
        ]);
        const imgs = [...container.querySelectorAll('main img')] as HTMLImageElement[];
        expect(imgs).toHaveLength(2);
        expect(imgs[0].className).toContain('object-contain');
        expect(imgs[0].style.aspectRatio).toBe('1600 / 900');
        expect(imgs[0].getAttribute('width')).toBe('1600');
        expect(imgs[0].getAttribute('alt')).toBe('صورة النادي');
        // In the masonry the image sets its own height: even 'cover' has nothing to trim there.
        expect(imgs[1].className).not.toContain('object-cover');
    });

    it('lays the hero out by the image: beside the text when tall, full-bleed only when the owner asked', () => {
        const tall = page([sec('hero', { title: 'أ', title_2: '', subtitle: '', primary: null, secondary: null, image: image(1080, 1350), facts: [], show_next_class: false }, { next_class: null }, 'dark')]);
        const tallImg = tall.container.querySelector('main img') as HTMLImageElement;
        expect(tallImg.className).toContain('object-contain');
        expect(tallImg.getAttribute('loading')).toBe('eager');
        cleanup();
        const cover = page([sec('hero', { title: 'أ', title_2: '', subtitle: '', primary: null, secondary: null, image: image(1080, 1350, 'cover'), facts: [], show_next_class: false }, { next_class: null }, 'dark')]);
        expect((cover.container.querySelector('main img') as HTMLImageElement).className).toContain('object-cover');
    });

    it('keeps the owner text as text: markup typed into a field is shown, never run', () => {
        const { container } = page([sec('intro', { title: '<img src=x onerror=alert(1)>', body: '<script>alert(1)</script>', button: null, align: 'start' })]);
        expect(container.querySelector('main img')).toBeNull();
        expect(container.querySelector('main script')).toBeNull();
        expect(container.textContent).toContain('<script>alert(1)</script>');
    });
});
