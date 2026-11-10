import { Fragment, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, FileText, Home, LogOut, RefreshCw, UserRound } from 'lucide-react';
import SiteLayout, { type SiteInfo } from './SiteLayout';
import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { useT } from '@/lib/i18n';

/**
 * A translated phrase with marked-up values in it: each :name of t('المتبقي :amount') replaced by its node (a figure in
 * its own span), so the phrase stays one sentence in either language and the Arabic reads exactly as before.
 */
export function rich(text: string, nodes: Record<string, ReactNode>): ReactNode {
    return text.split(/:([A-Za-z_]+)/g).map((part, i) => (i % 2 === 1 ? <Fragment key={i}>{part in nodes ? nodes[part] : `:${part}`}</Fragment> : part));
}

export default function PortalLayout({ site, title, children }: { site: SiteInfo; title: string; children: ReactNode }) {
    const { member } = useShared();
    const t = useT();
    const tabs = [
        { label: t('الرئيسية'), route: 'portal.dashboard', icon: Home },
        { label: t('حجز الحصص'), route: 'portal.classes', icon: CalendarCheck },
        { label: t('التجديد'), route: 'portal.renew', icon: RefreshCw },
        { label: t('الفواتير'), route: 'portal.invoices', icon: FileText },
        { label: t('ملفي'), route: 'portal.profile', icon: UserRound },
    ];

    return (
        <SiteLayout site={site} seoTitle={title}>
            <div className="mx-auto max-w-5xl px-4 py-8">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted">{t('أهلًا بك')}</p>
                        <h1 className="text-2xl font-semibold">{member?.name}</h1>
                    </div>
                    <button onClick={() => router.post(route('portal.logout'))} className="inline-flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm hover:bg-brand-50">
                        <LogOut className="size-4" /> {t('خروج')}
                    </button>
                </div>
                <nav className="no-scrollbar mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-line bg-white p-1">
                    {tabs.map(({ label, route: r, icon: Icon }) => (
                        <Link key={r} href={route(r)} className={cn('flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-3 py-2 text-sm', route().current(r) ? 'bg-brand text-white' : 'text-ink/80 hover:bg-brand-50')}>
                            <Icon className="size-4" /> {label}
                        </Link>
                    ))}
                </nav>
                {children}
            </div>
        </SiteLayout>
    );
}
