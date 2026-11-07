import { type FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import { Clock, Inbox, MessageCircle, Send, Zap } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, HBars, Input, PageHeader, StatCard, StatGrid } from '@/Components/ui';
import { number } from '@/lib/format';

/* «واتساب ← التقارير»: a period's conversations and messages, first answers, automatic notices and the team. */

interface Figures {
    conversations: { new: number; open_now: number; closed: number; unassigned: number; by_status: { key: string; label: string; count: number }[] };
    messages: { inbound: number; outbound: number; by_source: { direction: string; source: string; count: number }[] };
    first_response: { conversations: number; seconds: number | null };
    automatic: { succeeded: number; failed: number; skipped: number; waiting: number };
    staff: { id: number; name: string; open: number; replies: number }[];
    days: { day: string; inbound: number; outbound: number }[];
}

const SOURCE: Record<string, string> = {
    customer: 'من العملاء',
    platform: 'من الموظفين في النظام',
    automation: 'رسائل تلقائية',
    business_app: 'من تطبيق WhatsApp Business',
    history: 'سجل سابق مستورد',
};

function duration(seconds: number | null): string {
    if (seconds === null) return '—';
    if (seconds < 60) return `${seconds} ث`;
    if (seconds < 3600) return `${Math.round(seconds / 60)} د`;
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return m ? `${h} س ${m} د` : `${h} س`;
}

export default function Reports({ filters, figures }: { filters: { from: string; to: string }; figures: Figures }) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('club.whatsapp.reports'), { from, to }, { preserveState: true });
    };
    const maxDay = Math.max(1, ...figures.days.map((d) => d.inbound + d.outbound));

    return (
        <ClubLayout title="تقارير واتساب">
            <PageHeader title="تقارير واتساب" subtitle="المحادثات والرسائل وسرعة الرد والرسائل التلقائية لفترة تختارها. المحادثات التجريبية غير محسوبة." />

            <form onSubmit={apply} className="mb-5 flex flex-wrap items-end gap-2">
                <label className="text-[12.5px] text-muted">من<Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="mt-1 h-9" /></label>
                <label className="text-[12.5px] text-muted">إلى<Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="mt-1 h-9" /></label>
                <Button type="submit" size="sm">عرض</Button>
            </form>

            <StatGrid className="mb-5">
                <StatCard label="محادثات جديدة" value={number(figures.conversations.new)} icon={<MessageCircle className="size-5" />} tone="green" />
                <StatCard label="مفتوحة الآن" value={number(figures.conversations.open_now)} hint={`منها ${number(figures.conversations.unassigned)} غير مخصصة`} icon={<Inbox className="size-5" />} tone="amber" />
                <StatCard label="أُغلقت في الفترة" value={number(figures.conversations.closed)} icon={<Inbox className="size-5" />} tone="blue" />
                <StatCard label="متوسط وقت أول رد" value={duration(figures.first_response.seconds)} hint={`على ${number(figures.first_response.conversations)} محادثة`} icon={<Clock className="size-5" />} tone="violet" />
            </StatGrid>

            <div className="mb-5 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="الرسائل" subtitle={`واردة ${number(figures.messages.inbound)} · صادرة ${number(figures.messages.outbound)}`} />
                    <HBars data={figures.messages.by_source.map((r) => ({ label: SOURCE[r.source] ?? r.source, value: r.count }))} />
                </Card>
                <Card>
                    <CardHeader title="الرسائل التلقائية" subtitle="الترحيب والتأكيدات والتذكيرات التي أرسلها النظام في الفترة" />
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        <div className="rounded-xl bg-emerald-50 p-3"><dt className="text-emerald-800">نجحت</dt><dd className="text-xl font-semibold num">{number(figures.automatic.succeeded)}</dd></div>
                        <div className="rounded-xl bg-red-50 p-3"><dt className="text-red-800">فشلت</dt><dd className="text-xl font-semibold num">{number(figures.automatic.failed)}</dd></div>
                        <div className="rounded-xl bg-amber-50 p-3"><dt className="text-amber-800">لم تُرسل (قالب أو موافقة)</dt><dd className="text-xl font-semibold num">{number(figures.automatic.skipped)}</dd></div>
                        <div className="rounded-xl bg-stone-50 p-3"><dt className="text-stone-700">في الانتظار</dt><dd className="text-xl font-semibold num">{number(figures.automatic.waiting)}</dd></div>
                    </dl>
                </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card padded={false}>
                    <div className="p-5 pb-2"><CardHeader title="الموظفون" subtitle="المحادثات المفتوحة المخصصة لكل موظف، وردوده في الفترة" /></div>
                    {figures.staff.length === 0 ? <p className="px-5 pb-5 text-sm text-muted">لا توجد محادثات مخصصة.</p> : (
                        <table className="table-base">
                            <thead><tr><th>الموظف</th><th>مفتوحة مخصصة</th><th>ردود</th></tr></thead>
                            <tbody>
                                {figures.staff.map((s) => <tr key={s.id}><td>{s.name}</td><td className="num">{number(s.open)}</td><td className="num">{number(s.replies)}</td></tr>)}
                            </tbody>
                        </table>
                    )}
                </Card>
                <Card>
                    <CardHeader title="يومًا بيوم" subtitle="الواردة والصادرة" />
                    {figures.days.length === 0 ? <p className="text-sm text-muted">لا رسائل في الفترة.</p> : (
                        <ul className="space-y-1.5">
                            {figures.days.map((d) => (
                                <li key={d.day} className="flex items-center gap-2 text-[12.5px]">
                                    <span className="w-20 shrink-0 text-muted num">{d.day.slice(5)}</span>
                                    <span className="flex h-3 flex-1 overflow-hidden rounded-full bg-stone-100">
                                        <span className="bg-emerald-500" style={{ width: `${(d.inbound / maxDay) * 100}%` }} title={`واردة ${d.inbound}`} />
                                        <span className="bg-sky-500" style={{ width: `${(d.outbound / maxDay) * 100}%` }} title={`صادرة ${d.outbound}`} />
                                    </span>
                                    <span className="w-24 shrink-0 text-end num"><Send className="me-1 inline size-3 text-sky-600" />{d.outbound} · {d.inbound}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="mt-3 flex items-center gap-3 text-[11.5px] text-muted"><span className="inline-flex items-center gap-1"><span className="size-2 rounded-full bg-emerald-500" />واردة</span><span className="inline-flex items-center gap-1"><span className="size-2 rounded-full bg-sky-500" />صادرة</span><Zap className="size-3" /> تشمل الرسائل التلقائية داخل المحادثات</p>
                </Card>
            </div>
        </ClubLayout>
    );
}
