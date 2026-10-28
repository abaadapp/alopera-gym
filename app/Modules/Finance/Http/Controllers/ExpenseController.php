<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Export\TableExporter;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->filtered($request);

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => (clone $query)->with('category:id,name')->latest('expense_date')->latest('id')->paginate(30)->withQueryString()
                ->through(fn (Expense $e) => $e->only('id', 'number', 'description', 'amount', 'payment_method', 'payee', 'reference', 'expense_category_id', 'supplier_id') + [
                    'category' => $e->category?->name, 'expense_date' => $e->expense_date->toDateString(), 'has_attachment' => $e->attachment_path !== null,
                ]),
            'total' => round((float) (clone $query)->sum('amount'), 3),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('category_id', 'from', 'to', 'q'),
        ]);
    }

    public function store(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $this->validated($request);
        $expense = new Expense(collect($data)->except('attachment')->all());
        $expense->number = NumberSequence::next('expense');
        $expense->created_by = $request->user()->id;
        if ($request->hasFile('attachment')) {
            $expense->attachment_path = $request->file('attachment')->store('tenants/'.$tenants->require()->id.'/expenses', 'local');
        }
        $expense->save();

        return back()->with('success', 'تم تسجيل المصروف '.$expense->number);
    }

    public function update(Request $request, Expense $expense, TenantContext $tenants): RedirectResponse
    {
        $data = $this->validated($request);
        $expense->fill(collect($data)->except('attachment')->all());
        if ($request->hasFile('attachment')) {
            $expense->attachment_path = $request->file('attachment')->store('tenants/'.$tenants->require()->id.'/expenses', 'local');
        }
        $expense->save();

        return back()->with('success', 'تم حفظ المصروف.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return back()->with('success', 'تم حذف المصروف.');
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
        return Expense::query()
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($request->date('from'), fn ($q, $d) => $q->where('expense_date', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->where('expense_date', '<=', $d))
            ->when($request->string('q')->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'ilike', "%{$t}%")->orWhere('payee', 'ilike', "%{$t}%")));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'expense_category_id' => ['nullable', TenantRule::exists('expense_categories')],
            'supplier_id' => ['nullable', TenantRule::exists('suppliers')],
            'payment_method' => ['required', 'in:cash,bank_transfer,card,other'],
            'payee' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],
            'attachment' => ['nullable', 'file', 'max:'.config('gym.max_document_kb'), 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
    }
}
