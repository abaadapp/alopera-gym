import { Lock } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, PageHeader } from '@/Components/ui';
import { IntegrationMark, IntegrationStatus, type IntegrationStatusData } from '@/Components/Integrations';

interface Card {
    key: string;
    name: string;
    subtitle: string;
    site: string;
    line: string;
    route: string;
    can: boolean;
    status: IntegrationStatusData;
}

/**
 * «التطبيقات التكاملية»: three cards, no more. Each says what the service does for the club and where it stands;
 * «إعداد» opens its settings. A card the user may not set up says so instead of offering a button that fails.
 */
export default function IntegrationsIndex({ cards }: { cards: Card[] }) {
    return (
        <ClubLayout title="التطبيقات التكاملية">
            <PageHeader title="التطبيقات التكاملية" subtitle="الدفع الإلكتروني وأجهزة الدخول وواتساب: الربط هنا، وما تفعله كل خدمة في قسمها." />

            <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                {cards.map((card) => (
                    <div key={card.key} className="card flex flex-col p-5">
                        <div className="flex items-start gap-3">
                            <IntegrationMark service={card.key} />
                            <div className="min-w-0">
                                <h3 className="truncate font-bold text-ink">{card.name}</h3>
                                <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                    {card.subtitle} · <span dir="ltr">{card.site}</span>
                                </p>
                            </div>
                        </div>

                        <p className="mt-4 grow text-[13px] leading-relaxed text-[#6b7280]">{card.line}</p>
                        <p className={`mt-3 text-[12px] leading-5 ${card.status.state === 'error' ? 'text-red-700' : 'text-[#9ca3af]'}`}>{card.status.detail}</p>

                        <div className="mt-5 flex items-center justify-between gap-3 border-t border-[#e8e8e8] pt-4">
                            {card.can ? (
                                <Button variant="outline" size="sm" href={route(card.route)}>إعداد</Button>
                            ) : (
                                <span className="inline-flex items-center gap-1 text-[12px] text-[#9ca3af]"><Lock className="size-3.5" /> يتطلب صلاحية من مالك النادي</span>
                            )}
                            <IntegrationStatus status={card.status} />
                        </div>
                    </div>
                ))}
            </div>
        </ClubLayout>
    );
}
