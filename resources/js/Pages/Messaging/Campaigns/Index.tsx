import { useEffect, useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Megaphone, Plus, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, Field, Input, Modal, PageHeader, Pagination, Select } from '@/Components/ui';
import { number } from '@/lib/format';
import type { Option, Paginated } from '@/types';

export interface Audience {
    segment: string;
    days: number | null;
    plan_ids: number[];
    program_ids: number[];
}

interface CampaignRow {
    id: number;
    name: string;
    status: string;
    audience: Audience;
    recipients_count: number;
    sent_count: number;
    delivered_count: number;
    read_count: number;
    failed_count: number;
    template: string | null;
    scheduled_at: string | null;
    created_at: string;
}

interface TemplateOption {
    id: number;
    name: string;
    key: string;
    status: string;
    category: string;
    body: string;
}

interface Props {
    campaigns: Paginated<CampaignRow>;
    templates: TemplateOption[];
    segments: Record<string, string>;
    plans: Option[];
    programs: Option[];
}

export const CAMPAIGN_STATUS: Record<string, [string, 'gray' | 'blue' | 'amber' | 'green' | 'red']> = {
    draft: ['مسودة', 'gray'],
    scheduled: ['مجدولة', 'blue'],
    sending: ['جارٍ الإرسال', 'amber'],
    completed: ['اكتملت', 'green'],
    cancelled: ['ملغاة', 'red'],
};

export const SEGMENTS_WITH_DAYS = ['expiring', 'expired', 'inactive', 'new'];

type CampaignForm = {
    name: string;
    message_template_id: string;
    segment: string;
    days: string;
    plan_ids: number[];
    program_ids: number[];
    scheduled_at: string;
};

