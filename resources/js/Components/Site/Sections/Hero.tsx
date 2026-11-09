import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { heroLayout } from '@/lib/smart-image';
import SmartImage from '../SmartImage';
import { SiteButton } from '../ui';
import { img, link, list, str, type SectionProps } from './types';

interface Fact { id?: string; title?: string; text?: string }
interface NextClass { name: string; date: string; time: string; day_label?: string }

/**
 * v2 hero: dark, a two-line title, the description and two buttons, and a bar of facts with the next class.
 * The layout follows the image (lib/smart-image heroLayout): none → text on dark; a wide image → shown whole as a
 * banner with the text over its lower part (under it on phones); a tall or square one → beside the text; only the
 * owner's «ملء المساحة» makes it a full-bleed background that may trim its edges.
 */
export default function Hero({ section }: SectionProps) {
    const { tenant } = useShared();
    const d = section.data;
    const image = img(d.image);
    const layout = heroLayout(image);
    const facts = list<Fact>(d.facts).filter((f) => str(f.title) || str(f.text));
    const next = (d.show_next_class !== false ? (section.feed?.next_class as NextClass | null | undefined) : null) ?? null;
    const title = str(d.title);
    const title2 = str(d.title_2);
    const heading = title || title2 ? [title, title2].filter(Boolean) : [tenant?.name ?? ''];

    const text = (
        <div className={cn('grid w-full gap-8', layout === 'split' ? 'lg:gap-10' : 'lg:grid-cols-2 lg:items-end lg:gap-20')}>
            <div className="flex min-w-0 flex-col gap-4 sm:gap-5">
                {str(d.eyebrow) && <span className="text-[15px] text-[#E9DFC8]">{str(d.eyebrow)}</span>}
                <h1 className={cn('m-0 font-bold leading-[1.15] break-words', layout === 'split' ? 'text-[clamp(42px,6.5vw,96px)]' : 'text-[clamp(44px,8vw,120px)]')}>
                    {heading.map((line, i) => <span key={i} className="block">{line}</span>)}
                </h1>
            </div>
            <div className="flex max-w-[460px] min-w-0 flex-col gap-7 lg:pb-3">
                {str(d.subtitle) && <p className="m-0 text-lg leading-8 text-pretty whitespace-pre-line text-[#F1ECE3]">{str(d.subtitle)}</p>}
                {(link(d.primary)?.label || link(d.secondary)?.label) && (
                    <div className="flex flex-wrap items-center gap-x-7 gap-y-4">
                        <SiteButton link={link(d.primary)} tone="dark" className="h-[54px] px-7 text-base" />
                        <SiteButton link={link(d.secondary)} tone="dark" variant="underline" />
                    </div>
                )}
            </div>
        </div>
    );

    const bar = (facts.length > 0 || next) && (
        <div className="relative border-t border-white/20 bg-[rgba(14,12,9,0.35)]">
            <div className="mx-auto flex max-w-[1360px] flex-wrap items-center justify-between gap-x-10 gap-y-3 px-5 py-5 sm:px-10">
                {facts.length > 0 && (
                    <div className="flex flex-wrap gap-x-10 gap-y-2">
                        {facts.map((f, i) => (
                            <span key={f.id ?? i} className="text-[15px]">
                                <span className="font-semibold">{str(f.title)}</span>
                                {str(f.text) && <span className="text-[#D8CFBF]"> — {str(f.text)}</span>}
                            </span>
                        ))}
                    </div>
                )}
                {next && (
                    <span className="text-[15px] text-[#D8CFBF]">
                        الحصة القادمة: <span className="font-semibold text-white">{next.name}</span>{' '}
                        {next.day_label && <span>{next.day_label} </span>}
                        <span dir="ltr" className="[unicode-bidi:isolate]">{next.time}</span>
                    </span>
                )}
            </div>
        </div>
    );

    const shell = 'mx-auto w-full max-w-[1360px] px-5 sm:px-10';

    if (layout === 'split' && image) {
        return (
            <section id={section.id} className="relative scroll-mt-20 overflow-hidden bg-[#2A251F] text-white">
                <div className={cn(shell, 'grid items-center gap-10 py-14 sm:py-20 lg:min-h-[calc(100svh-65px)] lg:grid-cols-12 lg:gap-16 lg:py-16')}>
                    <div className="min-w-0 lg:col-span-7">{text}</div>
                    <div className="flex min-w-0 justify-center lg:col-span-5">
                        <SmartImage image={image} eager maxHeightClass="max-h-[80svh]" className="rounded-2xl" sizes="(min-width: 1024px) 40vw, 100vw" />
                    </div>
                </div>
                {bar}
            </section>
        );
    }

    if (layout === 'banner' && image) {
        return (
            <section id={section.id} className="relative scroll-mt-20 overflow-hidden bg-[#2A251F] text-white">
                <div className="grid">
                    {/* The whole image at its own ratio; on wide screens the text sits over its lower part. */}
                    <div className="col-start-1 row-start-1 flex justify-center self-start">
                        <SmartImage image={image} eager maxHeightClass="lg:max-h-[calc(100svh-65px)]" sizes="100vw" />
                    </div>
                    <div className="pointer-events-none col-start-1 row-start-1 hidden bg-[linear-gradient(to_top,rgba(14,12,9,0.88)_0%,rgba(14,12,9,0.55)_45%,rgba(14,12,9,0.1)_100%)] lg:block" />
                    <div className={cn(shell, 'relative col-start-1 row-start-2 py-12 sm:py-16 lg:row-start-1 lg:self-end lg:pt-40 lg:pb-14')}>{text}</div>
                </div>
                {bar}
            </section>
        );
    }

    return (
        <section id={section.id} className="relative flex scroll-mt-20 flex-col justify-end overflow-hidden bg-[#2A251F] text-white lg:min-h-[calc(100svh-65px)]">
            {layout === 'background' && image ? (
                <div className="absolute inset-0">
                    <SmartImage image={image} mode="fill" eager sizes="100vw" />
                </div>
            ) : (
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_85%_10%,rgba(138,103,0,0.22),transparent_55%),radial-gradient(ellipse_at_10%_90%,rgba(233,223,200,0.08),transparent_50%)]" />
            )}
            <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(to_top,rgba(14,12,9,0.88)_0%,rgba(14,12,9,0.55)_45%,rgba(14,12,9,0.15)_100%)]" />
            <div className={cn(shell, 'relative pt-24 pb-12 sm:pt-32 lg:pt-[120px] lg:pb-14')}>{text}</div>
            {bar && <div className="relative">{bar}</div>}
        </section>
    );
}
