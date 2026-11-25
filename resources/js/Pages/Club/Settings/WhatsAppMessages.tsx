import { useMemo, useRef, useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock, Pencil, PauseCircle, Plus, RefreshCw, Send, Target, Timer, User, XCircle, Zap } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Modal, Notice, PageHeader } from '@/Components/ui';
import { cn } from '@/lib/cn';

interface Value { n: number; name: string; label: string; example: string }
interface Message {
    id: number;
    key: string;
    title: string;
    meta_name: string;
    language: string;
    category: string;
    body: string;
    status: string;
    rejection_reason: string | null;
    submitted_at: string | null;
    automatic_off: boolean;
    values: Value[];
    group: string;
    purpose: string;
    when: string;
    how: string;
    to: string;
}

interface Props {
    groups: Record<string, string>;
    messages: Message[];
    unavailable: string | null;
    syncError: string | null;
}

const STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'gray'; icon: ReactNode }> = {
    approved: { label: 'معتمدة من Meta', tone: 'green', icon: <CheckCircle2 /> },
    pending: { label: 'قيد المراجعة لدى Meta', tone: 'amber', icon: <Clock /> },
    rejected: { label: 'رفضتها Meta', tone: 'red', icon: <XCircle /> },
    paused: { label: 'أوقفتها Meta مؤقتًا', tone: 'red', icon: <PauseCircle /> },
    disabled: { label: 'عطّلتها Meta', tone: 'red', icon: <XCircle /> },
    draft: { label: 'لم تُرسل إلى Meta بعد', tone: 'gray', icon: <AlertCircle /> },
};

/** {{1}} → [اسم العضوة]: the text as the owner reads and writes it. */
function friendly(body: string, values: Value[]): string {
    return body.replace(/\{\{\s*(\d+)\s*\}\}/g, (all, n) => {
        const v = values.find((x) => x.n === Number(n));
        return v ? `[${v.label}]` : all;
    });
}

/** [اسم العضوة] → {{1}}: the text as Meta takes it. */
function forMeta(text: string, values: Value[]): string {
    return values.reduce((t, v) => t.split(`[${v.label}]`).join(`{{${v.n}}}`), text);
}

/** The text with the example values in place, the values marked. */
function Preview({ body, values, en }: { body: string; values: Value[]; en: boolean }) {
    const parts = body.split(/(\{\{\s*\d+\s*\}\})/g);
    return (
        <div dir={en ? 'ltr' : 'rtl'} className="rounded-[14px] bg-[#e7f7e3] px-3.5 py-2.5 text-[14px] leading-7 whitespace-pre-line text-[#111b21] shadow-[0_1px_0.5px_rgba(0,0,0,0.13)]">
            {parts.map((part, i) => {
                const m = part.match(/\{\{\s*(\d+)\s*\}\}/);
                const v = m ? values.find((x) => x.n === Number(m[1])) : null;
                return v ? <span key={i} className="rounded bg-[#c7ebc0] px-1 font-medium">{v.example}</span> : <span key={i}>{part}</span>;
            })}
        </div>
    );
}

/**
 * «الإعدادات ← رسائل الواتساب»: every message the system sends to members, grouped by what it is about — what it is
 * for, when and how it goes out, and its text as members receive it. «تعديل النص» opens the text with the members' data
 * as named chips ([اسم العضوة]); saving sends it to Meta, and it is «قيد المراجعة» until Meta approves it.
 */
