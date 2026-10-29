import type { ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Clock, LogOut } from 'lucide-react';
import PanelShell from '@/Components/PanelShell';
import { useShared } from '@/hooks/useShared';
import { useNavigation } from '@/lib/nav';

export default function ClubLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, tenant } = useShared();
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
                            <img src={tenant.logo_url} alt="" className="size-8 shrink-0 rounded-lg object-cover" />
                        ) : (
                            <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-brand font-display text-sm text-white">{tenant?.name?.[0] ?? 'A'}</div>
                        )}
                        <div className="min-w-0 leading-tight">
                            <p className="truncate font-display text-sm font-semibold text-ink">{tenant?.name}</p>
                            <p className="truncate text-[11px] text-muted">{auth?.user.role}</p>
                        </div>
                    </>
                }
                actions={
                    <>
                        {tenant?.status === 'trial' && <span className="hidden rounded-full bg-sky-50 px-3 py-1 text-xs text-sky-700 sm:inline">فترة تجريبية حتى {tenant.trial_ends_at}</span>}
                        <button
                            onClick={() => router.post(route('club.staff-attendance.punch'), {}, { preserveScroll: true })}
                            className="hidden items-center gap-1.5 rounded-[10px] border border-[#dcdcdc] bg-white px-3 py-1.5 text-xs text-ink hover:bg-[#fafafa] sm:inline-flex"
                            title="تسجيل حضور/انصراف"
                        >
                            <Clock className="size-4" /> حضور/انصراف
                        </button>
                        <span className="hidden text-sm text-ink md:inline">{auth?.user.name}</span>
                        <button onClick={() => router.post(route('logout'))} className="grid size-10 place-items-center rounded-[10px] text-muted hover:bg-ink/[0.045] hover:text-ink" title="تسجيل الخروج">
                            <LogOut className="size-[18px]" />
                        </button>
                    </>
                }
            >
                {children}
            </PanelShell>
        </>
    );
}
