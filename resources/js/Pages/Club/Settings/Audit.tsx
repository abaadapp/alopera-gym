import { Fragment, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, ScrollText } from 'lucide-react';
import { SettingsScreen, SettingsTitle } from '@/Components/Settings';
import { Badge, Button, Card, EmptyState, Input, Pagination } from '@/Components/ui';
import type { Paginated } from '@/types';

interface Log {
    id: number;
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

const ACTORS: Record<string, string> = { user: 'موظف', member: 'عضو', system: 'النظام', gateway: 'بوابة الدفع', device: 'جهاز' };

const QUICK = [
    { key: '', label: 'الكل' },
    { key: 'auth.', label: 'الدخول' },
    { key: 'membership.', label: 'العضويات' },
    { key: 'payment', label: 'الدفعات' },
    { key: 'invoice', label: 'الفواتير' },
    { key: 'user.', label: 'المستخدمون' },
    { key: 'role.', label: 'الأدوار' },
    { key: 'backup.', label: 'النسخ الاحتياطي' },
];

export default function Audit({ logs, filters }: { logs: Paginated<Log>; filters: { event?: string; actor?: string; from?: string; to?: string } }) {
    const [f, setF] = useState({ event: filters.event ?? '', actor: filters.actor ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    const [open, setOpen] = useState<number | null>(null);

    const apply = (next = f) => router.get(route('club.audit.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, replace: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        apply();
    };

    return (
        <SettingsScreen title="سجل العمليات" width="full">
            <SettingsTitle title="سجل العمليات الحساسة" description="من فعل ماذا ومتى — سجل غير قابل للتعديل أو الحذف" />

            <Card padded={false}>
                <div className="space-y-3 p-4">
                    <div className="flex flex-wrap gap-1.5">
                        {QUICK.map((q) => (
                            <button
                                key={q.key}
                                onClick={() => {
                                    const next = { ...f, event: q.key };
                                    setF(next);
                                    apply(next);
                                }}
                                className={`rounded-full border px-3 py-1 text-xs ${f.event === q.key ? 'border-brand bg-brand text-white' : 'border-line hover:border-brand-300'}`}
                            >
                                {q.label}
                            </button>
                        ))}
                    </div>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                        <Input className="w-44" value={f.event} onChange={(e) => setF({ ...f, event: e.target.value })} placeholder="الحدث (مثل payment.)" dir="ltr" />
                        <Input className="w-44" value={f.actor} onChange={(e) => setF({ ...f, actor: e.target.value })} placeholder="اسم المنفّذ" />
                        <Input className="w-40" type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                        <Input className="w-40" type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                        <Button type="submit" variant="secondary">تصفية</Button>
                    </form>
                </div>

                {logs.data.length === 0 ? (
                    <EmptyState icon={<ScrollText className="size-6" />} title="لا توجد سجلات" />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead><tr><th>الوقت</th><th>المنفّذ</th><th>الحدث</th><th>السجل</th><th>IP</th><th /></tr></thead>
                            <tbody>
                                {logs.data.map((l) => (
                                    <Fragment key={l.id}>
                                        <tr className="cursor-pointer" onClick={() => setOpen(open === l.id ? null : l.id)}>
                                            <td className="whitespace-nowrap text-xs text-muted num">{l.created_at}</td>
                                            <td>
                                                <span className="text-sm">{l.actor_name ?? '—'}</span>
                                                <span className="block text-[11px] text-muted">{ACTORS[l.actor_type ?? ''] ?? l.actor_type}</span>
                                            </td>
                                            <td><Badge tone={l.event.includes('deleted') || l.event.includes('refused') || l.event.includes('rejected') || l.event.includes('failed') ? 'red' : 'gray'}><span dir="ltr">{l.event}</span></Badge></td>
                                            <td className="text-xs" dir="ltr">{l.subject_type ? `${l.subject_type} #${l.subject_id}` : '—'}</td>
                                            <td className="text-xs text-muted num">{l.ip_address ?? '—'}</td>
                                            <td><ChevronDown className={`size-4 text-muted transition ${open === l.id ? 'rotate-180' : ''}`} /></td>
                                        </tr>
                                        {open === l.id && (
                                            <tr>
                                                <td colSpan={6} className="bg-canvas">
                                                    <Diff log={l} />
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
        </SettingsScreen>
    );
}

function show(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
}

function Diff({ log }: { log: Log }) {
    const keys = Array.from(new Set([...Object.keys(log.old_values ?? {}), ...Object.keys(log.new_values ?? {})]));
    return (
        <div className="space-y-3 py-2">
            {keys.length > 0 && (
                <table className="w-full text-xs" dir="ltr">
                    <thead><tr className="text-muted"><th className="py-1 text-left">field</th><th className="py-1 text-left">before</th><th className="py-1 text-left">after</th></tr></thead>
                    <tbody>
                        {keys.map((k) => (
                            <tr key={k} className="border-t border-line/60">
                                <td className="py-1 pe-3 font-medium">{k}</td>
                                <td className="max-w-xs break-all py-1 pe-3 text-red-700">{show(log.old_values?.[k])}</td>
                                <td className="max-w-xs break-all py-1 text-emerald-700">{show(log.new_values?.[k])}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {log.properties && <pre className="overflow-x-auto rounded-lg bg-white p-2 text-[11px]" dir="ltr">{JSON.stringify(log.properties, null, 2)}</pre>}
            {keys.length === 0 && !log.properties && <p className="text-xs text-muted">لا توجد تفاصيل إضافية.</p>}
        </div>
    );
}
