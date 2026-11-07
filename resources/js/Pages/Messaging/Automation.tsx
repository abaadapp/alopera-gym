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

interface AutoEvent {
    key: string;
    label: string;
    detail: string;
    on: boolean;
    timing: 'expiry_reminder_days' | 'expired_followup_days' | null;
    templates: { key: string; label: string; category: string; approved: boolean }[];
}

interface Props {
    autoReplies: AutoReply[];
    events: AutoEvent[];
    rules: { expiry_reminder_days: number[]; expired_followup_days: number[]; hour: number; notify_class_changes: boolean };
}

const days = (text: string) => Array.from(new Set(text.split(/[^0-9]+/).filter(Boolean).map(Number))).filter((n) => n > 0).slice(0, 5);

export default function Automation({ autoReplies, events, rules }: Props) {
    const can = useCan();
    const [reply, setReply] = useState<AutoReply | 'new' | null>(null);
    const form = useForm({
        on: Object.fromEntries(events.map((e) => [e.key, e.on])) as Record<string, boolean>,
        expiry_reminder_days: rules.expiry_reminder_days ?? [],
        expired_followup_days: rules.expired_followup_days ?? [],
        hour: rules.hour,
    });
    const [expiryText, setExpiryText] = useState((rules.expiry_reminder_days ?? []).join('، '));
    const [followText, setFollowText] = useState((rules.expired_followup_days ?? []).join('، '));
    const save = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.automation.update'), { preserveScroll: true });
    };

    return (
        <ClubLayout title="الرسائل التلقائية">
            <PageHeader title="الرسائل التلقائية" subtitle="ما يُرسل تلقائيًا من بيانات الجيم ومتى، والردود الآلية على الرسائل الواردة" />

            <Card className="mb-6">
                <form onSubmit={save}>
                    <CardHeader
                        title="الرسائل التلقائية"
                        subtitle="كل رسالة تُرسل مرة واحدة فقط لكل حدث، بقالب معتمد في واتساب، ولمن وافق على رسائل الخدمة. المتابعة التسويقية تحتاج موافقة تسويقية أيضًا."
                        actions={<Button type="submit" size="sm" loading={form.processing}>حفظ</Button>}
                    />
                    <ul className="divide-y divide-line">
                        {events.map((ev) => (
                            <li key={ev.key} className="py-3">
                                <Toggle
                                    checked={form.data.on[ev.key]}
                                    onChange={(v) => form.setData('on', { ...form.data.on, [ev.key]: v })}
                                    label={ev.label}
                                    hint={ev.detail}
                                />
                                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                    {ev.templates.map((t) => (
                                        <Badge key={t.key} tone={t.approved ? 'green' : 'amber'}>{t.label}: {t.approved ? 'القالب معتمد' : 'القالب غير معتمد — لن تُرسل'}</Badge>
                                    ))}
                                    {ev.timing === 'expiry_reminder_days' && (
                                        <label className="inline-flex items-center gap-1.5 text-[12.5px] text-muted">
                                            قبل الانتهاء بـ
                                            <Input value={expiryText} onChange={(e) => { setExpiryText(e.target.value); form.setData('expiry_reminder_days', days(e.target.value)); }} className="h-8 w-28 num" dir="ltr" aria-label="أيام التذكير قبل الانتهاء" />
                                            يوم (مثل 7، 3)
                                        </label>
                                    )}
                                    {ev.timing === 'expired_followup_days' && (
                                        <label className="inline-flex items-center gap-1.5 text-[12.5px] text-muted">
                                            بعد الانتهاء بـ
                                            <Input value={followText} onChange={(e) => { setFollowText(e.target.value); form.setData('expired_followup_days', days(e.target.value)); }} className="h-8 w-28 num" dir="ltr" aria-label="أيام المتابعة بعد الانتهاء" />
                                            يوم
                                        </label>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3 text-sm">
                        <span className="text-muted">ساعة إرسال التذكيرات اليومية</span>
                        <Select value={String(form.data.hour)} onChange={(e) => form.setData('hour', Number(e.target.value))} className="h-9 w-28" aria-label="ساعة الإرسال">
                            {Array.from({ length: 24 }, (_, h) => <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>)}
                        </Select>
                        <span className="text-[12px] text-muted">بتوقيت النادي. التأكيدات (الترحيب والاشتراك والتجديد والدفع) تُرسل فور حدوثها.</span>
                    </div>
                    {Object.values(form.errors).length > 0 && <p className="mt-2 text-[12.5px] text-red-700">{Object.values(form.errors)[0]}</p>}
                </form>
                <div className="mt-3 flex flex-wrap gap-2">
                    {can('whatsapp.manage_templates') && <Button variant="outline" size="sm" href={route('club.templates.index')}>إدارة القوالب</Button>}
                    {can('settings.manage') && <Button variant="ghost" size="sm" href={route('club.settings.notifications')} icon={<Settings2 className="size-3.5" />}>إشعارات الحصص والمستحقات</Button>}
                </div>
            </Card>

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
                    <Button variant="outline" onClick={onClose}>إلغاء</Button>
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
