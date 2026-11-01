import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { CalendarCheck2, Lock, LockOpen } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { clientKey } from '@/Components/Inventory/parts';
import { Badge, Button, Card, CardHeader, DataTable, EmptyState, Field, Modal, Notice, PageHeader, Textarea, type Column } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { date, money, number } from '@/lib/format';

type Status = 'open' | 'closed' | 'reopened';
type Month = {
    month: string; label: string; year: number; status: Status; entries: number; revenue: string; expenses: string; net: string;
    closed_at: string | null; closed_by: string | null; reopened_at: string | null; reopened_by: string | null; reopen_reason: string | null;
    times_closed: number; warnings: string[]; closable: boolean; reopenable: boolean;
};
type Summary = { entries: number; debit: string; credit: string; revenue: string; expenses: string; net: string; vat: string };
type Year = {
    year: number; status: Status; summary: Summary | null; closed_at: string | null; closed_by: string | null;
    reopened_at: string | null; reopened_by: string | null; reopen_reason: string | null; closable: boolean; reopenable: boolean;
};
type Checks = { month: string; label: string; errors: string[]; warnings: string[]; pending: string[]; summary: Summary };

interface Props {
    months: Month[];
    years: Year[];
    lockedThrough: string | null;
    canClose: boolean;
    canReopen: boolean;
    checks?: Checks | null;
}

const STATUS: Record<Status, { label: string; tone: 'gray' | 'green' | 'amber' }> = {
    open: { label: 'مفتوحة', tone: 'gray' },
    closed: { label: 'مقفلة', tone: 'green' },
    reopened: { label: 'أعيد فتحها', tone: 'amber' },
};

/*
 * الفترات المحاسبية: months latest first, each with its result from the books and who closed it. Closing shows the
 * month's checks first (a partial reload of `checks`); a month closes with every open month before it. Reopening
 * takes a reason; the latest closed month reopens first.
 */
