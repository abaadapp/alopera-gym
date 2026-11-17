import type { ReactNode } from 'react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Diamond } from '@/Components/Site/ui';
import type { PageKey } from '@/types/site';
import { usePageText } from './Plans';

/**
 * Turns the owner's plain text into the page: «## » starts a heading, «- » a point, a blank line a new paragraph.
 * Text only — React escapes it, so nothing the owner types can become markup.
 */
export function legalBlocks(body: string): ReactNode[] {
    const out: ReactNode[] = [];
    let points: string[] = [];
    let para: string[] = [];
    const flush = () => {
        if (para.length) out.push(<p key={out.length} className="m-0 text-[17px] leading-9 text-[#3A342B]">{para.join(' ')}</p>);
        if (points.length) out.push(<ul key={out.length} className="m-0 flex list-none flex-col gap-2.5 p-0">{points.map((pt, i) => <li key={i} className="flex gap-3 text-[17px] leading-8 text-[#3A342B]"><Diamond />{pt}</li>)}</ul>);
        para = [];
        points = [];
    };
    for (const raw of body.split(/\r?\n/)) {
        const line = raw.trim();
        if (line === '') { flush(); continue; }
        if (line.startsWith('## ')) { flush(); out.push(<h2 key={out.length} className="m-0 mt-6 text-[clamp(21px,3vw,26px)] font-bold">{line.slice(3)}</h2>); continue; }
        if (line.startsWith('- ')) { if (para.length) { const keep = points; points = []; flush(); points = keep; } points.push(line.slice(2)); continue; }
        if (points.length) flush();
        para.push(line);
    }
    flush();
    return out;
}

/** The privacy policy and the terms: the owner's words for the page, written in «إدارة الموقع». */
export default function Legal({ site, seo, page, body }: { site: SiteInfo; seo: { title: string }; page: Extract<PageKey, 'privacy' | 'terms'>; body: string }) {
    const text = usePageText(page);
    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <header className="bg-[var(--site-surface)]">
                <div className="mx-auto flex max-w-3xl flex-col gap-3 px-5 pt-14 pb-10 sm:px-6 sm:pt-20">
                    <h1 className="m-0 text-[clamp(32px,6vw,52px)] leading-tight font-bold">{text?.title}</h1>
                    {text?.subtitle && <p className="m-0 text-lg text-[#5E5648]">{text.subtitle}</p>}
                </div>
            </header>
            <article className="mx-auto flex max-w-3xl flex-col gap-5 px-5 py-12 sm:px-6 sm:py-16">{legalBlocks(body ?? '')}</article>
        </SiteLayout>
    );
}
