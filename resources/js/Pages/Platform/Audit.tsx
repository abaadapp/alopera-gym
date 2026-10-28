import { Fragment, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, ScrollText } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Button, Card, EmptyState, Input, PageHeader, Pagination } from '@/Components/ui';
import type { Paginated } from '@/types';

interface Log {
    id: number;
    tenant_id: number | null;
    actor_type: string | null;
    actor_name: string | null;
    event: string;
    subject_type: string | null;
    subject_id: number | null;
    ip_address: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    properties: Record<string, unknown> | null;
    created_at: string;
}

export default function PlatformAudit({ logs, filters }: { logs: Paginated<Log>; filters: { tenant_id?: string | number; event?: string } }) {
    const [f, setF] = useState({ tenant_id: filters.tenant_id ? String(filters.tenant_id) : '', event: filters.event ?? '' });
    const [open, setOpen] = useState<number | null>(null);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('platform.audit'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
    };

    return (
        <PlatformLayout title="سجل التدقيق">
            <PageHeader title="سجل التدقيق — كل الأندية" subtitle="سجل غير قابل للتعديل لكل العمليات الحساسة على المنصة" />
            <Card padded={false}>
                <form onSubmit={submit} className="flex flex-wrap items-end gap-2 p-4">
                    <Input className="w-36" type="number" value={f.tenant_id} onChange={(e) => setF({ ...f, tenant_id: e.target.value })} placeholder="رقم النادي" />
                    <Input className="w-56" dir="ltr" value={f.event} onChange={(e) => setF({ ...f, event: e.target.value })} placeholder="event prefix (platform.)" />
                    <Button type="submit" variant="secondary">تصفية</Button>
                </form>
                {logs.data.length === 0 ? (
                    <EmptyState icon={<ScrollText className="size-6" />} title="لا توجد سجلات" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>النادي</th><th>المنفّذ</th><th>الحدث</th><th>السجل</th><th>IP</th><th /></tr></thead>
                            <tbody>
                                {logs.data.map((l) => (
                                    <Fragment key={l.id}>
                                        <tr className="cursor-pointer" onClick={() => setOpen(open === l.id ? null : l.id)}>
                                            <td className="whitespace-nowrap text-xs text-muted num">{l.created_at}</td>
                                            <td className="num">{l.tenant_id ?? 'المنصة'}</td>
                                            <td className="text-sm">{l.actor_name ?? l.actor_type ?? '—'}</td>
                                            <td><Badge><span dir="ltr">{l.event}</span></Badge></td>
                                            <td className="text-xs" dir="ltr">{l.subject_type ? `${l.subject_type} #${l.subject_id}` : '—'}</td>
                                            <td className="text-xs text-muted num">{l.ip_address ?? '—'}</td>
                                            <td><ChevronDown className={`size-4 text-muted transition ${open === l.id ? 'rotate-180' : ''}`} /></td>
                                        </tr>
                                        {open === l.id && (
                                            <tr>
                                                <td colSpan={7} className="bg-canvas">
                                                    <pre className="overflow-x-auto text-[11px] leading-5" dir="ltr">{JSON.stringify({ old: l.old_values, new: l.new_values, properties: l.properties }, null, 2)}</pre>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={logs} />
            </Card>
        </PlatformLayout>
    );
}
