import { Fragment, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { LinkData, RenderedSection } from '@/types/site';

/** Section tones of the design: white, the warm beige surface, and the dark ink. */
export type SiteTone = RenderedSection['tone'];

export const isDark = (tone: SiteTone) => tone === 'dark';

const TONE_CLASS: Record<SiteTone, string> = {
    white: 'bg-white text-[var(--site-ink)]',
    beige: 'bg-[var(--site-surface)] text-[var(--site-ink)]',
    dark: 'bg-[var(--site-ink)] text-white',
};

/** Body text colour on a tone. */
export const bodyText = (tone: SiteTone) => (isDark(tone) ? 'text-[#CFC7B8]' : 'text-[#4A443A]');
/** Secondary (meta) text colour on a tone. */
export const metaText = (tone: SiteTone) => (isDark(tone) ? 'text-[#9A9284]' : 'text-[#6B6356]');
/** Hairline colour on a tone. */
export const hairline = (tone: SiteTone) => (isDark(tone) ? 'border-white/15' : 'border-[rgba(22,19,15,0.14)]');

/**
 * One section of the home page: full-width band in its tone, content in the v2 column (1360px, 40px gutters on
 * wide screens, 20px on phones). `joined`: the section before has the same tone, so a hairline separates them.
 */
export function SectionShell({ section, joined = false, className, innerClassName, children }: {
    section: Pick<RenderedSection, 'id' | 'tone'>;
    joined?: boolean;
    className?: string;
    innerClassName?: string;
    children: ReactNode;
}) {
    return (
        <section id={section.id} className={cn('scroll-mt-20', TONE_CLASS[section.tone], className)}>
            <div className={cn('mx-auto w-full max-w-[1360px] px-5 py-16 sm:px-10 sm:py-20 lg:py-24', joined && 'border-t', joined && hairline(section.tone), innerClassName)}>
                {children}
            </div>
        </section>
    );
}

/** Eyebrow (small gold line), title and subtitle, as v2 sets them. */
export function SectionHeading({ eyebrow, title, subtitle, tone, align = 'start', aside, className }: {
    eyebrow?: string | null;
    title?: string | null;
    subtitle?: string | null;
    tone: SiteTone;
    align?: 'start' | 'center';
    /** Something shown opposite the title on wide screens (tabs, a link). */
    aside?: ReactNode;
    className?: string;
}) {
    if (!eyebrow && !title && !subtitle && !aside) return null;
    const center = align === 'center';
    return (
        <div className={cn('flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between', center && 'items-center text-center lg:flex-col lg:items-center', className)}>
            <div className={cn('flex min-w-0 flex-col gap-3', center && 'items-center')}>
                {eyebrow && <span className={cn('text-sm font-semibold', isDark(tone) ? 'text-[#E9DFC8]' : 'text-[var(--site-accent)]')}>{eyebrow}</span>}
                {title && <h2 className="m-0 text-[clamp(30px,4vw,48px)] leading-[1.2] font-bold text-balance break-words">{title}</h2>}
                {subtitle && <p className={cn('m-0 max-w-[560px] text-[17px] leading-8 whitespace-pre-line', bodyText(tone), center && 'mx-auto')}>{subtitle}</p>}
            </div>
            {aside && <div className="min-w-0 shrink-0">{aside}</div>}
        </div>
    );
}

/** A link from the content: plain <a> (it may point to another address — the preview runs on the panel's). */
export function SiteAnchor({ link, className, children }: { link: LinkData; className?: string; children?: ReactNode }) {
    const blank = link.external && /^https?:/i.test(link.href);
    return (
        <a href={link.href} className={className} {...(blank ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
            {children ?? link.label}
        </a>
    );
}

type ButtonVariant = 'solid' | 'outline' | 'underline';

/**
 * The site's buttons, as the approved design draws them: 'solid' is the gold pill (the main action on any tone),
 * 'outline' the quiet pill beside it, and 'underline' a text link with a rule under it.
 */
export function SiteButton({ link, tone, variant = 'solid', className }: { link: LinkData | null | undefined; tone: SiteTone; variant?: ButtonVariant; className?: string }) {
    if (!link || !link.label || !link.href) return null;
    const dark = isDark(tone);
    const cls = variant === 'solid'
        ? 'inline-flex h-[52px] max-w-full items-center justify-center rounded-full bg-[var(--site-gold)] px-7 text-[15px] font-semibold text-[var(--site-ink)] transition-colors hover:bg-[#DDB85C] hover:text-[var(--site-ink)]'
        : variant === 'outline'
            ? cn('inline-flex h-[52px] max-w-full items-center justify-center rounded-full border px-7 text-[15px] font-semibold transition-colors', dark ? 'border-white/35 text-white hover:bg-white/10' : 'border-[rgba(26,22,18,0.2)] text-[var(--site-ink)] hover:border-[var(--site-ink)]')
            : cn('inline-flex items-center border-b pb-0.5 text-base font-medium transition-colors', dark ? 'border-white text-white hover:text-[#E9DFC8]' : 'border-[var(--site-ink)] text-[var(--site-ink)] hover:border-[var(--site-accent)] hover:text-[var(--site-accent)]');
    return <SiteAnchor link={link} className={cn(cls, className)} />;
}

/** A white card of the design: 24px corners and a faint ring, on white or beige bands alike. */
export const siteCard = (tone: SiteTone) => cn('rounded-[24px]', isDark(tone) ? 'bg-white/5 ring-1 ring-white/10' : 'bg-white ring-1 ring-[rgba(26,22,18,0.08)]');

/** The small gold diamond the design puts before each line of a list. */
export function Diamond() {
    return <span aria-hidden className="mt-[0.6em] size-[7px] shrink-0 rotate-45 rounded-[1px] bg-[var(--site-gold)]" />;
}

/**
 * A photo the system keeps without its size (a trainer, a program, a product, a post): shown whole on a soft
 * beige frame — never cropped, never stretched. With no photo, nothing is drawn.
 */
export function PhotoFrame({ src, alt, className, imgClassName }: { src: string | null | undefined; alt: string; className?: string; imgClassName?: string }) {
    // A file missing on the server draws nothing rather than a broken-image icon.
    const [failed, setFailed] = useState(false);
    if (!src || failed) return null;
    return (
        <div className={cn('overflow-hidden rounded-[18px] bg-[#F1ECE3]', className)}>
            <img src={src} alt={alt} loading="lazy" decoding="async" onError={() => setFailed(true)} className={cn('block size-full object-contain', imgClassName)} />
        </div>
    );
}

/** Two digits, as v2 numbers its rows: 01, 02… */
export const twoDigits = (n: number) => String(n).padStart(2, '0');

/**
 * A translated phrase with elements in it: phrase(t('متبقٍ :n مقاعد'), { n: <span className="num">3</span> }) draws the
 * words around the element in the page's language (the Arabic exactly as before). A name the nodes lack stays as typed.
 */
export function phrase(text: string, nodes: Record<string, ReactNode>): ReactNode[] {
    return text.split(/:([A-Za-z_]+)/).map((part, i) => (i % 2 === 0 ? part : part in nodes ? <Fragment key={i}>{nodes[part]}</Fragment> : `:${part}`));
}

/** A plan or product price without trailing zeros: "29.000" → "29", "12.500" → "12.5". */
export function priceText(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(Number.isFinite(n) ? n : 0);
}
