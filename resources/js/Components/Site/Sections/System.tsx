/**
 * The sections filled from the gym system: programs, timetable, trainers, plans, products, offers and news,
 * sign-up and contact. The server sends only what visitors may see (published plans, public programs and trainers,
 * products on the website, published posts); the owner sets the headings and how many to show.
 */
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { CATEGORIES, LEVELS } from '@/Pages/Site/Programs';
import { cn } from '@/lib/cn';
import { currencyLabel, date as fmtDate, phone as fmtPhone, whatsappUrl } from '@/lib/format';
import { useT } from '@/lib/i18n';
import type { PlanFeed, PostFeed, ProductFeed, ProgramFeed, SessionFeed, TrainerFeed } from '@/types/site';
import { Diamond, PhotoFrame, SectionHeading, SectionShell, bodyText, hairline, isDark, metaText, priceText, siteCard, twoDigits } from '../ui';
import { bool, link, str, type SectionProps } from './types';

const arr = <T,>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : []);
const url = (v: unknown): string => (typeof v === 'string' ? v : '');

type Translate = ReturnType<typeof useT>;

/** A quiet «كل …» link with an arrow, under or beside a list. */
function MoreLink({ href, label, tone }: { href: string; label: string; tone: SectionProps['section']['tone'] }) {
    if (!href || !label) return null;
    return (
        <a href={href} className={cn('inline-flex items-center gap-2 border-b pb-0.5 text-[15px] font-medium transition-colors', isDark(tone) ? 'border-white/60 text-white hover:text-[#E9DFC8]' : 'border-[var(--site-ink)] hover:border-[var(--site-accent)] hover:text-[var(--site-accent)]')}>
            {label} <ArrowLeft className="size-4 ltr:rotate-180" aria-hidden />
        </a>
    );
}

