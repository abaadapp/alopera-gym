import { useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, CalendarRange, Info } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { DuesBody, FinanceBody, MovementBody, StatementsBody, type Dues, type Movement, type PeriodReport, type Statements } from '@/Components/Reports/Finance';
import { CheckinsBody, ClassesBody, ExpiringBody, MembersBody, StaffBody, TrainersBody, type Checkins, type Expiring, type StaffAttendance, type Trainers } from '@/Components/Reports/People';
import { PayoutsBody, PayrollBody, type Payouts, type Payroll } from '@/Components/Reports/Payroll';
import { DocumentsBody, RenewalsBody, type Documents, type Renewals } from '@/Components/Reports/Documents';
import { BranchesBody, SalesBody, type Branches, type Sales } from '@/Components/Reports/Sales';
import { TablesBody, type Tables } from '@/Components/Reports/Tables';
import { Button, Card, Checkbox, Input, Notice, PageHeader, Select } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { date } from '@/lib/format';

type Data = Record<string, unknown> & { empty: boolean };

interface Props {
    /** Which report (not `key`: React keeps that name for itself). */
    kind: string;
    title: string;
    /** «period»: dates and a comparison; «snapshot»: how things stand today. */
    mode: 'period' | 'snapshot';
    compare: boolean;
    filters: { from: string; to: string; preset: string; compare: string; compare_from: string; compare_to: string; previous_from: string; previous_to: string; branch: number | null };
    branchFilter: { choices: { id: number; name: string }[]; name: string } | null;
    canFinancial: boolean;
    canExport: boolean;
    data: Data;
}

const presets = [
    { key: 'today', label: 'اليوم' },
    { key: 'week', label: 'هذا الأسبوع' },
    { key: 'month', label: 'هذا الشهر' },
    { key: 'last_month', label: 'الشهر الماضي' },
    { key: 'quarter', label: 'هذا الربع' },
    { key: 'year', label: 'هذه السنة' },
];

const compareModes = [
    { key: 'previous', label: 'الفترة السابقة (بنفس المدة)' },
    { key: 'month', label: 'نفس الفترة من الشهر السابق' },
    { key: 'year', label: 'نفس الفترة من العام السابق' },
    { key: 'custom', label: 'فترة مخصصة' },
];

function Chip({ on, onClick, children, small }: { on: boolean; onClick: () => void; children: ReactNode; small?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={on}
            className={cn('rounded-full font-medium transition-colors', small ? 'h-8 px-3 text-[12px]' : 'h-9 px-4 text-[13px]', on ? 'bg-[#111] text-white' : 'border border-line bg-white text-[#6b7280] hover:border-[#d4d4d4] hover:text-[#111]')}
        >
            {children}
        </button>
    );
}

/**
 * One report of the reports page: its filters — the period and the comparison (or, for a report of today's state,
 * its own choices), the branch, the report's own filters — then its figures, charts and tables, and its export
 * for exactly what is on screen. Everything is in the address, so a report can be shared as a link.
 */
