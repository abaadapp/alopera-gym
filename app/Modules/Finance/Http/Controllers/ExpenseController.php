<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Core\Export\TableExporter;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Files\SafeFiles;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Core\Support\Period;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Rules\OpenPeriod;
use App\Modules\Accounting\Services\AccountFunds;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashShifts;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(Request $request, AccountingPeriods $periods): Response
    {
        $query = $this->filtered($request);

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => (clone $query)->with('category:id,name', 'account:id,name')->latest('expense_date')->latest('id')->paginate(in_array($request->integer('per_page'), [30, 50, 100], true) ? $request->integer('per_page') : 30)->withQueryString()
                ->through(fn (Expense $e) => $e->only('id', 'number', 'description', 'amount', 'payment_method', 'payee', 'reference', 'expense_category_id', 'supplier_id', 'account_id') + [
                    'category' => $e->category?->name, 'account' => $e->account?->name, 'expense_date' => $e->expense_date->toDateString(), 'has_attachment' => $e->attachment_path !== null,
                    'locked' => $periods->isLocked($e->expense_date),
                ]),
            'total' => round((float) (clone $query)->sum('amount'), 3),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // The cash boxes and bank accounts an expense can be paid from (empty choice: the method's default account).
            'moneyAccounts' => Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->orderBy('code')->get(['id', 'name', 'kind']),
            // Suppliers belong to the sales & inventory module; without it the expense form has no supplier field.
            'suppliers' => PermissionRegistry::moduleEnabled('inventory') ? Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'filters' => $request->only('category_id', 'payment_method', 'q', 'per_page'),
            'period' => Period::fromRequest($request)->toArray(),
        ]);
    }

    public function store(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $this->validated($request);
        // One form, one expense: sent twice (a double click, a retry), the second gets the first back.
        $number = SubmittedOnce::run($data['client_key'] ?? null, 'expense', fn () => $this->spending(Account::find($this->moneyAccount($data)), $data['amount'], function () use ($request, $tenants, $data) {
            $expense = new Expense(collect($data)->except('attachment', 'client_key')->all());
            $expense->number = NumberSequence::next('expense');
            $expense->branch_id = app(BranchContext::class)->assign();
            $expense->created_by = $request->user()->id;
            $expense->account_id = $this->moneyAccount($data);
            // Paid from a drawer during its shift: the shift's closing counts it.
            $expense->cash_shift_id = app(CashShifts::class)->current(Account::find($expense->account_id), $expense->branch_id);
            if ($request->hasFile('attachment')) {
                $expense->attachment_path = SafeFiles::putUpload($request->file('attachment'), 'tenants/'.$tenants->require()->id.'/expenses', 'attachment');
            }
            $expense->save();

            return $expense->number;
        }));

        return back()->with('success', 'تم تسجيل المصروف '.$number);
    }

    public function update(Request $request, Expense $expense, TenantContext $tenants): RedirectResponse
    {
        app(AccountingPeriods::class)->assertEditable($expense->expense_date, 'المصروف');
        $data = $this->validated($request);
        $expense->fill(collect($data)->except('attachment', 'client_key')->all());
        $expense->account_id = $this->moneyAccount($data);
        if ($expense->isDirty(['amount', 'account_id', 'payment_method'])) {
            $this->assertShiftOpen($expense);
        }
        // Moved to another account: it belongs to that drawer's open shift now (counted there), or to none.
        if ($expense->isDirty('account_id')) {
            $expense->cash_shift_id = app(CashShifts::class)->current(Account::find($expense->account_id), $expense->branch_id);
        }
        // Raised on the same account it spends the difference; moved to another, that account pays it whole.
        $spends = (int) $expense->getOriginal('account_id') === $expense->account_id ? Money::sub($expense->amount, (string) $expense->getOriginal('amount')) : $expense->amount;
        // A new attachment is written in the save's transaction: kept whole or the save fails, and gone again if the save
        // fails. The one it replaces stays on the disk as before (a financial record: the audit trail keeps its path).
        $this->spending(Account::find($expense->account_id), $spends, function () use ($request, $tenants, $expense) {
            if ($request->hasFile('attachment')) {
                $expense->attachment_path = SafeFiles::putUpload($request->file('attachment'), 'tenants/'.$tenants->require()->id.'/expenses', 'attachment');
            }
            $expense->save();
        });

        return back()->with('success', 'تم حفظ المصروف.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        app(AccountingPeriods::class)->assertEditable($expense->expense_date, 'المصروف');
        $this->assertShiftOpen($expense);
        $expense->delete();

        return back()->with('success', 'تم حذف المصروف.');
    }

    /** Paid from a drawer whose shift is closed: the closing counted it, so its money is not changed afterwards. */
    private function assertShiftOpen(Expense $expense): void
    {
        $shift = $expense->getOriginal('cash_shift_id') ? CashShift::find($expense->getOriginal('cash_shift_id')) : null;
        if ($shift && ! $shift->isOpen() && (int) $expense->getOriginal('account_id') === (int) $shift->account_id) {
            throw new BusinessRuleException('expense.shift_closed', "دُفع هذا المصروف من الصندوق في الوردية {$shift->number} وقد أُقفلت وحُسب فرقها؛ لا يُحذف ولا يُغيَّر مبلغه أو حسابه. صحّح بقيد يومية.");
        }
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', TenantRule::unique('expense_categories', 'name')]]);
        ExpenseCategory::create($data);

        return back()->with('success', 'تمت إضافة التصنيف.');
    }

    public function attachment(Expense $expense): StreamedResponse
    {
        abort_unless($expense->attachment_path && Storage::disk('local')->exists($expense->attachment_path), 404);

        return Storage::disk('local')->download($expense->attachment_path);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('category:id,name')->orderBy('expense_date');
        $rows = function () use ($query) {
            foreach ($query->lazy(500) as $e) {
                yield [$e->number, $e->expense_date->toDateString(), $e->category?->name, $e->description, $e->amount, Payment::METHOD_LABELS[$e->payment_method] ?? $e->payment_method, $e->payee, $e->reference];
            }
        };

        return TableExporter::download('expenses', ['الرقم', 'التاريخ', 'التصنيف', 'الوصف', 'المبلغ', 'طريقة الدفع', 'المستفيد', 'المرجع'], $rows(), $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv');
    }

    private function filtered(Request $request)
    {
        return BranchContext::scope(Expense::query())
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($request->string('payment_method')->toString(), fn ($q, $m) => $q->where('payment_method', $m))
            ->tap(fn ($q) => Period::fromRequest($request)->apply($q, 'expense_date'))
            ->when($request->string('q')->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'ilike', "%{$t}%")->orWhere('payee', 'ilike', "%{$t}%")));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'expense_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'expense_category_id' => ['nullable', TenantRule::exists('expense_categories')],
            'supplier_id' => ['nullable', TenantRule::exists('suppliers')],
            'payment_method' => ['required', 'in:cash,bank_transfer,card,other'],
            'payee' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],
            'attachment' => ['nullable', 'file', 'max:'.config('gym.max_document_kb'), 'mimes:pdf,jpg,jpeg,png,webp'],
            'account_id' => ['nullable', 'integer', TenantRule::exists('gym_accounts')],
            'client_key' => SubmittedOnce::RULE,
        ]);
    }

    /**
     * The cash box or bank account that paid it, kept with the expense: the one chosen (an active cash box or bank account
     * suiting the method), else the finance settings' account for the method.
     */
    /** Paid out of a cash box or bank account only while it holds the amount, one payment at a time (AccountFunds). */
    private function spending(?Account $account, string|float $amount, callable $save): mixed
    {
        $funds = app(AccountFunds::class);

        return $funds->exclusive($account, function () use ($funds, $account, $amount, $save) {
            $funds->covers($account, $amount);

            // The expense and its number are saved whole or not at all: a request that breaks after the insert must not
            // leave an expense the nightly accounting:sync would post later, below what the account held.
            return DB::transaction(fn () => $save());
        });
    }

    private function moneyAccount(array $data): int
    {
        $chart = app(ChartOfAccounts::class);
        if (empty($data['account_id'])) {
            return $chart->moneyFor($data['payment_method'], 'spending')->id;
        }
        $account = Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->find($data['account_id']);
        $suits = $account && ($data['payment_method'] !== 'cash' || $account->kind === 'cash') && ($data['payment_method'] !== 'bank_transfer' || $account->kind === 'bank');
        if (! $suits) {
            throw new BusinessRuleException('expense.account', 'الحساب المختار لا يناسب طريقة الدفع: النقد من صندوق، والتحويل من حساب بنكي.', 'account_id');
        }

        return $account->id;
    }
}