export function Programs({ section, joined }: SectionProps) {
    const t = useT();
    const d = section.data;
    const programs = arr<ProgramFeed>(section.feed?.programs);
    const more = link(d.button);
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10 lg:gap-12">
                <div className="grid gap-6 lg:grid-cols-2 lg:items-end lg:gap-20">
                    <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} />
                    {str(d.subtitle) && <p className={cn('m-0 max-w-[460px] text-[17px] leading-8 whitespace-pre-line', bodyText(section.tone))}>{str(d.subtitle)}</p>}
                </div>
                <div className={cn('grid gap-5', programs.length > 1 && 'md:grid-cols-2', programs.length > 2 && 'lg:grid-cols-3')}>
                    {programs.map((p, i) => {
                        const meta = [p.category ? (CATEGORIES[p.category] ? t(CATEGORIES[p.category]) : p.category) : null, p.level ? (LEVELS[p.level] ? t(LEVELS[p.level]) : p.level) : null, p.duration_minutes ? t(':count دقيقة', { count: p.duration_minutes }) : null].filter(Boolean) as string[];
                        return (
                            <article key={p.id} className={cn(siteCard(section.tone), 'flex min-w-0 flex-col p-2.5')}>
                                <div className="relative">
                                    {p.image_url ? <PhotoFrame src={p.image_url} alt={p.name} className="aspect-[4/3] w-full" /> : <div className="h-14" />}
                                    <span dir="ltr" className="absolute start-3 bottom-3 grid h-8 min-w-8 place-items-center rounded-full bg-white px-2 text-sm font-semibold text-[var(--site-accent)] shadow-sm">{twoDigits(i + 1)}</span>
                                </div>
                                <div className="flex flex-1 flex-col gap-3 px-4 pt-5 pb-5 sm:px-5">
                                    <h3 className="m-0 text-[24px] leading-[1.3] font-bold break-words">{p.name}</h3>
                                    {p.description && <p className={cn('m-0 text-[15px] leading-7 text-pretty whitespace-pre-line', bodyText(section.tone))}>{p.description}</p>}
                                    {meta.length > 0 && (
                                        <ul className={cn('m-0 mt-auto flex list-none flex-col gap-2 border-t p-0 pt-4', hairline(section.tone))}>
                                            {meta.map((m) => <li key={m} className="flex gap-2.5 text-sm font-medium"><Diamond />{m}</li>)}
                                        </ul>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>
                {more && <div><MoreLink href={more.href} label={more.label} tone={section.tone} /></div>}
            </div>
        </SectionShell>
    );
}

interface Day { date: string; label: string; sessions: SessionFeed[] }

function spots(s: SessionFeed, t: Translate): { text: string; tight: boolean } {
    if (s.full || s.spots_left === 0) return { text: t('مكتملة'), tight: true };
    if (s.spots_left === null || s.spots_left === undefined) return { text: '', tight: false };
    if (s.spots_left <= 3) return { text: s.spots_left === 1 ? t('بقي مقعد واحد') : t('بقي :count مقاعد', { count: s.spots_left }), tight: true };
    return { text: t(':count مقعدًا متاحًا', { count: s.spots_left }), tight: false };
}

export function Schedule({ section, joined }: SectionProps) {
    const t = useT();
    const d = section.data;
    const days = arr<Day>(section.feed?.days);
    const firstBusy = Math.max(0, days.findIndex((day) => day.sessions.length > 0));
    const [picked, setPicked] = useState(firstBusy);
    const day = days[picked] ?? days[0];
    const bookingUrl = url(section.feed?.booking_url);
    const showTrainer = bool(d.show_trainer, true);
    const tone = section.tone;

    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-8 lg:gap-9">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={tone} aside={<MoreLink href={url(section.feed?.schedule_url)} label={t('الجدول الكامل')} tone={tone} />} />
                <div className={cn('flex gap-1 overflow-x-auto rounded-[18px] p-1.5', isDark(tone) ? 'bg-white/5' : 'bg-[#EEE8DC]')} role="tablist" aria-label={t('أيام الجدول')}>
                    {days.map((dd, i) => (
                        <button
                            key={dd.date}
                            type="button"
                            role="tab"
                            aria-selected={i === picked}
                            onClick={() => setPicked(i)}
                            className={cn('flex min-w-[96px] flex-none cursor-pointer flex-col items-center rounded-[14px] px-3 py-3 text-center transition-colors sm:flex-1',
                                i === picked ? 'bg-[var(--site-ink)] text-white' : cn('bg-transparent', isDark(tone) ? 'text-[#CFC7B8] hover:text-white' : 'text-[#5E5648] hover:text-[var(--site-ink)]'))}
                        >
                            <span className="text-base font-semibold whitespace-nowrap">{dd.label}</span>
                            <span className="text-xs opacity-75">{dd.sessions.length === 0 ? t('لا حصص') : dd.sessions.length === 1 ? t('حصة واحدة') : dd.sessions.length === 2 ? t('حصتان') : t(':count حصص', { count: dd.sessions.length })}</span>
                        </button>
                    ))}
                </div>
                <div className="flex flex-col">
                    {(day?.sessions ?? []).map((s) => {
                        const sp = spots(s, t);
                        const closed = s.full || s.spots_left === 0;
                        return (
                            <div key={s.id} className={cn('grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-5 gap-y-3 border-b py-4 sm:py-[18px] md:grid-cols-[90px_minmax(180px,2fr)_minmax(120px,1fr)_minmax(110px,1fr)_auto] md:gap-x-6', hairline(tone))}>
                                <span dir="ltr" className="text-xl font-bold [unicode-bidi:isolate]">{s.time}</span>
                                <div className="flex min-w-0 flex-col">
                                    <span className="text-lg font-semibold break-words">{s.program ?? t('حصة')}</span>
                                    <span className={cn('text-sm', metaText(tone))}>
                                        <span dir="ltr" className="[unicode-bidi:isolate]">{s.time} – {s.end_time}</span>
                                        {showTrainer && s.trainer && <span className="md:hidden"> · {s.trainer}</span>}
                                    </span>
                                </div>
                                <span className={cn('hidden text-[15px] md:block', isDark(tone) ? 'text-[#CFC7B8]' : 'text-[#3A342B]')}>{showTrainer ? s.trainer ?? '' : ''}</span>
                                <span className={cn('col-start-2 text-sm md:col-start-auto', sp.tight ? 'font-semibold text-[var(--site-accent)]' : metaText(tone))}>{sp.text}</span>
                                {bookingUrl && (
                                    <a
                                        href={closed ? undefined : bookingUrl}
                                        aria-disabled={closed}
                                        className={cn('col-span-2 inline-flex h-[42px] min-w-[104px] items-center justify-center rounded-full border px-[18px] text-sm font-semibold transition-colors md:col-span-1',
                                            closed ? 'cursor-not-allowed border-[rgba(22,19,15,0.15)] text-[#9A9284]' : 'border-[var(--site-ink)] bg-[var(--site-ink)] text-white hover:border-[var(--site-gold)] hover:bg-[var(--site-gold)] hover:text-[var(--site-ink)]')}
                                    >
                                        {closed ? t('مكتملة') : t('احجزي')}
                                    </a>
                                )}
                            </div>
                        );
                    })}
                    {(day?.sessions ?? []).length === 0 && <p className={cn('m-0 py-12', metaText(tone))}>{t('لا توجد حصص في هذا اليوم.')}</p>}
                </div>
            </div>
        </SectionShell>
    );
}

export function Trainers({ section, joined }: SectionProps) {
    const d = section.data;
    const trainers = arr<TrainerFeed>(section.feed?.trainers);
    if (trainers.length === 0) return null;
    const role = (t: TrainerFeed) => (t.specialties ?? [])[0] ?? '';
    const tags = (t: TrainerFeed) => (t.specialties ?? []).slice(1);
    const tone = section.tone;
    // No photo is invented: a trainer without one gets her initial on the soft frame, and when none has a photo
    // the frames go and the initials sit in small circles instead.
    const anyPhoto = trainers.some((t) => !!t.photo_url);
    const initial = (t: TrainerFeed) => t.name.replace(/^(الكوتش|كوتش|المدربة|Coach)\s+/u, '').trim().charAt(0);
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10 lg:gap-12">
                <div className="grid gap-6 lg:grid-cols-2 lg:items-end lg:gap-20">
                    <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={tone} />
                    {str(d.subtitle) && <p className={cn('m-0 max-w-[460px] text-[17px] leading-8 whitespace-pre-line', bodyText(tone))}>{str(d.subtitle)}</p>}
                </div>
                {/* The design's row of trainer cards: the whole photo, the name, the role in gold, then her specialties as pills. */}
                <div className="grid grid-cols-2 gap-x-4 gap-y-9 sm:gap-x-5 lg:grid-cols-4">
                    {trainers.map((t) => (
                        <article key={t.id} className="flex min-w-0 flex-col gap-3">
                            {t.photo_url ? (
                                <PhotoFrame src={t.photo_url} alt={t.name} className="aspect-[4/5] w-full" />
                            ) : (
                                <span aria-hidden className={cn('grid place-items-center font-bold text-[var(--site-gold)]', anyPhoto ? 'aspect-[4/5] w-full rounded-[18px] text-[56px]' : 'size-16 rounded-full text-2xl', isDark(tone) ? 'bg-white/5' : anyPhoto ? 'bg-[#F1ECE3]' : 'bg-[#1A1612]')}>{initial(t)}</span>
                            )}
                            <div className="flex flex-col gap-0.5 px-0.5">
                                <h3 className="m-0 text-[19px] font-bold break-words sm:text-[21px]">{t.name}</h3>
                                {role(t) && <span className="text-[15px] text-[var(--site-accent)]">{role(t)}</span>}
                            </div>
                            {tags(t).length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {tags(t).map((tag) => <span key={tag} className={cn('rounded-full border px-2.5 py-0.5 text-xs', isDark(tone) ? 'border-white/20 text-[#CFC7B8]' : 'border-[rgba(26,22,18,0.14)] text-[#5E5648]')}>{tag}</span>)}
                                </div>
                            )}
                            {!tags(t).length && t.bio && <p className={cn('m-0 line-clamp-3 text-sm leading-6', metaText(tone))}>{t.bio}</p>}
                        </article>
                    ))}
                </div>
            </div>
        </SectionShell>
    );
}

/** The plans as the design's cards: white with a faint ring; the featured one dark with a gold badge and a gold button. */
export function PlansGrid({ plans, currency, featuredLabel, buttonLabel }: { plans: PlanFeed[]; currency: string; featuredLabel: string; buttonLabel: string }) {
    const t = useT();
    return (
        <div className={cn('grid items-stretch gap-5', plans.length > 1 && 'md:grid-cols-2', plans.length > 2 && 'lg:grid-cols-3', plans.length === 1 && 'max-w-md')}>
            {plans.map((p) => {
                const featured = p.is_featured;
                return (
                    <article key={p.id} className={cn('flex min-w-0 flex-col gap-5 rounded-[24px] px-6 pt-8 pb-7 sm:px-8', featured ? 'bg-[#1A1612] text-white shadow-[0_24px_50px_rgba(26,22,18,0.18)]' : 'bg-white text-[var(--site-ink)] ring-1 ring-[rgba(26,22,18,0.08)]')}>
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="m-0 text-[22px] font-bold break-words">{p.name}</h3>
                            {featured && featuredLabel && <span className="rounded-full bg-[var(--site-gold)] px-3 py-1 text-xs font-semibold whitespace-nowrap text-[var(--site-ink)]">{featuredLabel}</span>}
                        </div>
                        {p.description && <p className={cn('m-0 min-h-[3rem] text-[15px] leading-7 whitespace-pre-line', featured ? 'text-[#CFC7B8]' : 'text-[#5E5648]')}>{p.description}</p>}
                        <div className={cn('flex flex-col gap-0.5 border-b pb-5', featured ? 'border-white/10' : 'border-[rgba(26,22,18,0.08)]')}>
                            <div className="flex flex-wrap items-baseline gap-2">
                                <span dir="ltr" className="text-[clamp(44px,4.8vw,56px)] leading-[1.1] font-bold [unicode-bidi:isolate]">{priceText(p.price)}</span>
                                <span className={cn('text-[15px]', featured ? 'text-[#CFC7B8]' : 'text-[#6E6555]')}>{currencyLabel(currency)}{p.duration_label ? ` / ${p.duration_label}` : ''}</span>
                            </div>
                            {p.signup_fee && Number(p.signup_fee) > 0 && <span className={cn('text-[13px]', featured ? 'text-[#9A9284]' : 'text-[#7A7060]')}>{t('رسوم تسجيل لمرة واحدة:')} <span dir="ltr" className="[unicode-bidi:isolate]">{priceText(p.signup_fee)}</span> {currencyLabel(currency)}</span>}
                        </div>
                        <ul className="m-0 flex flex-1 list-none flex-col gap-2.5 p-0">
                            {(p.features ?? []).filter(Boolean).map((f, i) => (
                                <li key={i} className="flex gap-2.5 text-[15px] font-medium"><Diamond />{f}</li>
                            ))}
                        </ul>
                        <a href={p.join_url} className={cn('mt-2 inline-flex h-[52px] items-center justify-center rounded-full border text-[15px] font-semibold transition-colors', featured ? 'border-[var(--site-gold)] bg-[var(--site-gold)] text-[var(--site-ink)] hover:border-[#DDB85C] hover:bg-[#DDB85C]' : 'border-[rgba(26,22,18,0.2)] bg-white text-[var(--site-ink)] hover:border-[var(--site-ink)] hover:bg-[var(--site-ink)] hover:text-white')}>
                            {buttonLabel || t('اختاري الباقة')}
                        </a>
                    </article>
                );
            })}
        </div>
    );
}

export function Plans({ section, joined }: SectionProps) {
    const d = section.data;
    const plans = arr<PlanFeed>(section.feed?.plans);
    const more = link(d.button);
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10 lg:gap-12">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} subtitle={str(d.subtitle)} tone={section.tone} align="center" />
                <PlansGrid plans={plans} currency={str(section.feed?.currency) || 'OMR'} featuredLabel={str(d.featured_label)} buttonLabel={str(d.button_label)} />
                {more && <div className="flex justify-center"><MoreLink href={more.href} label={more.label} tone={section.tone} /></div>}
            </div>
        </SectionShell>
    );
}

