import { useEffect, useRef, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, CheckCheck, Lock, MessageCircle, Send, UserRound } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, PageHeader, SearchInput, Tabs } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { phone } from '@/lib/format';
import { label, messageStatus } from '@/lib/labels';

interface ConversationRow {
    id: number;
    phone: string;
    contact_name: string | null;
    status: string;
    unread_count: number;
    member: { id: number; full_name: string; member_no: string } | null;
    last_message_at: string | null;
}

interface ThreadMessage {
    id: number;
    direction: 'inbound' | 'outbound';
    type: string;
    body: string | null;
    status: string;
    error_message: string | null;
    template_key: string | null;
    at: string;
}

interface Active {
    id: number;
    phone: string;
    contact_name: string | null;
    status: string;
    member_id: number | null;
    window_open: boolean;
    messages: ThreadMessage[];
}

interface Props {
    conversations: ConversationRow[];
    active: Active | null;
    enabled: boolean;
    filters: { status?: string; q?: string };
}

const statusTabs = [
    { key: 'open', label: 'مفتوحة' },
    { key: 'closed', label: 'مغلقة' },
    { key: 'all', label: 'الكل' },
];

export default function Inbox({ conversations, active, enabled, filters }: Props) {
    const can = useCan();
    const status = filters.status || 'open';
    const form = useForm({ body: '' });
    const bottom = useRef<HTMLDivElement>(null);

    useEffect(() => {
        bottom.current?.scrollIntoView({ block: 'end' });
    }, [active?.id, active?.messages.length]);

    const send = (e: FormEvent) => {
        e.preventDefault();
        if (!active) return;
        form.post(route('club.inbox.reply', active.id), { preserveScroll: true, onSuccess: () => form.reset('body') });
    };

    const listHref = (id: number) => route('club.inbox.show', { conversation: id, status: filters.status || undefined, q: filters.q || undefined });
    const title = (c: { contact_name: string | null; phone: string }) => c.contact_name || phone(c.phone);

    return (
        <ClubLayout title="محادثات واتساب">
            <PageHeader title="محادثات واتساب" subtitle="الرسائل الواردة من الأعضاء والرد عليها خلال نافذة الـ24 ساعة" />

            {!enabled && (
                <div className="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    ربط واتساب غير مفعّل لهذا النادي، لذلك لن تصل رسائل جديدة ولن تُرسل الردود.
                    {can('integrations.manage') && (
                        <Link href={route('club.integrations.index')} className="ms-2 font-medium underline">فعّله من إعدادات الربط</Link>
                    )}
                </div>
            )}

            <Card padded={false} className="overflow-hidden">
                <div className="grid min-h-[70vh] lg:grid-cols-[340px_1fr]">
                    {/* Conversation list */}
                    <aside className={cn('flex flex-col border-line lg:border-e', active && 'hidden lg:flex')}>
                        <div className="space-y-3 border-b border-line p-3">
                            <SearchInput value={filters.q} placeholder="الاسم أو الرقم…" extra={{ status: filters.status || undefined }} />
                            <Tabs
                                tabs={statusTabs}
                                value={status}
                                onChange={(key) => router.get(route('club.inbox.index'), { status: key, q: filters.q || undefined }, { preserveState: true })}
                                className="border-0"
                            />
                        </div>
                        <div className="flex-1 overflow-y-auto">
                            {conversations.length === 0 ? (
                                <EmptyState icon={<MessageCircle className="size-6" />} title="لا توجد محادثات" />
                            ) : (
                                <ul>
                                    {conversations.map((c) => (
                                        <li key={c.id}>
                                            <Link
                                                href={listHref(c.id)}
                                                preserveScroll
                                                className={cn('flex items-center gap-3 border-b border-line/70 px-4 py-3 transition hover:bg-brand-50/60', active?.id === c.id && 'bg-brand-50')}
                                            >
                                                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand">
                                                    <UserRound className="size-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center justify-between gap-2">
                                                        <span className="truncate text-sm font-medium">{title(c)}</span>
                                                        <span className="shrink-0 text-[11px] text-muted num">{c.last_message_at?.slice(5) ?? ''}</span>
                                                    </span>
                                                    <span className="flex items-center justify-between gap-2">
                                                        <span className="truncate text-xs text-muted">{c.member ? `عضو · ${c.member.member_no}` : <span className="num">{phone(c.phone)}</span>}</span>
                                                        {c.unread_count > 0 && <span className="rounded-full bg-brand px-1.5 text-[11px] text-white num">{c.unread_count}</span>}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </aside>

                    {/* Thread */}
                    <section className={cn('flex min-h-[70vh] flex-col', !active && 'hidden lg:flex')}>
                        {!active ? (
                            <div className="grid flex-1 place-items-center">
                                <EmptyState icon={<MessageCircle className="size-6" />} title="اختر محادثة" description="تظهر هنا الرسائل الواردة والردود والقوالب المرسلة." />
                            </div>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <Link href={route('club.inbox.index', { status: filters.status || undefined })} className="grid size-8 place-items-center rounded-lg hover:bg-brand-50 lg:hidden" aria-label="رجوع">
                                            <ArrowRight className="size-4" />
                                        </Link>
                                        <div>
                                            <p className="text-sm font-semibold">{title(active)}</p>
                                            <p className="text-xs text-muted num">{phone(active.phone)}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {active.member_id && can('members.view') && (
                                            <Button variant="outline" size="sm" href={route('club.members.show', active.member_id)} icon={<UserRound className="size-3.5" />}>
                                                ملف العضو
                                            </Button>
                                        )}
                                        <Button variant={active.status === 'open' ? 'secondary' : 'outline'} size="sm" icon={<CheckCheck className="size-3.5" />} onClick={() => router.post(route('club.inbox.close', active.id), {}, { preserveScroll: true })}>
                                            {active.status === 'open' ? 'إغلاق المحادثة' : 'إعادة فتح'}
                                        </Button>
                                    </div>
                                </div>

                                <div className="flex-1 space-y-2 overflow-y-auto bg-[#fbf6f8] px-4 py-4">
                                    {active.messages.length === 0 && <p className="py-10 text-center text-sm text-muted">لا توجد رسائل بعد.</p>}
                                    {active.messages.map((m) => {
                                        const outbound = m.direction === 'outbound';
                                        const [st, tone] = label(messageStatus, m.status);
                                        return (
                                            <div key={m.id} className={cn('flex', outbound ? 'justify-end' : 'justify-start')}>
                                                <div className={cn('max-w-[80%] rounded-2xl px-3.5 py-2 text-sm shadow-sm', outbound ? 'rounded-ee-sm bg-brand text-white' : 'rounded-es-sm bg-white text-ink')}>
                                                    {m.type === 'template' && <p className={cn('mb-1 text-[11px]', outbound ? 'text-white/70' : 'text-muted')}>قالب: {m.template_key}</p>}
                                                    <p className="whitespace-pre-wrap leading-6">{m.body || '—'}</p>
                                                    <div className={cn('mt-1 flex items-center justify-end gap-2 text-[11px]', outbound ? 'text-white/70' : 'text-muted')}>
                                                        <span className="num">{m.at.slice(11)}</span>
                                                        {outbound && <Badge tone={tone} className="px-1.5 py-0 text-[10px]">{st}</Badge>}
                                                    </div>
                                                    {m.error_message && <p className="mt-1 text-[11px] text-red-100">{m.error_message}</p>}
                                                </div>
                                            </div>
                                        );
                                    })}
                                    <div ref={bottom} />
                                </div>

                                {can('whatsapp.send') && (
                                    <form onSubmit={send} className="border-t border-line p-3">
                                        {!active.window_open ? (
                                            <div className="flex items-start gap-2 rounded-xl bg-stone-100 px-3 py-2.5 text-sm text-stone-600">
                                                <Lock className="mt-0.5 size-4 shrink-0" />
                                                <span>
                                                    انتهت نافذة الـ24 ساعة منذ آخر رسالة من العضو؛ يسمح واتساب بالرد الحر داخلها فقط. لإرسال رسالة الآن استخدم قالبًا معتمدًا من ملف العضو.
                                                </span>
                                            </div>
                                        ) : (
                                            <div className="flex items-end gap-2">
                                                <textarea
                                                    className="input max-h-40 min-h-11 flex-1 resize-y"
                                                    rows={1}
                                                    value={form.data.body}
                                                    onChange={(e) => form.setData('body', e.target.value)}
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter' && !e.shiftKey) {
                                                            e.preventDefault();
                                                            send(e as unknown as FormEvent);
                                                        }
                                                    }}
                                                    placeholder="اكتب ردك… (Enter للإرسال)"
                                                    disabled={!enabled}
                                                />
                                                <Button type="submit" loading={form.processing} disabled={!enabled || form.data.body.trim() === ''} icon={<Send className="size-4" />}>
                                                    إرسال
                                                </Button>
                                            </div>
                                        )}
                                        {form.errors.body && <p className="mt-1 text-xs text-red-600">{form.errors.body}</p>}
                                    </form>
                                )}
                            </>
                        )}
                    </section>
                </div>
            </Card>
        </ClubLayout>
    );
}
