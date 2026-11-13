import { Link, usePage } from '@inertiajs/react';
import { Check, Clock, Crown, Dumbbell, Info, Snowflake, Sparkles, UserRound } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { phrase } from '@/Components/Site/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { currencyLabel, money, number } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { planAccess, say } from '@/lib/plan-access';
import type { PageKey, PageText, SiteChrome } from '@/types/site';

export interface PlanData {
    id: number;
    name: string;
    type: string;
    type_label: string;
    description: string | null;
    duration_value: number;
    duration_unit: string;
    duration_label: string;
    price: string;
    signup_fee: string;
    sessions_limit: number | null;
    /** What a sale of it gives now (the owner's «صلاحيات الباقة»). */
    allows_gym: boolean;
    allows_classes: boolean;
    freeze_allowed: boolean;
    max_freeze_days: number;
    features: string[] | null;
    color: string | null;
    is_featured: boolean;
    programs?: string[];
}

interface PtPackage {
    id: number;
    name: string;
    description: string | null;
    sessions_count: number;
    session_minutes: number;
    price: string;
    validity_days: number;
}

/** Plan card used on the home page, plans page, sign-up and portal renewal. */
export function PlanCard({
    plan,
    currency,
    selected,
    onSelect,
    cta,
}: {
    plan: PlanData;
    currency: string;
    selected?: boolean;
    onSelect?: (id: number) => void;
    cta?: { label: string; href: string };
}) {
    const featured = plan.is_featured;
    const t = useT();
    const en = useLocale() === 'en';
    // What the owner set the plan to give: the door, the classes, or both. Nothing it does not give is ticked.
    const access = planAccess(plan);
    const perks: string[] = [
        ...access.included.map((p) => say(p, t, en)),
        ...(plan.freeze_allowed
            ? [plan.max_freeze_days > 0
                ? en && plan.max_freeze_days === 1 ? 'Can be frozen for up to 1 day' : t('إمكانية التجميد حتى :count يومًا', { count: plan.max_freeze_days })
                : t('إمكانية تجميد الاشتراك')]
            : []),
        ...(plan.features ?? []),
    ];

    const body = (
        <>
            {featured && (
                <span className="absolute -top-3 start-6 inline-flex items-center gap-1 rounded-full bg-brand px-3 py-1 text-xs font-medium text-white shadow-md shadow-brand/30">
                    <Crown className="size-3.5" /> {t('الأكثر طلبًا')}
                </span>
            )}
            <div className="flex items-start justify-between gap-3">
                <div>
                    <span className="inline-block rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700">{plan.type_label}</span>
                    <h3 className="mt-3 font-display text-xl font-semibold text-ink">{plan.name}</h3>
                </div>
                {onSelect && (
                    <span className={cn('mt-1 grid size-6 shrink-0 place-items-center rounded-full border-2 transition', selected ? 'border-brand bg-brand text-white' : 'border-line bg-white')}>
                        {selected && <Check className="size-3.5" />}
                    </span>
                )}
            </div>
            <div className="mt-5 flex items-baseline gap-1.5">
                <span className="font-display text-4xl font-semibold text-ink num">{number(plan.price, 3)}</span>
                <span className="text-sm text-muted">{currencyLabel(currency)}</span>
                <span className="text-sm text-muted">/ {plan.duration_label}</span>
            </div>
            {Number(plan.signup_fee) > 0 && <p className="mt-1 text-xs text-muted">{t('+ رسوم تسجيل لمرة واحدة :amount', { amount: money(plan.signup_fee, currency) })}</p>}
            {plan.description && <p className="mt-4 text-sm leading-7 text-muted">{plan.description}</p>}
            <ul className="mt-5 space-y-2.5">
                {perks.map((perk, i) => (
                    <li key={i} className="flex items-start gap-2.5 text-sm text-ink/85">
                        <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-brand-50 text-brand">
                            <Check className="size-3" />
                        </span>
                        {perk}
                    </li>
                ))}
                {access.notes.map((note, i) => (
                    <li key={`note-${i}`} className="flex items-start gap-2.5 text-sm text-muted">
                        <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-line/60 text-muted">
                            <Info className="size-3" />
                        </span>
                        {say(note, t, en)}
                    </li>
                ))}
            </ul>
            {plan.programs && plan.programs.length > 0 && (
                <div className="mt-5 flex flex-wrap gap-1.5">
                    {plan.programs.map((p) => (
                        <span key={p} className="rounded-full border border-line px-2.5 py-0.5 text-xs text-muted">{p}</span>
                    ))}
                </div>
            )}
            {cta && (
                <Link
                    href={cta.href}
                    className={cn(
                        'mt-7 block rounded-full py-3 text-center text-sm font-medium transition',
                        featured ? 'bg-brand text-white shadow-md shadow-brand/30 hover:bg-brand-600' : 'border border-brand-200 text-brand-700 hover:bg-brand-50',
                    )}
                >
                    {cta.label}
                </Link>
            )}
        </>
    );

    const classes = cn(
        'relative flex flex-col rounded-3xl border bg-white p-6 text-start transition',
        featured ? 'border-brand-300 shadow-xl shadow-brand/10' : 'border-line shadow-sm',
        onSelect && 'cursor-pointer hover:border-brand-300',
        selected && 'ring-4 ring-brand/15 border-brand',
    );

    return onSelect ? (
        <button type="button" onClick={() => onSelect(plan.id)} className={classes} aria-pressed={selected}>
            {body}
        </button>
    ) : (
        <div className={classes}>{body}</div>
    );
}

