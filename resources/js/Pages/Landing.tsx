import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft, BarChart3, CalendarCheck, Check, CreditCard, Fingerprint, Globe, LogIn, MessageCircle, ShieldCheck, Sparkles, Users,
} from 'lucide-react';
import { cn } from '@/lib/cn';
import { currencyLabel, number } from '@/lib/format';

interface PlatformPlan {
    id: number;
    name: string;
    description: string | null;
    price_monthly: string;
    price_yearly: string;
    currency: string;
    max_members: number | null;
    max_branches: number | null;
    features: string[] | null;
}

interface Props {
    plans: PlatformPlan[];
    clubs: { name: string; slug: string; city: string | null; logo_url: string | null }[];
    seo: { title: string; description: string };
    appName: string;
    contactEmail: string | null;
}

const features = [
    { icon: Users, title: 'المشتركات والعضويات', text: 'ملف شامل لكل مشتركة، باقات وخصومات، تجميد وتجديد وترقية، وتفعيل تلقائي بعد الدفع.' },
    { icon: CalendarCheck, title: 'الحصص والحجوزات', text: 'جدول أسبوعي بسعة لكل حصة، حجز من البوابة أو الاستقبال، وتسجيل الحضور والغياب.' },
    { icon: CreditCard, title: 'المدفوعات والمالية', text: 'فواتير وإيصالات، نقدي وتحويل وبطاقة، دفع إلكتروني عبر Paymob، مصروفات ومستحقات.' },
    { icon: MessageCircle, title: 'أتمتة واتساب', text: 'ترحيب وتأكيد وتذكير قبل الانتهاء وروابط دفع، مع حملات مستهدفة وصندوق محادثات.' },
    { icon: Globe, title: 'موقع وبوابة لكل نادٍ', text: 'موقع متجاوب بهوية ناديك، تسجيل ودفع أونلاين، وبوابة للمشتركة لحجز الحصص.' },
    { icon: Fingerprint, title: 'أجهزة الدخول', text: 'قارئات QR والبطاقات والبصمة، دخول حسب حالة العضوية، وحضور الموظفات.' },
    { icon: BarChart3, title: 'تقارير ومؤشرات', text: 'الإيرادات والتحصيل والتجديدات وأداء الحصص، ومقارنة بين الفترات وتصدير Excel.' },
    { icon: ShieldCheck, title: 'صلاحيات وأمان', text: 'أدوار للمالكة والإدارة والاستقبال والمالية والمدربات، وسجل لكل العمليات الحساسة.' },
];

