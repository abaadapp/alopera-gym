<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Export\TableExporter;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Payroll runs (مسيرات الرواتب) and their payouts (صرف الرواتب). Each action checks its own permission. */
class PayrollRunController extends Controller
{
    private const ANY = ['payroll.prepare', 'payroll.approve', 'payroll.pay', 'payroll.reports'];

    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): Response
    {
        $this->allowAny($request, self::ANY);
        $runs = PayrollRun::withCount(['items', 'items as paid_count' => fn ($q) => $q->whereNotNull('paid_at')])->orderByDesc('period')->get();
        $selected = $runs->firstWhere('id', $request->integer('run')) ?? $runs->first();
        $used = $runs->map(fn ($r) => $r->period->format('Y-m'));

        return Inertia::render('Payroll/Runs', [
            'runs' => $runs->map(fn (PayrollRun $r) => $this->summary($r)),
            'run' => $selected ? $this->detail($selected) : null,
            'months' => collect(range(0, 5))->map(fn ($i) => now()->startOfMonth()->subMonths($i))
                ->reject(fn ($m) => $used->contains($m->format('Y-m')))
                ->map(fn ($m) => ['value' => $m->format('Y-m'), 'label' => $m->locale('ar')->translatedFormat('F Y')])->values(),
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $run = $this->payroll->open($request->validate(['month' => ['required', 'date_format:Y-m']])['month']);

        return to_route('club.payroll.index', ['run' => $run->id])->with('success', "فُتحت مسيرة {$run->label()} بـ {$run->items()->count()} موظفة.");
    }

    public function updateItem(Request $request, PayrollItem $item): RedirectResponse
    {
        $data = $request->validate([
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'overtime' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'allowances' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'deductions' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'deduction_notes' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->payroll->updateItem($item, $data);

        return back()->with('success', "حُدّث سطر {$item->employee_name}.");
    }

    public function destroyItem(PayrollItem $item): RedirectResponse
    {
        $this->payroll->removeItem($item);

        return back()->with('success', "أُزيلت {$item->employee_name} من المسيرة.");
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        $this->payroll->discard($run);

        return to_route('club.payroll.index')->with('success', 'حُذفت المسودة.');
    }

    public function approve(PayrollRun $run): RedirectResponse
    {
        $this->payroll->approve($run);

        return back()->with('success', 'اعتُمدت المسيرة وسُجّلت الرواتب المستحقة في الحسابات.');
    }

    /** The printable run sheet, or one employee's payslip (?item=). */
    public function print(Request $request, PayrollRun $run, TenantContext $tenants): Response
    {
        $this->allowAny($request, self::ANY);
        $items = $run->items()->with(['employee:id,name,iban,bank_name,payment_method', 'payout:id,number,paid_on'])->orderBy('employee_name')->get();
        if ($request->integer('item')) {
            $items = $items->where('id', $request->integer('item'))->values();
            abort_if($items->isEmpty(), 404);
        }
        $club = $tenants->require();

        return Inertia::render('Payroll/Print', [
            'mode' => $request->integer('item') ? 'payslip' : 'sheet',
            'run' => $this->summary($run->loadCount(['items', 'items as paid_count' => fn ($q) => $q->whereNotNull('paid_at')])) + ['approved_at' => $run->approved_at?->toDateString(), 'approver' => $run->approver?->name],
            'items' => $items->map(fn (PayrollItem $i) => $this->item($i) + ['iban' => $i->employee?->iban ? '•••• '.mb_substr($i->employee->iban, -4) : null, 'bank_name' => $i->employee?->bank_name]),
            'club' => ['name' => $club->name, 'logo_url' => $club->logoUrl(), 'phone' => $club->phone, 'email' => $club->email],
        ]);
    }

    public function export(Request $request, PayrollRun $run): StreamedResponse
    {
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';
        $rows = $run->items()->orderBy('employee_name')->get()->map(fn (PayrollItem $i) => [
            $i->employee_name, $i->job_title, $i->attendance_days, $i->basic, $i->allowances, $i->overtime_hours, $i->overtime, $i->deductions, $i->deduction_notes, $i->net,
            $i->paid_at ? 'مصروف '.$i->paid_at->toDateString() : 'غير مصروف',
        ]);

        return TableExporter::download("payroll-{$run->period->format('Y-m')}", ['الموظفة', 'المسمى', 'أيام الحضور', 'الأساسي', 'البدلات', 'ساعات إضافية', 'الإضافي', 'الخصومات', 'سبب الخصم', 'الصافي', 'الصرف'], $rows, $format);
    }

    /** صرف الرواتب: approved runs with what is still owed, payouts made, and the accounts to pay from. */
    public function payouts(Request $request, FinanceReports $reports): Response
    {
        $this->allowAny($request, ['payroll.pay', 'payroll.reports']);
        $runs = PayrollRun::whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID])
            ->withCount(['items', 'items as paid_count' => fn ($q) => $q->whereNotNull('paid_at')])
            ->withSum(['items as unpaid' => fn ($q) => $q->whereNull('paid_at')], 'net')
            ->orderByDesc('period')->get();
        $selected = $runs->firstWhere('id', $request->integer('run')) ?? $runs->firstWhere('status', PayrollRun::APPROVED) ?? $runs->first();

        return Inertia::render('Payroll/Payouts', [
            'runs' => $runs->map(fn (PayrollRun $r) => $this->summary($r) + ['unpaid' => Money::normalize($r->unpaid ?? 0)]),
            'run' => $selected ? $this->detail($selected) : null,
            'payouts' => PayrollPayout::with(['account:id,name', 'author:id,name', 'run:id,period'])->withCount('items')->latest('id')->limit(30)->get()
                ->map(fn (PayrollPayout $p) => ['id' => $p->id, 'number' => $p->number, 'paid_on' => $p->paid_on->toDateString(), 'account' => $p->account?->name, 'total' => $p->total, 'reference' => $p->reference, 'items' => $p->items_count, 'by' => $p->author?->name, 'period' => $p->run?->label()]),
            'waiting' => Money::normalize(PayrollItem::whereNull('paid_at')->whereHas('run', fn ($q) => $q->where('status', PayrollRun::APPROVED))->sum('net')),
            'accounts' => $reports->moneyAccounts()->filter(fn ($m) => in_array($m['account']->kind, Account::PAYING_KINDS, true))
                ->map(fn ($m) => ['id' => $m['account']->id, 'name' => $m['account']->name, 'kind' => $m['account']->kind, 'balance' => $m['balance']])->values(),
            'defaultAccount' => $request->user()->tenant->settings['payroll']['payout_account_id'] ?? null,
            'can' => $this->abilities($request),
        ]);
    }

    public function pay(Request $request, PayrollRun $run): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer'],
            'account_id' => ['required', 'integer'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [], ['items' => 'الموظفات', 'account_id' => 'حساب الصرف', 'paid_on' => 'تاريخ الصرف']);
        $account = Account::findOrFail($data['account_id']);

        $result = $this->payroll->pay($run, $data['items'], $account, $data['paid_on'], $data['reference'] ?? null);

        return back()->with('success', "صُرفت رواتب {$result['paid']} موظفة ({$result['payout']->number}) من «{$account->name}». تسجيل الصرف هنا لا ينفّذ تحويلًا بنكيًا.");
    }

    private function allowAny(Request $request, array $permissions): void
    {
        abort_unless(collect($permissions)->contains(fn ($p) => $request->user()->hasPermission($p)), 403);
    }

    private function abilities(Request $request): array
    {
        $u = $request->user();

        return ['prepare' => $u->hasPermission('payroll.prepare'), 'approve' => $u->hasPermission('payroll.approve'), 'pay' => $u->hasPermission('payroll.pay'), 'reports' => $u->hasPermission('payroll.reports')];
    }

    private function summary(PayrollRun $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'period' => $r->period->format('Y-m'), 'label' => $r->label(), 'status' => $r->status,
            'gross' => $r->gross, 'deductions' => $r->deductions, 'net' => $r->net,
            'items_count' => $r->items_count ?? null, 'paid_count' => $r->paid_count ?? null,
        ];
    }

    private function detail(PayrollRun $r): array
    {
        $items = $r->items()->with('payout:id,number,paid_on')->orderBy('employee_name')->get();

        return $this->summary($r) + [
            'approved_at' => $r->approved_at?->toDateString(),
            'approver' => $r->approver?->name,
            'month_end' => CarbonImmutable::parse($r->period)->endOfMonth()->toDateString(),
            'items' => $items->map(fn (PayrollItem $i) => $this->item($i)),
            'unpaid' => Money::add('0', ...$items->whereNull('paid_at')->pluck('net')->all()),
        ];
    }

    private function item(PayrollItem $i): array
    {
        return [
            'id' => $i->id, 'employee_id' => $i->employee_id, 'name' => $i->employee_name, 'job_title' => $i->job_title,
            'basic' => $i->basic, 'allowances' => $i->allowances, 'allowance_lines' => $i->allowance_lines ?? [],
            'overtime_hours' => $i->overtime_hours, 'overtime' => $i->overtime, 'deductions' => $i->deductions, 'deduction_notes' => $i->deduction_notes,
            'earnings' => $i->earnings(), 'net' => $i->net, 'notes' => $i->notes,
            'attendance_days' => $i->attendance_days, 'attendance_hours' => $i->attendance_hours, 'payment_method' => $i->payment_method,
            'paid_at' => $i->paid_at?->toDateString(), 'payout' => $i->payout ? ['number' => $i->payout->number, 'paid_on' => $i->payout->paid_on->toDateString()] : null,
        ];
    }
}
