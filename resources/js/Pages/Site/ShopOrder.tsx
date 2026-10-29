import { router } from '@inertiajs/react';
import { Check, CircleX, CreditCard, Package } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';

interface Order {
    number: string;
    token: string;
    at: string;
    status: 'pending' | 'completed' | 'voided' | 'cancelled';
    step: 'new' | 'preparing' | 'ready' | 'delivered' | null;
    paid: boolean;
    method: string;
    total: string;
    subtotal: string;
    tax: string;
    name: string;
    reserved_until: string | null;
    can_pay: boolean;
    items: { name: string; quantity: string; unit_price: string; line_total: string }[];
}

const STEPS = [
    { key: 'new', label: 'استلمنا طلبك' },
    { key: 'preparing', label: 'قيد التجهيز' },
    { key: 'ready', label: 'جاهز للاستلام' },
    { key: 'delivered', label: 'تم التسليم' },
] as const;

/** The customer's view of an order, reached only through its private link. */
export default function ShopOrder({ site, seo, order, pickupNote, currency }: { site: SiteInfo; seo: { title: string }; order: Order; pickupNote: string | null; currency: string }) {
    const cancelled = order.status === 'cancelled' || order.status === 'voided';
    const current = STEPS.findIndex((s) => s.key === (order.status === 'completed' ? 'delivered' : order.step));

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <section className="mx-auto max-w-3xl px-4 py-12">
                <div className="rounded-3xl border border-line bg-white p-6 sm:p-8">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-sm text-muted">طلب رقم</p>
                            <h1 className="font-display text-2xl font-semibold num">{order.number}</h1>
                            <p className="mt-1 text-xs text-muted num">{order.at}</p>
                        </div>
                        <span className={cn('rounded-full px-3 py-1 text-sm font-medium', cancelled ? 'bg-stone-100 text-stone-600' : order.paid ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700')}>
                            {cancelled ? 'ملغى' : order.paid ? 'مدفوع' : order.method === 'pickup' ? 'الدفع عند الاستلام' : 'بانتظار الدفع'}
                        </span>
                    </div>

                    {cancelled ? (
                        <p className="mt-6 flex items-center gap-2 rounded-2xl bg-stone-50 p-4 text-sm text-muted"><CircleX className="size-5" /> أُلغي هذا الطلب. للاستفسار تواصل مع النادي.</p>
                    ) : (
                        <ol className="mt-8 grid grid-cols-4 gap-2">
                            {STEPS.map((s, i) => (
                                <li key={s.key} className="text-center">
                                    <span className={cn('mx-auto grid size-9 place-items-center rounded-full border text-sm', i <= current ? 'border-brand bg-brand text-white' : 'border-line text-muted')}>
                                        {i < current || order.status === 'completed' ? <Check className="size-4" /> : <span className="num">{i + 1}</span>}
                                    </span>
                                    <span className={cn('mt-2 block text-xs', i <= current ? 'font-medium text-ink' : 'text-muted')}>{s.label}</span>
                                </li>
                            ))}
                        </ol>
                    )}

                    {order.can_pay && (
                        <div className="mt-6 rounded-2xl bg-brand-50 p-4">
                            <p className="text-sm">أكمل الدفع الإلكتروني لتأكيد طلبك{order.reserved_until && <> قبل <span className="num">{order.reserved_until}</span></>}.</p>
                            <Button className="mt-3 rounded-full" icon={<CreditCard className="size-4" />} onClick={() => router.post(route('site.shop.order.pay', order.token))}>الدفع الآن — {money(order.total, currency)}</Button>
                        </div>
                    )}

                    <table className="mt-8 w-full text-sm">
                        <thead><tr className="border-b border-line text-muted"><th className="py-2 text-start font-medium">المنتج</th><th className="py-2 text-center font-medium">الكمية</th><th className="py-2 text-end font-medium">المبلغ</th></tr></thead>
                        <tbody>
                            {order.items.map((i, idx) => (
                                <tr key={idx} className="border-b border-line/60"><td className="py-2.5"><Package className="me-2 inline size-4 text-muted" />{i.name}</td><td className="py-2.5 text-center num">{number(i.quantity)}</td><td className="py-2.5 text-end num">{money(i.line_total, currency)}</td></tr>
                            ))}
                        </tbody>
                        <tfoot>
                            {Number(order.tax) > 0 && <tr><td className="pt-3 text-muted" colSpan={2}>الضريبة</td><td className="pt-3 text-end num">{money(order.tax, currency)}</td></tr>}
                            <tr><td className="pt-3 font-semibold" colSpan={2}>الإجمالي</td><td className="pt-3 text-end text-lg font-semibold num">{money(order.total, currency)}</td></tr>
                        </tfoot>
                    </table>

                    {pickupNote && <p className="mt-6 rounded-2xl bg-stone-50 p-4 text-sm leading-7 text-muted">{pickupNote}</p>}
                    <p className="mt-6 text-xs text-muted">احتفظ برابط هذه الصفحة لمتابعة حالة طلبك.</p>
                </div>
            </section>
        </SiteLayout>
    );
}
