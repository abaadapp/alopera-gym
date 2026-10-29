import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Flash } from '@/Components/ui';

export default function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <>
            <Head title={title} />
            <div className="grid min-h-screen lg:grid-cols-2">
                <div className="relative hidden overflow-hidden bg-brand lg:block">
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.18),transparent_45%),radial-gradient(circle_at_80%_80%,rgba(255,255,255,0.12),transparent_40%)]" />
                    <div className="relative flex h-full flex-col justify-end p-12 text-white">
                        <p className="font-display text-4xl leading-tight font-semibold">إدارة ناديك<br />بكل أناقة وسهولة.</p>
                        <p className="mt-4 max-w-md text-white/80">العضويات، الحصص، الحجوزات، المدفوعات وواتساب — في منصة واحدة مصممة للأندية والمراكز الرياضية.</p>
                    </div>
                </div>
                <div className="flex items-center justify-center px-5 py-12">
                    <div className="w-full max-w-sm">
                        <h1 className="text-2xl font-semibold">{title}</h1>
                        {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
                        <div className="mt-8">{children}</div>
                    </div>
                </div>
            </div>
            <Flash />
        </>
    );
}
