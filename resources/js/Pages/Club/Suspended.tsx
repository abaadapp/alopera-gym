import { Head, router } from '@inertiajs/react';
import { LogOut, PauseCircle, Receipt } from 'lucide-react';
import { Button } from '@/Components/ui';
import { useCan, useShared } from '@/hooks/useShared';

/** Shown instead of the panel while the club's platform subscription is suspended. */
export default function Suspended({ reason, status }: { reason: string | null; status: string }) {
    const { tenant, auth } = useShared();
    const can = useCan();

    return (
        <>
            <Head title="الحساب موقوف" />
            <div className="grid min-h-screen place-items-center bg-canvas px-4">
                <div className="card w-full max-w-lg p-8 text-center">
                    <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-amber-50 text-amber-600">
                        <PauseCircle className="size-8" />
                    </div>
                    <h1 className="mt-5 text-2xl font-semibold">{status === 'cancelled' ? 'اشتراك النادي ملغى' : 'حساب النادي موقوف مؤقتًا'}</h1>
                    <p className="mt-2 text-sm leading-7 text-muted">
                        تم إيقاف لوحة {tenant?.name ?? 'النادي'} على المنصة. بيانات النادي محفوظة بالكامل ولم يُحذف شيء،
                        وتعود اللوحة للعمل فور تسوية اشتراك المنصة.
                    </p>
                    {reason && <p className="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">السبب: {reason}</p>}
                    <div className="mt-6 flex flex-wrap justify-center gap-2">
                        {can('billing.view') && (
                            <Button href={route('club.billing')} icon={<Receipt className="size-4" />}>اشتراك المنصة والفواتير</Button>
                        )}
                        <Button variant="outline" icon={<LogOut className="size-4" />} onClick={() => router.post(route('logout'))}>تسجيل الخروج</Button>
                    </div>
                    {!can('billing.view') && <p className="mt-4 text-xs text-muted">تواصلي مع مالكة النادي لإعادة التفعيل.</p>}
                    <p className="mt-6 text-xs text-muted">مسجّلة باسم {auth?.user.name}</p>
                </div>
            </div>
        </>
    );
}
