import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Languages, Menu, ShoppingBag, X } from 'lucide-react';
import { Flash } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { useCart } from '@/lib/cart';
import { cn } from '@/lib/cn';
import { phone as fmtPhone, whatsappUrl } from '@/lib/format';
import { announceLanguageSwitch } from '@/lib/carry-over';
import { tr, useLocale, useT } from '@/lib/i18n';
import type { LinkData, SiteChrome } from '@/types/site';

export interface SiteInfo {
    is_published: boolean;
    announcement: string | null;
    contact_phone: string | null;
    whatsapp_number: string | null;
    contact_email: string | null;
    address: string | null;
    map_url: string | null;
    working_hours: { days: string; hours: string }[];
    social_links: Record<string, string | null>;
    logo_url: string;
    signup_enabled: boolean;
    /** The club shows products in its website shop (sales module on, shop on, a product shown). */
    shop_open: boolean;
}

/** The menu before the owner has one (or on a page the server sends without the site's chrome). */
function fallbackLinks(shopOpen: boolean): LinkData[] {
    const pages: [string, string][] = [
        ['الباقات', 'site.plans'],
        ['البرامج', 'site.programs'],
        ['جدول الحصص', 'site.schedule'],
        ['المدربات', 'site.trainers'],
        ...(shopOpen ? [['المتجر', 'site.shop'] as [string, string]] : []),
        ['العروض والأخبار', 'site.news'],
        ['تواصل معنا', 'site.contact'],
    ];
    return pages.map(([label, name]) => ({ label: tr(label), href: route(name), external: false }));
}

const SOCIAL: Record<string, string> = { instagram: 'إنستغرام', x: 'إكس', tiktok: 'تيك توك', snapchat: 'سناب شات', facebook: 'فيسبوك', youtube: 'يوتيوب' };

/**
 * The switch between the Arabic site and the English one (/en/...): the same page in the other language, with the
 * same query (a chosen plan). A plain link, so the page loads again in its own direction (right to left or left to
 * right) from the server. Shown in the header on every screen size, and again in the phone menu.
 */
function LanguageSwitch({ className, long = false }: { className?: string; long?: boolean }) {
    const locale = useLocale();
    const alternates = (usePage().props.seo as { alternates?: Record<string, string> } | undefined)?.alternates;
    const to = locale === 'en' ? 'ar' : 'en';
    const href = alternates?.[to];
    if (!href) return null;
    // The same query (a chosen plan); not when the address has its own (the draft preview's ?lang=).
    const query = typeof window !== 'undefined' && !href.includes('?') ? window.location.search : '';
    const name = to === 'en' ? 'English' : 'العربية';
    return (
        <a href={href + query} hrefLang={to} lang={to} className={className} aria-label={to === 'en' ? 'English version' : 'النسخة العربية'} onClick={announceLanguageSwitch}>
            <Languages className="size-[18px] shrink-0" aria-hidden />
            {/* The name in its own language and direction; the link sits in the page's own direction. */}
            {long ? <span lang={to} dir={to === 'en' ? 'ltr' : 'rtl'}>{name}</span> : (<><span lang={to} className="hidden sm:inline">{name}</span><span lang={to} className="sm:hidden">{to === 'en' ? 'EN' : 'عربي'}</span></>)}
        </a>
    );
}

