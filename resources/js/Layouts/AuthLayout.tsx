import type { ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { AdminScope, Flash } from '@/Components/ui';

const Bar = ({ height }: { height: number }) => <span className="w-full rounded-t-[3px] bg-[#111]/55" style={{ height: `${height}%` }} />;
const Line = ({ width, dark = false }: { width: number; dark?: boolean }) => <span className={`block h-2 rounded-full ${dark ? 'bg-[#111]/70' : 'bg-[#111]/12'}`} style={{ width: `${width}%` }} />;

/** The other half of a wide screen: what the panel is for, in the reference's quiet grey. Said once, so hidden from a screen reader. */
function Showcase() {
    return (
        <aside aria-hidden className="hidden bg-[#f2f3f4] lg:flex lg:h-dvh lg:flex-col lg:items-center lg:justify-center lg:gap-8 lg:px-10 lg:py-12">
            <div className="flex h-[300px] w-full items-center justify-center">
                <div className="w-full max-w-[420px] rounded-[18px] border border-[#ececec] bg-white p-4 shadow-[0_30px_60px_-32px_rgba(17,17,17,0.35)]">
                    <div className="mb-4 flex gap-1.5">
                        <span className="size-2 rounded-full bg-[#111]/10" />
                        <span className="size-2 rounded-full bg-[#111]/10" />
                        <span className="size-2 rounded-full bg-[#111]/10" />
                    </div>
                    <div className="flex items-end gap-2" style={{ height: 96 }}>
                        {[38, 62, 45, 80, 55, 92, 70].map((height, i) => <Bar key={i} height={height} />)}
                    </div>
                    <div className="mt-4 space-y-2">
                        <Line width={72} dark />
                        <Line width={46} />
                    </div>
                </div>
            </div>
            <div className="max-w-[380px] text-center">
                <h2 className="text-[20px] leading-tight font-bold text-[#111]">إدارة النادي في مكان واحد</h2>
                <p className="mt-2 text-[14px] leading-relaxed text-[#6b7280]">العضويات والحصص والحضور والمبيعات والفواتير والتقارير، لصاحب النادي والموظفين بصلاحياتهم.</p>
            </div>
        </aside>
    );
}

/** The doors in: the reference's layout (a near-white field, the logo at the head of the form) with the club's own logo. */
export default function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    const { logo, year } = usePage<{ logo: string; year: number }>().props;

    return (
        <>
            <Head title={title} />
            <AdminScope className="flex min-h-dvh flex-col lg:grid lg:h-dvh lg:grid-cols-2 lg:items-stretch">
                <div className="relative flex min-h-dvh flex-1 flex-col overflow-hidden bg-[#f7f8f9] lg:min-h-0">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-0"
                        style={{ backgroundImage: 'radial-gradient(60rem 34rem at 18% -8%, rgba(17,17,17,0.055), transparent 62%),radial-gradient(52rem 30rem at 88% 108%, rgba(17,17,17,0.045), transparent 60%)' }}
                    />
                    <header className="relative z-10 flex items-center justify-between gap-4 px-5 py-5 sm:px-8">
                        <img src={logo} alt="Alopera Gym" className="h-20 w-auto object-contain" width={107} height={80} />
                    </header>
                    <main className="relative z-10 flex flex-1 items-center justify-center px-5 py-6 sm:px-8">
                        <div className="w-full max-w-[420px]">
                            <h1 className="text-[26px] leading-tight font-bold text-[#111]">{title}</h1>
                            {subtitle && <p className="mt-1.5 text-[14px] text-[#6b7280]">{subtitle}</p>}
                            <div className="mt-6">{children}</div>
                        </div>
                    </main>
                    <footer className="relative z-10 shrink-0 px-5 pb-6 text-center sm:px-8">
                        <p className="text-[12px] text-[#9ca3af]">
                            © {year} Alopera Gym — جميع الحقوق محفوظة ·{' '}
                            <a href="/licenses/index.html" className="underline-offset-2 hover:underline">
                                تراخيص البرامج مفتوحة المصدر
                            </a>
                        </p>
                    </footer>
                </div>
                <Showcase />
            </AdminScope>
            <Flash />
        </>
    );
}
