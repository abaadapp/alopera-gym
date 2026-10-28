import { router } from '@inertiajs/react';
import { Check, Mail, MessageCircle, Phone } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, EmptyState, PageHeader, Pagination } from '@/Components/ui';
import { phone as fmtPhone, whatsappUrl } from '@/lib/format';
import type { Paginated } from '@/types';

interface ContactMessage {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    subject: string | null;
    message: string;
    status: string;
    at: string;
}

export default function ContactMessages({ messages }: { messages: Paginated<ContactMessage> }) {
    return (
        <ClubLayout title="رسائل التواصل">
            <PageHeader title="رسائل نموذج التواصل" subtitle="ما يصل من صفحة «تواصلي معنا» في الموقع" />

            <Card padded={false}>
                {messages.data.length === 0 ? (
                    <EmptyState icon={<Mail className="size-6" />} title="لا توجد رسائل" />
                ) : (
                    <ul className="divide-y divide-line">
                        {messages.data.map((m) => {
                            const digits = (m.phone ?? '').replace(/\D/g, '');
                            return (
                                <li key={m.id} className={`p-5 ${m.status === 'new' ? 'bg-brand-50/50' : ''}`}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">
                                                {m.name} {m.status === 'new' && <Badge tone="brand" className="ms-1">جديدة</Badge>}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted num">{m.at}</p>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {m.phone && <Button size="sm" variant="outline" href={`tel:${m.phone}`} external icon={<Phone className="size-3.5" />}><span className="num">{fmtPhone(digits.length === 8 ? `968${digits}` : digits)}</span></Button>}
                                            {m.phone && <Button size="sm" variant="outline" href={whatsappUrl(digits.length === 8 ? `968${digits}` : digits, `مرحبًا ${m.name}`)} external icon={<MessageCircle className="size-3.5" />}>واتساب</Button>}
                                            {m.email && <Button size="sm" variant="outline" href={`mailto:${m.email}`} external icon={<Mail className="size-3.5" />}>بريد</Button>}
                                            {m.status === 'new' && (
                                                <Button size="sm" variant="secondary" icon={<Check className="size-3.5" />} onClick={() => router.post(route('club.website.messages.read', m.id), {}, { preserveScroll: true })}>
                                                    تمت القراءة
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    {m.subject && <p className="mt-3 text-sm font-medium">{m.subject}</p>}
                                    <p className="mt-1 whitespace-pre-wrap text-sm leading-7 text-ink/80">{m.message}</p>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <Pagination data={messages} />
            </Card>
        </ClubLayout>
    );
}