export default function CampaignsIndex({ campaigns, templates, segments, plans, programs }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <ClubLayout title="الحملات التسويقية">
            <PageHeader
                title="الحملات التسويقية"
                subtitle="أرسل عروضًا ورسائل لفئات محددة — للأعضاء الموافقين على التسويق فقط"
                actions={<Button icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>حملة جديدة</Button>}
            />

            <Card padded={false}>
                {campaigns.data.length === 0 ? (
                    <EmptyState icon={<Megaphone className="size-6" />} title="لا توجد حملات بعد" description="مثال: عرض تجديد للأعضاء الذين انتهت عضويتهم خلال آخر 30 يومًا." action={<Button onClick={() => setOpen(true)}>إنشاء حملة</Button>} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table-base">
                            <thead>
                                <tr><th>الحملة</th><th>الجمهور</th><th>القالب</th><th>الحالة</th><th>المستلمون</th><th>التسليم</th><th>القراءة</th></tr>
                            </thead>
                            <tbody>
                                {campaigns.data.map((c) => {
                                    const [st, tone] = CAMPAIGN_STATUS[c.status] ?? [c.status, 'gray'];
                                    const rate = (n: number) => (c.recipients_count > 0 ? Math.round((n / c.recipients_count) * 100) : 0);
                                    return (
                                        <tr key={c.id}>
                                            <td>
                                                <Link href={route('club.campaigns.show', c.id)} className="font-medium hover:text-brand">{c.name}</Link>
                                                <p className="text-xs text-muted">{c.scheduled_at ? <>مجدولة <span className="num">{c.scheduled_at}</span></> : <span className="num">{c.created_at}</span>}</p>
                                            </td>
                                            <td className="text-xs">{segments[c.audience?.segment] ?? c.audience?.segment}{c.audience?.days ? <> (<span className="num">{c.audience.days}</span> يوم)</> : null}</td>
                                            <td className="text-xs" dir="ltr">{c.template}</td>
                                            <td><Badge tone={tone}>{st}</Badge></td>
                                            <td className="num">{number(c.recipients_count)}</td>
                                            <td className="num">{rate(c.delivered_count)}%</td>
                                            <td className="num">{rate(c.read_count)}%</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination data={campaigns} />
            </Card>

            {open && <CampaignModal onClose={() => setOpen(false)} templates={templates} segments={segments} plans={plans} programs={programs} />}
        </ClubLayout>
    );
}

function CampaignModal({ onClose, templates, segments, plans, programs }: Omit<Props, 'campaigns'> & { onClose: () => void }) {
    const form = useForm<CampaignForm>({ name: '', message_template_id: '', segment: 'expired', days: '30', plan_ids: [], program_ids: [], scheduled_at: '' });
    const [count, setCount] = useState<number | null>(null);
    const [counting, setCounting] = useState(false);
    const needsDays = SEGMENTS_WITH_DAYS.includes(form.data.segment);
    const template = templates.find((t) => String(t.id) === form.data.message_template_id);

    useEffect(() => {
        const controller = new AbortController();
        const id = setTimeout(async () => {
            setCounting(true);
            try {
                const params: Record<string, unknown> = { segment: form.data.segment, plan_ids: form.data.plan_ids, program_ids: form.data.program_ids };
                if (needsDays && form.data.days) params.days = form.data.days;
                const res = await fetch(route('club.campaigns.audience', params), { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (res.ok) setCount((await res.json()).count);
            } catch {
                /* aborted */
            } finally {
                setCounting(false);
            }
        }, 300);
        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [form.data.segment, form.data.days, form.data.plan_ids, form.data.program_ids, needsDays]);

    const toggle = (key: 'plan_ids' | 'program_ids', id: number) => {
        const list = form.data[key];
        form.setData(key, list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, days: needsDays ? d.days : '', scheduled_at: d.scheduled_at || '' }));
        form.post(route('club.campaigns.store'));
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title="حملة جديدة"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>إلغاء</Button>
                    <Button onClick={submit} loading={form.processing}>حفظ كمسودة</Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label="اسم الحملة" error={errors.name} required>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="مثال: عرض العودة — أكتوبر" />
                </Field>

                <Field label="القالب" error={errors.message_template_id} required>
                    <Select value={form.data.message_template_id} onChange={(e) => form.setData('message_template_id', e.target.value)} placeholder="اختر قالبًا">
                        {templates.map((t) => (
                            <option key={t.id} value={t.id}>{t.name}{t.status !== 'approved' ? ' (غير معتمد)' : ''}</option>
                        ))}
                    </Select>
                </Field>
                {template && (
                    <div className="rounded-xl bg-[#fbf6f8] p-3 text-sm">
                        <p className="whitespace-pre-wrap leading-6">{template.body}</p>
                        {template.status !== 'approved' && <p className="mt-2 text-xs text-amber-700">لن تُطلق الحملة قبل اعتماد القالب في WhatsApp Manager وتحديث حالته.</p>}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="الجمهور" error={errors.segment}>
                        <Select value={form.data.segment} onChange={(e) => form.setData('segment', e.target.value)}>
                            {Object.entries(segments).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                        </Select>
                    </Field>
                    {needsDays && (
                        <Field label="عدد الأيام" error={errors.days}>
                            <Input type="number" min={1} max={730} value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} />
                        </Field>
                    )}
                </div>

                {plans.length > 0 && (
                    <div>
                        <p className="label">حسب الباقة (اختياري)</p>
                        <div className="flex flex-wrap gap-1.5">
                            {plans.map((p) => (
                                <Chip key={p.id} active={form.data.plan_ids.includes(p.id)} onClick={() => toggle('plan_ids', p.id)}>{p.name}</Chip>
                            ))}
                        </div>
                    </div>
                )}
                {programs.length > 0 && (
                    <div>
                        <p className="label">حسب السلوك: حجز هذه البرامج خلال 90 يومًا (اختياري)</p>
                        <div className="flex flex-wrap gap-1.5">
                            {programs.map((p) => (
                                <Chip key={p.id} active={form.data.program_ids.includes(p.id)} onClick={() => toggle('program_ids', p.id)}>{p.name}</Chip>
                            ))}
                        </div>
                    </div>
                )}

                <Field label="جدولة الإرسال (اختياري)" error={errors.scheduled_at} hint="اتركه فارغًا لإطلاق الحملة يدويًا من صفحتها">
                    <Input type="datetime-local" value={form.data.scheduled_at} onChange={(e) => form.setData('scheduled_at', e.target.value)} />
                </Field>

                <div className="flex items-center gap-3 rounded-[12px] border border-line bg-[#fafafa] px-4 py-3">
                    <Users className="size-5 text-[#6b7280]" />
                    <p className="text-sm">
                        الجمهور المتوقع: <b className="num">{counting && count === null ? '…' : number(count ?? 0)}</b> عضو
                        <span className="block text-xs text-muted">يشمل فقط من وافق على الرسائل التسويقية ولم يُلغِ الاشتراك.</span>
                    </p>
                </div>
            </form>
        </Modal>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button type="button" onClick={onClick} className={`rounded-full border px-3 py-1 text-xs transition ${active ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:border-brand-300'}`}>
            {children}
        </button>
    );
}
