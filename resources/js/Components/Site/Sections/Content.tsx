/**
 * The sections the owner writes: intro, image and text, gallery, service cards, video, testimonials, numbers,
 * FAQ and a call to action. Plain text only (React escapes it); line breaks the owner typed are kept.
 */
import { Plus } from 'lucide-react';
import { cn } from '@/lib/cn';
import { galleryCellSpan, imageOrientation, sideImageSpan } from '@/lib/smart-image';
import SmartImage from '../SmartImage';
import { SectionHeading, SectionShell, SiteButton, bodyText, hairline, isDark, metaText, twoDigits } from '../ui';
import { img, link, list, str, type SectionProps } from './types';

export function Intro({ section, joined }: SectionProps) {
    const d = section.data;
    const center = str(d.align) === 'center';
    return (
        <SectionShell section={section} joined={joined}>
            <div className={cn('flex max-w-[860px] flex-col gap-6', center && 'mx-auto items-center text-center')}>
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} align={center ? 'center' : 'start'} />
                {str(d.body) && <p className={cn('m-0 text-lg leading-9 text-pretty whitespace-pre-line', bodyText(section.tone))}>{str(d.body)}</p>}
                <SiteButton link={link(d.button)} tone={section.tone} className="mt-2" />
            </div>
        </SectionShell>
    );
}

const COL_SPAN: Record<number, string> = { 5: 'lg:col-span-5', 6: 'lg:col-span-6', 7: 'lg:col-span-7' };

export function ImageText({ section, joined }: SectionProps) {
    const d = section.data;
    const image = img(d.image);
    const text = (
        <div className="flex min-w-0 flex-col gap-6">
            <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
            {str(d.body) && <p className={cn('m-0 text-[17px] leading-9 text-pretty whitespace-pre-line', bodyText(section.tone))}>{str(d.body)}</p>}
            <SiteButton link={link(d.button)} tone={section.tone} className="mt-2 self-start" />
        </div>
    );
    if (!image) {
        return (
            <SectionShell section={section} joined={joined}>
                <div className="max-w-[860px]">{text}</div>
            </SectionShell>
        );
    }
    const span = sideImageSpan(image);
    const imageFirst = str(d.image_side) === 'start';
    const picture = (
        <div className={cn('flex min-w-0 justify-center', COL_SPAN[span.image])}>
            <SmartImage image={image} maxHeightClass={imageOrientation(image) === 'portrait' ? 'max-h-[80svh]' : undefined} className="rounded-2xl" sizes="(min-width: 1024px) 55vw, 100vw" />
        </div>
    );
    return (
        <SectionShell section={section} joined={joined}>
            <div className="grid items-center gap-10 lg:grid-cols-12 lg:gap-16">
                {imageFirst && picture}
                <div className={cn('min-w-0', COL_SPAN[span.text])}>{text}</div>
                {!imageFirst && picture}
            </div>
        </SectionShell>
    );
}

interface GalleryItem { id?: string; image?: unknown; caption?: string }

export function Gallery({ section, joined }: SectionProps) {
    const d = section.data;
    const items = list<GalleryItem>(d.items).map((it) => ({ ...it, picture: img(it.image) })).filter((it) => it.picture);
    const grid = str(d.layout) === 'grid';
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                {grid ? (
                    <div className="grid grid-flow-row-dense auto-rows-[160px] grid-cols-2 gap-3 sm:auto-rows-[200px] sm:gap-4 lg:auto-rows-[240px] lg:grid-cols-4">
                        {items.map((it, i) => {
                            const span = galleryCellSpan(it.picture!);
                            return (
                                <figure key={it.id ?? i} className={cn('m-0 flex min-w-0 flex-col gap-2', span.cols === 2 && 'col-span-2', span.rows === 2 && 'row-span-2')}>
                                    <div className={cn('min-h-0 flex-1 overflow-hidden rounded-2xl', isDark(section.tone) ? 'bg-white/5' : 'bg-[#F1EDE6]')}>
                                        <SmartImage image={it.picture!} mode="fill" sizes="(min-width: 1024px) 25vw, 50vw" />
                                    </div>
                                    {str(it.caption) && <figcaption className={cn('truncate text-sm', metaText(section.tone))}>{str(it.caption)}</figcaption>}
                                </figure>
                            );
                        })}
                    </div>
                ) : (
                    <div className="columns-1 gap-4 sm:columns-2 lg:columns-3">
                        {items.map((it, i) => (
                            <figure key={it.id ?? i} className="m-0 mb-4 flex break-inside-avoid flex-col gap-2">
                                <SmartImage image={it.picture!} className="rounded-2xl" sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw" />
                                {str(it.caption) && <figcaption className={cn('text-sm', metaText(section.tone))}>{str(it.caption)}</figcaption>}
                            </figure>
                        ))}
                    </div>
                )}
            </div>
        </SectionShell>
    );
}

