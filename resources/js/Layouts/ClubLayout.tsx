import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import PanelShell from '@/Components/PanelShell';
import Topbar from '@/Components/Topbar';
import { useShared } from '@/hooks/useShared';
import { useNavigation } from '@/lib/nav';

export default function ClubLayout({ title, children }: { title: string; children: ReactNode }) {
    const { tenant } = useShared();
    const { groups } = useNavigation();

    return (
        <>
            <Head title={`${title} · ${tenant?.name ?? ''}`} />
            <PanelShell
                title={title}
                groups={groups.map((group) => ({ footer: group.footer, links: group.sections.map((s) => ({ label: s.label, href: s.href, icon: s.icon, active: s.active })) }))}
                identity={
                    <>
                        {tenant?.logo_url ? (
                            <img src={tenant.logo_url} alt="" className="size-7 shrink-0 rounded-lg object-cover" />
                        ) : (
                            <div className="grid size-7 shrink-0 place-items-center rounded-lg bg-brand font-display text-[13px] text-white">{tenant?.name?.[0] ?? 'A'}</div>
                        )}
                        <p className="min-w-0 truncate font-display text-[15px] font-semibold text-[#111]">{tenant?.name}</p>
                    </>
                }
                topbar={(openMenu) => <Topbar onMenu={openMenu} />}
            >
                {children}
            </PanelShell>
        </>
    );
}