export function Products({ section, joined }: SectionProps) {
    const t = useT();
    const d = section.data;
    const products = arr<ProductFeed>(section.feed?.products);
    const currency = str(section.feed?.currency) || 'OMR';
    const tone = section.tone;
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={tone} aside={<MoreLink href={url(section.feed?.shop_url)} label={t('تسوّقي من المتجر')} tone={tone} />} />
                <div className="grid grid-cols-2 gap-x-4 gap-y-8 sm:gap-x-6 lg:grid-cols-4">
                    {products.map((p) => (
                        <a key={p.id} href={p.url} className="group flex min-w-0 flex-col gap-3">
                            {p.image_url ? (
                                <PhotoFrame src={p.image_url} alt={p.name} className="aspect-square w-full transition-opacity group-hover:opacity-90" />
                            ) : (
                                <div className={cn('border-t', hairline(tone))} />
                            )}
                            <span className="text-base font-semibold break-words group-hover:text-[var(--site-accent)]">{p.name}</span>
                            <span className={cn('text-sm', metaText(tone))}>
                                <span dir="ltr" className="[unicode-bidi:isolate]">{priceText(p.price)}</span> {currencyLabel(currency)}
                                {!p.in_stock && <span className="text-[#A33A22]"> · {t('نفدت الكمية')}</span>}
                            </span>
                        </a>
                    ))}
                </div>
            </div>
        </SectionShell>
    );
}