export default function FinancePeriods({ months, years, lockedThrough, canClose, canReopen, checks }: Props) {
    const currency = useCurrency();
    const [closing, setClosing] = useState<Month | null>(null);
    const [reopening, setReopening] = useState<{ month?: Month; year?: Year } | null>(null);
    const [closingYear, setClosingYear] = useState<Year | null>(null);

    const columns: Column<Month>[] = [
        { key: 'month', header: 'الشهر', cell: (m) => <span className="font-medium">{m.label}</span> },
        { key: 'status', header: 'الحالة', cell: (m) => <StatusBadge status={m.status} /> },
        { key: 'entries', header: 'القيود', align: 'end', cell: (m) => <span className="num">{number(m.entries)}</span> },
        { key: 'revenue', header: 'الإيرادات', align: 'end', cell: (m) => <span className="num">{money(m.revenue, currency)}</span> },
        { key: 'expenses', header: 'المصروفات', align: 'end', cell: (m) => <span className="num">{money(m.expenses, currency)}</span> },
        { key: 'net', header: 'صافي النتيجة', align: 'end', cell: (m) => <span className={Number(m.net) < 0 ? 'num text-red-600' : 'num'}>{money(m.net, currency)}</span> },
        { key: 'who', header: 'الإقفال', cell: (m) => <Who m={m} /> },
        { key: 'actions', header: '', align: 'end', cell: (m) => <Actions m={m} /> },
    ];

    function Actions({ m }: { m: Month }) {
        return (
            <div className="flex justify-end gap-2">
                {canClose && m.closable && <Button size="sm" variant="outline" icon={<Lock className="size-3.5" />} onClick={() => setClosing(m)}>إقفال</Button>}
                {canReopen && m.reopenable && <Button size="sm" variant="ghost" icon={<LockOpen className="size-3.5" />} onClick={() => setReopening({ month: m })}>إعادة فتح</Button>}
            </div>
        );
    }

    return (
        <ClubLayout title="الفترات المحاسبية">
            <PageHeader
                title="الفترات المحاسبية"
                subtitle="الشهر المقفل لا يُرحَّل فيه قيد ولا يُعدَّل مستند بتاريخه، وأي تصحيح لاحق يُقيَّد في فترة مفتوحة. يُقفل الشهر بعد انتهائه، ويُقفل معه كل شهر مفتوح قبله."
            />

            <Notice tone={lockedThrough ? 'green' : 'gray'} icon={<CalendarCheck2 />} className="mb-5">
                {lockedThrough ? <>الدفاتر مقفلة حتى <strong className="num">{date(lockedThrough)}</strong>. القيود والمستندات بتاريخ قبله أو فيه مرفوضة.</> : 'لا توجد فترات مقفلة بعد. كل التواريخ مفتوحة للقيد.'}
            </Notice>

            {years.length > 0 && (
                <Card className="mb-5">
                    <CardHeader title="السنوات المالية" subtitle="تُقفل السنة بعد إقفال كل أشهرها، وتُحفظ أرقامها. لا تُفتح أشهرها إلا بعد إعادة فتحها." />
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {years.map((y) => (
                            <div key={y.year} className="rounded-[12px] border border-line p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-semibold num">{y.year}</span>
                                    <StatusBadge status={y.status} />
                                </div>
                                {y.summary && (
                                    <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-[13px]">
                                        <dt className="text-muted">الإيرادات</dt><dd className="text-end num">{money(y.summary.revenue, currency)}</dd>
                                        <dt className="text-muted">المصروفات</dt><dd className="text-end num">{money(y.summary.expenses, currency)}</dd>
                                        <dt className="text-muted">صافي النتيجة</dt><dd className="text-end font-medium num">{money(y.summary.net, currency)}</dd>
                                    </dl>
                                )}
                                {y.closed_by && <p className="mt-2 text-[12px] text-muted">أقفلها {y.closed_by} · <span className="num">{y.closed_at}</span></p>}
                                {y.status === 'reopened' && y.reopen_reason && <p className="mt-1 text-[12px] text-amber-700">أعاد فتحها {y.reopened_by}: {y.reopen_reason}</p>}
                                <div className="mt-3 flex gap-2">
                                    {canClose && y.closable && <Button size="sm" variant="outline" icon={<Lock className="size-3.5" />} onClick={() => setClosingYear(y)}>إقفال السنة</Button>}
                                    {canReopen && y.reopenable && <Button size="sm" variant="ghost" icon={<LockOpen className="size-3.5" />} onClick={() => setReopening({ year: y })}>إعادة فتح</Button>}
                                </div>
                            </div>
                        ))}
                    </div>
                </Card>
            )}

            <Card padded={false}>
                {months.length ? (
                    <DataTable
                        rows={months}
                        columns={columns}
                        rowKey={(m) => m.month}
                        params={{}}
                        card={(m) => (
                            <div className="space-y-2 p-4">
                                <div className="flex items-center justify-between gap-2"><span className="font-medium">{m.label}</span><StatusBadge status={m.status} /></div>
                                <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-[13px]">
                                    <span className="text-muted">القيود</span><span className="text-end num">{number(m.entries)}</span>
                                    <span className="text-muted">الإيرادات</span><span className="text-end num">{money(m.revenue, currency)}</span>
                                    <span className="text-muted">المصروفات</span><span className="text-end num">{money(m.expenses, currency)}</span>
                                    <span className="text-muted">صافي النتيجة</span><span className="text-end font-medium num">{money(m.net, currency)}</span>
                                </div>
                                <Who m={m} />
                                <Actions m={m} />
                            </div>
                        )}
                    />
                ) : (
                    <EmptyState title="لا توجد قيود بعد" description="تظهر الأشهر هنا بعد أول قيد في الدفاتر." />
                )}
            </Card>

            {closing && <CloseDialog month={closing} months={months} checks={checks?.month === closing.month ? checks : null} onClose={() => setClosing(null)} />}
            {closingYear && <CloseYearDialog year={closingYear} onClose={() => setClosingYear(null)} />}
            {reopening && <ReopenDialog target={reopening} onClose={() => setReopening(null)} />}
        </ClubLayout>
    );
}

function StatusBadge({ status }: { status: Status }) {
    const s = STATUS[status];

    return <Badge tone={s.tone}>{s.label}</Badge>;
}

function Who({ m }: { m: Month }) {
    if (m.status === 'open') return <span className="text-[12px] text-muted">—</span>;

    return (
        <div className="text-[12px] leading-5 text-muted">
            {m.closed_by && <div>أقفلها {m.closed_by} · <span className="num">{m.closed_at}</span></div>}
            {m.status === 'reopened' && <div className="text-amber-700">أعاد فتحها {m.reopened_by}: {m.reopen_reason}</div>}
            {m.status === 'closed' && m.warnings.length > 0 && <div className="text-amber-700">{m.warnings.length} ملاحظة عند الإقفال</div>}
        </div>
    );
}

function SummaryList({ s }: { s: Summary }) {
    const currency = useCurrency();
    const rows: [string, string][] = [
        ['عدد القيود', number(s.entries)],
        ['مجموع المدين', money(s.debit, currency)],
        ['مجموع الدائن', money(s.credit, currency)],
        ['الإيرادات', money(s.revenue, currency)],
        ['المصروفات', money(s.expenses, currency)],
        ['صافي النتيجة', money(s.net, currency)],
        ['صافي حركة الضريبة المستحقة', money(s.vat, currency)],
    ];

    return (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 rounded-[12px] border border-line p-4 text-[13px]">
            {rows.map(([label, value]) => <div key={label} className="contents"><dt className="text-muted">{label}</dt><dd className="text-end num">{value}</dd></div>)}
        </dl>
    );
}