export default function ReportShow({ kind, title, mode, compare, filters, branchFilter, canFinancial, canExport, data }: Props) {
    const { url } = usePage();
    const query = Object.fromEntries(new URLSearchParams(url.split('?')[1] ?? '')) as Record<string, string>;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [compareFrom, setCompareFrom] = useState(filters.compare_from);
    const [compareTo, setCompareTo] = useState(filters.compare_to);
    const [customCompare, setCustomCompare] = useState(filters.compare === 'custom');

    // A filter changed: the others kept, back to the first page of any list.
    const go = (patch: Record<string, string | null>) => {
        const next: Record<string, string> = { ...query };
        delete next.page;
        Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? delete next[k] : (next[k] = v)));
        router.get(route('club.reports.show', kind), next, { preserveState: true, preserveScroll: true });
    };
    const exportParams = Object.fromEntries(Object.entries(query).filter(([k]) => k !== 'page'));
    const option = (k: string) => (data.options as Record<string, unknown> | undefined)?.[k];

    const subtitle = mode === 'snapshot'
        ? `الوضع اليوم، ${date(new Date().toISOString().slice(0, 10))}`
        : kind === 'statements' && (data as unknown as Statements).view === 'trial'
            ? `الأرصدة حتى ${date(filters.to)}`
            : `من ${date(filters.from)} إلى ${date(filters.to)}${compare ? ` · مقارنة بـ ${date(filters.previous_from)} – ${date(filters.previous_to)}` : ''}`;

    return (
        <ClubLayout title={title}>
            <PageHeader
                back={<Link href={route('club.reports.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight className="size-4" /> التقارير</Link>}
                title={title}
                subtitle={<>{subtitle}{branchFilter && <> · {branchFilter.name}</>}</>}
                actions={<ExportMenu href={canExport ? route('club.reports.export', kind) : null} params={exportParams} title={title} size="md" />}
            />

            <Card className="mb-6">
                {mode === 'period' && (
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div className="flex flex-wrap gap-2" role="group" aria-label="الفترة">
                            {presets.map((p) => <Chip key={p.key} on={filters.preset === p.key} onClick={() => go({ preset: p.key, from: null, to: null })}>{p.label}</Chip>)}
                        </div>
                        <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); go({ preset: null, from, to }); }}>
                            <CalendarRange className="size-4 text-muted" />
                            <Input type="date" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="من" />
                            <span className="text-muted">—</span>
                            <Input type="date" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} aria-label="إلى" />
                            <Button type="submit" variant="secondary">عرض</Button>
                        </form>
                    </div>
                )}

                <ReportFilters kind={kind} data={data} option={option} go={go} branch={branchFilter && (
                    branchFilter.choices.length > 0 ? (
                        <label className="flex items-center gap-2 text-[13px] text-muted">
                            <Building2 className="size-4" />
                            <span className="sr-only">الفرع</span>
                            <Select className="h-9 w-48 py-1 text-[13px]" value={filters.branch ?? 'all'} onChange={(e) => go({ branch: e.target.value })} aria-label="الفرع">
                                <option value="all">كل الفروع</option>
                                {branchFilter.choices.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </label>
                    ) : (
                        <span className="flex h-9 items-center gap-2 rounded-full border border-line px-3 text-[13px] text-muted"><Building2 className="size-4" /> {branchFilter.name}</span>
                    )
                )} first={mode === 'snapshot'} />

                {mode === 'period' && compare && (
                    <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                        <span className="text-[13px] text-muted">قارن مع:</span>
                        {compareModes.map((m) => (
                            <Chip key={m.key} small on={customCompare ? m.key === 'custom' : filters.compare === m.key}
                                onClick={() => (m.key === 'custom' ? setCustomCompare(true) : (setCustomCompare(false), go({ compare: m.key === 'previous' ? null : m.key, compare_from: null, compare_to: null })))}>
                                {m.label}
                            </Chip>
                        ))}
                        {customCompare && (
                            <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); go({ compare: 'custom', compare_from: compareFrom, compare_to: compareTo }); }}>
                                <Input type="date" className="w-40" value={compareFrom} onChange={(e) => setCompareFrom(e.target.value)} aria-label="مقارنة من" />
                                <span className="text-muted">—</span>
                                <Input type="date" className="w-40" value={compareTo} onChange={(e) => setCompareTo(e.target.value)} aria-label="مقارنة إلى" />
                                <Button type="submit" variant="secondary" size="sm">قارن</Button>
                            </form>
                        )}
                    </div>
                )}
            </Card>

            {data.empty && (
                <Notice tone="gray" icon={<Info />} className="mb-4">
                    لا توجد بيانات لهذه الفترة والفلاتر. {canExport && 'التصدير يعطي ملفًا صحيحًا برؤوس الأعمدة فقط.'}
                </Notice>
            )}

            <Body kind={kind} data={data} canFinancial={canFinancial} to={filters.to} />
        </ClubLayout>
    );
}