/** A post (offer or news) in the v2 style: whole photo on a soft frame when there is one, then the words. */
export function PostTile({ post }: { post: Pick<PostFeed, 'type' | 'title' | 'excerpt' | 'image_url' | 'published_at' | 'ends_on' | 'url'> }) {
    const t = useT();
    const offer = post.type === 'offer';
    return (
        <a href={post.url} className="group flex min-w-0 flex-col gap-4">
            {post.image_url ? <PhotoFrame src={post.image_url} alt={post.title} className="aspect-[16/10] w-full transition-opacity group-hover:opacity-90" /> : <div className="border-t border-[rgba(22,19,15,0.14)]" />}
            <div className="flex flex-col gap-2">
                <span className="text-[13px] font-semibold text-[var(--site-accent)]">{offer ? t('عرض') : t('خبر')}</span>
                <h3 className="m-0 text-xl leading-8 font-bold break-words group-hover:text-[var(--site-accent)]">{post.title}</h3>
                {post.excerpt && <p className="m-0 line-clamp-2 text-[15px] leading-7 text-[#4A443A]">{post.excerpt}</p>}
                <span className="text-[13px] text-[#6B6356]">{offer && post.ends_on ? t('ساري حتى :date', { date: fmtDate(post.ends_on) }) : fmtDate(post.published_at)}</span>
            </div>
        </a>
    );
}

