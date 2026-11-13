import { Link, router } from '@inertiajs/react';
import { UserPlus, UserRound } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, DataTable, PageHeader, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { useCan } from '@/hooks/useShared';
import { date } from '@/lib/format';
import { label, memberStatus, membershipStatus } from '@/lib/labels';
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

/** The membership tabs, each with its badge's colour. */
const MEMBERSHIP_TABS = [
    { value: 'active', label: 'نشطة', dot: '#059669' },
    { value: 'expiring', label: 'قريبة من الانتهاء', dot: '#d97706' },
    { value: 'pending', label: 'بانتظار الدفع', dot: '#d97706' },
    { value: 'frozen', label: 'مجمّدة', dot: '#7c3aed' },
    { value: 'expired', label: 'منتهية / بدون عضوية', dot: '#9ca3af' },
];

/*
 * الأعضاء on the panel's one list table: the search, the membership tabs, the member's own status, the order and the page
 * are in the address and read by the server. A row opens the member's file.
 */
export default function MembersIndex({ members, filters }: { members: Paginated<MemberRow>; filters: Record<string, string | undefined> }) {
    const can = useCan();

    const Who = ({ m }: { m: MemberRow }) => (
        <div className="flex items-center gap-3">
            {m.photo_url ? <img src={m.photo_url} alt="" loading="lazy" decoding="async" className="size-9 rounded-full object-cover" /> : <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#f3f4f6] text-[#4b4b4b]"><UserRound className="size-4" /></span>}
            <div className="min-w-0">
                <Link href={route('club.members.show', m.id)} className="font-medium hover:underline">{m.full_name}</Link>
                <p className="text-xs text-muted num">{m.member_no}</p>
            </div>
        </div>
    );
    const Membership = ({ m }: { m: MemberRow }) => {
        const [text, tone] = m.membership ? label(membershipStatus, m.membership.status) : ['بدون عضوية', 'gray' as const];
        return (
            <div className="flex flex-wrap items-center gap-2">
                <Badge tone={tone}>{text}</Badge>
                {m.membership && <span className="text-xs text-muted">{m.membership.plan}</span>}
                {m.status !== 'active' && <Badge tone={label(memberStatus, m.status)[1]}>{label(memberStatus, m.status)[0]}</Badge>}
            </div>
        );
    };

    const columns: Column<MemberRow>[] = [
        { key: 'name', header: 'العضو', cell: (m) => <Who m={m} /> },
        { key: 'phone', header: 'الهاتف', cell: (m) => <span className="num" dir="ltr">{m.phone_display}</span> },
        { key: 'membership', header: 'العضوية الحالية', cell: (m) => <Membership m={m} /> },
        { key: 'ends_on', header: 'تنتهي', cell: (m) => (m.membership ? date(m.membership.ends_on) : '—') },
        { key: 'joined', header: 'الانضمام', cell: (m) => (m.joined_on ? date(m.joined_on) : '—') },
        { key: 'last_check_in', header: 'آخر دخول', cell: (m) => <span className="text-xs text-muted num">{m.last_check_in_at ?? '—'}</span> },
    ];

    const tableFilters: TableFilter[] = [
        { param: 'membership', label: 'العضوية', tabs: true, options: MEMBERSHIP_TABS },
        { param: 'status', label: 'حالة العضو', options: Object.entries(memberStatus).map(([value, [text]]) => ({ value, label: text })) },
        { param: 'per_page', label: 'عدد الصفوف', options: ['25', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="الأعضاء">
            <PageHeader
                title="الأعضاء"
                subtitle={`${members.total} عضو`}
                actions={
                    <>
                        <ExportMenu href={can('members.export') ? route('club.members.export') : null} title="الأعضاء" formats={['xlsx', 'csv']} />
                        {can('members.manage') && <Button href={route('club.members.create')} icon={<UserPlus className="size-4" />}>عضو جديد</Button>}
                    </>
                }
            />

            <DataTable
                rows={members.data}
                columns={columns}
                rowKey={(m) => m.id}
                params={filters}
                pagination={members as unknown as PageMeta}
                search={{ placeholder: 'الاسم أو الهاتف أو رقم العضوية' }}
                filters={tableFilters}
                sorts={[{ key: 'name', label: 'الاسم' }, { key: 'joined', label: 'تاريخ الانضمام' }, { key: 'ends_on', label: 'تاريخ انتهاء العضوية' }]}
                empty="لا أعضاء بعد"
                card={(m) => (
                    <button type="button" onClick={() => router.visit(route('club.members.show', m.id))} className="block w-full space-y-2 px-4 py-3 text-start">
                        <div className="flex items-center justify-between gap-3"><Who m={m} /><span className="text-xs text-muted num" dir="ltr">{m.phone_display}</span></div>
                        <div className="flex flex-wrap items-center justify-between gap-2 text-[13px]"><Membership m={m} />{m.membership && <span className="text-muted">تنتهي {date(m.membership.ends_on)}</span>}</div>
                    </button>
                )}
            />
        </ClubLayout>
    );
}
