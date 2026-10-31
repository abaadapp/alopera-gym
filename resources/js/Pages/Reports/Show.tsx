import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import ExportMenu from '@/Components/Reports/ExportMenu';
import { DuesBody, FinanceBody, MovementBody, StatementsBody, type Dues, type Movement, type PeriodReport, type Statements } from '@/Components/Reports/Finance';
import { CheckinsBody, ClassesBody, ExpiringBody, MembersBody, StaffBody, TrainersBody, type Checkins, type Expiring, type StaffAttendance, type Trainers } from '@/Components/Reports/People';
import { PayoutsBody, PayrollBody, type Payouts, type Payroll } from '@/Components/Reports/Payroll';
import { DocumentsBody, RenewalsBody, type Documents, type Renewals } from '@/Components/Reports/Documents';
import { BranchesBody, SalesBody, type Branches, type Sales } from '@/Components/Reports/Sales';
import { TablesBody, type Tables } from '@/Components/Reports/Tables';
import { BackLink, Button, Card, Checkbox, Input, Notice, PageHeader, Select, Tabs } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { date } from '@/lib/format';

type Data = Record<string, unknown> & { empty: boolean };

interface Props {
    /** Which report (not `key`: React keeps that name for itself). */
    kind: string;
    title: string;
    description: string;
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
            className={cn('rounded-full border font-medium transition-colors', small ? 'px-3 py-1 text-[12px]' : 'px-4 py-1.5 text-[13px]', on ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e7eb] bg-white text-[#4b5563] hover:border-[#d1d5db]')}
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
/** A filter of the filter card: a 12px caption over its 40px field. */
function Captioned({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="flex min-w-[10rem] flex-1 flex-col gap-1 sm:max-w-[16rem]">
            <span className="text-[12px] text-[#6b7280]">{label}</span>
            {children}
        </label>
    );
}

export default function ReportShow({ kind, title, description, mode, compare, filters, branchFilter, canFinancial, canExport, data }: Props) {
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
                back={<BackLink href={route('club.reports.index')}>كل التقارير</BackLink>}
                title={title}
                subtitle={description}
                actions={<ExportMenu href={canExport ? route('club.reports.export', kind) : null} params={exportParams} title={title} size="md" />}
            />

            {mode === 'period' && (
                <Tabs
                    className="mb-4"
                    tabs={presets.map((p) => ({ key: p.key, label: p.label }))}
                    value={filters.preset}
                    onChange={(key) => go({ preset: key, from: null, to: null })}
                    trailing={
                        <form className="flex items-center gap-2 py-1.5" onSubmit={(e) => { e.preventDefault(); go({ preset: null, from, to }); }}>
                            <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="من" />
                            <span className="text-[#9ca3af]">—</span>
                            <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={to} onChange={(e) => setTo(e.target.value)} aria-label="إلى" />
                            <Button type="submit" variant="outline" size="sm">عرض</Button>
                        </form>
                    }
                />
            )}

            <ReportFilters kind={kind} data={data} option={option} go={go} branch={branchFilter && (
                branchFilter.choices.length > 0 ? (
                    <Captioned label="الفرع">
                        <Select value={filters.branch ?? 'all'} onChange={(e) => go({ branch: e.target.value })}>
                            <option value="all">كل الفروع</option>
                            {branchFilter.choices.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    </Captioned>
                ) : (
                    <Captioned label="الفرع"><span className="flex h-10 items-center rounded-[10px] border border-line bg-[#fafafa] px-3 text-sm text-[#4b4b4b]">{branchFilter.name}</span></Captioned>
                )
            )} compareRow={mode === 'period' && compare ? (
                <div className="flex basis-full flex-wrap items-center gap-2">
                    <span className="text-[12px] text-[#6b7280]">قارن مع:</span>
                    {compareModes.map((m) => (
                        <Chip key={m.key} small on={customCompare ? m.key === 'custom' : filters.compare === m.key}
                            onClick={() => (m.key === 'custom' ? setCustomCompare(true) : (setCustomCompare(false), go({ compare: m.key === 'previous' ? null : m.key, compare_from: null, compare_to: null })))}>
                            {m.label}
                        </Chip>
                    ))}
                    {customCompare && (
                        <form className="flex flex-wrap items-center gap-2" onSubmit={(e) => { e.preventDefault(); go({ compare: 'custom', compare_from: compareFrom, compare_to: compareTo }); }}>
                            <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={compareFrom} onChange={(e) => setCompareFrom(e.target.value)} aria-label="مقارنة من" />
                            <span className="text-[#9ca3af]">—</span>
                            <Input type="date" className="h-8 w-[8.5rem] text-[13px]" value={compareTo} onChange={(e) => setCompareTo(e.target.value)} aria-label="مقارنة إلى" />
                            <Button type="submit" variant="outline" size="sm">قارن</Button>
                        </form>
                    )}
                </div>
            ) : null} />

            <p className="mb-3 text-[12px] text-[#9ca3af]">{subtitle}{branchFilter && <> · {branchFilter.name}</>}</p>

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
function ReportFilters({ kind, data, option, go, branch, compareRow }: { kind: string; data: Data; option: (k: string) => unknown; go: (p: Record<string, string | null>) => void; branch: ReactNode; compareRow: ReactNode }) {
    let own: ReactNode = null;
    if (kind === 'statements') {
        const view = (data as unknown as Statements).view;
        own = (['income', 'trial'] as const).map((v) => <Chip key={v} small on={view === v} onClick={() => go({ view: v === 'income' ? null : v })}>{v === 'income' ? 'قائمة الدخل' : 'ميزان المراجعة'}</Chip>);
    } else if (kind === 'movement') {
        const d = data as unknown as Movement;
        own = (
            <>
                <Captioned label="الحساب">
                    <Select value={d.options.account_id ?? ''} onChange={(e) => go({ account_id: e.target.value || null })}>
                        <option value="">الكل</option>
                        {d.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </Select>
                </Captioned>
                {[['', 'الكل'], ['in', 'داخل'], ['out', 'خارج']].map(([k, t]) => <Chip key={k} small on={(d.options.direction ?? '') === k} onClick={() => go({ direction: k || null })}>{t}</Chip>)}
            </>
        );
    } else if (kind === 'dues') {
        own = <Checkbox className="h-10 text-[13px]" label="المتأخرة فقط" checked={Boolean(option('overdue'))} onChange={(e) => go({ overdue: e.target.checked ? '1' : null })} />;
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
            <Captioned label="الموظف">
                <Select value={d.options.user_id ?? ''} onChange={(e) => go({ user_id: e.target.value || null })}>
                    <option value="">الكل</option>
                    {d.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </Select>
            </Captioned>
        );
    } else if (kind === 'stock-movements') {
        const d = data as unknown as Tables;
        own = (
            <Captioned label="نوع الحركة">
                <Select value={String(d.options.type ?? '')} onChange={(e) => go({ type: e.target.value || null })}>
                    <option value="">الكل</option>
                    {Object.entries((d.options.types ?? {}) as Record<string, string>).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </Select>
            </Captioned>
        );
    } else if (kind === 'documents') {
        const d = data as unknown as Documents;
        own = (
            <>
                {[['', 'كل السارية'], ['expiring', 'قريبة الانتهاء'], ['expired', 'منتهية'], ['none', 'بلا تاريخ انتهاء']].map(([k, t]) => <Chip key={k} small on={(d.options.state ?? '') === k} onClick={() => go({ state: k || null })}>{t}</Chip>)}
                <Captioned label="نوع المستند">
                    <Select value={d.options.type ?? ''} onChange={(e) => go({ type: e.target.value || null })}>
                        <option value="">الكل</option>
                        {d.types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </Captioned>
            </>
        );
    }

    if (!own && !branch && !compareRow) return null;
    return (
        <Card padded={false} className="mb-6 flex flex-wrap items-end gap-3 p-4">
            {branch}
            {own && <div className="flex flex-wrap items-end gap-2">{own}</div>}
            {compareRow}
        </Card>
    );
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
