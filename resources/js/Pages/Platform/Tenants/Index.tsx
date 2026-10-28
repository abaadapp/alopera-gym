import { Link, router } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Button, Card, EmptyState, PageHeader, Pagination, SearchInput, Select } from '@/Components/ui';
import { date } from '@/lib/format';
import { label, tenantStatus } from '@/lib/labels';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    name: string;
    slug: string;
    status: string;
    city: string | null;
    domain: string | null;
    plan: string | null;
    ends_on: string | null;
    created_at: string;
}

export default function TenantsIndex({ tenants, filters }: { tenants: Paginated<Row>; filters: { q?: string; status?: string } }) {
    return (
        <PlatformLayout title="الأندية">
            <PageHeader title="الأندية" subtitle={`${tenants.total} نادٍ على المنصة`} actions={<Button href={route('platform.tenants.create')} icon={<Plus className="size-4" />}>نادٍ جديد</Button>} />
            <Card padded={false}>
                <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                    <Select className="w-48" value={filters.status ?? ''} onChange={(e) => router.get(route('platform.tenants.index'), { q: filters.q || undefined, status: e.target.value || undefined }, { preserveState: true })} placeholder="كل الحالات">
                        {Object.entries(tenantStatus).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}
                    </Select>
                    <SearchInput value={filters.q} placeholder="الاسم أو المعرّف…" extra={{ status: filters.status || undefined }} />
                </div>
                {tenants.data.length === 0 ? (
                    <EmptyState icon={<Building2 className="size-6" />} title="لا توجد نتائج" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>النادي</th><th>المدينة</th><th>النطاق</th><th>الباقة</th><th>ينتهي الاشتراك</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {tenants.data.map((t) => {
                                    const [l, tone] = label(tenantStatus, t.status);
                                    return (
                                        <tr key={t.id} className="cursor-pointer" onClick={() => router.visit(route('platform.tenants.show', t.id))}>
                                            <td>
                                                <Link href={route('platform.tenants.show', t.id)} className="font-medium hover:text-brand">{t.name}</Link>
                                                <p className="text-xs text-muted" dir="ltr">{t.slug}</p>
                                            </td>
                                            <td>{t.city ?? '—'}</td>
                                            <td className="text-xs" dir="ltr">{t.domain ?? '—'}</td>
                                            <td>{t.plan ?? '—'}</td>
                                            <td>{date(t.ends_on)}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={tenants} />
            </Card>
        </PlatformLayout>
    );
}
