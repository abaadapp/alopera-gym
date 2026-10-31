import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Database, Download, RotateCcw, Upload } from 'lucide-react';
import { SettingsScreen, SettingsTitle } from '@/Components/Settings';
import { Button, Card, CardHeader, EmptyState, Field, Input } from '@/Components/ui';
import { useShared } from '@/hooks/useShared';
import { number } from '@/lib/format';

interface Backup {
    name: string;
    size: number;
    created_at: string;
}

function size(bytes: number) {
    if (bytes > 1024 * 1024) return `${number(bytes / 1024 / 1024, 1)} MB`;
    return `${number(bytes / 1024, 0)} KB`;
}

export default function Backups({ backups }: { backups: Backup[] }) {
    const { tenant } = useShared();
    const [creating, setCreating] = useState(false);
    const form = useForm<{ archive: File | null; confirm: string }>({ archive: null, confirm: '' });

    const create = () => {
        setCreating(true);
        router.post(route('club.backups.store'), {}, { preserveScroll: true, onFinish: () => setCreating(false) });
    };

    const restore = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.backups.restore'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <SettingsScreen title="النسخ الاحتياطي" width="full">
            <SettingsTitle title="النسخ الاحتياطي والاستعادة" description="نسخة تلقائية يومية الساعة 3 فجرًا (آخر 14 نسخة)، ويمكنك إنشاء نسخة يدويًا في أي وقت" actions={<Button onClick={create} loading={creating} icon={<Database className="size-4" />}>إنشاء نسخة الآن</Button>} />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card padded={false} className="lg:col-span-2">
                    <div className="p-5 pb-0">
                        <CardHeader title="النسخ المحفوظة" subtitle="تتضمن كل بيانات النادي والملفات المرفوعة" />
                    </div>
                    {backups.length === 0 ? (
                        <EmptyState icon={<Database className="size-6" />} title="لا توجد نسخ بعد" action={<Button size="sm" onClick={create} loading={creating}>إنشاء أول نسخة</Button>} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table-base">
                                <thead><tr><th>الملف</th><th>التاريخ</th><th>الحجم</th><th /></tr></thead>
                                <tbody>
                                    {backups.map((b) => (
                                        <tr key={b.name}>
                                            <td className="text-xs" dir="ltr">{b.name}</td>
                                            <td className="text-sm num">{b.created_at}</td>
                                            <td className="text-xs text-muted num">{size(b.size)}</td>
                                            <td className="text-end">
                                                <Button size="sm" variant="outline" href={route('club.backups.download', b.name)} external icon={<Download className="size-3.5" />}>تنزيل</Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card>
                    <CardHeader title={<span className="flex items-center gap-2"><RotateCcw className="size-4" /> استعادة نسخة</span>} />
                    <div className="mb-4 flex items-start gap-2 rounded-xl bg-red-50 p-3 text-xs leading-6 text-red-800">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <p>الاستعادة تستبدل كل بيانات النادي الحالية ببيانات النسخة. تُنشأ نسخة من الوضع الحالي تلقائيًا قبل الاستعادة. لا تُقبل نسخة تخص ناديًا آخر.</p>
                    </div>
                    <form onSubmit={restore} className="space-y-4">
                        <Field label="ملف النسخة (.zip)" error={form.errors.archive}>
                            <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-line px-3 py-3 text-sm hover:bg-brand-50">
                                <Upload className="size-4 text-muted" />
                                <span className="truncate">{form.data.archive?.name ?? 'اختيار ملف'}</span>
                                <input type="file" accept=".zip,application/zip" className="hidden" onChange={(e) => form.setData('archive', e.target.files?.[0] ?? null)} />
                            </label>
                        </Field>
                        <Field label={<>للتأكيد اكتب معرّف النادي: <b dir="ltr">{tenant?.slug}</b></>} error={form.errors.confirm}>
                            <Input dir="ltr" value={form.data.confirm} onChange={(e) => form.setData('confirm', e.target.value)} autoComplete="off" />
                        </Field>
                        <Button type="submit" variant="danger" className="w-full" loading={form.processing} disabled={!form.data.archive || form.data.confirm !== tenant?.slug}>
                            استعادة
                        </Button>
                    </form>
                </Card>
            </div>
        </SettingsScreen>
    );
}
