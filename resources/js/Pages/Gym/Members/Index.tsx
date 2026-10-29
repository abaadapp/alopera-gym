import { Link, router } from '@inertiajs/react';
import { Download, UserPlus, UserRound, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, PageHeader, Pagination, SearchInput, Tabs } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { date } from '@/lib/format';
import { label, membershipStatus } from '@/lib/labels';
import type { Paginated } from '@/types';

export interface MemberRow {
    id: number;
    member_no: string;
    full_name: string;
    phone: string;
    phone_display: string;
    email: string | null;
    status: string;
    photo_url: string | null;
    joined_on: string | null;
    last_check_in_at: string | null;
    membership: { status: string; plan: string; ends_on: string; days_left: number } | null;
}

const filters = [
    { key: '', label: 'الكل' },
    { key: 'active', label: 'نشطة' },
    { key: 'expiring', label: 'قريبة من الانتهاء' },
    { key: 'pending', label: 'بانتظار الدفع' },
    { key: 'frozen', label: 'مجمّدة' },
    { key: 'expired', label: 'منتهية / بدون عضوية' },
] as const;

export default function MembersIndex({ members, filters: current }: { members: Paginated<MemberRow>; filters: { q?: string; membership?: string } }) {
    const can = useCan();

    return (
        <ClubLayout title="الأعضاء">
            <PageHeader
                title="الأعضاء"
                subtitle={`${members.total} عضو`}
                actions={
                    <>
                        {can('members.export') && (
                            <Button variant="outline" href={route('club.members.export', { format: 'xlsx' })} external icon={<Download className="size-4" />}>
                                تصدير Excel
                            </Button>
                        )}
                        {can('members.manage') && (
                            <Button href={route('club.members.create')} icon={<UserPlus className="size-4" />}>
                                عضو جديد
                            </Button>
                        )}
                    </>
                }
            />

            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <Tabs
                        tabs={filters.map((f) => ({ key: f.key as string, label: f.label }))}
                        value={current.membership ?? ''}
                        onChange={(key) => router.get(route('club.members.index'), { q: current.q || undefined, membership: key || undefined }, { preserveState: true })}
                        className="border-0"
                    />
                    <SearchInput value={current.q} placeholder="الاسم، الهاتف، رقم العضوية…" extra={{ membership: current.membership || undefined }} />
                </div>

                {members.data.length === 0 ? (
                    <EmptyState icon={<Users className="size-6" />} title="لا توجد نتائج" description="جرّب بحثًا آخر أو أضف عضوًا جديدًا." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>العضو</th><th>الهاتف</th><th>العضوية الحالية</th><th>تنتهي</th><th>آخر دخول</th></tr>
                            </thead>
                            <tbody>
                                {members.data.map((m) => {
                                    const [statusLabel, tone] = m.membership ? label(membershipStatus, m.membership.status) : ['بدون عضوية', 'gray' as const];
                                    return (
                                        <tr key={m.id} className="cursor-pointer" onClick={() => router.visit(route('club.members.show', m.id))}>
                                            <td>
                                                <div className="flex items-center gap-3">
                                                    {m.photo_url ? <img src={m.photo_url} alt="" className="size-9 rounded-full object-cover" /> : <span className="grid size-9 place-items-center rounded-full bg-brand-50 text-brand"><UserRound className="size-4" /></span>}
                                                    <div>
                                                        <Link href={route('club.members.show', m.id)} className="font-medium hover:text-brand">{m.full_name}</Link>
                                                        <p className="text-xs text-muted num">{m.member_no}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="num">{m.phone_display}</td>
                                            <td>
                                                <div className="flex items-center gap-2">
                                                    <Badge tone={tone}>{statusLabel}</Badge>
                                                    {m.membership && <span className="text-xs text-muted">{m.membership.plan}</span>}
                                                </div>
                                            </td>
                                            <td>{m.membership ? date(m.membership.ends_on) : '—'}</td>
                                            <td className="text-xs text-muted">{m.last_check_in_at ?? '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={members} />
            </Card>
        </ClubLayout>
    );
}
