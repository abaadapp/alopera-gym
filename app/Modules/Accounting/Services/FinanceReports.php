<?php

namespace App\Modules\Accounting\Services;

use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Support\Collection;

/**
 * Balances and statements, always summed from journal lines (no stored balances to drift). A balance is signed
 * by the account's normal side: cash with money in it is positive, so is revenue earned or salaries owed.
 */
final class FinanceReports
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /**
     * Debit and credit totals per account id; for one branch when $branch is given (its entries, and for the main
     * branch also the entries without a branch — BranchContext::narrow()).
     *
     * @return array<int, array{debit: string, credit: string}>
     */
    public function totals(?string $from = null, ?string $to = null, ?int $branch = null): array
    {
        return app(BranchContext::class)->narrow(JournalLine::query(), $branch, 'e.branch_id')
            ->join('gym_journal_entries as e', 'e.id', '=', 'gym_journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->groupBy('gym_journal_lines.account_id')
            ->selectRaw('gym_journal_lines.account_id, coalesce(sum(gym_journal_lines.debit), 0) as debit, coalesce(sum(gym_journal_lines.credit), 0) as credit')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->account_id => ['debit' => Money::normalize($row->debit), 'credit' => Money::normalize($row->credit)]])
            ->all();
    }

    public function signed(Account $account, array $totals): string
    {
        $t = $totals[$account->id] ?? ['debit' => '0', 'credit' => '0'];

        return $account->normal_side === 'debit' ? Money::sub($t['debit'], $t['credit']) : Money::sub($t['credit'], $t['debit']);
    }

    public function balance(Account $account, ?string $to = null): string
    {
        return $this->signed($account, $this->totals(null, $to));
    }

    public function balanceOf(string $systemKey, ?string $to = null): string
    {
        return $this->balance($this->chart->account($systemKey), $to);
    }

    /** The cash box, banks and clearing accounts with their balances. */
    public function moneyAccounts(bool $withInactive = false): Collection
    {
        $this->chart->ensure();
        $totals = $this->totals();

        return Account::money()->when(! $withInactive, fn ($q) => $q->where('is_active', true))->orderBy('code')->get()
            ->map(fn (Account $a) => ['account' => $a, 'balance' => $this->signed($a, $totals)]);
    }

    /**
     * Revenue less expenses for a period. Contra accounts (returns, salary deductions) carry their own sign, so
     * they reduce the group they sit in.
     *
     * @return array{revenue: list<array{account: Account, amount: string}>, expenses: list<array{account: Account, amount: string}>, total_revenue: string, total_expenses: string, net: string}
     */
    public function incomeStatement(string $from, string $to, ?int $branch = null): array
    {
        $this->chart->ensure();
        $totals = $this->totals($from, $to, $branch);
        $rows = fn (string $type, string $side) => Account::where('type', $type)->where('is_group', false)->orderBy('code')->get()
            ->map(function (Account $a) use ($totals, $side) {
                $t = $totals[$a->id] ?? ['debit' => '0', 'credit' => '0'];

                return ['account' => $a, 'amount' => $side === 'credit' ? Money::sub($t['credit'], $t['debit']) : Money::sub($t['debit'], $t['credit'])];
            })
            ->filter(fn ($r) => ! Money::isZero($r['amount']) || $r['account']->system_key !== null)
            ->values();

        $revenue = $rows('revenue', 'credit');
        $expenses = $rows('expense', 'debit');
        $totalRevenue = Money::add('0', ...$revenue->pluck('amount')->all());
        $totalExpenses = Money::add('0', ...$expenses->pluck('amount')->all());

        return ['revenue' => $revenue->all(), 'expenses' => $expenses->all(), 'total_revenue' => $totalRevenue, 'total_expenses' => $totalExpenses, 'net' => Money::sub($totalRevenue, $totalExpenses)];
    }

    /** @return array{rows: list<array{account: Account, debit: string, credit: string, balance: string}>, total_debit: string, total_credit: string} */
    public function trialBalance(?string $to = null, ?int $branch = null): array
    {
        $this->chart->ensure();
        $totals = $this->totals(null, $to, $branch);
        $rows = Account::where('is_group', false)->orderBy('code')->get()
            ->filter(fn (Account $a) => isset($totals[$a->id]))
            ->map(fn (Account $a) => ['account' => $a, 'debit' => $totals[$a->id]['debit'], 'credit' => $totals[$a->id]['credit'], 'balance' => $this->signed($a, $totals)])
            ->values();

        return [
            'rows' => $rows->all(),
            'total_debit' => Money::add('0', ...$rows->pluck('debit')->all()),
            'total_credit' => Money::add('0', ...$rows->pluck('credit')->all()),
        ];
    }

    /** The financial summary page: where the money is, what is owed both ways, and the period's result. */
    public function summary(string $from, string $to): array
    {
        $money = $this->moneyAccounts();
        $statement = $this->incomeStatement($from, $to);
        $totals = $this->totals();
        $period = $this->totals($from, $to);
        $salaries = Money::sub($this->signed($this->chart->account('salaries_expense'), $period), $this->signed($this->chart->account('salary_deductions'), $period));

        return [
            'cash' => Money::add('0', ...$money->filter(fn ($m) => $m['account']->kind === 'cash')->pluck('balance')->all()),
            'banks' => Money::add('0', ...$money->filter(fn ($m) => $m['account']->kind === 'bank')->pluck('balance')->all()),
            'clearing' => Money::add('0', ...$money->filter(fn ($m) => in_array($m['account']->kind, ['card', 'gateway'], true))->pluck('balance')->all()),
            'receivable' => $this->signed($this->chart->account('receivable'), $totals),
            'salaries_payable' => $this->signed($this->chart->account('salaries_payable'), $totals),
            'vat_payable' => $this->signed($this->chart->account('vat_payable'), $totals),
            'revenue' => $statement['total_revenue'],
            'expenses' => $statement['total_expenses'],
            'salaries' => $salaries,
            'net' => $statement['net'],
        ];
    }
}
