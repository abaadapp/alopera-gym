import { useState, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AtSign, Mail, MapPin, Menu, MessageCircle, Phone, X } from 'lucide-react';
import { Flash } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { phone as fmtPhone, whatsappUrl } from '@/lib/format';

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
    logo_url: string | null;
    signup_enabled: boolean;
}

const links = [
    { label: 'الرئيسية', route: 'site.home' },
    { label: 'الباقات', route: 'site.plans' },
    { label: 'البرامج', route: 'site.programs' },
    { label: 'جدول الحصص', route: 'site.schedule' },
    { label: 'المدربات', route: 'site.trainers' },
    { label: 'العروض والأخبار', route: 'site.news' },
    { label: 'تواصلي معنا', route: 'site.contact' },
];

/**
 * Public website shell. <title>/description are rendered on the server from
 * the `seo` prop; Head here only keeps the title in sync on client navigation.
 */
export default function SiteLayout({ site, children, seoTitle }: { site: SiteInfo; children: ReactNode; seoTitle?: string }) {
    const { tenant, member } = useShared();
    const [open, setOpen] = useState(false);

    return (
        <>
            {seoTitle && <Head title={seoTitle} />}
            <div className="min-h-screen bg-[#fffafb]">
                {site.announcement && <div className="bg-brand px-4 py-2 text-center text-sm text-white">{site.announcement}</div>}
                <header className="sticky top-0 z-30 border-b border-line/70 bg-white/90 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
                        <Link href={route('site.home')} className="flex items-center gap-2.5">
                            {site.logo_url ? <img src={site.logo_url} alt={tenant?.name} className="size-10 rounded-xl object-cover" width={40} height={40} /> : <span className="grid size-10 place-items-center rounded-xl bg-brand font-display text-white">{tenant?.name?.[0]}</span>}
                            <span className="font-display text-lg font-semibold text-ink">{tenant?.name}</span>
                        </Link>
                        <nav className="hidden items-center gap-1 lg:flex" aria-label="القائمة الرئيسية">
                            {links.map((l) => (
                                <Link key={l.route} href={route(l.route)} className={cn('rounded-full px-3 py-1.5 text-sm transition', route().current(l.route) ? 'bg-brand-50 text-brand-700' : 'text-ink/80 hover:text-brand')}>
                                    {l.label}
                                </Link>
                            ))}
                        </nav>
                        <div className="flex items-center gap-2">
                            <Link href={route(member ? 'portal.dashboard' : 'portal.login')} className="hidden rounded-full border border-line px-4 py-2 text-sm hover:border-brand-300 sm:inline-block">
                                {member ? 'حسابي' : 'دخول المشتركات'}
                            </Link>
                            {site.signup_enabled && (
                                <Link href={route('site.join')} className="rounded-full bg-brand px-4 py-2 text-sm font-medium text-white shadow-sm shadow-brand/30 hover:bg-brand-600">
                                    اشتركي الآن
                                </Link>
                            )}
                            <button className="grid size-10 place-items-center rounded-full hover:bg-brand-50 lg:hidden" onClick={() => setOpen(!open)} aria-label="القائمة">
                                {open ? <X className="size-5" /> : <Menu className="size-5" />}
                            </button>
                        </div>
                    </div>
                    {open && (
                        <nav className="border-t border-line bg-white px-4 py-3 lg:hidden">
                            {links.map((l) => (
                                <Link key={l.route} href={route(l.route)} className="block rounded-xl px-3 py-2.5 text-sm hover:bg-brand-50">{l.label}</Link>
                            ))}
                            <Link href={route(member ? 'portal.dashboard' : 'portal.login')} className="block rounded-xl px-3 py-2.5 text-sm font-medium text-brand">{member ? 'حسابي' : 'دخول المشتركات'}</Link>
                        </nav>
                    )}
                </header>

                <main>{children}</main>

                <footer className="mt-20 border-t border-line bg-white">
                    <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 md:grid-cols-3">
                        <div>
                            <p className="font-display text-lg font-semibold">{tenant?.name}</p>
                            <p className="mt-2 text-sm leading-7 text-muted">نادٍ نسائي للياقة والصحة. احجزي حصصك وجددي اشتراكك أونلاين.</p>
                        </div>
                        <div className="space-y-2 text-sm">
                            {site.contact_phone && <a href={`tel:+${site.contact_phone}`} className="flex items-center gap-2 text-ink/80"><Phone className="size-4 text-brand" /><span className="num">{fmtPhone(site.contact_phone)}</span></a>}
                            {site.whatsapp_number && <a href={whatsappUrl(site.whatsapp_number)} className="flex items-center gap-2 text-ink/80" target="_blank" rel="noreferrer"><MessageCircle className="size-4 text-brand" />واتساب</a>}
                            {site.contact_email && <a href={`mailto:${site.contact_email}`} className="flex items-center gap-2 text-ink/80"><Mail className="size-4 text-brand" />{site.contact_email}</a>}
                            {site.address && <p className="flex items-center gap-2 text-ink/80"><MapPin className="size-4 text-brand" />{site.address}</p>}
                            {site.social_links?.instagram && <a href={site.social_links.instagram} className="flex items-center gap-2 text-ink/80" target="_blank" rel="noreferrer"><AtSign className="size-4 text-brand" />إنستغرام</a>}
                        </div>
                        {(site.working_hours ?? []).length > 0 && (
                            <div className="text-sm">
                                <p className="mb-2 font-medium">أوقات العمل</p>
                                {(site.working_hours ?? []).map((w, i) => (
                                    <p key={i} className="flex justify-between gap-4 py-0.5 text-ink/80"><span>{w.days}</span><span>{w.hours}</span></p>
                                ))}
                            </div>
                        )}
                    </div>
                    <p className="border-t border-line py-4 text-center text-xs text-muted">© {new Date().getFullYear()} {tenant?.name}</p>
                </footer>
            </div>
            <Flash />
        </>
    );
}
