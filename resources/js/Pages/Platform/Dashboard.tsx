import { Link } from '@inertiajs/react';
import { AlertCircle, Building2, CircleDollarSign, FileWarning, Hourglass, PauseCircle, Plus, Users } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Badge, Button, Card, CardHeader, EmptyState, PageHeader, StatCard } from '@/Components/ui';
import { money, number } from '@/lib/format';
import { label, tenantStatus } from '@/lib/labels';

interface Props {
    stats: { tenants: number; active: number; trial: number; past_due: number; suspended: number; mrr: number; unpaid_invoices: number; unpaid_amount: number; members: number };
    recent: { id: number; name: string; slug: string; status: string; plan: string | null; created_at: string }[];
}

export default function PlatformDashboard({ stats, recent }: Props) {
    return (
        <PlatformLayout title="الرئيسية">
            <PageHeader title="إدارة المنصة" subtitle="الأندية المشتركة وإيرادات اشتراكات المنصة" actions={<Button href={route('platform.tenants.create')} icon={<Plus className="size-4" />}>نادٍ جديد</Button>} />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="الأندية" value={number(stats.tenants)} icon={<Building2 className="size-5" />} hint={`نشطة ${number(stats.active)} · تجريبية ${number(stats.trial)}`} href={route('platform.tenants.index')} />
                <StatCard label="الإيراد الشهري المتكرر (MRR)" value={money(stats.mrr, 'OMR')} tone="green" icon={<CircleDollarSign className="size-5" />} hint="الاشتراكات السنوية موزعة على 12 شهرًا" />
                <StatCard label="فواتير غير مدفوعة" value={number(stats.unpaid_invoices)} tone="amber" icon={<FileWarning className="size-5" />} hint={money(stats.unpaid_amount, 'OMR')} href={route('platform.invoices.index', { status: 'unpaid' })} />
                <StatCard label="الأعضاء في كل الأندية" value={number(stats.members)} tone="violet" icon={<Users className="size-5" />} />
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <Link href={route('platform.tenants.index', { status: 'trial' })} className="card flex items-center gap-3 p-4 hover:border-brand-200"><Hourglass className="size-5 text-sky-600" /><span className="text-sm">فترة تجريبية: <b className="num">{number(stats.trial)}</b></span></Link>
                <Link href={route('platform.tenants.index', { status: 'past_due' })} className="card flex items-center gap-3 p-4 hover:border-brand-200"><AlertCircle className="size-5 text-amber-600" /><span className="text-sm">متأخرة السداد: <b className="num">{number(stats.past_due)}</b></span></Link>
                <Link href={route('platform.tenants.index', { status: 'suspended' })} className="card flex items-center gap-3 p-4 hover:border-brand-200"><PauseCircle className="size-5 text-red-600" /><span className="text-sm">موقوفة: <b className="num">{number(stats.suspended)}</b></span></Link>
            </div>

            <Card padded={false} className="mt-6">
                <div className="p-5 pb-0"><CardHeader title="أحدث الأندية" /></div>
                {recent.length === 0 ? (
                    <EmptyState title="لا توجد أندية بعد" action={<Button size="sm" href={route('platform.tenants.create')}>إنشاء أول نادٍ</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>النادي</th><th>المعرّف</th><th>الباقة</th><th>الحالة</th><th>تاريخ الإنشاء</th></tr></thead>
                            <tbody>
                                {recent.map((t) => {
                                    const [l, tone] = label(tenantStatus, t.status);
                                    return (
                                        <tr key={t.id}>
                                            <td><Link href={route('platform.tenants.show', t.id)} className="font-medium hover:text-brand">{t.name}</Link></td>
                                            <td className="text-xs" dir="ltr">{t.slug}</td>
                                            <td>{t.plan ?? '—'}</td>
                                            <td><Badge tone={tone}>{l}</Badge></td>
                                            <td className="text-xs num">{t.created_at}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </PlatformLayout>
    );
}
