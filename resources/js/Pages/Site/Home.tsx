import { Link } from '@inertiajs/react';
import { ArrowLeft, CalendarCheck, CreditCard, Heart, Megaphone, Newspaper, ShieldCheck, Sparkles } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { useCurrency, useShared } from '@/hooks/useShared';
import { date } from '@/lib/format';
import { PlanCard, SectionTitle, type PlanData } from './Plans';
import { ProgramCard, type ProgramData } from './Programs';
import { TrainerCard, type TrainerData } from './Trainers';

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

interface Props {
    site: SiteInfo;
    seo: { title: string };
    hero: { title: string; subtitle: string | null; image_url: string | null; cta: string; badge: string | null };
    features: { title: string; text: string }[];
    about: { title: string | null; body: string | null; image_url: string | null };
    stats: { label: string; value: string }[];
    plans: PlanData[];
    programs: ProgramData[];
    trainers: TrainerData[];
    offers: PostData[];
    news: PostData[];
    gallery: { url: string | null; title: string | null }[];
}

export function PostCard({ post }: { post: PostData }) {
    const offer = post.type === 'offer';
    return (
        <Link href={route('site.post', { slug: post.slug })} className="group block overflow-hidden rounded-3xl border border-line bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-brand/5">
            <div className="relative aspect-[16/10] overflow-hidden bg-gradient-to-br from-brand-50 to-brand-100">
                {post.image_url ? (
                    <img src={post.image_url} alt={post.title} loading="lazy" width={640} height={400} className="size-full object-cover transition duration-500 group-hover:scale-105" />
                ) : (
                    <div className="grid size-full place-items-center text-brand-300">{offer ? <Megaphone className="size-12" /> : <Newspaper className="size-12" />}</div>
                )}
                <span className={`absolute top-3 start-3 rounded-full px-3 py-1 text-xs font-medium ${offer ? 'bg-brand text-white' : 'bg-white/90 text-ink'}`}>{offer ? 'عرض' : 'خبر'}</span>
            </div>
            <div className="p-5">
                <h3 className="font-display text-lg leading-8 font-semibold text-ink group-hover:text-brand">{post.title}</h3>
                {post.excerpt && <p className="mt-2 line-clamp-2 text-sm leading-7 text-muted">{post.excerpt}</p>}
                <p className="mt-3 text-xs text-muted">
                    {offer && post.ends_on ? <>ساري حتى {date(post.ends_on)}</> : date(post.published_at)}
                </p>
            </div>
        </Link>
    );
}

const FEATURE_ICONS = [CalendarCheck, CreditCard, ShieldCheck];

