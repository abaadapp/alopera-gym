<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Export\TableExporter;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** الملخص المالي، الحركة المالية، التقارير المالية. */
class FinanceController extends Controller
{
    public function __construct(private readonly FinanceReports $reports) {}

    public function summary(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $summary = $this->reports->summary($from, $to);

        return Inertia::render('Finance/Summary', [
            'summary' => $summary,
            'accounts' => $this->reports->moneyAccounts()->map(fn ($m) => $this->money($m['account'], $m['balance']))->values(),
            'overdue' => [
                'count' => Invoice::whereIn('status', Invoice::OPEN_STATUSES)->whereDate('due_date', '<', today())->count(),
                'amount' => Money::normalize(Invoice::whereIn('status', Invoice::OPEN_STATUSES)->whereDate('due_date', '<', today())->selectRaw('coalesce(sum(total - paid_total - credited_total), 0) as v')->value('v')),
            ],
            'unpaidSalaries' => Money::normalize(PayrollItem::whereNull('paid_at')->whereHas('run', fn ($q) => $q->where('status', PayrollRun::APPROVED))->sum('net')),
            'recent' => JournalEntry::with('lines.account:id,name,kind')->latest('entry_date')->latest('id')->limit(8)->get()->map(fn (JournalEntry $e) => $this->entryRow($e)),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /** Every line that touched a money account (or one chosen account), with a running balance for a single account. */
    public function movement(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $money = $this->reports->moneyAccounts(withInactive: true);
        $accountId = $request->integer('account_id') ?: null;
        $account = $accountId ? Account::find($accountId) : null;
        $ids = $account ? [$account->id] : $money->pluck('account.id')->all();

        $query = JournalLine::with(['entry:id,number,entry_date,description,source_type,source_event,reverses_id,reversed_at', 'account:id,name,kind,normal_side'])
            ->whereIn('account_id', $ids)
            ->whereHas('entry', fn ($q) => $q->whereBetween('entry_date', [$from, $to]))
            ->when($request->string('direction')->toString() === 'in', fn ($q) => $q->where('debit', '>', 0))
            ->when($request->string('direction')->toString() === 'out', fn ($q) => $q->where('credit', '>', 0))
            ->join('gym_journal_entries as je', 'je.id', '=', 'gym_journal_lines.journal_entry_id')
            ->orderByDesc('je.entry_date')->orderByDesc('gym_journal_lines.id')->select('gym_journal_lines.*');

        $totals = (clone $query)->toBase()->reorder()->select(DB::raw('coalesce(sum(gym_journal_lines.debit), 0) as d'), DB::raw('coalesce(sum(gym_journal_lines.credit), 0) as c'))->first();
        $opening = $account ? $this->reports->signed($account, $this->reports->totals(null, CarbonImmutable::parse($from)->subDay()->toDateString())) : null;

        return Inertia::render('Finance/Movement', [
            'lines' => $query->paginate(50)->withQueryString()->through(fn (JournalLine $l) => [
                'id' => $l->id, 'date' => $l->entry->entry_date->toDateString(), 'number' => $l->entry->number, 'description' => $l->entry->description,
                'source' => $l->entry->sourceLabel(), 'account' => $l->account->name, 'kind' => $l->account->kind, 'in' => $l->debit, 'out' => $l->credit, 'memo' => $l->memo,
                'reversed' => $l->entry->reversed_at !== null,
            ]),
            'stats' => ['in' => Money::normalize($totals->d), 'out' => Money::normalize($totals->c), 'net' => Money::sub($totals->d, $totals->c)],
            'opening' => $opening,
            'closing' => $account ? $this->reports->signed($account, $this->reports->totals(null, $to)) : null,
            'accounts' => $money->map(fn ($m) => ['id' => $m['account']->id, 'name' => $m['account']->name, 'kind' => $m['account']->kind])->values(),
            'filters' => ['from' => $from, 'to' => $to, 'account_id' => $accountId, 'direction' => $request->string('direction')->toString() ?: null],
        ]);
    }

    /** قائمة الدخل and ميزان المراجعة for a period. */
    public function statements(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $income = $this->reports->incomeStatement($from, $to);
        $trial = $this->reports->trialBalance($to);
        $row = fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'amount' => $r['amount'], 'contra' => in_array($r['account']->system_key, ['sales_returns', 'salary_deductions'], true)];

        return Inertia::render('Finance/Statements', [
            'income' => ['revenue' => array_map($row, $income['revenue']), 'expenses' => array_map($row, $income['expenses']), 'total_revenue' => $income['total_revenue'], 'total_expenses' => $income['total_expenses'], 'net' => $income['net']],
            'trial' => ['rows' => array_map(fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'type' => $r['account']->type, 'debit' => $r['debit'], 'credit' => $r['credit'], 'balance' => $r['balance'], 'side' => $r['account']->normal_side], $trial['rows']), 'total_debit' => $trial['total_debit'], 'total_credit' => $trial['total_credit']],
            'filters' => ['from' => $from, 'to' => $to, 'view' => $request->string('view')->toString() === 'trial' ? 'trial' : 'income'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        if ($request->query('view') === 'trial') {
            $trial = $this->reports->trialBalance($to);
            $rows = collect($trial['rows'])->map(fn ($r) => [$r['account']->code, $r['account']->name, $r['debit'], $r['credit'], $r['balance']])->push(['', 'الإجمالي', $trial['total_debit'], $trial['total_credit'], '']);

            return TableExporter::download("trial-balance-{$to}", ['الرمز', 'الحساب', 'مدين', 'دائن', 'الرصيد'], $rows, $format);
        }

        $income = $this->reports->incomeStatement($from, $to);
        $rows = collect($income['revenue'])->map(fn ($r) => ['الإيرادات', $r['account']->code, $r['account']->name, $r['amount']])
            ->push(['', '', 'إجمالي الإيرادات', $income['total_revenue']])
            ->concat(collect($income['expenses'])->map(fn ($r) => ['المصروفات', $r['account']->code, $r['account']->name, $r['amount']]))
            ->push(['', '', 'إجمالي المصروفات', $income['total_expenses']])
            ->push(['', '', 'صافي النتيجة', $income['net']]);

        return TableExporter::download("income-statement-{$from}-{$to}", ['القسم', 'الرمز', 'الحساب', 'المبلغ'], $rows, $format);
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $from = CarbonImmutable::parse($request->string('from')->toString() ?: now()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($request->string('to')->toString() ?: today()->toDateString());

        return $from->greaterThan($to) ? [$to->toDateString(), $from->toDateString()] : [$from->toDateString(), $to->toDateString()];
    }

    private function money(Account $a, string $balance): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'balance' => $balance, 'bank_name' => $a->bank_name, 'masked' => $a->maskedNumber(), 'is_active' => $a->is_active, 'system' => $a->system_key !== null];
    }

    private function entryRow(JournalEntry $e): array
    {
        return [
            'id' => $e->id, 'number' => $e->number, 'date' => $e->entry_date->toDateString(), 'description' => $e->description, 'total' => $e->total,
            'source' => $e->sourceLabel(), 'reversed' => $e->reversed_at !== null, 'is_reversal' => $e->reverses_id !== null,
        ];
    }
}