/** The report's own filters, with the branch, in one row of the filter card. */
function ReportFilters({ kind, data, option, go, branch, first }: { kind: string; data: Data; option: (k: string) => unknown; go: (p: Record<string, string | null>) => void; branch: ReactNode; first: boolean }) {
    let own: ReactNode = null;
    if (kind === 'statements') {
        const view = (data as unknown as Statements).view;
        own = (['income', 'trial'] as const).map((v) => <Chip key={v} small on={view === v} onClick={() => go({ view: v === 'income' ? null : v })}>{v === 'income' ? 'قائمة الدخل' : 'ميزان المراجعة'}</Chip>);
    } else if (kind === 'movement') {
        const d = data as unknown as Movement;
        own = (
            <>
                <Select className="h-9 w-56 py-1 text-[13px]" value={d.options.account_id ?? ''} onChange={(e) => go({ account_id: e.target.value || null })} aria-label="الحساب">
                    <option value="">كل الحسابات</option>
                    {d.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </Select>
                {[['', 'الكل'], ['in', 'داخل'], ['out', 'خارج']].map(([k, t]) => <Chip key={k} small on={(d.options.direction ?? '') === k} onClick={() => go({ direction: k || null })}>{t}</Chip>)}
            </>
        );
    } else if (kind === 'dues') {
        own = <Checkbox label="المتأخرة فقط" checked={Boolean(option('overdue'))} onChange={(e) => go({ overdue: e.target.checked ? '1' : null })} />;
    } else if (kind === 'expiring') {
        own = (
            <>
                <span className="text-[13px] text-muted">تنتهي خلال:</span>
                {['7', '14', '30', '60'].map((d) => <Chip key={d} small on={String(option('days')) === d} onClick={() => go({ days: d === '7' ? null : d })}>{d} يومًا</Chip>)}
            </>
        );
    } else if (kind === 'checkins') {
        own = [['', 'كل النتائج'], ['allowed', 'مسموح'], ['denied', 'مرفوض'], ['duplicate', 'مكرر']].map(([k, t]) => <Chip key={k} small on={(option('result') ?? '') === k} onClick={() => go({ result: k || null })}>{t}</Chip>);
    } else if (kind === 'staff-attendance') {
        const d = data as unknown as StaffAttendance;
        own = (
            <Select className="h-9 w-56 py-1 text-[13px]" value={d.options.user_id ?? ''} onChange={(e) => go({ user_id: e.target.value || null })} aria-label="الموظف">
                <option value="">كل الموظفين</option>
                {d.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
        );
    } else if (kind === 'stock-movements') {
        const d = data as unknown as Tables;
        own = (
            <Select className="h-9 w-56 py-1 text-[13px]" value={String(d.options.type ?? '')} onChange={(e) => go({ type: e.target.value || null })} aria-label="نوع الحركة">
                <option value="">كل أنواع الحركة</option>
                {Object.entries((d.options.types ?? {}) as Record<string, string>).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
        );
    } else if (kind === 'documents') {
        const d = data as unknown as Documents;
        own = (
            <>
                {[['', 'كل السارية'], ['expiring', 'قريبة الانتهاء'], ['expired', 'منتهية'], ['none', 'بلا تاريخ انتهاء']].map(([k, t]) => <Chip key={k} small on={(d.options.state ?? '') === k} onClick={() => go({ state: k || null })}>{t}</Chip>)}
                <Select className="h-9 w-48 py-1 text-[13px]" value={d.options.type ?? ''} onChange={(e) => go({ type: e.target.value || null })} aria-label="نوع المستند">
                    <option value="">كل الأنواع</option>
                    {d.types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </Select>
            </>
        );
    }

    if (!own && !branch) return null;
    return <div className={cn('flex flex-wrap items-center gap-2', !first && 'mt-4 border-t border-line pt-4')}>{branch}{branch && own && <span className="mx-1 hidden h-5 w-px bg-line sm:block" />}{own}</div>;
}

function Body({ kind, data, canFinancial, to }: { kind: string; data: Data; canFinancial: boolean; to: string }) {
    const d = data as never;
    switch (kind) {
        case 'finance': return <FinanceBody report={(data as unknown as { report: PeriodReport }).report} />;
        case 'statements': return <StatementsBody data={d as Statements} to={to} />;
        case 'movement': return <MovementBody data={d as Movement} />;
        case 'dues': return <DuesBody data={d as Dues} />;
        case 'sales': return <SalesBody data={d as Sales} />;
        case 'members': return <MembersBody report={(data as unknown as { report: PeriodReport }).report} canFinancial={canFinancial} />;
        case 'expiring': return <ExpiringBody data={d as Expiring} />;
        case 'classes': return <ClassesBody report={(data as unknown as { report: PeriodReport }).report} />;
        case 'trainers': return <TrainersBody data={d as Trainers} />;
        case 'checkins': return <CheckinsBody data={d as Checkins} />;
        case 'staff-attendance': return <StaffBody data={d as StaffAttendance} />;
        case 'payroll': return <PayrollBody data={d as Payroll} />;
        case 'payouts': return <PayoutsBody data={d as Payouts} />;
        case 'documents': return <DocumentsBody data={d as Documents} />;
        case 'renewals': return <RenewalsBody data={d as Renewals} />;
        case 'branches': return <BranchesBody data={d as Branches} canFinancial={canFinancial} />;
        case 'stock-balance': case 'low-stock': case 'stock-movements': case 'stocktakes': case 'transfers':
        case 'purchases': case 'suppliers': case 'supplier-dues': case 'goods-receipts': return <TablesBody data={d as Tables} />;
        default: return null;
    }
}
