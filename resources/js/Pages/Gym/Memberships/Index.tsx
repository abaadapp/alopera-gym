import { Link } from '@inertiajs/react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, DataTable, PageHeader, type Column, type PageMeta, type TableFilter } from '@/Components/ui';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { useCan, useCurrency } from '@/hooks/useShared';
import { date, money, dayCount } from '@/lib/format';
import { label, membershipKind, membershipStatus } from '@/lib/labels';
import type { Option, Paginated } from '@/types';

interface Row {
    id: number;
    plan: string;
    kind: string;
    status: string;
    starts_on: string;
    ends_on: string;
    days_left: number;
    sessions_total: number | null;
    sessions_used: number;
    sessions_left: number | null;
    total: string;
    invoice_id: number | null;
    member: { id: number; full_name: string; member_no: string; phone: string } | null;
}

/** The status tabs, each with its badge's colour. */
const FILTERS = [
    { value: 'active', label: 'نشطة', dot: '#059669' },
    { value: 'expiring', label: 'تنتهي خلال 7 أيام', dot: '#d97706' },
    { value: 'pending', label: 'بانتظار الدفع', dot: '#d97706' },
    { value: 'scheduled', label: 'مجدولة', dot: '#2563eb' },
    { value: 'frozen', label: 'مجمّدة', dot: '#7c3aed' },
    { value: 'expired', label: 'منتهية', dot: '#9ca3af' },
    { value: 'cancelled', label: 'ملغاة', dot: '#dc2626' },
];

/*
 * العضويات on the panel's one list table: the status tabs (with their counts), the member search, the plan and kind, the
 * order and the page are in the address and read by the server; the files take the same filters.
 */
export default function MembershipsIndex({ memberships, counts, plans, filters }: { memberships: Paginated<Row>; counts: Record<string, number>; plans: Option[]; filters: Record<string, string | undefined> }) {
    const can = useCan();
    const currency = useCurrency();
    const exportParams = Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) as Record<string, string>;

    const Member = ({ m }: { m: Row }) => (m.member ? (
        <Link href={route('club.members.show', m.member.id)} className="font-medium hover:underline">
            {m.member.full_name}
            <span className="block text-xs font-normal text-muted num">{m.member.member_no}</span>
        </Link>
    ) : <>—</>);
    const Left = ({ m }: { m: Row }) => (['active', 'frozen'].includes(m.status)
        ? <Badge tone={m.days_left <= 3 ? 'red' : m.days_left <= 7 ? 'amber' : 'gray'}><span className="num">{dayCount(m.days_left)}</span></Badge>
        : <>—</>);
    const Status = ({ m }: { m: Row }) => {
        const [text, tone] = label(membershipStatus, m.status);
        return <Badge tone={tone}>{text}</Badge>;
    };
    const Collect = ({ m }: { m: Row }) => (m.status === 'pending' && m.invoice_id && can('invoices.view')
        ? <Link href={route('club.invoices.show', m.invoice_id)} className="text-sm font-medium text-[#1d4ed8] hover:underline">تحصيل</Link>
        : null);

    const columns: Column<Row>[] = [
        { key: 'member', header: 'العضو', cell: (m) => <Member m={m} /> },
        { key: 'plan', header: 'الباقة', cell: (m) => m.plan },
        { key: 'kind', header: 'النوع', cell: (m) => <span className="text-xs text-muted">{membershipKind[m.kind] ?? m.kind}</span> },
        { key: 'ends_on', header: 'الفترة', cell: (m) => <span className="whitespace-nowrap text-xs">{date(m.starts_on)} ← {date(m.ends_on)}</span> },
        { key: 'left', header: 'المتبقي', cell: (m) => <Left m={m} /> },
        { key: 'sessions', header: 'الحصص', cell: (m) => <span className="num text-xs">{m.sessions_total !== null ? `${m.sessions_used}/${m.sessions_total}` : 'غير محدود'}</span> },
        { key: 'total', header: 'الإجمالي', align: 'end', cell: (m) => <span className="num">{money(m.total, currency)}</span> },
        { key: 'status', header: 'الحالة', cell: (m) => <Status m={m} /> },
        { key: 'actions', header: '', align: 'end', cell: (m) => <Collect m={m} /> },
    ];

    const tableFilters: TableFilter[] = [
        { param: 'filter', label: 'الحالة', tabs: true, options: FILTERS.map((f) => ({ ...f, label: counts[f.value] ? `${f.label} (${counts[f.value]})` : f.label })) },
        { param: 'plan_id', label: 'الباقة', options: plans.map((p) => ({ value: String(p.id), label: p.name })) },
        { param: 'kind', label: 'النوع', options: Object.entries(membershipKind).map(([value, text]) => ({ value, label: text })) },
        { param: 'per_page', label: 'عدد الصفوف', options: ['25', '50', '100'].map((n) => ({ value: n, label: n })) },
    ];

    return (
        <ClubLayout title="العضويات">
            <PageHeader
                title="العضويات"
                subtitle="متابعة الاشتراكات حسب الحالة: النشطة، القريبة من الانتهاء، المنتظرة للدفع، المجمّدة والمنتهية"
                actions={<ExportMenu href={can('members.export') ? route('club.memberships.index') : null} params={exportParams} title="العضويات" formats={['xlsx', 'csv']} />}
            />

            <DataTable
                rows={memberships.data}
                columns={columns}
                rowKey={(m) => m.id}
                params={filters}
                pagination={memberships as unknown as PageMeta}
                search={{ placeholder: 'اسم العضو أو رقمه أو هاتفه' }}
                filters={tableFilters}
                sorts={[{ key: 'ends_on', label: 'تاريخ الانتهاء' }, { key: 'starts_on', label: 'تاريخ البدء' }, { key: 'total', label: 'الإجمالي' }]}
                empty="لا توجد عضويات"
                card={(m) => (
                    <div className="space-y-1.5 px-4 py-3 text-[13px]">
                        <div className="flex items-start justify-between gap-3"><Member m={m} /><Status m={m} /></div>
                        <p className="text-muted">{m.plan} · {date(m.starts_on)} ← {date(m.ends_on)}</p>
                        <div className="flex items-center justify-between gap-2"><Left m={m} /><span className="num">{money(m.total, currency)}</span><Collect m={m} /></div>
                    </div>
                )}
            />
        </ClubLayout>
    );
}