export function Posts({ section, joined }: SectionProps) {
    const t = useT();
    const d = section.data;
    const posts = arr<PostFeed>(section.feed?.posts);
    return (
        <SectionShell section={section} joined={joined}>
            <div className="flex flex-col gap-10">
                <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={section.tone} aside={<MoreLink href={url(section.feed?.all_url)} label={t('كل العروض والأخبار')} tone={section.tone} />} />
                <div className="grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">{posts.map((p) => <PostTile key={p.id} post={p} />)}</div>
            </div>
        </SectionShell>
    );
}

interface Step { id?: string; text?: string }

export function Join({ section, site, joined }: SectionProps) {
    const t = useT();
    const d = section.data;
    const steps = arr<Step>(d.steps).filter((s) => str(s.text));
    const plans = bool(d.show_plans, true) ? arr<PlanFeed>(section.feed?.plans) : [];
    const joinUrl = url(section.feed?.join_url);
    const currency = str(section.feed?.currency) || 'OMR';
    const [chosen, setChosen] = useState<number | null>(plans.find((p) => p.is_featured)?.id ?? plans[0]?.id ?? null);
    const target = plans.find((p) => p.id === chosen)?.join_url ?? joinUrl;
    const tone = section.tone;

    return (
        <SectionShell section={section} joined={joined}>
            <div className="grid items-start gap-12 lg:grid-cols-2 lg:gap-24">
                <div className="flex min-w-0 flex-col gap-5">
                    <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} subtitle={str(d.subtitle)} tone={tone} />
                    {steps.length > 0 && (
                        <ol className="m-0 mt-4 flex list-none flex-col p-0">
                            {steps.map((s, i) => (
                                <li key={s.id ?? i} className={cn('flex items-center gap-4 border-t py-3.5', hairline(tone))}>
                                    <span className={cn('grid size-8 shrink-0 place-items-center rounded-full text-sm font-semibold', i === 0 ? 'bg-[var(--site-gold)] text-[var(--site-ink)]' : cn('border', isDark(tone) ? 'border-white/25 text-[#CFC7B8]' : 'border-[rgba(26,22,18,0.2)] text-[#5E5648]'))}>{i + 1}</span>
                                    <span className="text-base font-semibold">{str(s.text)}</span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
                {site.signup_enabled && joinUrl && (
                    <div className={cn('flex min-w-0 flex-col gap-5 rounded-[28px] p-6 sm:p-9', isDark(tone) ? 'bg-white/5 ring-1 ring-white/10' : 'bg-[#F8F5EF] ring-1 ring-[rgba(26,22,18,0.08)]')}>
                        {plans.length > 0 && (
                            <>
                                <h3 className="m-0 text-[22px] font-bold">{t('اختاري باقتكِ')}</h3>
                                <div className="flex flex-col gap-2.5" role="radiogroup" aria-label={t('الباقات')}>
                                    {plans.map((p) => (
                                        <button
                                            key={p.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={chosen === p.id}
                                            onClick={() => setChosen(p.id)}
                                            className={cn('flex cursor-pointer items-center gap-3.5 rounded-2xl border bg-white p-4 text-start text-[var(--site-ink)] sm:p-[18px]', chosen === p.id ? 'border-[var(--site-gold)] ring-1 ring-[var(--site-gold)]' : 'border-[rgba(22,19,15,0.12)] hover:border-[rgba(22,19,15,0.3)]')}
                                        >
                                            <span className="flex size-[18px] flex-none items-center justify-center rounded-full border border-[var(--site-ink)]">
                                                <span className={cn('size-2 rounded-full', chosen === p.id ? 'bg-[var(--site-gold)]' : 'bg-transparent')} />
                                            </span>
                                            <span className="min-w-0 flex-1 text-base font-semibold break-words">{p.name}</span>
                                            <span className="text-[15px] whitespace-nowrap text-[#4A443A]"><span dir="ltr" className="[unicode-bidi:isolate]">{priceText(p.price)}</span> {currencyLabel(currency)}</span>
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}
                        <a href={target} className="inline-flex h-[52px] items-center justify-center rounded-full bg-[var(--site-gold)] px-9 text-[15px] font-semibold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] sm:self-start">
                            {str(d.button_label) || t('ابدئي التسجيل')}
                        </a>
                        <p className={cn('m-0 text-[13px]', metaText(tone))}>{t('تكملين بياناتكِ في صفحة التسجيل.')}</p>
                    </div>
                )}
            </div>
        </SectionShell>
    );
}

const SOCIAL: Record<string, string> = { instagram: 'إنستغرام', x: 'إكس', tiktok: 'تيك توك', snapchat: 'سناب شات', facebook: 'فيسبوك', youtube: 'يوتيوب' };

export function Contact({ section, site, chrome, joined }: SectionProps) {
    const t = useT();
    const d = section.data;
    const tone = section.tone;
    const sendUrl = url(section.feed?.send_url);
    const preview = !!chrome?.preview;
    const form = useForm({ name: '', phone: '', message: '', website: '' });
    const [sent, setSent] = useState(false);
    const hours = bool(d.show_hours, true) ? site.working_hours ?? [] : [];
    const social = useMemo(() => Object.entries(site.social_links ?? {}).filter(([, v]) => !!v) as [string, string][], [site.social_links]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (preview || !sendUrl) return;
        form.post(sendUrl, { preserveScroll: true, onSuccess: () => { form.reset(); setSent(true); } });
    };

    const info: { k: string; v: ReactNode }[] = [];
    if (site.contact_phone) info.push({ k: t('الهاتف'), v: <a href={`tel:+${site.contact_phone}`} dir="ltr" className="[unicode-bidi:isolate] hover:text-[var(--site-accent)]">{fmtPhone(site.contact_phone)}</a> });
    if (site.whatsapp_number) info.push({ k: t('واتساب'), v: <a href={whatsappUrl(site.whatsapp_number)} target="_blank" rel="noopener noreferrer" dir="ltr" className="[unicode-bidi:isolate] hover:text-[var(--site-accent)]">{fmtPhone(site.whatsapp_number)}</a> });
    if (site.contact_email) info.push({ k: t('البريد الإلكتروني'), v: <a href={`mailto:${site.contact_email}`} className="break-all hover:text-[var(--site-accent)]">{site.contact_email}</a> });
    if (site.address) info.push({ k: t('العنوان'), v: site.address });
    if (hours.length > 0) info.push({ k: t('أوقات العمل'), v: hours.map((h) => `${h.days}: ${h.hours}`).join('\n') });
    if (social.length > 0) info.push({ k: t('تابعينا'), v: <span className="flex flex-wrap gap-x-4 gap-y-1">{social.map(([key, href]) => <a key={key} href={href} target="_blank" rel="noopener noreferrer" className="border-b border-current hover:text-[var(--site-accent)]">{SOCIAL[key] ? t(SOCIAL[key]) : key}</a>)}</span> });

    const input = cn('h-[52px] w-full min-w-0 rounded-2xl border bg-white px-3.5 text-base text-[var(--site-ink)] outline-none placeholder:text-[#9A9284] focus:border-[var(--site-ink)]', 'border-[rgba(22,19,15,0.2)]');
    const showForm = bool(d.show_form, true) && !!sendUrl;

    return (
        <SectionShell section={section} joined={joined}>
            <div className="grid gap-14 lg:grid-cols-2 lg:gap-24">
                <div className="flex min-w-0 flex-col gap-9">
                    <SectionHeading eyebrow={str(d.eyebrow)} title={str(d.title)} tone={tone} />
                    {info.length > 0 && (
                        <dl className="m-0 grid gap-x-8 gap-y-6 sm:grid-cols-2">
                            {info.map((it) => (
                                <div key={it.k} className={cn('flex min-w-0 flex-col gap-1 border-t pt-4', hairline(tone))}>
                                    <dt className={cn('text-sm font-semibold', isDark(tone) ? 'text-[#E2C27A]' : 'text-[var(--site-accent)]')}>{it.k}</dt>
                                    <dd className="m-0 text-base whitespace-pre-line break-words">{it.v}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                    {bool(d.show_map, true) && site.map_url && (
                        <a href={site.map_url} target="_blank" rel="noopener noreferrer" className={cn('inline-flex h-[52px] items-center justify-center self-start rounded-full border px-7 text-[15px] font-semibold transition-colors', isDark(tone) ? 'border-white/35 hover:bg-white/10' : 'border-[rgba(26,22,18,0.2)] hover:border-[var(--site-ink)]')}>
                            {t('الموقع على الخريطة')}
                        </a>
                    )}
                </div>
                {(showForm || site.whatsapp_number) && (
                    <div className={cn(siteCard(tone), 'flex min-w-0 flex-col gap-6 self-start p-6 sm:p-9')}>
                        {site.whatsapp_number && (
                            <div className="flex flex-col gap-3">
                                <h3 className="m-0 text-[22px] font-bold">{t('تواصلي معنا مباشرة')}</h3>
                                <a href={whatsappUrl(site.whatsapp_number)} target="_blank" rel="noopener noreferrer" className="inline-flex h-[52px] items-center justify-center gap-2 rounded-full bg-[#25D366] px-7 text-[15px] font-semibold text-white transition-colors hover:bg-[#1FB959]">
                                    {t('تواصلي عبر واتساب')}
                                </a>
                                <span dir="ltr" className={cn('text-center text-sm [unicode-bidi:isolate]', metaText(tone))}>{fmtPhone(site.whatsapp_number)}</span>
                            </div>
                        )}
                        {showForm && site.whatsapp_number && <div className={cn('border-t', hairline(tone))} />}
                        {showForm && (
                    <div className="flex min-w-0 flex-col gap-4">
                        {sent ? (
                            <div className="flex flex-col gap-2 py-6">
                                <h3 className="m-0 text-2xl font-bold">{t('تم استلام رسالتك بنجاح')}</h3>
                                <button type="button" onClick={() => setSent(false)} className="mt-2 self-start border-b border-current bg-transparent p-0 text-[15px]">{t('رسالة أخرى')}</button>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                                <h3 className="m-0 text-[22px] font-bold">{t('أرسلي لنا رسالة')}</h3>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <label className="flex min-w-0 flex-col gap-1.5">
                                        <span className="sr-only">{t('الاسم')}</span>
                                        <input className={input} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('الاسم')} autoComplete="name" maxLength={120} required />
                                        {form.errors.name && <span className="text-sm text-[#A33A22]">{form.errors.name}</span>}
                                    </label>
                                    <label className="flex min-w-0 flex-col gap-1.5">
                                        <span className="sr-only">{t('رقم الهاتف')}</span>
                                        <input className={cn(input, 'text-start')} dir="ltr" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} placeholder={t('رقم الهاتف')} autoComplete="tel" maxLength={32} />
                                        {form.errors.phone && <span className="text-sm text-[#A33A22]">{form.errors.phone}</span>}
                                    </label>
                                </div>
                                <label className="flex flex-col gap-1.5">
                                    <span className="sr-only">{t('رسالتكِ')}</span>
                                    <textarea className={cn(input, 'h-auto resize-y py-3.5')} rows={4} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} placeholder={t('رسالتكِ')} maxLength={3000} required />
                                    {form.errors.message && <span className="text-sm text-[#A33A22]">{form.errors.message}</span>}
                                </label>
                                {/* Honeypot: people never see it; bots fill it and the server refuses the message. */}
                                <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden className="hidden" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} />
                                <button type="submit" disabled={form.processing || preview} className="inline-flex h-[52px] cursor-pointer items-center justify-center self-start rounded-full bg-[var(--site-ink)] px-9 text-[15px] font-semibold text-white transition-colors hover:bg-[var(--site-gold)] hover:text-[var(--site-ink)] disabled:cursor-not-allowed disabled:opacity-60">
                                    {form.processing ? t('جارٍ الإرسال…') : t('إرسال')}
                                </button>
                                {preview && <p className={cn('m-0 text-[13px]', metaText(tone))}>{t('الإرسال معطّل في المعاينة.')}</p>}
                            </form>
                        )}
                    </div>
                        )}
                    </div>
                )}
            </div>
        </SectionShell>
    );
}