/** Platform marketing page (SaaS) — not a club website. */
export default function Landing({ plans, clubs, seo, appName, contactEmail }: Props) {
    const [yearly, setYearly] = useState(false);

    return (
        <>
            <Head title={seo.title} />
            <div className="min-h-screen bg-[#fffafb]">
                <header className="sticky top-0 z-30 border-b border-line/70 bg-white/85 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
                        <span className="flex items-center gap-2.5">
                            <span className="grid size-10 place-items-center rounded-xl bg-brand font-display text-lg text-white">A</span>
                            <span className="font-display text-lg font-semibold">{appName}</span>
                        </span>
                        <nav className="hidden items-center gap-6 text-sm text-ink/80 md:flex">
                            <a href="#features" className="hover:text-brand">المزايا</a>
                            {plans.length > 0 && <a href="#pricing" className="hover:text-brand">الأسعار</a>}
                            {clubs.length > 0 && <a href="#clubs" className="hover:text-brand">الأندية</a>}
                        </nav>
                        <Link href={route('login')} className="inline-flex items-center gap-2 rounded-full bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                            <LogIn className="size-4" /> دخول الإدارة
                        </Link>
                    </div>
                </header>

                <section className="relative overflow-hidden">
                    <div className="absolute inset-0 bg-gradient-to-br from-brand-50 via-[#fffafb] to-brand-100/50" />
                    <div className="pointer-events-none absolute -top-40 end-[-10rem] size-[32rem] rounded-full bg-brand-soft/30 blur-3xl" />
                    <div className="relative mx-auto max-w-4xl px-4 py-20 text-center sm:py-28">
                        <span className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-medium text-brand-700 shadow-sm">
                            <Sparkles className="size-3.5" /> مصممة للأندية الرياضية النسائية
                        </span>
                        <h1 className="mt-6 font-display text-4xl leading-[1.25] font-semibold text-ink sm:text-6xl">كل ما يحتاجه ناديكِ<br className="hidden sm:block" /> في منصة واحدة</h1>
                        <p className="mx-auto mt-6 max-w-2xl text-lg leading-8 text-muted">
                            العضويات، الحصص، الحجوزات، المدفوعات الإلكترونية، أتمتة واتساب، موقع وبوابة للمشتركات — بالعربية، وبهوية ناديك.
                        </p>
                        <div className="mt-9 flex flex-wrap justify-center gap-3">
                            <a href="#pricing" className="inline-flex items-center gap-2 rounded-full bg-brand px-7 py-3.5 font-medium text-white shadow-lg shadow-brand/30 hover:bg-brand-600">
                                ابدئي تجربتك <ArrowLeft className="size-4" />
                            </a>
                            <a href="#features" className="rounded-full border border-brand-200 bg-white px-7 py-3.5 font-medium text-brand-700 hover:bg-brand-50">اكتشفي المزايا</a>
                        </div>
                    </div>
                </section>

                <section id="features" className="mx-auto max-w-6xl scroll-mt-20 px-4 py-16">
                    <div className="mx-auto mb-12 max-w-2xl text-center">
                        <h2 className="font-display text-3xl font-semibold sm:text-4xl">من الاستقبال إلى التقارير</h2>
                        <p className="mt-3 text-muted">واجهات خاصة للمالكة والإدارة والاستقبال والمالية والمدربات، وبوابة للمشتركة.</p>
                    </div>
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {features.map(({ icon: Icon, title, text }) => (
                            <div key={title} className="rounded-3xl border border-line bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-brand/5">
                                <span className="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand"><Icon className="size-6" /></span>
                                <h3 className="mt-4 font-display text-lg font-semibold">{title}</h3>
                                <p className="mt-2 text-sm leading-7 text-muted">{text}</p>
                            </div>
                        ))}
                    </div>
                </section>

                {plans.length > 0 && (
                    <section id="pricing" className="scroll-mt-20 bg-gradient-to-b from-transparent via-brand-50/60 to-transparent py-16">
                        <div className="mx-auto max-w-6xl px-4">
                            <div className="mx-auto mb-10 max-w-2xl text-center">
                                <h2 className="font-display text-3xl font-semibold sm:text-4xl">باقات المنصة</h2>
                                <p className="mt-3 text-muted">اشتراك شهري أو سنوي، مع فترة تجريبية مجانية.</p>
                                <div className="mt-6 inline-flex rounded-full border border-line bg-white p-1 text-sm">
                                    <button type="button" onClick={() => setYearly(false)} className={cn('rounded-full px-5 py-2', !yearly ? 'bg-brand text-white' : 'text-ink/80')}>شهري</button>
                                    <button type="button" onClick={() => setYearly(true)} className={cn('rounded-full px-5 py-2', yearly ? 'bg-brand text-white' : 'text-ink/80')}>سنوي</button>
                                </div>
                            </div>
                            <div className={cn('grid gap-6', plans.length >= 3 ? 'lg:grid-cols-3' : 'sm:grid-cols-2', 'mx-auto max-w-5xl')}>
                                {plans.map((plan, i) => {
                                    const featured = plans.length >= 3 ? i === 1 : false;
                                    return (
                                        <div key={plan.id} className={cn('flex flex-col rounded-3xl border bg-white p-7', featured ? 'border-brand-300 shadow-xl shadow-brand/10' : 'border-line shadow-sm')}>
                                            <h3 className="font-display text-xl font-semibold">{plan.name}</h3>
                                            {plan.description && <p className="mt-2 text-sm text-muted">{plan.description}</p>}
                                            <div className="mt-5 flex items-baseline gap-1.5">
                                                <span className="font-display text-4xl font-semibold num">{number(yearly ? plan.price_yearly : plan.price_monthly, 3)}</span>
                                                <span className="text-sm text-muted">{currencyLabel(plan.currency)} / {yearly ? 'سنة' : 'شهر'}</span>
                                            </div>
                                            <ul className="mt-6 flex-1 space-y-2.5 text-sm">
                                                <li className="flex gap-2"><Check className="size-4 shrink-0 text-brand" />{plan.max_members ? <>حتى <span className="num">{number(plan.max_members)}</span> مشتركة</> : 'عدد غير محدود من المشتركات'}</li>
                                                <li className="flex gap-2"><Check className="size-4 shrink-0 text-brand" />{plan.max_branches ? <><span className="num">{plan.max_branches}</span> {plan.max_branches === 1 ? 'فرع' : 'فروع'}</> : 'فروع غير محدودة'}</li>
                                                {(plan.features ?? []).map((f) => <li key={f} className="flex gap-2"><Check className="size-4 shrink-0 text-brand" />{f}</li>)}
                                            </ul>
                                            {contactEmail && (
                                                <a href={`mailto:${contactEmail}?subject=${encodeURIComponent(`طلب اشتراك في باقة ${plan.name}`)}`} className={cn('mt-7 block rounded-full py-3 text-center text-sm font-medium', featured ? 'bg-brand text-white hover:bg-brand-600' : 'border border-brand-200 text-brand-700 hover:bg-brand-50')}>
                                                    تواصلي معنا للاشتراك
                                                </a>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </section>
                )}

                {clubs.length > 0 && (
                    <section id="clubs" className="mx-auto max-w-6xl scroll-mt-20 px-4 py-16">
                        <h2 className="mb-8 text-center font-display text-3xl font-semibold">أندية على المنصة</h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {clubs.map((club) => (
                                <a key={club.slug} href={route('site.home', { club: club.slug })} className="flex items-center gap-3 rounded-2xl border border-line bg-white p-4 shadow-sm transition hover:border-brand-200">
                                    {club.logo_url ? (
                                        <img src={club.logo_url} alt={club.name} loading="lazy" width={48} height={48} className="size-12 rounded-xl object-cover" />
                                    ) : (
                                        <span className="grid size-12 place-items-center rounded-xl bg-brand-50 font-display text-lg text-brand">{club.name.trim()[0]}</span>
                                    )}
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{club.name}</span>
                                        {club.city && <span className="block text-xs text-muted">{club.city}</span>}
                                    </span>
                                </a>
                            ))}
                        </div>
                    </section>
                )}

                <footer className="border-t border-line bg-white py-8 text-center text-sm text-muted">
                    {contactEmail && (
                        <p className="mb-2">
                            للاشتراك والاستفسار: <a href={`mailto:${contactEmail}`} className="font-medium text-brand hover:underline" dir="ltr">{contactEmail}</a>
                        </p>
                    )}
                    © {new Date().getFullYear()} {appName}
                </footer>
            </div>
        </>
    );
}
