import { type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, KeyRound, MonitorSmartphone } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, PageHeader, Select, BackLink } from '@/Components/ui';

/**
 * On the device: which point-of-sale device this browser is, pairing it with a one-time code from Settings, or —
 * for whoever manages devices — activating it directly for a branch.
 */
export default function PosDevice({ device, canActivate, branches }: { device: { name: string; branch: string | null; activated_at: string | null } | null; canActivate: boolean; branches: { id: number; name: string }[] }) {
    const pair = useForm({ code: '' });
    const activate = useForm({ name: '', branch_id: String(branches[0]?.id ?? ''), settlement_account_id: '' });
    const submitPair = (e: FormEvent) => { e.preventDefault(); pair.post(route('club.pos.device.pair')); };
    const submitActivate = (e: FormEvent) => { e.preventDefault(); activate.transform((d) => ({ ...d, settlement_account_id: null })); activate.post(route('club.pos.device.activate')); };

    return (
        <ClubLayout title="جهاز نقطة البيع">
            <div className="mx-auto max-w-2xl">
                <PageHeader back={<BackLink href={route('club.pos.index')}>نقطة البيع</BackLink>} title="جهاز نقطة البيع" subtitle="يُربط هذا المتصفح بنقطة بيع لفرع، فتُسجَّل عليها المبيعات ويُخصم مخزون فرعها" />
                {device ? (
                    <Card className="mb-4">
                        <div className="flex items-center gap-4">
                            <span className="grid size-12 place-items-center rounded-[12px] bg-emerald-50 text-emerald-700"><CheckCircle2 className="size-6" /></span>
                            <div>
                                <p className="font-semibold">هذا المتصفح هو «{device.name}»</p>
                                <p className="text-[13px] text-muted">{device.branch} · فُعّل في <span className="num">{device.activated_at}</span></p>
                            </div>
                        </div>
                    </Card>
                ) : (
                    <Card className="mb-4">
                        <CardHeader title="ربط برمز" subtitle="اطلب رمز ربط من «الإعدادات ← أجهزة نقاط البيع» وأدخله هنا. الرمز صالح لمرة واحدة ولمدة 30 دقيقة." />
                        <form onSubmit={submitPair} className="flex flex-wrap items-end gap-3">
                            <Field label="رمز الربط" error={pair.errors.code} className="min-w-48 flex-1"><Input dir="ltr" value={pair.data.code} onChange={(e) => pair.setData('code', e.target.value.toUpperCase())} placeholder="ABCD-2345" maxLength={20} autoFocus className="text-lg tracking-[0.2em]" /></Field>
                            <Button type="submit" icon={<KeyRound />} loading={pair.processing}>ربط هذا الجهاز</Button>
                        </form>
                    </Card>
                )}
                {canActivate && (
                    <Card>
                        <CardHeader title={device ? 'إعادة التفعيل' : 'تفعيل مباشر'} subtitle="لمن يدير الأجهزة: يُفعَّل هذا المتصفح الآن لفرع، دون رمز." />
                        <form onSubmit={submitActivate} className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                            <Field label="اسم الجهاز" error={activate.errors.name} required><Input value={activate.data.name} onChange={(e) => activate.setData('name', e.target.value)} maxLength={80} placeholder={device?.name ?? 'كاشير الاستقبال'} /></Field>
                            <Field label="الفرع" error={activate.errors.branch_id} required><Select value={activate.data.branch_id} onChange={(e) => activate.setData('branch_id', e.target.value)}>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</Select></Field>
                            <Button type="submit" variant="outline" icon={<MonitorSmartphone />} loading={activate.processing}>تفعيل</Button>
                        </form>
                    </Card>
                )}
            </div>
        </ClubLayout>
    );
}
