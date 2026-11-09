import { usePage } from '@inertiajs/react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { SiteSection } from '@/Components/Site/Sections';
import { PostTile } from '@/Components/Site/Sections/System';
import type { SiteChrome, SitePage } from '@/types/site';

export interface PostData {
    id: number;
    type: string;
    title: string;
    slug: string;
    excerpt: string | null;
    image_url: string | null;
    published_at: string | null;
    ends_on: string | null;
}

/** A post on the news page and elsewhere: the v2 tile, linking to the post's own page. */
export function PostCard({ post }: { post: PostData }) {
    return <PostTile post={{ ...post, type: post.type === 'offer' ? 'offer' : 'news', url: route('site.post', { slug: post.slug }) }} />;
}

interface Props {
    site: SiteInfo;
    seo: { title: string };
    chrome?: SiteChrome;
    page: SitePage;
}

/**
 * The official home page: the sections the owner arranged in «إدارة الموقع», in order, each drawn by its own
 * component (Components/Site/Sections). The server already left out hidden and empty sections, and filled the
 * system ones (plans, timetable, trainers…) with the club's live data.
 */
export default function Home({ site, seo, page }: Props) {
    const chrome = (usePage().props.chrome as SiteChrome | undefined) ?? null;
    const sections = page?.sections ?? [];

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            {sections.map((section, i) => (
                <SiteSection key={section.id} section={section} site={site} chrome={chrome} joined={i > 0 && sections[i - 1].tone === section.tone && section.type !== 'hero'} />
            ))}
        </SiteLayout>
    );
}
