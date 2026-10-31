import { Link } from '@inertiajs/react';
import { ChevronLeft, FileText } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Badge, PageHeader } from '@/Components/ui';
import { cardHover } from '@/Components/ui/Card';
import { cn } from '@/lib/cn';

type PaperCard = { key: string; label: string; desc: string; section: string; customised: boolean };

/** قوالب الأوراق: a card per printed document; each opens its own editor with a live preview. */
export default function Papers({ papers }: { papers: PaperCard[] }) {
    const sections = [...new Set(papers.map((p) => p.section))];

    return (
        <ClubLayout title="قوالب الأوراق">
            <PageHeader back={<SettingsBack />} title="قوالب الأوراق" subtitle="لكل ورقة يطبعها النادي قالب يُضبط وحده — اختر ورقة لتفتح محرّرها ومعاينتها الحية." />
            <div className="space-y-6">
                {sections.map((section) => (
                    <section key={section}>
                        <h2 className="mb-3 text-[13px] font-semibold text-muted">{section}</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {papers.filter((p) => p.section === section).map((p) => (
                                <Link key={p.key} href={route('club.settings.papers.edit', p.key)} className={cn('card group flex items-center gap-4 p-5 text-start', cardHover)}>
                                    <FileText className="size-5 shrink-0 text-[#9ca3af]" />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="text-[15px] font-semibold text-ink">{p.label}</span>
                                            {p.customised && <Badge tone="brand">مخصّص</Badge>}
                                        </span>
                                        <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{p.desc}</span>
                                    </span>
                                    <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280]" />
                                </Link>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
            <p className="mt-6 text-[12px] text-muted">قوالب رسائل واتساب في «التطبيقات التكاملية»، ومستندات النشاط (السجلات والتراخيص) في قسم «المستندات» — لا علاقة لها بقوالب الطباعة هنا.</p>
        </ClubLayout>
    );
}