/** A link of the menu or footer: a plain <a>, so a section anchor (#…) or another address both work. */
function NavLink({ link, className, onClick }: { link: LinkData; className?: string; onClick?: () => void }) {
    const blank = link.external && /^https?:/i.test(link.href);
    return (
        <a href={link.href} onClick={onClick} className={className} {...(blank ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
            {link.label}
        </a>
    );
}

/**
 * The official website's frame, in the v2 design: a white sticky header (logo, menu, join button), the page, and a
 * dark footer. The menu, join button, footer and colours come from «إدارة الموقع» (the `chrome` prop the server
 * sends with every site page); <title>/description are rendered on the server from the `seo` prop, and Head here
 * only keeps the title in sync on client navigation.
 */
export default function SiteLayout({ site, children, seoTitle }: { site: SiteInfo; children: ReactNode; seoTitle?: string }) {
    const { tenant, member } = useShared();
    const t = useT();
    const chrome = (usePage().props.chrome as SiteChrome | undefined) ?? null;
    const [open, setOpen] = useState(false);
    const cart = useCart();

    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    const theme = chrome?.theme ?? { accent: '#8A6700', ink: '#16130F', surface: '#F6F3EE' };
    // --site-gold: the warm gold of the design's buttons and bullets (the accent stays the deeper gold of small text).
    const vars = { '--brand': theme.accent, '--site-accent': theme.accent, '--site-ink': theme.ink, '--site-surface': theme.surface, '--site-gold': '#C9A042' } as CSSProperties;
    const links = chrome ? chrome.header.links : fallbackLinks(site.shop_open);
    const cta: LinkData | null = chrome ? chrome.header.cta : site.signup_enabled ? { label: t('انضمي الآن'), href: route('site.join'), external: false } : null;
    const showLogin = chrome ? chrome.header.show_login : true;
    const homeUrl = chrome?.home_url ?? route('site.home');
    const announcement = chrome ? chrome.announcement : site.announcement;
    const footerLinks = chrome ? chrome.footer.links : links;
    const showHours = chrome ? chrome.footer.show_hours : true;
    const showSocial = chrome ? chrome.footer.show_social : true;
    const social = Object.entries(site.social_links ?? {}).filter(([, v]) => !!v) as [string, string][];
    const hours = site.working_hours ?? [];
    const login = { label: member ? t('حسابي') : t('دخول العضوات'), href: route(member ? 'portal.dashboard' : 'portal.login') };

    return (
        <>
            {seoTitle && <Head title={seoTitle} />}
            {/* The site's own face comes and goes with it: loaded for the whole app it would reach the panel, which loads none. */}
            <Head>
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" />
            </Head>
            <div className="site-theme flex min-h-screen flex-col overflow-x-clip bg-white font-sans leading-[1.65] text-[var(--site-ink)]" style={vars}>
                {announcement && <div className="bg-[var(--site-ink)] px-5 py-2 text-center text-sm text-white">{announcement}</div>}

                <header className="sticky top-0 z-50 border-b border-[rgba(22,19,15,0.1)] bg-white/95 backdrop-blur-[10px]">
                    <div className="mx-auto flex min-h-16 max-w-[1360px] items-center gap-4 px-5 py-2.5 sm:px-10 xl:gap-8">
                        <a href={homeUrl} className="flex shrink-0" aria-label={tenant?.name ?? t('الرئيسية')}>
                            <img src={site.logo_url} alt={tenant?.name ?? ''} className="block h-11 w-auto object-contain" />
                        </a>
                        <nav className="hidden min-w-0 flex-1 flex-wrap justify-center gap-x-[22px] xl:flex" aria-label={t('القائمة الرئيسية')}>
                            {links.map((l, i) => (
                                <NavLink key={i} link={l} className="py-1.5 text-[15px] whitespace-nowrap text-[#3A342B] transition-colors hover:text-[var(--site-accent)]" />
                            ))}
                        </nav>
                        <div className="ms-auto flex shrink-0 items-center gap-2 sm:gap-4">
                            {site.shop_open && (
                                <Link href={route('site.shop.cart')} className="relative grid size-10 place-items-center rounded-xl text-[var(--site-ink)] hover:bg-[var(--site-surface)]" aria-label={t('السلة (:count)', { count: cart.count })}>
                                    <ShoppingBag className="size-5" />
                                    {cart.count > 0 && <span className="absolute -top-0.5 -end-0.5 grid min-w-5 place-items-center rounded-full bg-[var(--site-accent)] px-1 text-[11px] font-semibold text-white">{cart.count}</span>}
                                </Link>
                            )}
                            <LanguageSwitch className="inline-flex h-10 items-center gap-1.5 rounded-full border border-[rgba(22,19,15,0.15)] px-2.5 text-sm font-medium whitespace-nowrap text-[var(--site-ink)] transition-colors hover:border-[var(--site-accent)] hover:text-[var(--site-accent)] sm:px-3" />
                            {showLogin && (
                                <a href={login.href} className="hidden py-1.5 text-[15px] whitespace-nowrap text-[var(--site-ink)] underline underline-offset-[5px] hover:text-[var(--site-accent)] sm:inline">
                                    {login.label}
                                </a>
                            )}
                            {cta && cta.label && (
                                <NavLink link={cta} className="inline-flex h-11 items-center rounded-full bg-[var(--site-gold)] px-4 text-[15px] font-semibold whitespace-nowrap text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] hover:text-[var(--site-ink)] sm:px-[22px]" />
                            )}
                            <button
                                type="button"
                                className="grid size-10 place-items-center rounded-xl text-[var(--site-ink)] hover:bg-[var(--site-surface)] xl:hidden"
                                onClick={() => setOpen(!open)}
                                aria-label={open ? t('إغلاق القائمة') : t('القائمة')}
                                aria-expanded={open}
                            >
                                {open ? <X className="size-5" /> : <Menu className="size-5" />}
                            </button>
                        </div>
                    </div>
                    {open && (
                        <nav className="max-h-[calc(100svh-65px)] overflow-y-auto border-t border-[rgba(22,19,15,0.1)] bg-white px-5 py-3 sm:px-10 xl:hidden" aria-label={t('القائمة')}>
                            {links.map((l, i) => (
                                <NavLink key={i} link={l} onClick={() => setOpen(false)} className="block border-b border-[rgba(22,19,15,0.08)] py-3.5 text-base text-[#3A342B] last:border-b-0 hover:text-[var(--site-accent)]" />
                            ))}
                            {showLogin && (
                                <a href={login.href} className="mt-2 block py-3 text-base font-semibold text-[var(--site-ink)]">{login.label}</a>
                            )}
                            <LanguageSwitch long className="flex items-center gap-2 border-t border-[rgba(22,19,15,0.08)] py-3.5 text-base font-medium text-[var(--site-ink)] hover:text-[var(--site-accent)]" />
                        </nav>
                    )}
                </header>

                <main className="flex-1">{children}</main>

                <footer className="border-t border-[rgba(26,22,18,0.08)] bg-[var(--site-surface)] text-[#5E5648]">
                    <div className="mx-auto grid max-w-[1360px] gap-10 px-5 py-14 sm:px-10 md:grid-cols-2 lg:grid-cols-12 lg:gap-12">
                        <div className="flex min-w-0 flex-col gap-4 lg:col-span-4">
                            <img src={site.logo_url} alt={tenant?.name ?? ''} className="block h-[60px] w-auto self-start object-contain" />
                            {chrome?.footer.line && <p className="m-0 max-w-[360px] text-sm leading-7 whitespace-pre-line">{chrome.footer.line}</p>}
                        </div>
                        {footerLinks.length > 0 && (
                            <nav className="flex min-w-0 flex-col gap-2.5 text-sm lg:col-span-2" aria-label={t('روابط الموقع')}>
                                {footerLinks.map((l, i) => <NavLink key={i} link={l} className="self-start text-[#5E5648] transition-colors hover:text-[var(--site-accent)]" />)}
                            </nav>
                        )}
                        <div className="flex min-w-0 flex-col gap-2.5 text-sm lg:col-span-3">
                            {site.contact_phone && <a href={`tel:+${site.contact_phone}`} dir="ltr" className="self-start [unicode-bidi:isolate] hover:text-[var(--site-accent)]">{fmtPhone(site.contact_phone)}</a>}
                            {site.whatsapp_number && <a href={whatsappUrl(site.whatsapp_number)} target="_blank" rel="noopener noreferrer" className="self-start hover:text-[var(--site-accent)]">{t('واتساب')}</a>}
                            {site.contact_email && <a href={`mailto:${site.contact_email}`} className="self-start break-all hover:text-[var(--site-accent)]">{site.contact_email}</a>}
                            {site.address && <p className="m-0 leading-7">{site.address}</p>}
                            {showSocial && social.length > 0 && (
                                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                    {social.map(([key, href]) => <a key={key} href={href} target="_blank" rel="noopener noreferrer" className="border-b border-[rgba(26,22,18,0.25)] hover:border-[var(--site-accent)] hover:text-[var(--site-accent)]">{SOCIAL[key] ? t(SOCIAL[key]) : key}</a>)}
                                </div>
                            )}
                        </div>
                        {showHours && hours.length > 0 && (
                            <div className="flex min-w-0 flex-col gap-1.5 text-sm lg:col-span-3">
                                <p className="m-0 mb-1 font-semibold text-[var(--site-ink)]">{t('أوقات العمل')}</p>
                                {hours.map((w, i) => (
                                    <p key={i} className="m-0 flex flex-wrap justify-between gap-x-4"><span>{w.days}</span><span>{w.hours}</span></p>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="border-t border-[rgba(26,22,18,0.08)]">
                        <p className="mx-auto m-0 max-w-[1360px] px-5 py-5 text-[13px] text-[#7A7060] sm:px-10">© {new Date().getFullYear()} {tenant?.name}</p>
                    </div>
                </footer>

                {chrome?.preview && (
                    <div className="pointer-events-none fixed inset-x-0 bottom-3 z-[60] flex justify-center px-4">
                        <span className="rounded-full bg-[#A33A22] px-4 py-1.5 text-sm font-semibold text-white shadow-lg">{t('معاينة المسودة — لا يراها الزوار')}</span>
                    </div>
                )}
            </div>
            <Flash />
        </>
    );
}
