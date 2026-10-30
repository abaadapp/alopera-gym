<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** الملخص المالي. The money movement and the statements are reports (Reports module); their old addresses lead there. */
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

    /** The money movement is a report now (ReportCatalog «movement»); an old link opens it with its filters. */
    public function movement(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.show', ['report' => 'movement'] + $request->query());
    }

    /** The income statement and trial balance are a report now (ReportCatalog «statements»). */
    public function statements(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.show', ['report' => 'statements'] + $request->query());
    }

    public function export(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.export', ['report' => 'statements'] + $request->query());
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
