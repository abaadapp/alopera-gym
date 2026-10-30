<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * الحركة المالية: every amount that came into or left the cash box, the banks and the clearing accounts (or one of
 * them, with its opening and closing balance), in or out, against the comparison period.
 */
final class MovementReport extends Report
{
    public function __construct(private readonly FinanceReports $books) {}

    public function page(ReportFilters $f, User $user): array
    {
        [$accounts, $account, $direction] = $this->choices($f);
        $ids = $account ? [$account->id] : $accounts->pluck('account.id')->all();
        $current = $this->totals($this->lines($f, $ids, $direction, $f->from, $f->to));
        $previous = $this->totals($this->lines($f, $ids, $direction, $f->prevFrom, $f->prevTo));

        return [
            'lines' => $this->lines($f, $ids, $direction, $f->from, $f->to)
                ->with(['entry:id,number,entry_date,description,source_type,source_event,reverses_id,reversed_at', 'account:id,name,kind'])
                ->paginate(50)->withQueryString()->through(fn (JournalLine $l) => $this->row($l)),
            'comparison' => $this->compare($current, $previous),
            'byAccount' => $this->byAccount($f, $ids, $direction),
            'opening' => $account ? $this->books->signed($account, $this->books->totals(null, $f->from->subDay()->toDateString(), $f->branch)) : null,
            'closing' => $account ? $this->books->signed($account, $this->books->totals(null, $f->to->toDateString(), $f->branch)) : null,
            'accounts' => $accounts->map(fn ($m) => ['id' => $m['account']->id, 'name' => $m['account']->name, 'kind' => $m['account']->kind])->values(),
            'options' => ['account_id' => $account?->id, 'direction' => $direction ?: null],
            'empty' => (float) $current['in'] == 0.0 && (float) $current['out'] == 0.0 && ! $this->lines($f, $ids, $direction, $f->from, $f->to)->exists(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        [$accounts, $account, $direction] = $this->choices($f);
        $ids = $account ? [$account->id] : $accounts->pluck('account.id')->all();
        $lines = $this->lines($f, $ids, $direction, $f->from, $f->to);

        return [
            'empty' => ! (clone $lines)->exists(),
            'filters' => [
                ['الحساب', $account?->name ?? 'كل حسابات النقد والبنوك والتسوية'],
                ['الاتجاه', ['in' => 'داخل', 'out' => 'خارج'][$direction] ?? 'الكل'],
            ],
            'sheets' => [
                new S('الحركة', [['التاريخ', S::TEXT], ['رقم القيد', S::TEXT], ['البيان', S::TEXT], ['الحساب', S::TEXT], ['المصدر', S::TEXT], ['داخل', S::MONEY], ['خارج', S::MONEY], ['ملاحظة', S::TEXT], ['الحالة', S::TEXT]],
                    (function () use ($lines) {
                        foreach ($lines->with(['entry:id,number,entry_date,description,source_type,source_event,reverses_id,reversed_at', 'account:id,name,kind'])->lazy(500) as $l) {
                            $r = $this->row($l);
                            yield [$r['date'], $r['number'], $r['description'], $r['account'], $r['source'], $r['in'], $r['out'], $r['memo'], $r['reversed'] ? 'معكوس' : ''];
                        }
                    })()),
                new S('حسب الحساب', [['الحساب', S::TEXT], ['داخل', S::MONEY], ['خارج', S::MONEY], ['الصافي', S::MONEY]],
                    array_map(fn ($a) => [$a['name'], $a['in'], $a['out'], $a['net']], $this->byAccount($f, $ids, $direction))),
            ],
        ];
    }

    /** The money accounts, the one asked for (only a money account), and the direction. */
    private function choices(ReportFilters $f): array
    {
        $accounts = $this->books->moneyAccounts(withInactive: true);
        $asked = $f->integer('account_id');
        $account = $asked ? $accounts->first(fn ($m) => $m['account']->id === $asked)['account'] ?? null : null;

        return [$accounts, $account, $f->choice('direction', ['in', 'out'])];
    }

    /** @param list<int> $ids */
    private function lines(ReportFilters $f, array $ids, string $direction, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->in(JournalLine::query(), $f, 'je.branch_id')
            ->join('gym_journal_entries as je', 'je.id', '=', 'gym_journal_lines.journal_entry_id')
            ->whereIn('gym_journal_lines.account_id', $ids)
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($direction === 'in', fn ($q) => $q->where('gym_journal_lines.debit', '>', 0))
            ->when($direction === 'out', fn ($q) => $q->where('gym_journal_lines.credit', '>', 0))
            ->orderByDesc('je.entry_date')->orderByDesc('gym_journal_lines.id')->select('gym_journal_lines.*');
    }

    /** @return array{in: string, out: string, net: string} */
    private function totals(Builder $lines): array
    {
        $t = $lines->toBase()->reorder()->select(DB::raw('coalesce(sum(gym_journal_lines.debit), 0) as d'), DB::raw('coalesce(sum(gym_journal_lines.credit), 0) as c'))->first();

        return ['in' => Money::normalize($t->d), 'out' => Money::normalize($t->c), 'net' => Money::sub($t->d, $t->c)];
    }

    /** @param list<int> $ids */
    private function byAccount(ReportFilters $f, array $ids, string $direction): array
    {
        return $this->lines($f, $ids, $direction, $f->from, $f->to)->toBase()->reorder()
            ->join('gym_accounts as a', 'a.id', '=', 'gym_journal_lines.account_id')
            ->groupBy('a.id', 'a.name', 'a.code')->orderBy('a.code')
            ->select('a.name', DB::raw('coalesce(sum(gym_journal_lines.debit), 0) as d'), DB::raw('coalesce(sum(gym_journal_lines.credit), 0) as c'))
            ->get()->map(fn ($r) => ['name' => $r->name, 'in' => Money::normalize($r->d), 'out' => Money::normalize($r->c), 'net' => Money::sub($r->d, $r->c)])->all();
    }

    private function row(JournalLine $l): array
    {
        return [
            'id' => $l->id, 'date' => $l->entry->entry_date->toDateString(), 'number' => $l->entry->number, 'description' => $l->entry->description,
            'source' => $l->entry->sourceLabel(), 'account' => $l->account->name, 'kind' => $l->account->kind, 'in' => $l->debit, 'out' => $l->credit, 'memo' => $l->memo,
            'reversed' => $l->entry->reversed_at !== null,
        ];
    }
}
