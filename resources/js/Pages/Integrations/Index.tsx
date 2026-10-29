import { Lock } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, PageHeader } from '@/Components/ui';
import { IntegrationCard, type IntegrationStatusData } from '@/Components/Integrations';

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

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                {cards.map((card) => (
                    <IntegrationCard
                        key={card.key}
                        service={card.key}
                        name={card.name}
                        subtitle={card.subtitle}
                        site={card.site}
                        line={card.line}
                        status={card.status}
                        action={card.can ? (
                            <Button variant="outline" size="sm" href={route(card.route)}>إعداد</Button>
                        ) : (
                            <span className="inline-flex items-center gap-1 text-[12px] text-[#9ca3af]"><Lock className="size-3.5" /> يتطلب صلاحية من مالك النادي</span>
                        )}
                    />
                ))}
            </div>
        </ClubLayout>
    );
}
