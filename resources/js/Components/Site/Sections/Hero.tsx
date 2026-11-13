import { useShared } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { useT } from '@/lib/i18n';
import { heroLayout } from '@/lib/smart-image';
import SmartImage from '../SmartImage';
import { SiteButton } from '../ui';
import { img, link, list, str, type SectionProps } from './types';

interface Fact { id?: string; title?: string; text?: string }
interface NextClass { name: string; date: string; time: string; day_label?: string }

/**
 * The hero of the approved design: dark, the title with its second part in gold, the description, a gold and an
 * outline button, the facts in a row, and the next class in a small card at the far side.
 * The layout follows the image (lib/smart-image heroLayout): none → text on dark, as tall as the text needs; a wide image → shown whole as a
 * banner with the text over its lower part (under it on phones); a tall or square one → beside the text; only the
 * owner's «ملء المساحة» makes it a full-bleed background that may trim its edges.
 */
export default function Hero({ section }: SectionProps) {
    const { tenant } = useShared();
    const t = useT();
    const d = section.data;
    const image = img(d.image);
    const layout = heroLayout(image);
    const facts = list<Fact>(d.facts).filter((f) => str(f.title) || str(f.text));
    const next = (d.show_next_class !== false ? (section.feed?.next_class as NextClass | null | undefined) : null) ?? null;
    const title = str(d.title);
    const title2 = str(d.title_2);
    const heading = title || title2 ? [title, title2].filter(Boolean) : [tenant?.name ?? ''];

    // The approved design: an eyebrow after a short gold rule, the title with its second part in gold, the
    // description, a gold and an outline pill, then the facts in a row under a hairline.
    const text = (
        <div className={cn('flex w-full min-w-0 flex-col gap-6', layout === 'split' ? '' : 'max-w-[640px]')}>
            {str(d.eyebrow) && (
                <span className="flex items-center gap-3 text-[15px] font-medium text-[#E2C27A]">
                    <span aria-hidden className="h-px w-8 shrink-0 bg-[#C9A042]" />
                    {str(d.eyebrow)}
                </span>
            )}
            <h1 className={cn('m-0 font-bold leading-[1.15] text-balance break-words ltr:leading-[1.05]', layout === 'split' ? 'text-[clamp(40px,5.5vw,80px)]' : 'text-[clamp(42px,6.5vw,92px)]')}>
                {heading[0]}
                {heading[1] && <> <span className="text-[var(--site-gold)]">{heading[1]}</span></>}
            </h1>
            {str(d.subtitle) && <p className="m-0 max-w-[560px] text-lg leading-8 text-pretty whitespace-pre-line text-[#E9E2D5]">{str(d.subtitle)}</p>}
            {(link(d.primary)?.label || link(d.secondary)?.label) && (
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <SiteButton link={link(d.primary)} tone="dark" className="h-[54px] px-8 text-base" />
                    <SiteButton link={link(d.secondary)} tone="dark" variant="outline" className="h-[54px] px-8 text-base" />
                </div>
            )}
            {facts.length > 0 && (
                <dl className="m-0 mt-4 grid grid-cols-2 gap-x-8 gap-y-5 border-t border-white/15 pt-6 sm:grid-cols-3">
                    {facts.map((f, i) => (
                        <div key={f.id ?? i} className="flex min-w-0 flex-col gap-0.5">
                            <dt className="text-[17px] font-semibold break-words text-white">{str(f.title)}</dt>
                            {str(f.text) && <dd className="m-0 text-sm text-[#BDB4A3]">{str(f.text)}</dd>}
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );

    // The next class, as the design's small white card at the far side of the hero.
    const bar = next && (
        <div className="flex items-center gap-3.5 self-start rounded-[20px] bg-[#F8F5EF] p-3.5 pe-6 text-[var(--site-ink)] shadow-[0_18px_40px_rgba(0,0,0,0.25)] lg:self-end">
            <span dir="ltr" className="grid h-14 min-w-14 place-items-center rounded-[14px] bg-[var(--site-ink)] px-2 text-[17px] font-bold text-[var(--site-gold)] [unicode-bidi:isolate]">{next.time}</span>
            <span className="flex min-w-0 flex-col">
                <span className="text-xs text-[#7A7060]">{t('الحصة القادمة')}{next.day_label ? ` · ${next.day_label}` : ''}</span>
                <span className="text-base font-bold break-words">{next.name}</span>
            </span>
        </div>
    );

    const shell = 'mx-auto w-full max-w-[1360px] px-5 sm:px-10';

    if (layout === 'split' && image) {
        return (
            <section id={section.id} className="relative scroll-mt-20 overflow-hidden bg-[#2A251F] text-white">
                <div className={cn(shell, 'grid items-center gap-10 py-14 sm:py-20 lg:min-h-[calc(100svh-65px)] lg:grid-cols-12 lg:gap-16 lg:py-16')}>
                    <div className="flex min-w-0 flex-col gap-8 lg:col-span-7">{text}{bar}</div>
                    <div className="flex min-w-0 justify-center lg:col-span-5">
                        <SmartImage image={image} eager maxHeightClass="max-h-[80svh]" className="rounded-2xl" sizes="(min-width: 1024px) 40vw, 100vw" />
                    </div>
                </div>
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
                    <div className={cn(shell, 'relative col-start-1 row-start-2 flex flex-col gap-8 py-12 sm:py-16 lg:row-start-1 lg:flex-row lg:items-end lg:justify-between lg:self-end lg:pt-40 lg:pb-14')}>{text}{bar}</div>
                </div>
            </section>
        );
    }

    return (
        // Without an image the space kept for the photo would be an empty dark screen on a computer, so the band is
        // only as tall as its text needs (the same colours and type); with an image it is the full screen, as in v2.
        <section
            id={section.id}
            className={cn(
                'relative flex scroll-mt-20 flex-col justify-end overflow-hidden bg-[#2A251F] text-white',
                layout === 'background' && image ? 'lg:min-h-[calc(100svh-65px)]' : 'lg:min-h-[min(calc(100svh-65px),640px)]',
            )}
        >
            {layout === 'background' && image ? (
                <div className="absolute inset-0">
                    <SmartImage image={image} mode="fill" eager sizes="100vw" />
                </div>
            ) : (
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_85%_10%,rgba(138,103,0,0.22),transparent_55%),radial-gradient(ellipse_at_10%_90%,rgba(233,223,200,0.08),transparent_50%)]" />
            )}
            <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(to_top,rgba(14,12,9,0.88)_0%,rgba(14,12,9,0.55)_45%,rgba(14,12,9,0.15)_100%)]" />
            <div className={cn(shell, 'relative flex flex-col gap-10 pt-24 pb-14 sm:pt-32 lg:flex-row lg:items-end lg:justify-between lg:pt-[120px] lg:pb-16')}>{text}{bar}</div>
        </section>
    );
}
