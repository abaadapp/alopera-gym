import { Clock, Dumbbell } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { PageHero } from './Plans';

export interface ProgramData {
    id: number;
    name: string;
    slug: string;
    category: string;
    level: string;
    description: string | null;
    color: string;
    duration_minutes: number;
    image_url: string | null;
}

export const LEVELS: Record<string, string> = { all: 'لجميع المستويات', beginner: 'مبتدئات', intermediate: 'متوسط', advanced: 'متقدم' };
export const CATEGORIES: Record<string, string> = { class: 'حصة جماعية', program: 'برنامج تدريبي', pt: 'تدريب شخصي' };

export function ProgramCard({ program }: { program: ProgramData }) {
    return (
        <article className="group overflow-hidden rounded-3xl border border-line bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-brand/5">
            <div className="relative aspect-[4/3] overflow-hidden" style={{ background: `linear-gradient(135deg, ${program.color}22, ${program.color}55)` }}>
                {program.image_url ? (
                    <img src={program.image_url} alt={program.name} loading="lazy" width={480} height={360} className="size-full object-cover transition duration-500 group-hover:scale-105" />
                ) : (
                    <div className="grid size-full place-items-center">
                        <Dumbbell className="size-12 opacity-60" style={{ color: program.color }} />
                    </div>
                )}
                <span className="absolute top-3 start-3 rounded-full bg-white/90 px-3 py-1 text-xs font-medium text-ink backdrop-blur">{CATEGORIES[program.category] ?? program.category}</span>
            </div>
            <div className="p-5">
                <div className="flex items-center gap-2">
                    <span className="size-2.5 rounded-full" style={{ background: program.color }} />
                    <h3 className="font-display text-lg font-semibold text-ink">{program.name}</h3>
                </div>
                <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted">
                    <span>{LEVELS[program.level] ?? program.level}</span>
                    <span className="inline-flex items-center gap-1"><Clock className="size-3.5" /><span className="num">{program.duration_minutes}</span> دقيقة</span>
                </div>
                {program.description && <p className="mt-3 line-clamp-3 text-sm leading-7 text-muted">{program.description}</p>}
            </div>
        </article>
    );
}

export default function Programs({ site, seo, programs }: { site: SiteInfo; seo: { title: string }; programs: ProgramData[] }) {
    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="البرامج التدريبية" subtitle="حصص جماعية وبرامج متنوعة لكل المستويات — قوة، مرونة، لياقة وتوازن." icon={<Dumbbell className="size-6" />} />
            <section className="mx-auto max-w-6xl px-4 py-12">
                {programs.length === 0 ? (
                    <p className="py-16 text-center text-muted">ستُضاف البرامج قريبًا.</p>
                ) : (
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {programs.map((p) => <ProgramCard key={p.id} program={p} />)}
                    </div>
                )}
            </section>
        </SiteLayout>
    );
}