interface ServiceItem { id?: string; title?: string; text?: string; image?: unknown }

export function Services({ section, joined }: SectionProps) {
    const d = section.data;
    const items = list<ServiceItem>(d.items).filter((it) => str(it.title) || str(it.text) || img(it.image));
    const asList = str(d.style) === 'list';
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10 lg:gap-12">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} subtitle={str(d.subtitle)} tone={section.tone} />
                {asList ? (
                    <div className="flex flex-col">
                        {items.map((it, i) => {
                            const picture = img(it.image);
                            return (
                                <article key={it.id ?? i} className={cn('grid gap-5 border-t py-8 sm:py-10 lg:grid-cols-12 lg:gap-12', hairline(section.tone))}>
                                    <div className="flex min-w-0 items-baseline gap-5 lg:col-span-4">
                                        <span dir="ltr" className="text-[15px] text-[var(--site-accent)]">{twoDigits(i + 1)}</span>
                                        <h3 className="m-0 text-[clamp(24px,2.6vw,34px)] leading-[1.2] font-bold break-words">{str(it.title)}</h3>
                                    </div>
                                    <p className={cn('m-0 min-w-0 text-[17px] leading-8 whitespace-pre-line', bodyText(section.tone), picture ? 'lg:col-span-4' : 'lg:col-span-8')}>{str(it.text)}</p>
                                    {picture && (
                                        <div className="min-w-0 lg:col-span-4">
                                            <SmartImage image={picture} maxHeightClass="max-h-[320px]" className="rounded-2xl" sizes="(min-width: 1024px) 30vw, 100vw" />
                                        </div>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                ) : (
                    <div className="grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                        {items.map((it, i) => {
                            const picture = img(it.image);
                            return (
                                <figure key={it.id ?? i} className={cn('m-0 flex min-w-0 flex-col gap-4', !picture && cn('border-t pt-6', hairline(section.tone)))}>
                                    {picture && (
                                        <div className={cn('overflow-hidden rounded-2xl', isDark(section.tone) ? 'bg-white/5' : 'bg-[#F1EDE6]')}>
                                            <SmartImage image={picture} maxHeightClass="max-h-[360px]" sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw" />
                                        </div>
                                    )}
                                    <figcaption className="flex flex-col gap-1.5">
                                        {!picture && <span className="text-sm text-[var(--site-accent)]">{twoDigits(i + 1)}</span>}
                                        <span className="text-lg font-semibold break-words">{str(it.title)}</span>
                                        {str(it.text) && <span className={cn('text-[15px] leading-7 whitespace-pre-line', metaText(section.tone))}>{str(it.text)}</span>}
                                    </figcaption>
                                </figure>
                            );
                        })}
                    </div>
                )}
            </div>
        </SectionShell>
    );
}

export function Video({ section, joined }: SectionProps) {
    const d = section.data;
    const embed = typeof section.feed?.embed_url === 'string' ? (section.feed.embed_url as string) : '';
    return (
        <SectionShell section={section} joined={joined}>
            <div className="grid gap-10 lg:grid-cols-12 lg:items-center lg:gap-16">
                <div className="flex min-w-0 flex-col gap-5 lg:col-span-4">
                    <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                    {str(d.body) && <p className={cn('m-0 text-[17px] leading-8 whitespace-pre-line', bodyText(section.tone))}>{str(d.body)}</p>}
                </div>
                {embed && (
                    <div className="min-w-0 lg:col-span-8">
                        <div className="aspect-video w-full overflow-hidden rounded-2xl bg-black">
                            <iframe
                                src={embed}
                                title={str(d.title) || 'فيديو'}
                                loading="lazy"
                                className="size-full border-0"
                                allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                                allowFullScreen
                                referrerPolicy="strict-origin-when-cross-origin"
                            />
                        </div>
                    </div>
                )}
            </div>
        </SectionShell>
    );
}

interface Quote { id?: string; quote?: string; name?: string; meta?: string }

export function Testimonials({ section, joined }: SectionProps) {
    const d = section.data;
    const items = list<Quote>(d.items).filter((q) => str(q.quote));
    const [first, ...rest] = items;
    const by = (q: Quote) => (str(q.name) || str(q.meta)) && (
        <figcaption className="text-base">
            {str(q.name) && <span className="font-semibold">{str(q.name)}</span>}
            {str(q.meta) && <span className={metaText(section.tone)}>{str(q.name) ? ' · ' : ''}{str(q.meta)}</span>}
        </figcaption>
    );
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-12 lg:gap-14">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                {first && (
                    <figure className="m-0 flex max-w-[1000px] flex-col gap-6">
                        <blockquote className="m-0 text-[clamp(24px,3.2vw,42px)] leading-[1.5] font-medium text-pretty whitespace-pre-line">{str(first.quote)}</blockquote>
                        {by(first)}
                    </figure>
                )}
                {rest.length > 0 && (
                    <div className="grid gap-x-20 gap-y-10 md:grid-cols-2">
                        {rest.map((q, i) => (
                            <figure key={q.id ?? i} className={cn('m-0 flex flex-col gap-3.5 border-t pt-6', hairline(section.tone))}>
                                <blockquote className="m-0 text-lg leading-[1.8] text-pretty whitespace-pre-line">{str(q.quote)}</blockquote>
                                {by(q)}
                            </figure>
                        ))}
                    </div>
                )}
            </div>
        </SectionShell>
    );
}

