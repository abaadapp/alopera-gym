import { router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { NotificationRow } from '@/Components/Topbar';
import { Button, Card, EmptyState, PageHeader, Pagination, Tabs } from '@/Components/ui';
import type { Paginated, StaffNotification } from '@/types';

interface Props {
    list: Paginated<StaffNotification>;
    unread: number;
    kinds: Record<string, string>;
    filters: { view: 'all' | 'unread'; kind: string | null };
}

/** Every notification of the signed-in staff member: all or unread only, by kind; open one, or mark all read. */
export default function Notifications({ list, unread, kinds, filters }: Props) {
    const apply = (patch: Partial<Props['filters']>) => router.get(route('club.notifications.index'), { ...filters, ...patch }, { preserveScroll: true, preserveState: true, replace: true });

    return (
        <ClubLayout title="الإشعارات">
            <PageHeader
                title="الإشعارات"
                subtitle="ما يحتاج انتباهك في النادي: المستندات، طلبات الموقع، المدفوعات، العضويات، والتطبيقات التكاملية."
                actions={unread > 0 && (
                    <Button variant="outline" icon={<CheckCheck className="size-4" />} onClick={() => router.post(route('club.notifications.read-all'), {}, { preserveScroll: true })}>
                        تعليم الكل كمقروء ({unread})
                    </Button>
                )}
            />

            <Card padded={false}>
                <div className="px-5 pt-3">
                    <Tabs
                        value={filters.view}
                        onChange={(view) => apply({ view })}
                        tabs={[{ key: 'all', label: 'الكل' }, { key: 'unread', label: 'غير المقروءة', count: unread }]}
                    />
                    {Object.keys(kinds).length > 1 && (
                        <div className="flex flex-wrap gap-1.5 py-3">
                            {[['', 'كل الأنواع'], ...Object.entries(kinds)].map(([key, label]) => (
                                <button
                                    key={key || 'all'}
                                    type="button"
                                    onClick={() => apply({ kind: key || null })}
                                    className={`rounded-full px-3 py-1 text-[12px] font-medium transition-colors ${(filters.kind ?? '') === key ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#4b4b4b] hover:bg-[#e8e8e6]'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
                {list.data.length === 0 ? (
                    <EmptyState icon={<Bell />} title={filters.view === 'unread' ? 'لا توجد إشعارات غير مقروءة' : 'لا توجد إشعارات'} description="تصل هنا تنبيهات ما يخص صلاحياتك فقط." />
                ) : (
                    <div className="divide-y divide-[#f2f2f0] px-3 pb-2">
                        {list.data.map((n) => <NotificationRow key={n.id} n={n} />)}
                    </div>
                )}
            </Card>
            <Pagination data={list} />
        </ClubLayout>
    );
}
