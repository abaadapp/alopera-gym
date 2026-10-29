import { Newspaper } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Pagination } from '@/Components/ui';
import type { Paginated } from '@/types';
import { PostCard, type PostData } from './Home';
import { PageHero } from './Plans';

export default function News({ site, seo, posts }: { site: SiteInfo; seo: { title: string }; posts: Paginated<PostData> }) {
    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="العروض والأخبار" subtitle="تابع أحدث العروض والفعاليات والأخبار في النادي." icon={<Newspaper className="size-6" />} />
            <section className="mx-auto max-w-6xl px-4 py-12">
                {posts.data.length === 0 ? (
                    <p className="py-16 text-center text-muted">لا توجد منشورات حاليًا.</p>
                ) : (
                    <>
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{posts.data.map((p) => <PostCard key={p.id} post={p} />)}</div>
                        <div className="mt-8 overflow-hidden rounded-2xl border border-line bg-white">
                            <Pagination data={posts} />
                        </div>
                    </>
                )}
            </section>
        </SiteLayout>
    );
}
