import { Sparkles } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { PageHero } from './Plans';

export interface TrainerData {
    id: number;
    name: string;
    bio: string | null;
    specialties: string[] | null;
    color: string | null;
    photo_url: string | null;
}

export function TrainerCard({ trainer, compact = false }: { trainer: TrainerData; compact?: boolean }) {
    const tint = trainer.color || 'var(--brand)';
    return (
        <article className="overflow-hidden rounded-3xl border border-line bg-white text-center shadow-sm">
            <div className="relative aspect-square overflow-hidden" style={{ background: `color-mix(in oklab, ${tint} 14%, white)` }}>
                {trainer.photo_url ? (
                    <img src={trainer.photo_url} alt={trainer.name} loading="lazy" width={400} height={400} className="size-full object-cover" />
                ) : (
                    <div className="grid size-full place-items-center">
                        <span className="grid size-24 place-items-center rounded-full bg-white/80 font-display text-4xl" style={{ color: tint }}>
                            {trainer.name.trim()[0]}
                        </span>
                    </div>
                )}
            </div>
            <div className="p-5">
                <h3 className="font-display text-lg font-semibold">{trainer.name}</h3>
                {trainer.specialties && trainer.specialties.length > 0 && (
                    <div className="mt-2 flex flex-wrap justify-center gap-1.5">
                        {trainer.specialties.slice(0, compact ? 3 : 8).map((s) => (
                            <span key={s} className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs text-brand-700">{s}</span>
                        ))}
                    </div>
                )}
                {!compact && trainer.bio && <p className="mt-3 text-sm leading-7 text-muted">{trainer.bio}</p>}
            </div>
        </article>
    );
}

export default function Trainers({ site, seo, trainers }: { site: SiteInfo; seo: { title: string }; trainers: TrainerData[] }) {
    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="فريق التدريب" subtitle="مدربون معتمدون يرافقونك خطوة بخطوة نحو هدفك." icon={<Sparkles className="size-6" />} />
            <section className="mx-auto max-w-6xl px-4 py-12">
                {trainers.length === 0 ? (
                    <p className="py-16 text-center text-muted">سنعرّفك بفريقنا قريبًا.</p>
                ) : (
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {trainers.map((t) => <TrainerCard key={t.id} trainer={t} />)}
                    </div>
                )}
            </section>
        </SiteLayout>
    );
}