export function SectionTitle({ eyebrow, title, subtitle, center = true }: { eyebrow?: string; title: string; subtitle?: string; center?: boolean }) {
    return (
        <div className={cn('mb-10 max-w-2xl', center && 'mx-auto text-center')}>
            {eyebrow && <p className="mb-2 text-sm font-medium tracking-wide text-brand">{eyebrow}</p>}
            <h2 className="font-display text-3xl font-semibold text-ink sm:text-4xl">{title}</h2>
            {subtitle && <p className="mt-3 leading-7 text-muted">{subtitle}</p>}
        </div>
    );
}

/** The owner's words for an inner page (edited in «إدارة الموقع» → «الصفحات الداخلية»). */
export function usePageText(page: PageKey): PageText | undefined {
    return usePage<{ chrome?: SiteChrome }>().props.chrome?.pages?.[page];
}

/** The head of an inner page: with `page`, its title and line come from the site's content; otherwise from props. */
export function PageHero({ page, title: fixedTitle, subtitle: fixedSubtitle, icon }: { page?: PageKey; title?: string; subtitle?: string; icon?: React.ReactNode }) {
    const text = usePageText(page ?? 'plans');
    const title = (page ? text?.title : undefined) || fixedTitle || '';
    const subtitle = page ? text?.subtitle : fixedSubtitle;
    return (
        <section className="relative overflow-hidden bg-gradient-to-b from-brand-50 to-[#fffafb]">
            <div className="pointer-events-none absolute -top-24 end-[-6rem] size-72 rounded-full bg-brand-soft/30 blur-3xl" />
            <div className="pointer-events-none absolute -bottom-24 start-[-4rem] size-64 rounded-full bg-brand/10 blur-3xl" />
            <div className="relative mx-auto max-w-6xl px-4 py-14 text-center sm:py-20">
                {icon && <div className="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-white text-brand shadow-sm">{icon}</div>}
                <h1 className="font-display text-3xl font-semibold text-ink sm:text-5xl">{title}</h1>
                {subtitle && <p className="mx-auto mt-4 max-w-xl leading-8 text-muted">{subtitle}</p>}
            </div>
        </section>
    );
}

export default function Plans({ site, seo, plans, ptPackages }: { site: SiteInfo; seo: { title: string }; plans: PlanData[]; ptPackages: PtPackage[] }) {
    const currency = useCurrency();
    const text = usePageText('plans');
    const t = useT();
    const en = useLocale() === 'en';

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero page="plans" title={t('الباقات والأسعار')} icon={<Sparkles className="size-6" />} />

            <section className="mx-auto max-w-6xl px-4 py-12">
                {plans.length === 0 ? (
                    <p className="py-16 text-center text-muted">{t('لا توجد باقات معروضة حاليًا.')}</p>
                ) : (
                    <div className="grid gap-6 pt-3 sm:grid-cols-2 lg:grid-cols-3">
                        {plans.map((plan) => (
                            <PlanCard key={plan.id} plan={plan} currency={currency} cta={site.signup_enabled ? { label: t('اشترك في هذه الباقة'), href: route('site.join', { plan: plan.id }) } : undefined} />
                        ))}
                    </div>
                )}
            </section>

            {ptPackages.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 py-12">
                    <SectionTitle eyebrow={t('تدريب شخصي')} title={text?.pt_title || t('التدريب الشخصي')} subtitle={text?.pt_subtitle || undefined} />
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {ptPackages.map((p) => (
                            <div key={p.id} className="rounded-3xl border border-line bg-white p-6 shadow-sm">
                                <div className="flex items-center gap-3">
                                    <span className="grid size-11 place-items-center rounded-2xl bg-brand-50 text-brand"><UserRound className="size-5" /></span>
                                    <h3 className="font-display text-lg font-semibold">{p.name}</h3>
                                </div>
                                <div className="mt-4 flex items-baseline gap-1.5">
                                    <span className="font-display text-3xl font-semibold num">{number(p.price, 3)}</span>
                                    <span className="text-sm text-muted">{currencyLabel(currency)}</span>
                                </div>
                                <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                                    <span className="rounded-2xl bg-brand-50/70 px-2 py-2"><Dumbbell className="mx-auto mb-1 size-4 text-brand" /><span className="num">{p.sessions_count}</span> {en && p.sessions_count === 1 ? 'session' : t('جلسات')}</span>
                                    <span className="rounded-2xl bg-brand-50/70 px-2 py-2"><Clock className="mx-auto mb-1 size-4 text-brand" /><span className="num">{p.session_minutes}</span> {t('دقيقة')}</span>
                                    <span className="rounded-2xl bg-brand-50/70 px-2 py-2"><Snowflake className="mx-auto mb-1 size-4 text-brand" />{phrase(en && p.validity_days === 1 ? 'Valid for :n day' : t('صالحة :n يومًا'), { n: <span className="num">{p.validity_days}</span> })}</span>
                                </div>
                                {p.description && <p className="mt-4 text-sm leading-7 text-muted">{p.description}</p>}
                                <Link href={route('site.contact')} className="mt-5 block rounded-full border border-brand-200 py-2.5 text-center text-sm font-medium text-brand-700 hover:bg-brand-50">
                                    {t('احجز استشارة')}
                                </Link>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </SiteLayout>
    );
}
