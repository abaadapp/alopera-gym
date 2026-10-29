import { Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { date } from '@/lib/format';
import type { PostData } from './Home';

export default function Post({ site, seo, post }: { site: SiteInfo; seo: { title: string }; post: PostData & { body: string | null } }) {
    const offer = post.type === 'offer';

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <article className="mx-auto max-w-3xl px-4 py-10 sm:py-14">
                <Link href={route('site.news')} className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-brand">
                    <ArrowRight className="size-4" /> العروض والأخبار
                </Link>
                <header className="mt-6">
                    <span className={`inline-block rounded-full px-3 py-1 text-xs font-medium ${offer ? 'bg-brand text-white' : 'bg-brand-50 text-brand-700'}`}>{offer ? 'عرض' : 'خبر'}</span>
                    <h1 className="mt-4 font-display text-3xl leading-[1.35] font-semibold text-ink sm:text-4xl">{post.title}</h1>
                    <p className="mt-3 inline-flex items-center gap-1.5 text-sm text-muted">
                        <CalendarDays className="size-4" />
                        {date(post.published_at)}
                        {offer && post.ends_on && <> · ساري حتى {date(post.ends_on)}</>}
                    </p>
                </header>
                {post.image_url && (
                    <img src={post.image_url} alt={post.title} width={960} height={600} className="mt-8 aspect-[16/10] w-full rounded-[2rem] object-cover" />
                )}
                {post.excerpt && <p className="mt-8 text-lg leading-9 text-ink/85">{post.excerpt}</p>}
                {post.body && <div className="mt-6 leading-9 whitespace-pre-line text-ink/80">{post.body}</div>}
                {offer && site.signup_enabled && (
                    <div className="mt-10 rounded-3xl bg-brand-50 p-6 text-center">
                        <p className="font-display text-xl font-semibold">استفد من العرض الآن</p>
                        <Link href={route('site.join')} className="mt-4 inline-block rounded-full bg-brand px-7 py-3 text-sm font-medium text-white hover:bg-brand-600">اشترك الآن</Link>
                    </div>
                )}
            </article>
        </SiteLayout>
    );
}
