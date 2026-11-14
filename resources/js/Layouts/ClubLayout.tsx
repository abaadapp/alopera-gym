import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import PanelShell from '@/Components/PanelShell';
import Topbar from '@/Components/Topbar';
import { useShared } from '@/hooks/useShared';
import { useNavigation } from '@/lib/nav';

export default function ClubLayout({ title, children, fullBleed = false }: { title: string; children: ReactNode; fullBleed?: boolean }) {
    const { tenant } = useShared();
    const { groups } = useNavigation();

    return (
        <>
            <Head title={`${title} · ${tenant?.name ?? ''}`} />
            <PanelShell
                groups={groups.map((group) => ({ footer: group.footer, links: group.sections.map((s) => ({ label: s.label, href: s.href, icon: s.icon, active: s.active })) }))}
                identity={tenant?.logo_url && <img src={tenant.logo_url} alt={tenant.name} className="h-[68px] w-auto max-w-full object-contain" width={91} height={68} />}
                topbar={(openMenu) => <Topbar onMenu={openMenu} />}
                fullBleed={fullBleed}
            >
                {children}
            </PanelShell>
        </>
    );
}
