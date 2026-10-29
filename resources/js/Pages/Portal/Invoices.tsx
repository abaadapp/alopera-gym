import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ExternalLink, FileText, History } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { SiteInfo } from '@/Layouts/SiteLayout';
import { Badge } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money } from '@/lib/format';
import { invoiceStatus, label, membershipKind, membershipStatus, paymentMethod } from '@/lib/labels';
import type { MembershipData } from './Dashboard';

interface PortalInvoice {
    id: number;
    number: string;
    status: string;
    total: string;
    paid_total: string;
    currency: string;
    balance: string;
    issue_date: string;
    items: { description: string; line_total: string }[];
    payments: { receipt_number: string | null; kind: string; method: string; amount: string; paid_at: string | null }[];
    public_url: string;
}

export default function Invoices({ site, seo, invoices, onlinePayments, history }: { site: SiteInfo; seo: { title: string }; invoices: PortalInvoice[]; onlinePayments: boolean; history: MembershipData[] }) {
    const currency = useCurrency();
    const [paying, setPaying] = useState<number | null>(null);

    const pay = (id: number) => {
        setPaying(id);
        router.post(route('portal.invoices.pay', { invoice: id }), {}, { onFinish: () => setPaying(null) });
    };

    return (
        <PortalLayout site={site} title={seo.title}>
            <h2 className="mb-4 flex items-center gap-2 font-display text-lg font-semibold"><FileText className="size-5 text-brand" /> الفواتير</h2>
            {invoices.length === 0 ? (
                <p className="rounded-2xl border border-line bg-white p-8 text-center text-sm text-muted">لا توجد فواتير بعد.</p>
            ) : (
                <div className="space-y-4">
                    {invoices.map((inv) => {
                        const [statusLabel, tone] = label(invoiceStatus, inv.status);
                        const open = ['issued', 'partially_paid'].includes(inv.status) && Number(inv.balance) > 0;
                        return (
                            <article key={inv.id} className="rounded-2xl border border-line bg-white p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium num">{inv.number}</p>
                                        <p className="text-xs text-muted">{date(inv.issue_date)}</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge tone={tone}>{statusLabel}</Badge>
                                        <span className="font-display text-lg font-semibold num">{money(inv.total, currency)}</span>
                                    </div>
                                </div>
                                <ul className="mt-3 space-y-1 text-sm text-ink/80">
                                    {inv.items.map((it, i) => (
                                        <li key={i} className="flex justify-between gap-3"><span>{it.description}</span><span className="num shrink-0">{money(it.line_total, currency)}</span></li>
                                    ))}
                                </ul>
                                {inv.payments.length > 0 && (
                                    <div className="mt-3 rounded-xl bg-canvas p-3 text-xs text-muted">
                                        {inv.payments.map((p, i) => (
                                            <p key={i} className="flex justify-between gap-3">
                                                <span>{p.kind === 'refund' ? 'استرداد' : 'إيصال'} <span className="num">{p.receipt_number}</span> · {paymentMethod[p.method] ?? p.method} · {p.paid_at}</span>
                                                <span className="num">{money(p.amount, currency)}</span>
                                            </p>
                                        ))}
                                    </div>
                                )}
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
                                    <a href={inv.public_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-sm text-brand hover:underline">
                                        <ExternalLink className="size-4" /> عرض وطباعة
                                    </a>
                                    {open && (
                                        onlinePayments ? (
                                            <button onClick={() => pay(inv.id)} disabled={paying === inv.id} className="rounded-full bg-brand px-5 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                                                {paying === inv.id ? 'جارٍ التحويل…' : <>ادفع <span className="num">{money(inv.balance, currency)}</span></>}
                                            </button>
                                        ) : (
                                            <span className="text-sm text-muted">المتبقي <span className="num">{money(inv.balance, currency)}</span> — ادفع في الاستقبال</span>
                                        )
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}

            {history.length > 0 && (
                <section className="mt-10">
                    <h2 className="mb-4 flex items-center gap-2 font-display text-lg font-semibold"><History className="size-5 text-brand" /> سجل الاشتراكات</h2>
                    <div className="overflow-x-auto rounded-2xl border border-line bg-white">
                        <table className="table-base">
                            <thead><tr><th>الباقة</th><th>النوع</th><th>من</th><th>إلى</th><th>الحالة</th></tr></thead>
                            <tbody>
                                {history.map((m) => {
                                    const [statusLabel, tone] = label(membershipStatus, m.status);
                                    return (
                                        <tr key={m.id}>
                                            <td className="font-medium">{m.plan}</td>
                                            <td>{membershipKind[m.kind] ?? m.kind}</td>
                                            <td>{date(m.starts_on)}</td>
                                            <td>{date(m.ends_on)}</td>
                                            <td><Badge tone={tone}>{statusLabel}</Badge></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </PortalLayout>
    );
}
