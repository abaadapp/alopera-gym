import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { MessageSquareReply, Pencil, Plus, Settings2, Trash2 } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, CardHeader, ConfirmAction, EmptyState, Field, Input, Modal, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { number } from '@/lib/format';

interface AutoReply {
    id: number;
    keyword: string;
    match_type: string;
    reply: string;
    is_active: boolean;
    hits: number;
}

interface Props {
    autoReplies: AutoReply[];
    rules: { expiry_reminder_days: number[]; expired_followup_days: number[]; notify_class_changes: boolean };
}

export default function Automation({ autoReplies, rules }: Props) {
    const can = useCan();
    const [reply, setReply] = useState<AutoReply | 'new' | null>(null);

    return (
        <ClubLayout title="الأتمتة">
            <PageHeader title="أتمتة واتساب" subtitle="ما يُرسل تلقائيًا ومتى، والردود الآلية على الرسائل الواردة" />

            <div className="mb-6 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader
                        title="قواعد التشغيل"
                        actions={can('settings.manage') ? <Button variant="outline" size="sm" href={route('club.settings.notifications')} icon={<Settings2 className="size-3.5" />}>تعديل</Button> : undefined}
                    />
                    <dl className="space-y-2.5 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted">تذكير قبل الانتهاء</dt>
                            <dd className="num">{rules.expiry_reminder_days?.length ? rules.expiry_reminder_days.join('، ') + ' يوم' : 'متوقف'}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted">متابعة بعد الانتهاء</dt>
                            <dd className="num">{rules.expired_followup_days?.length ? rules.expired_followup_days.join('، ') + ' يوم' : 'متوقفة'}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted">إبلاغ عند تعديل الحصة</dt>
                            <dd>{rules.notify_class_changes ? <Badge tone="green">مفعّل</Badge> : <Badge>متوقف</Badge>}</dd>
                        </div>
                    </dl>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title="رسائل الخدمة التلقائية" />
                    <p className="text-sm leading-7 text-ink/80">
                        يرسل النظام رسائل الترحيب والفواتير والتذكيرات والحجوزات تلقائيًا عند حدوثها، بشرط أن يكون قالبها <b>معتمدًا</b> و<b>مفعّلًا</b> في
                        «القوالب»، وأن يكون العضو قد وافق على استقبال الرسائل.
                    </p>
                    {can('templates.manage') && <Button className="mt-4" variant="outline" size="sm" href={route('club.templates.index')}>إدارة القوالب</Button>}
                </Card>
            </div>

            <Card padded={false}>
                <div className="p-5 pb-0">
                    <CardHeader
                        title="الردود الآلية"
                        subtitle="ردّ تلقائي على الرسائل الواردة حسب كلمة مفتاحية. كلمات «إيقاف» و«stop» توقف الرسائل التسويقية تلقائيًا."
                        actions={<Button size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => setReply('new')}>رد جديد</Button>}
                    />
                </div>
                {autoReplies.length === 0 ? (
                    <EmptyState icon={<MessageSquareReply className="size-6" />} title="لا توجد ردود آلية" description="مثال: كلمة «الأسعار» ← رابط صفحة الباقات." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الكلمة</th><th>المطابقة</th><th>الرد</th><th>الاستخدام</th><th>الحالة</th><th /></tr>
                            </thead>
                            <tbody>
                                {autoReplies.map((r) => (
                                    <tr key={r.id}>
                                        <td className="font-medium">{r.keyword}</td>
                                        <td className="text-xs">{r.match_type === 'exact' ? 'مطابقة تامة' : 'تحتوي'}</td>
                                        <td className="max-w-md"><p className="line-clamp-2 text-xs text-ink/80">{r.reply}</p></td>
                                        <td className="num">{number(r.hits)}</td>
                                        <td>{r.is_active ? <Badge tone="green">مفعّل</Badge> : <Badge>متوقف</Badge>}</td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setReply(r)}>تعديل</Button>
                                                <ConfirmAction url={route('club.auto-replies.destroy', r.id)} method="delete" title="حذف الرد الآلي" message={`حذف الرد على «${r.keyword}»؟`} variant="ghost" icon={<Trash2 className="size-3.5" />}>
                                                    حذف
                                                </ConfirmAction>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {reply && <AutoReplyModal reply={reply === 'new' ? null : reply} onClose={() => setReply(null)} />}
        </ClubLayout>
    );
}

function AutoReplyModal({ reply, onClose }: { reply: AutoReply | null; onClose: () => void }) {
    const form = useForm({
        keyword: reply?.keyword ?? '',
        match_type: reply?.match_type ?? 'contains',
        reply: reply?.reply ?? '',
        is_active: reply?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (reply) form.put(route('club.auto-replies.update', reply.id), options);
        else form.post(route('club.auto-replies.store'), options);
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={reply ? 'تعديل الرد الآلي' : 'رد آلي جديد'}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>إلغاء</Button>
                    <Button onClick={submit} loading={form.processing}>حفظ</Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="الكلمة المفتاحية" error={form.errors.keyword} required>
                        <Input value={form.data.keyword} onChange={(e) => form.setData('keyword', e.target.value)} placeholder="مثال: الأسعار" />
                    </Field>
                    <Field label="طريقة المطابقة" error={form.errors.match_type}>
                        <Select value={form.data.match_type} onChange={(e) => form.setData('match_type', e.target.value)}>
                            <option value="contains">الرسالة تحتوي الكلمة</option>
                            <option value="exact">الرسالة هي الكلمة فقط</option>
                        </Select>
                    </Field>
                </div>
                <Field label="نص الرد" error={form.errors.reply} hint="يُرسل داخل نافذة الـ24 ساعة فقط (رد على رسالة واردة)" required>
                    <Textarea rows={4} value={form.data.reply} onChange={(e) => form.setData('reply', e.target.value)} />
                </Field>
                <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="مفعّل" />
            </form>
        </Modal>
    );
}