export default function Home({ site, seo, hero, features, about, stats, plans, programs, trainers, offers, news, gallery }: Props) {
    const currency = useCurrency();
    const { tenant } = useShared();

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            {/* Hero */}
            <section className="relative overflow-hidden">
                <div className="absolute inset-0 bg-gradient-to-br from-brand-50 via-[#fffafb] to-brand-100/60" />
                <div className="pointer-events-none absolute -top-32 end-[-8rem] size-[28rem] rounded-full bg-brand-soft/30 blur-3xl" />
                <div className="relative mx-auto grid max-w-6xl items-center gap-10 px-4 py-14 sm:py-20 lg:grid-cols-2">
                    <div>
                        {hero.badge && (
                            <span className="mb-5 inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-medium text-brand-700 shadow-sm">
                                <Heart className="size-3.5" /> {hero.badge}
                            </span>
                        )}
                        <h1 className="font-display text-4xl leading-[1.25] font-semibold text-ink sm:text-5xl lg:text-6xl">{hero.title}</h1>
                        {hero.subtitle && <p className="mt-5 max-w-xl text-lg leading-8 text-muted">{hero.subtitle}</p>}
                        <div className="mt-8 flex flex-wrap gap-3">
                            {site.signup_enabled && (
                                <Link href={route('site.join')} className="inline-flex items-center gap-2 rounded-full bg-brand px-7 py-3.5 font-medium text-white shadow-lg shadow-brand/30 transition hover:bg-brand-600">
                                    {hero.cta} <ArrowLeft className="size-4" />
                                </Link>
                            )}
                            <Link href={route('site.schedule')} className="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white px-7 py-3.5 font-medium text-brand-700 transition hover:bg-brand-50">
                                جدول الحصص
                            </Link>
                        </div>
                        {stats.length > 0 && (
                            <dl className="mt-10 grid max-w-lg grid-cols-2 gap-4 sm:grid-cols-4">
                                {stats.map((s, i) => (
                                    <div key={i}>
                                        <dt className="order-2 text-xs text-muted">{s.label}</dt>
                                        <dd className="font-display text-2xl font-semibold text-brand"><span className="num">{s.value}</span></dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                    <div className="relative">
                        <div className="absolute -inset-4 rotate-3 rounded-[2.5rem] bg-brand/10" />
                        <div className="relative aspect-[4/5] overflow-hidden rounded-[2.5rem] bg-gradient-to-br from-brand-200 to-brand shadow-2xl shadow-brand/20 sm:aspect-[5/5]">
                            {hero.image_url ? (
                                <img src={hero.image_url} alt={tenant?.name ?? ''} width={720} height={800} className="size-full object-cover" fetchPriority="high" />
                            ) : (
                                <div className="flex size-full flex-col items-center justify-center gap-3 text-white/90">
                                    <Sparkles className="size-16" />
                                    <p className="font-display text-2xl">{tenant?.name}</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </section>

            {/* Why us */}
            {features.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <div className="grid gap-4 sm:grid-cols-3">
                        {features.map(({ title, text }, i) => {
                            const Icon = FEATURE_ICONS[i % FEATURE_ICONS.length];
                            return (
                                <div key={i} className="rounded-3xl border border-line bg-white p-6 shadow-sm">
                                    <span className="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand"><Icon className="size-6" /></span>
                                    <h3 className="mt-4 font-display text-lg font-semibold">{title}</h3>
                                    <p className="mt-1.5 text-sm leading-7 text-muted">{text}</p>
                                </div>
                            );
                        })}
                    </div>
                </section>
            )}

            {/* About */}
            {(about.title || about.body) && (
                <section className={`mx-auto grid max-w-6xl items-center gap-10 px-4 py-14 ${about.image_url ? 'lg:grid-cols-2' : ''}`}>
                    <div className={about.image_url ? 'order-2 lg:order-1' : 'rounded-[2rem] border border-line bg-white p-8 shadow-sm sm:p-12'}>
                        <p className="mb-2 text-sm font-medium text-brand">من نحن</p>
                        <h2 className="font-display text-3xl font-semibold sm:text-4xl">{about.title ?? 'عن النادي'}</h2>
                        {about.body && <p className="mt-5 max-w-3xl leading-8 whitespace-pre-line text-muted">{about.body}</p>}
                    </div>
                    {about.image_url && (
                        <div className="order-1 aspect-[4/3] overflow-hidden rounded-[2rem] bg-gradient-to-br from-brand-50 to-brand-100 lg:order-2">
                            <img src={about.image_url} alt={about.title ?? ''} loading="lazy" width={720} height={540} className="size-full object-cover" />
                        </div>
                    )}
                </section>
            )}

            {/* Offers */}
            {offers.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <SectionTitle eyebrow="عروض حصرية" title="عروضنا الحالية" />
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{offers.map((p) => <PostCard key={p.id} post={p} />)}</div>
                </section>
            )}

            {/* Plans */}
            {plans.length > 0 && (
                <section className="bg-gradient-to-b from-transparent via-brand-50/60 to-transparent py-16">
                    <div className="mx-auto max-w-6xl px-4">
                        <SectionTitle eyebrow="الباقات" title="اختاري باقتك" subtitle="أسعار واضحة بلا مفاجآت، وتفعيل فوري بعد الدفع." />
                        <div className="grid gap-6 pt-3 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <PlanCard key={plan.id} plan={plan} currency={currency} cta={site.signup_enabled ? { label: 'اشتركي الآن', href: route('site.join', { plan: plan.id }) } : undefined} />
                            ))}
                        </div>
                        <p className="mt-8 text-center">
                            <Link href={route('site.plans')} className="inline-flex items-center gap-1.5 text-sm font-medium text-brand hover:underline">كل الباقات والأسعار <ArrowLeft className="size-4" /></Link>
                        </p>
                    </div>
                </section>
            )}

            {/* Programs */}
            {programs.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <SectionTitle eyebrow="البرامج" title="برامج تناسب كل هدف" />
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{programs.map((p) => <ProgramCard key={p.id} program={p} />)}</div>
                    <p className="mt-8 text-center">
                        <Link href={route('site.programs')} className="inline-flex items-center gap-1.5 text-sm font-medium text-brand hover:underline">كل البرامج <ArrowLeft className="size-4" /></Link>
                    </p>
                </section>
            )}

            {/* Trainers */}
            {trainers.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <SectionTitle eyebrow="المدربات" title="فريق يرافقكِ بشغف" />
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">{trainers.map((t) => <TrainerCard key={t.id} trainer={t} compact />)}</div>
                </section>
            )}

            {/* Gallery */}
            {gallery.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <SectionTitle eyebrow="معرض الصور" title="أجواء النادي" />
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {gallery.map((g, i) => (
                            <div key={i} className={`overflow-hidden rounded-2xl bg-brand-50 ${i === 0 ? 'col-span-2 row-span-2' : ''}`}>
                                {g.url && <img src={g.url} alt={g.title ?? ''} loading="lazy" width={480} height={480} className="aspect-square size-full object-cover" />}
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {/* News */}
            {news.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <SectionTitle eyebrow="آخر الأخبار" title="من النادي" />
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{news.map((p) => <PostCard key={p.id} post={p} />)}</div>
                </section>
            )}

            {/* CTA */}
            {site.signup_enabled && (
                <section className="mx-auto max-w-6xl px-4 py-10">
                    <div className="relative overflow-hidden rounded-[2.5rem] bg-brand px-6 py-14 text-center text-white sm:px-14">
                        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.18),transparent_45%),radial-gradient(circle_at_85%_80%,rgba(255,255,255,0.12),transparent_40%)]" />
                        <h2 className="relative font-display text-3xl font-semibold sm:text-4xl">ابدئي رحلتك اليوم</h2>
                        <p className="relative mx-auto mt-3 max-w-lg text-white/85">سجّلي خلال دقيقة، ادفعي أونلاين، وابدئي حجز حصصك فورًا.</p>
                        <Link href={route('site.join')} className="relative mt-8 inline-flex items-center gap-2 rounded-full bg-white px-8 py-3.5 font-medium text-brand-700 shadow-lg hover:bg-brand-50">
                            اشتركي الآن <ArrowLeft className="size-4" />
                        </Link>
                    </div>
                </section>
            )}
        </SiteLayout>
    );
}
