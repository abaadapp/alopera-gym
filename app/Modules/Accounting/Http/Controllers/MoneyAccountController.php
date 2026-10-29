<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الحسابات البنكية: the cash box, bank accounts and payment clearing accounts, their balances, opening balances
 * and transfers between them. Bank numbers are stored encrypted and shown masked.
 */
class MoneyAccountController extends Controller
{
    public function __construct(private readonly ChartOfAccounts $chart, private readonly Ledger $ledger, private readonly FinanceReports $reports) {}

    public function index(): Response
    {
        $accounts = $this->reports->moneyAccounts(withInactive: true);
        $openings = JournalEntry::where('source_type', 'account')->where('source_event', 'opening')->whereNull('reversed_at')->get()->keyBy('source_id');

        return Inertia::render('Finance/Banks', [
            'accounts' => $accounts->map(fn ($m) => [
                'id' => $m['account']->id, 'code' => $m['account']->code, 'name' => $m['account']->name, 'kind' => $m['account']->kind,
                'balance' => $m['balance'], 'bank_name' => $m['account']->bank_name, 'masked' => $m['account']->maskedNumber(),
                'is_active' => $m['account']->is_active, 'system' => $m['account']->system_key !== null,
                'opening' => isset($openings[$m['account']->id]) ? ['amount' => $openings[$m['account']->id]->total, 'date' => $openings[$m['account']->id]->entry_date->toDateString()] : null,
            ])->values(),
            'totals' => [
                'cash' => Money::add('0', ...$accounts->filter(fn ($m) => $m['account']->kind === 'cash')->pluck('balance')->all()),
                'banks' => Money::add('0', ...$accounts->filter(fn ($m) => $m['account']->kind === 'bank')->pluck('balance')->all()),
                'clearing' => Money::add('0', ...$accounts->filter(fn ($m) => in_array($m['account']->kind, ['card', 'gateway'], true))->pluck('balance')->all()),
            ],
            'kinds' => Account::KINDS,
            'canManage' => request()->user()->hasPermission('accounting.manage'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['cash', 'bank'])],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:34'],
            'iban' => ['nullable', 'string', 'max:34'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'opening_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['name' => 'اسم الحساب', 'opening_balance' => 'الرصيد الافتتاحي']);

        $account = DB::transaction(function () use ($data) {
            $parent = $this->chart->account('cash_and_banks');
            $code = 1105;
            while (Account::where('code', (string) $code)->exists()) {
                $code++;
            }
            $account = Account::create([
                'parent_id' => $parent->id, 'code' => (string) $code, 'name' => $data['name'], 'type' => 'asset', 'normal_side' => 'debit',
                'kind' => $data['kind'], 'bank_name' => $data['bank_name'] ?? null, 'account_number' => $data['account_number'] ?? null, 'iban' => $data['iban'] ?? null,
                'is_group' => false, 'is_active' => true,
            ]);
            if (! empty($data['opening_balance']) && Money::cmp($data['opening_balance'], 0) > 0) {
                $this->postOpening($account, $data['opening_balance'], $data['opening_date'] ?? today()->toDateString());
            }

            return $account;
        });
        $audit->log('accounting.account_created', $account, ['name' => $account->name, 'kind' => $account->kind]);

        return back()->with('success', "أُضيف الحساب «{$account->name}».");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->isMoney(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:34'],
            'iban' => ['nullable', 'string', 'max:34'],
            'is_active' => ['boolean'],
        ]);
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            if ($account->system_key !== null) {
                throw new BusinessRuleException('accounting.system_account', 'هذا حساب أساسي يستخدمه النظام ولا يُوقف.', 'is_active');
            }
            if (! Money::isZero($this->reports->balance($account))) {
                throw new BusinessRuleException('accounting.balance', 'انقل الرصيد إلى حساب آخر قبل إيقاف هذا الحساب.', 'is_active');
            }
        }
        // Blank number fields keep what is stored (they are never sent back to the browser in full).
        foreach (['account_number', 'iban'] as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }
        $account->update($data);

        return back()->with('success', 'حُفظ الحساب.');
    }

    /** Set (or correct) an account's opening balance: the previous opening entry is reversed, a new one posted. */
    public function opening(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->isMoney() && $account->isPostable(), 404);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:999999999'], 'date' => ['required', 'date', 'before_or_equal:today']], [], ['amount' => 'الرصيد الافتتاحي']);

        DB::transaction(function () use ($account, $data) {
            $previous = JournalEntry::where(['source_type' => 'account', 'source_id' => $account->id, 'source_event' => 'opening'])->whereNull('reversed_at')->latest('revision')->first();
            if ($previous) {
                $this->ledger->reverse($previous, "تصحيح الرصيد الافتتاحي — {$account->name}", ['source_type' => 'account', 'source_id' => $account->id, 'event' => 'opening_fix', 'revision' => $previous->revision]);
            }
            if (Money::cmp($data['amount'], 0) > 0) {
                $this->postOpening($account, $data['amount'], $data['date'], ($previous?->revision ?? 0) + 1);
            }
        });

        return back()->with('success', 'سُجّل الرصيد الافتتاحي.');
    }

    /** Move money between two of the club's accounts (cash deposited at the bank, card settlements reaching it…). */
    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_id' => ['required', 'integer', 'different:to_id'],
            'to_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['from_id' => 'من حساب', 'to_id' => 'إلى حساب', 'amount' => 'المبلغ']);
        $from = Account::money()->postable()->findOrFail($data['from_id']);
        $to = Account::money()->postable()->findOrFail($data['to_id']);

        $this->ledger->post(['date' => $data['date'], 'description' => "تحويل من {$from->name} إلى {$to->name}".($data['note'] ? " — {$data['note']}" : '')], [
            ['account' => $to, 'debit' => $data['amount']],
            ['account' => $from, 'credit' => $data['amount']],
        ]);

        return back()->with('success', 'سُجّل التحويل بين الحسابين.');
    }

    private function postOpening(Account $account, string|float $amount, string $date, int $revision = 1): void
    {
        $this->ledger->post([
            'date' => $date,
            'description' => "الرصيد الافتتاحي — {$account->name}",
            'source_type' => 'account', 'source_id' => $account->id, 'event' => 'opening', 'revision' => $revision,
        ], [
            ['account' => $account, 'debit' => $amount],
            ['account' => $this->chart->account('opening_equity'), 'credit' => $amount],
        ]);
    }
}