interface Stat { id?: string; value?: string; label?: string }

export function Stats({ section, joined }: SectionProps) {
    const d = section.data;
    const items = list<Stat>(d.items).filter((s) => str(s.value) || str(s.label));
    const dark = isDark(section.tone);
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                <dl className={cn('m-0 grid grid-cols-2 gap-x-6 gap-y-8 lg:grid-cols-4', items.length === 3 && 'lg:grid-cols-3', items.length > 4 && 'lg:grid-cols-3 xl:grid-cols-6')}>
                    {items.map((s, i) => (
                        <div key={s.id ?? i} className={cn('flex min-w-0 flex-col gap-1 border-t pt-5', hairline(section.tone))}>
                            <dt className={cn('order-2 text-[15px]', metaText(section.tone))}>{str(s.label)}</dt>
                            <dd className={cn('m-0 text-[clamp(36px,4.5vw,60px)] leading-none font-bold [unicode-bidi:plaintext] break-words', dark ? 'text-[#E9DFC8]' : 'text-[var(--site-accent)]')}>{str(s.value)}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </SectionShell>
    );
}

interface Faq { id?: string; q?: string; a?: string }

export function FaqSection({ section, joined }: SectionProps) {
    const d = section.data;
    const items = list<Faq>(d.items).filter((f) => str(f.q));
    return (
        <SectionShell section={section} joined={joined}>
            <div className="grid gap-10 lg:grid-cols-12 lg:gap-16">
                <div className="min-w-0 lg:col-span-4">
                    <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                </div>
                <div className={cn('flex min-w-0 flex-col border-b lg:col-span-8', hairline(section.tone))}>
                    {items.map((f, i) => (
                        <details key={f.id ?? i} className={cn('group border-t', hairline(section.tone))}>
                            <summary className="flex cursor-pointer list-none items-center justify-between gap-6 py-5 text-lg font-semibold [&::-webkit-details-marker]:hidden">
                                <span className="min-w-0 break-words">{str(f.q)}</span>
                                <Plus className="size-5 shrink-0 text-[var(--site-accent)] transition-transform group-open:rotate-45" aria-hidden />
                            </summary>
                            {str(f.a) && <p className={cn('m-0 pb-6 text-base leading-8 whitespace-pre-line', bodyText(section.tone))}>{str(f.a)}</p>}
                        </details>
                    ))}
                </div>
            </div>
        </SectionShell>
    );
}

export function Cta({ section, joined }: SectionProps) {
    const d = section.data;
    return (
        <SectionShell section={section} joined={joined}>
            <div className="grid gap-8 lg:grid-cols-2 lg:items-end lg:gap-20">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                <div className="flex max-w-[480px] min-w-0 flex-col gap-7">
                    {str(d.body) && <p className={cn('m-0 text-lg leading-8 whitespace-pre-line', bodyText(section.tone))}>{str(d.body)}</p>}
                    <div className="flex flex-wrap items-center gap-x-7 gap-y-4">
                        <SiteButton link={link(d.primary)} tone={section.tone} />
                        <SiteButton link={link(d.secondary)} tone={section.tone} variant="underline" />
                    </div>
                </div>
            </div>
        </SectionShell>
    );
}
