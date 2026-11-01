<?php

namespace App\Modules\Accounting\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Closed accounting periods. A month is closed once it is over, in order (every month before it closed first), after
 * its checks: every entry balanced and every invoice, payment, credit note and expense of the month in the books.
 * From then on nothing is posted with a date on or before the last closed day (Ledger::post refuses it, and the
 * screens that take a date say so first); a correction is posted in an open period. Reopening takes its own
 * permission and a reason, latest month first, and is written to the audit trail like the closing.
 *
 * A year is closed once all its months are; it records the year's figures. Its months reopen only after the year does.
 * No closing entries are posted: revenue and expense accounts are read per period, so the books stay as they were.
 */
final class AccountingPeriods
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    /** The last day of the last closed month: nothing dated on or before it is posted. */
    public function lockedThrough(): ?CarbonImmutable
    {
        $through = AccountingPeriod::where('kind', AccountingPeriod::MONTH)->where('status', AccountingPeriod::CLOSED)->max('ends_on');

        return $through ? CarbonImmutable::parse($through)->startOfDay() : null;
    }

    public function isLocked(DateTimeInterface|string $date): bool
    {
        $through = $this->lockedThrough();

        return $through !== null && CarbonImmutable::parse($date)->startOfDay()->lte($through);
    }

    public function closedMessage(?DateTimeInterface $through = null): string
    {
        $through ??= $this->lockedThrough();

        return 'التاريخ داخل فترة محاسبية مقفلة (حتى '.$through?->format('d/m/Y').'). اختر تاريخًا بعدها، أو اطلب إعادة فتح الفترة.';
    }

    public function assertOpen(DateTimeInterface|string $date, string $field = 'error', ?string $message = null): void
    {
        if ($this->isLocked($date)) {
            throw new BusinessRuleException('period.closed', $message ?? $this->closedMessage(), $field);
        }
    }

    /** A record dated in a closed period is final: it is not edited or deleted; its correction goes in an open period. */
    public function assertEditable(DateTimeInterface|string $date, string $what): void
    {
        $this->assertOpen($date, 'error', $what.' في فترة محاسبية مقفلة (حتى '.$this->lockedThrough()?->format('d/m/Y').')؛ لا يُعدّل ولا يُحذف. سجّل التصحيح بتاريخ في فترة مفتوحة.');
    }

    /** Hold off a closing (or reopening) of the club's months until the current transaction ends. */
    public function holdClosing(): void
    {
        Tenant::whereKey($this->tenants->require()->id)->sharedLock()->first();
    }

    /** The date a correction is posted on: its own date if that is open, else today (always open — a month closes once over). */
    public function postingDate(DateTimeInterface|string|null $date): CarbonImmutable
    {
        $date = $date === null ? CarbonImmutable::now() : CarbonImmutable::parse($date);

        return $this->isLocked($date) ? CarbonImmutable::now() : $date;
    }

    /**
     * Every month from the first one with an entry to the last one that is over, latest first, with its status and
     * figures.
     *
     * @return list<array<string, mixed>>
     */
    public function months(): array
    {
        $first = JournalEntry::min('entry_date');
        $rows = AccountingPeriod::with('closer:id,name', 'reopener:id,name')->where('kind', AccountingPeriod::MONTH)->get()->keyBy(fn ($p) => $p->starts_on->format('Y-m'));
        $earliest = collect([$first, $rows->min(fn ($p) => $p->starts_on)])->filter()->map(fn ($d) => CarbonImmutable::parse($d))->min();
        if (! $earliest) {
            return [];
        }

        $figures = $this->monthlyFigures();
        $last = CarbonImmutable::today()->startOfMonth()->subMonth();
        $through = $this->lockedThrough();
        $out = [];
        for ($m = $last; $m->gte($earliest->startOfMonth()); $m = $m->subMonth()) {
            $key = $m->format('Y-m');
            $row = $rows->get($key);
            $f = $figures[$key] ?? ['entries' => 0, 'revenue' => '0.000', 'expenses' => '0.000'];
            $out[] = [
                'month' => $key,
                'label' => $m->locale('ar')->translatedFormat('F Y'),
                'year' => (int) $m->year,
                'status' => $row?->status ?? 'open',
                'entries' => $f['entries'],
                'revenue' => $f['revenue'],
                'expenses' => $f['expenses'],
                'net' => Money::sub($f['revenue'], $f['expenses']),
                'closed_at' => $row?->closed_at?->format('Y-m-d H:i'),
                'closed_by' => $row?->closer?->name,
                'reopened_at' => $row?->reopened_at?->format('Y-m-d H:i'),
                'reopened_by' => $row?->reopener?->name,
                'reopen_reason' => $row?->reopen_reason,
                'times_closed' => $row?->times_closed ?? 0,
                'warnings' => $row?->isClosed() ? ($row->warnings ?? []) : [],
                // What may be done with it now: close the next open month (or through it), reopen the latest closed one.
                'closable' => ! $row?->isClosed(),
                'reopenable' => $row?->isClosed() && $through !== null && $m->endOfMonth()->startOfDay()->equalTo($through),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function years(): array
    {
        $rows = AccountingPeriod::with('closer:id,name', 'reopener:id,name')->where('kind', AccountingPeriod::YEAR)->get()->keyBy(fn ($p) => (int) $p->starts_on->year);
        $first = JournalEntry::min('entry_date');
        if (! $first) {
            return [];
        }
        $through = $this->lockedThrough();
        $out = [];
        for ($y = (int) CarbonImmutable::today()->year - 1; $y >= (int) CarbonImmutable::parse($first)->year; $y--) {
            $row = $rows->get($y);
            $out[] = [
                'year' => $y,
                'status' => $row?->status ?? 'open',
                'summary' => $row?->summary,
                'closed_at' => $row?->closed_at?->format('Y-m-d H:i'),
                'closed_by' => $row?->closer?->name,
                'reopened_at' => $row?->reopened_at?->format('Y-m-d H:i'),
                'reopened_by' => $row?->reopener?->name,
                'reopen_reason' => $row?->reopen_reason,
                'closable' => ! $row?->isClosed() && $through !== null && $through->gte(CarbonImmutable::create($y, 12, 31)),
                'reopenable' => (bool) $row?->isClosed(),
            ];
        }

        return $out;
    }

    /**
     * What closing a month would find. Errors stop the closing; warnings are things still open that will be posted
     * in an open period (a transfer confirmed later) and are kept with the closed month; `pending` are records not
     * in the books yet, posted by the closing itself (the daily sync would post them too).
     *
     * @return array{month: string, label: string, errors: list<string>, warnings: list<string>, pending: list<string>, summary: array<string, mixed>}
     */
    public function checks(string $month): array
    {
        [$start, $end] = $this->bounds($month);
        $from = $start->toDateString();
        $to = $end->toDateString();
        $errors = [];

        $unbalanced = JournalLine::query()->join('gym_journal_entries as e', 'e.id', '=', 'gym_journal_lines.journal_entry_id')
            ->whereBetween('e.entry_date', [$from, $to])->groupBy('e.id', 'e.number')
            ->havingRaw('sum(gym_journal_lines.debit) <> sum(gym_journal_lines.credit) or count(*) < 2')
            ->pluck('e.number');
        if ($unbalanced->isNotEmpty()) {
            $errors[] = 'قيود غير متوازنة: '.$unbalanced->take(10)->implode('، ').'. صحّحها بقيد عكسي قبل الإقفال.';
        }
        $mismatched = JournalEntry::whereBetween('entry_date', [$from, $to])
            ->whereRaw('total <> (select coalesce(sum(l.debit), 0) from gym_journal_lines l where l.journal_entry_id = gym_journal_entries.id)')->pluck('number');
        if ($mismatched->isNotEmpty()) {
            $errors[] = 'قيود يختلف إجماليها عن سطورها: '.$mismatched->take(10)->implode('، ').'.';
        }

        $pending = array_values(array_filter([
            $this->listed('فواتير', Invoice::query()->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])->where('total', '>', 0)->whereBetween('issue_date', [$from, $to])
                ->whereNotExists(fn (Builder $q) => $this->entryOf($q, 'invoice', 'invoices.id', ['issue']))->pluck('number')),
            $this->listed('فواتير ملغاة لم يُعكس قيدها', Invoice::query()->where('status', Invoice::VOID)->whereBetween('voided_at', [$start, $end->endOfDay()])
                ->whereExists(fn (Builder $q) => $this->entryOf($q, 'invoice', 'invoices.id', ['issue'])->whereNull('reversed_at'))->pluck('number')),
            $this->listed('دفعات', Payment::query()->where('status', Payment::COMPLETED)->where('amount', '>', 0)
                ->whereRaw('coalesce(confirmed_at, paid_at, created_at) between ? and ?', [$start, $end->endOfDay()])
                ->whereNotExists(fn (Builder $q) => $this->entryOf($q, 'payment', 'payments.id', ['receive', 'refund']))->get()->map(fn ($p) => $p->receipt_number ?? '#'.$p->id)),
            $this->listed('إشعارات دائنة', CreditNote::query()->where('amount', '>', 0)->whereBetween('created_at', [$start, $end->endOfDay()])
                ->whereNotExists(fn (Builder $q) => $this->entryOf($q, 'credit_note', 'credit_notes.id', ['issue']))->pluck('number')),
            $this->listed('مصروفات', Expense::query()->whereBetween('expense_date', [$from, $to])
                ->whereNotExists(fn (Builder $q) => $this->entryOf($q, 'expense', 'expenses.id', ['record'])->whereNull('reversed_at'))->pluck('number')),
        ]));

        $warnings = array_values(array_filter([
            $this->counted('تحويلات بنكية بانتظار التأكيد؛ تُقيَّد بتاريخ تأكيدها', Payment::query()->where('status', Payment::PENDING)->whereBetween('paid_at', [$start, $end->endOfDay()])->count()),
            $this->counted('مسودات فواتير بتاريخ في هذا الشهر؛ لن تُصدر بهذا التاريخ بعد الإقفال', Invoice::query()->where('status', Invoice::DRAFT)->whereBetween('issue_date', [$from, $to])->count()),
            $this->counted('سندات موردين بانتظار الاعتماد بتاريخ في هذا الشهر', SupplierBill::query()->where('status', SupplierBill::PENDING)->whereBetween('issue_date', [$from, $to])->count()),
            PayrollRun::query()->where('status', PayrollRun::DRAFT)->whereDate('period', $from)->exists() ? 'مسيرة رواتب هذا الشهر ما زالت مسودة؛ لن تُعتمد بتاريخه بعد الإقفال.' : null,
        ]));

        return ['month' => $start->format('Y-m'), 'label' => $start->locale('ar')->translatedFormat('F Y'), 'errors' => $errors, 'warnings' => $warnings, 'pending' => $pending, 'summary' => $this->summary($from, $to)];
    }

    /**
     * Close every open month up to this one, in order, all or none. Each is checked after its missing postings are
     * made; the first with an error stops the closing and nothing is closed.
     *
     * @return list<string> the months closed
     */
    public function closeThrough(string $month, User $user): array
    {
        [$target] = $this->bounds($month);
        if ($target->endOfMonth()->startOfDay()->gte(CarbonImmutable::today())) {
            throw new BusinessRuleException('period.not_over', 'لا يُقفل شهر قبل أن ينتهي.', 'month');
        }

        return DB::transaction(function () use ($target, $user) {
            // One closing at a time for the club.
            Tenant::whereKey($this->tenants->require()->id)->lockForUpdate()->first();
            if (AccountingPeriod::where(['kind' => AccountingPeriod::MONTH, 'starts_on' => $target->toDateString(), 'status' => AccountingPeriod::CLOSED])->exists()) {
                throw new BusinessRuleException('period.already_closed', 'هذا الشهر مقفل مسبقًا.', 'month');
            }

            // Whatever is not in the books yet is posted first (the same as the daily sync).
            app(LedgerProjector::class)->syncClub();

            $through = $this->lockedThrough();
            $first = CarbonImmutable::parse(JournalEntry::min('entry_date') ?? $target)->startOfMonth();
            $start = $through ? $through->addDay()->startOfMonth() : $first->min($target);
            $closed = [];
            for ($m = $start; $m->lte($target); $m = $m->addMonth()) {
                $checks = $this->checks($m->format('Y-m'));
                $problems = [...$checks['errors'], ...array_map(fn ($p) => $p.' — لم تدخل الدفاتر بعد المزامنة.', $checks['pending'])];
                if ($problems !== []) {
                    throw new BusinessRuleException('period.errors', 'لم يُقفل شيء. '.$checks['label'].': '.implode(' ', $problems), 'month');
                }

                $row = AccountingPeriod::firstOrNew(['kind' => AccountingPeriod::MONTH, 'starts_on' => $m->toDateString()]);
                $row->forceFill([
                    'ends_on' => $m->endOfMonth()->toDateString(),
                    'status' => AccountingPeriod::CLOSED,
                    'summary' => $checks['summary'],
                    'warnings' => $checks['warnings'],
                    'closed_at' => now(),
                    'closed_by' => $user->id,
                    'times_closed' => $row->times_closed + 1,
                ])->save();
                $this->audit->log('accounting.period_closed', $row, ['month' => $m->format('Y-m'), 'times' => $row->times_closed, 'warnings' => count($checks['warnings'])], new: $checks['summary']);
                $closed[] = $checks['label'];
            }

            return $closed;
        });
    }

    public function reopen(string $month, string $reason, User $user): AccountingPeriod
    {
        [$start] = $this->bounds($month);

        return DB::transaction(function () use ($start, $reason, $user) {
            Tenant::whereKey($this->tenants->require()->id)->lockForUpdate()->first();
            $row = AccountingPeriod::where(['kind' => AccountingPeriod::MONTH, 'starts_on' => $start->toDateString()])->lockForUpdate()->first();
            if (! $row?->isClosed()) {
                throw new BusinessRuleException('period.not_closed', 'هذا الشهر غير مقفل.', 'month');
            }
            $later = AccountingPeriod::where('kind', AccountingPeriod::MONTH)->where('status', AccountingPeriod::CLOSED)->where('starts_on', '>', $start->toDateString())->orderBy('starts_on')->get();
            if ($later->isNotEmpty()) {
                throw new BusinessRuleException('period.later_closed', 'أعد فتح الأشهر اللاحقة أولًا، من الأحدث: '.$later->map(fn ($p) => $p->starts_on->locale('ar')->translatedFormat('F Y'))->implode('، ').'.', 'month');
            }
            if (AccountingPeriod::where(['kind' => AccountingPeriod::YEAR, 'starts_on' => $start->startOfYear()->toDateString(), 'status' => AccountingPeriod::CLOSED])->exists()) {
                throw new BusinessRuleException('period.year_closed', 'سنة '.$start->year.' مقفلة؛ أعد فتح السنة أولًا.', 'month');
            }

            $row->forceFill(['status' => AccountingPeriod::REOPENED, 'reopened_at' => now(), 'reopened_by' => $user->id, 'reopen_reason' => $reason])->save();
            $this->audit->log('accounting.period_reopened', $row, ['month' => $start->format('Y-m'), 'reason' => $reason]);

            return $row;
        });
    }

    public function closeYear(int $year, User $user): AccountingPeriod
    {
        $start = CarbonImmutable::create($year, 1, 1);
        $end = $start->endOfYear()->startOfDay();

        return DB::transaction(function () use ($start, $end, $year, $user) {
            Tenant::whereKey($this->tenants->require()->id)->lockForUpdate()->first();
            $through = $this->lockedThrough();
            if ($through === null || $through->lt($end)) {
                throw new BusinessRuleException('period.months_open', 'أقفل كل أشهر سنة '.$year.' أولًا'.($through ? ' (آخر شهر مقفل: '.$through->locale('ar')->translatedFormat('F Y').').' : '.'), 'year');
            }
            $row = AccountingPeriod::firstOrNew(['kind' => AccountingPeriod::YEAR, 'starts_on' => $start->toDateString()]);
            if ($row->isClosed()) {
                throw new BusinessRuleException('period.already_closed', 'هذه السنة مقفلة مسبقًا.', 'year');
            }
            $summary = $this->summary($start->toDateString(), $end->toDateString());
            $row->forceFill([
                'ends_on' => $end->toDateString(), 'status' => AccountingPeriod::CLOSED, 'summary' => $summary, 'warnings' => [],
                'closed_at' => now(), 'closed_by' => $user->id, 'times_closed' => $row->times_closed + 1,
            ])->save();
            $this->audit->log('accounting.year_closed', $row, ['year' => $year, 'times' => $row->times_closed], new: $summary);

            return $row;
        });
    }

    public function reopenYear(int $year, string $reason, User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($year, $reason, $user) {
            Tenant::whereKey($this->tenants->require()->id)->lockForUpdate()->first();
            $row = AccountingPeriod::where(['kind' => AccountingPeriod::YEAR, 'starts_on' => CarbonImmutable::create($year, 1, 1)->toDateString()])->lockForUpdate()->first();
            if (! $row?->isClosed()) {
                throw new BusinessRuleException('period.not_closed', 'هذه السنة غير مقفلة.', 'year');
            }
            $row->forceFill(['status' => AccountingPeriod::REOPENED, 'reopened_at' => now(), 'reopened_by' => $user->id, 'reopen_reason' => $reason])->save();
            $this->audit->log('accounting.year_reopened', $row, ['year' => $year, 'reason' => $reason]);

            return $row;
        });
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function bounds(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new BusinessRuleException('period.month', 'اختر شهرًا صحيحًا.', 'month');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /** The figures kept with a closed period: the entries and the result, as the books have them. */
    private function summary(string $from, string $to): array
    {
        $totals = JournalLine::query()->join('gym_journal_entries as e', 'e.id', '=', 'gym_journal_lines.journal_entry_id')
            ->whereBetween('e.entry_date', [$from, $to])
            ->selectRaw('count(distinct e.id) as entries, coalesce(sum(gym_journal_lines.debit), 0) as debit, coalesce(sum(gym_journal_lines.credit), 0) as credit')->first();
        $statement = app(FinanceReports::class)->incomeStatement($from, $to);
        $chart = app(ChartOfAccounts::class);
        $vat = JournalLine::query()->join('gym_journal_entries as e', 'e.id', '=', 'gym_journal_lines.journal_entry_id')
            ->whereBetween('e.entry_date', [$from, $to])->where('gym_journal_lines.account_id', $chart->account('vat_payable')->id)
            ->selectRaw('coalesce(sum(credit), 0) - coalesce(sum(debit), 0) as net')->value('net');

        return [
            'entries' => (int) $totals->entries,
            'debit' => Money::normalize($totals->debit),
            'credit' => Money::normalize($totals->credit),
            'revenue' => $statement['total_revenue'],
            'expenses' => $statement['total_expenses'],
            'net' => $statement['net'],
            'vat' => Money::normalize($vat ?? 0),
        ];
    }

    /** @return array<string, array{entries: int, revenue: string, expenses: string}> */
    private function monthlyFigures(): array
    {
        $out = [];
        JournalLine::query()->join('gym_journal_entries as e', 'e.id', '=', 'gym_journal_lines.journal_entry_id')
            ->join('gym_accounts as a', 'a.id', '=', 'gym_journal_lines.account_id')
            ->selectRaw("to_char(e.entry_date, 'YYYY-MM') as m, a.type, coalesce(sum(gym_journal_lines.debit), 0) as debit, coalesce(sum(gym_journal_lines.credit), 0) as credit")
            ->groupByRaw("to_char(e.entry_date, 'YYYY-MM'), a.type")->get()
            ->each(function ($r) use (&$out) {
                $o = $out[$r->m] ?? ['entries' => 0, 'revenue' => '0.000', 'expenses' => '0.000'];
                if ($r->type === 'revenue') {
                    $o['revenue'] = Money::add($o['revenue'], Money::sub($r->credit, $r->debit));
                } elseif ($r->type === 'expense') {
                    $o['expenses'] = Money::add($o['expenses'], Money::sub($r->debit, $r->credit));
                }
                $out[$r->m] = $o;
            });
        $counts = JournalEntry::query()->selectRaw("to_char(entry_date, 'YYYY-MM') as m, count(*) as n")->groupByRaw("to_char(entry_date, 'YYYY-MM')")->pluck('n', 'm');
        foreach ($counts as $m => $n) {
            $out[$m] = ['entries' => (int) $n] + ($out[$m] ?? ['revenue' => '0.000', 'expenses' => '0.000']);
        }

        return $out;
    }

    private function entryOf(Builder $q, string $type, string $column, array $events): Builder
    {
        return $q->from('gym_journal_entries')->where('gym_journal_entries.source_type', $type)
            ->whereColumn('gym_journal_entries.source_id', $column)->whereIn('gym_journal_entries.source_event', $events);
    }

    private function listed(string $label, $numbers): ?string
    {
        $numbers = collect($numbers);

        return $numbers->isEmpty() ? null : $label.' ('.$numbers->count().'): '.$numbers->take(8)->implode('، ').($numbers->count() > 8 ? '…' : '');
    }

    private function counted(string $label, int $n): ?string
    {
        return $n > 0 ? $label.' ('.$n.').' : null;
    }
}