export default function WhatsAppMessages({ groups, messages, unavailable, syncError }: Props) {
    const [group, setGroup] = useState<string>('all');
    const [editing, setEditing] = useState<Message | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    const counts = useMemo(() => {
        const c = { approved: 0, pending: 0, rejected: 0, draft: 0 };
        for (const m of messages) {
            if (m.status === 'approved') c.approved++;
            else if (m.status === 'pending') c.pending++;
            else if (m.status === 'draft') c.draft++;
            else c.rejected++;
        }
        return c;
    }, [messages]);
    const present = Object.entries(groups).filter(([key]) => messages.some((m) => m.group === key));
    const shown = group === 'all' ? present : present.filter(([key]) => key === group);

    return (
        <ClubLayout title="رسائل الواتساب">
            <PageHeader
                title="رسائل الواتساب"
                subtitle="كل رسالة يرسلها النظام: هدفها، ومتى تُرسل، ونصها كما يصل. عدّلي النص واحفظيه، فيُرسل إلى Meta للموافقة."
                actions={
                    <Button
                        variant="outline"
                        icon={<RefreshCw className={cn(refreshing && 'animate-spin')} />}
                        disabled={refreshing || unavailable !== null}
                        onClick={() => router.post(route('club.settings.whatsapp-messages.refresh'), {}, { preserveScroll: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) })}
                    >
                        تحديث الحالة من Meta
                    </Button>
                }
            />

            <div className="space-y-4">
                {unavailable && <Notice tone="amber" icon={<AlertCircle />}>{unavailable} حتى ذلك الحين يمكنكِ قراءة الرسائل فقط.</Notice>}
                {syncError && <Notice tone="red" icon={<AlertCircle />}>تعذّرت قراءة الحالات من Meta: {syncError}</Notice>}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Count tone="green" label="معتمدة وتُرسل" value={counts.approved} />
                    <Count tone="amber" label="قيد المراجعة" value={counts.pending} />
                    <Count tone="red" label="مرفوضة أو موقوفة" value={counts.rejected} />
                    <Count tone="gray" label="لم تُرسل لـ Meta" value={counts.draft} />
                </div>

                <div className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 lg:mx-0 lg:px-0" role="tablist">
                    {[['all', 'كل الرسائل'] as const, ...present].map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={group === key}
                            onClick={() => setGroup(key)}
                            className={cn('shrink-0 rounded-full border px-4 py-2 text-[13px] font-medium whitespace-nowrap transition', group === key ? 'border-ink bg-ink text-white' : 'border-line bg-white text-[#4b4b4b] hover:border-ink')}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {shown.map(([key, label]) => (
                    <section key={key} className="space-y-3">
                        <h2 className="pt-2 text-[15px] font-bold text-ink">{label}</h2>
                        <div className="grid gap-3 xl:grid-cols-2">
                            {messages.filter((m) => m.group === key).map((m) => (
                                <MessageCard key={m.id} message={m} onEdit={unavailable ? null : () => setEditing(m)} />
                            ))}
                        </div>
                    </section>
                ))}
            </div>

            {editing && <Editor message={editing} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function Count({ tone, label, value }: { tone: 'green' | 'amber' | 'red' | 'gray'; label: string; value: number }) {
    const colors = { green: 'text-[#047857]', amber: 'text-[#b45309]', red: 'text-[#b91c1c]', gray: 'text-[#4b5563]' };
    return (
        <div className="rounded-[14px] border border-line bg-white px-4 py-3">
            <p className={cn('num text-[22px] font-bold', colors[tone])}>{value}</p>
            <p className="text-[12px] text-muted">{label}</p>
        </div>
    );
}

function MessageCard({ message: m, onEdit }: { message: Message; onEdit: (() => void) | null }) {
    const status = STATUS[m.status] ?? STATUS.draft;
    return (
        <Card className="flex flex-col gap-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="text-[15px] font-bold text-ink">{m.title}</h3>
                    <p className="mt-0.5 text-[12px] text-muted">
                        {m.category === 'marketing' ? 'رسالة تسويقية' : 'رسالة خدمة'} · {m.language === 'en' ? 'الإنجليزية' : 'العربية'}
                    </p>
                </div>
                <Badge tone={status.tone}>{status.icon}{status.label}</Badge>
            </div>

            <div className="rounded-[14px] bg-[#efeae2] p-3">
                <Preview body={m.body} values={m.values} en={m.language === 'en'} />
            </div>

            <dl className="grid gap-2 text-[13px] leading-6">
                <Row icon={<Target />} label="الهدف">{m.purpose}</Row>
                <Row icon={<Timer />} label="متى تُرسل">{m.when}</Row>
                <Row icon={<Zap />} label="الطريقة">{m.how}</Row>
                <Row icon={<User />} label="إلى">{m.to}</Row>
            </dl>

            {m.status === 'rejected' && m.rejection_reason && (
                <Notice tone="red" icon={<XCircle />}>سبب الرفض: {m.rejection_reason} عدّلي النص وأرسليه مرة أخرى.</Notice>
            )}
            {m.status === 'pending' && (
                <Notice tone="amber" icon={<Clock />}>أُرسلت إلى Meta{m.submitted_at ? ` (${m.submitted_at})` : ''}، ولا تُرسل للعضوات حتى توافق عليها. تتحدث الحالة هنا تلقائيًا.</Notice>
            )}
            {m.automatic_off && <Notice tone="gray" icon={<PauseCircle />}>هذه الرسالة موقوفة في «الرسائل التلقائية»، فلا تُرسل حتى لو كانت معتمدة.</Notice>}

            <div className="mt-auto flex justify-end border-t border-line pt-3">
                {/* Meta does not take a new text for a message it is still reviewing. */}
                <Button variant="outline" size="sm" icon={<Pencil />} disabled={!onEdit || m.status === 'pending'} title={m.status === 'pending' ? 'انتظري رد Meta على التعديل السابق' : undefined} onClick={() => onEdit?.()}>تعديل النص</Button>
            </div>
        </Card>
    );
}

function Row({ icon, label, children }: { icon: ReactNode; label: string; children: ReactNode }) {
    return (
        <div className="flex gap-2 [&>svg]:mt-1 [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:text-muted">
            {icon}
            <div className="min-w-0"><dt className="inline font-semibold text-ink">{label}: </dt><dd className="inline text-[#4b4b4b]">{children}</dd></div>
        </div>
    );
}

function Editor({ message: m, onClose }: { message: Message; onClose: () => void }) {
    const en = m.language === 'en';
    const [text, setText] = useState(() => friendly(m.body, m.values));
    const area = useRef<HTMLTextAreaElement>(null);
    const form = useForm({ body: m.body });
    const body = forMeta(text, m.values);
    const missing = m.values.filter((v) => !text.includes(`[${v.label}]`));
    const unknown = (text.match(/\[[^\]\n]+\]/g) ?? []).filter((t) => !m.values.some((v) => `[${v.label}]` === t));
    const edgeValue = /^\s*\{\{\d+\}\}|\{\{\d+\}\}[\s.!،؟?]*$/.test(body);
    const problem = text.trim() === ''
        ? 'اكتبي نص الرسالة.'
        : missing.length > 0
          ? `يجب أن تبقى كل البيانات في الرسالة. أضيفي: ${missing.map((v) => `[${v.label}]`).join('، ')}`
          : unknown.length > 0
            ? `${unknown.join('، ')} ليس من بيانات هذه الرسالة. استخدمي الأزرار تحت النص.`
            : edgeValue
              ? 'لا تقبل Meta أن تبدأ الرسالة أو تنتهي ببيان: أضيفي كلمة قبله أو بعده.'
              : null;
    const unchanged = body === m.body;

    const insert = (v: Value) => {
        const el = area.current;
        const token = `[${v.label}]`;
        if (!el) return setText((t) => t + token);
        const start = el.selectionStart ?? text.length;
        const end = el.selectionEnd ?? text.length;
        const next = text.slice(0, start) + token + text.slice(end);
        setText(next);
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const save = () => {
        form.transform(() => ({ body }));
        form.put(route('club.settings.whatsapp-messages.update', { template: m.id }), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`تعديل: ${m.title}`}
            description="اكتبي النص كما تريدينه. ما بين القوسين [ ] يملؤه النظام لكل عضوة تلقائيًا."
            size="large"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>إلغاء</Button>
                    <Button icon={<Send />} loading={form.processing} disabled={problem !== null || unchanged} onClick={save}>حفظ وإرسال إلى Meta</Button>
                </>
            }
        >
            <div className="space-y-4 pb-2">
                <div>
                    <textarea
                        ref={area}
                        dir={en ? 'ltr' : 'rtl'}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        rows={7}
                        maxLength={1100}
                        className="input min-h-[160px] resize-y leading-7"
                    />
                    <p className="num mt-1 text-end text-[11px] text-muted">{body.length}/1024</p>
                </div>

                {m.values.length > 0 && (
                    <div>
                        <p className="mb-2 text-[13px] font-semibold text-ink">بيانات تضيفينها بضغطة (تتغير لكل عضوة):</p>
                        <div className="flex flex-wrap gap-2">
                            {m.values.map((v) => (
                                <button
                                    key={v.n}
                                    type="button"
                                    onClick={() => insert(v)}
                                    className={cn('flex items-center gap-1 rounded-full border px-3 py-1.5 text-[13px] transition', text.includes(`[${v.label}]`) ? 'border-[#bbf7d0] bg-[#f0fdf4] text-[#166534]' : 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]')}
                                >
                                    <Plus className="size-3.5" />{v.label}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <div>
                    <p className="mb-2 text-[13px] font-semibold text-ink">هكذا تصل الرسالة:</p>
                    <div className="rounded-[14px] bg-[#efeae2] p-3">
                        <Preview body={body} values={m.values} en={en} />
                    </div>
                </div>

                {(problem || form.errors.body) && <Notice tone="red" icon={<AlertCircle />}>{form.errors.body ?? problem}</Notice>}
                <Notice tone="amber" icon={<Clock />}>
                    بعد الحفظ تُرسل الرسالة إلى Meta وتصير «قيد المراجعة» (عادةً من دقائق إلى 24 ساعة). خلال المراجعة لا تُرسل هذه الرسالة لأحد، وتعود تلقائيًا فور الموافقة. تسمح Meta بتعديل الرسالة المعتمدة مرة كل 24 ساعة.
                </Notice>
            </div>
        </Modal>
    );
}