function CloseDialog({ month, months, checks, onClose }: { month: Month; months: Month[]; checks: Checks | null; onClose: () => void }) {
    const key = useRef(clientKey());
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Every open month before this one closes with it.
    const before = months.filter((m) => m.month < month.month && m.status !== 'closed').map((m) => m.label).reverse();

    useEffect(() => {
        router.reload({ only: ['checks'], data: { check: month.month } });
    }, [month.month]);

    const submit = () => {
        setProcessing(true);
        router.post(route('club.finance.periods.close'), { month: month.month, client_key: key.current }, {
            preserveScroll: true,
            onError: (e) => setError(Object.values(e)[0] ?? null),
            onSuccess: () => onClose(),
            onFinish: () => setProcessing(false),
        });
    };
    const blocked = !checks || checks.errors.length > 0;

    return (
        <Modal open onClose={onClose} size="lg" title={`إقفال ${month.label}`} description="بعد الإقفال لا يُرحَّل قيد بتاريخ في هذا الشهر أو قبله، ولا يُعدَّل مصروف أو سند بتاريخه."
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={processing} disabled={blocked} icon={<Lock className="size-4" />}>إقفال الفترة</Button></>}>
            <div className="space-y-4">
                {before.length > 0 && <Notice tone="blue">سيُقفل معه كل شهر مفتوح قبله، ويُفحص كل منها بالقواعد نفسها: {before.join('، ')}.</Notice>}
                {!checks ? (
                    <p className="py-6 text-center text-[13px] text-muted">جارٍ فحص الشهر…</p>
                ) : (
                    <>
                        <SummaryList s={checks.summary} />
                        {checks.errors.map((e) => <Notice key={e} tone="red">{e}</Notice>)}
                        {checks.pending.length > 0 && (
                            <Notice tone="blue">
                                <p className="font-medium">مستندات لم تدخل الدفاتر بعد، وتُرحَّل تلقائيًا عند الإقفال:</p>
                                <ul className="mt-1 list-disc ps-5">{checks.pending.map((p) => <li key={p}>{p}</li>)}</ul>
                            </Notice>
                        )}
                        {checks.warnings.length > 0 && (
                            <Notice tone="amber">
                                <p className="font-medium">ملاحظات لا تمنع الإقفال:</p>
                                <ul className="mt-1 list-disc ps-5">{checks.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                            </Notice>
                        )}
                        {!checks.errors.length && !checks.warnings.length && !checks.pending.length && <Notice tone="green">الشهر متزن، وكل مستنداته في الدفاتر.</Notice>}
                    </>
                )}
                {error && <Notice tone="red">{error}</Notice>}
            </div>
        </Modal>
    );
}

function CloseYearDialog({ year, onClose }: { year: Year; onClose: () => void }) {
    const form = useForm({ year: year.year });
    const submit = () => form.post(route('club.finance.periods.years.close'), { preserveScroll: true, onSuccess: () => onClose() });

    return (
        <Modal open onClose={onClose} title={`إقفال سنة ${year.year}`} description="تُحفظ أرقام السنة مع إقفالها. لا يُعاد فتح أي شهر منها إلا بعد إعادة فتح السنة."
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing} icon={<Lock className="size-4" />}>إقفال السنة</Button></>}>
            {form.errors.year ? <Notice tone="red">{form.errors.year}</Notice> : <p className="text-[13px] text-muted">كل أشهر السنة مقفلة. لا تُرحَّل قيود إقفال، فتبقى الحسابات كما هي وتُقرأ لكل فترة.</p>}
        </Modal>
    );
}

function ReopenDialog({ target, onClose }: { target: { month?: Month; year?: Year }; onClose: () => void }) {
    const isYear = target.year !== undefined;
    const form = useForm({ month: target.month?.month ?? '', year: target.year?.year ?? '', reason: '' });
    const submit = () => form.post(route(isYear ? 'club.finance.periods.years.reopen' : 'club.finance.periods.reopen'), { preserveScroll: true, onSuccess: () => onClose() });
    const label = isYear ? `سنة ${target.year?.year}` : target.month?.label;
    const scopeError = form.errors.month ?? form.errors.year;

    return (
        <Modal open onClose={onClose} title={`إعادة فتح ${label}`} description="يُسجَّل السبب واسمك ووقت الفتح في سجل التدقيق. بعد التصحيح أقفل الفترة مجددًا."
            footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button variant="danger" onClick={submit} loading={form.processing} disabled={form.data.reason.trim().length < 10} icon={<LockOpen className="size-4" />}>إعادة الفتح</Button></>}>
            <div className="space-y-3">
                {scopeError && <Notice tone="red">{scopeError}</Notice>}
                <Field label="سبب إعادة الفتح" error={form.errors.reason} required hint="عشرة أحرف على الأقل.">
                    <Textarea rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="مثال: تصحيح تاريخ فاتورة مورد سُجّلت في الشهر الخطأ" />
                </Field>
            </div>
        </Modal>
    );
}
