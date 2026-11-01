<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Services\CashShifts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ورديات الصندوق: the open shifts with what passed through them so far, opening a shift, moving cash to or from the
 * drawer, closing with the count, and the closed shifts. Viewing takes cash_shifts.view or cash_shifts.manage; acting
 * takes cash_shifts.manage. A user tied to a branch sees that branch's shifts.
 */
class CashShiftController extends Controller
{
    public function __construct(private readonly CashShifts $shifts) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('cash_shifts.view') || $user->can('cash_shifts.manage'), 403);
        $scoped = fn () => BranchContext::scope(CashShift::with('account:id,name', 'opener:id,name', 'closer:id,name', 'branch:id,name'));

        return Inertia::render('Finance/CashShifts', [
            'open' => $scoped()->where('status', CashShift::OPEN)->latest('opened_at')->get()
                ->map(fn (CashShift $s) => $this->row($s) + ['summary' => $this->shifts->summary($s), 'movements' => $s->movements()->with('counterAccount:id,name', 'author:id,name')->latest('id')->get()
                    ->map(fn ($m) => ['id' => $m->id, 'direction' => $m->direction, 'amount' => $m->amount, 'account' => $m->counterAccount?->name, 'reason' => $m->reason, 'by' => $m->author?->name, 'at' => $m->created_at->format('Y-m-d H:i')])]),
            'closed' => $scoped()->where('status', CashShift::CLOSED)->latest('closed_at')->paginate(20)->withQueryString()->through(fn (CashShift $s) => $this->row($s)),
            'drawers' => Account::money()->postable()->where('kind', 'cash')->orderBy('code')->get(['id', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'name' => $a->name, 'last_counted' => CashShift::where('account_id', $a->id)->where('status', CashShift::CLOSED)->latest('closed_at')->value('counted_cash')]),
            'counterAccounts' => Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->orderBy('code')->get(['id', 'name', 'kind']),
            'canManage' => $user->can('cash_shifts.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', TenantRule::exists('gym_accounts')],
            'opening_float' => ['required', 'numeric', 'min:0', 'max:999999'],
            'client_key' => SubmittedOnce::RULE,
        ], [], ['account_id' => 'الصندوق', 'opening_float' => 'النقد الافتتاحي']);
        $number = SubmittedOnce::run($data['client_key'] ?? null, 'cash-shift-open', fn () => $this->shifts->open(Account::findOrFail($data['account_id']), $data['opening_float'], $request->user())->number);

        return back()->with('success', 'فُتحت الوردية '.$number.'.');
    }

    public function move(Request $request, CashShift $shift): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'counter_account_id' => ['required', 'integer', TenantRule::exists('gym_accounts')],
            'reason' => ['required', 'string', 'max:255'],
            'client_key' => SubmittedOnce::RULE,
        ], [], ['counter_account_id' => 'الحساب', 'reason' => 'البيان']);
        SubmittedOnce::run($data['client_key'] ?? null, 'cash-shift-move:'.$shift->id, fn () => $this->shifts->move($shift, $data['direction'], $data['amount'], Account::findOrFail($data['counter_account_id']), $data['reason'])->id);

        return back()->with('success', $data['direction'] === 'out' ? 'سُجّل نقل النقد من الدرج.' : 'سُجّل النقد المضاف إلى الدرج.');
    }

    public function close(Request $request, CashShift $shift): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:999999'],
            'close_note' => ['nullable', 'string', 'max:500'],
            'client_key' => SubmittedOnce::RULE,
        ], [], ['counted_cash' => 'النقد المعدود', 'close_note' => 'سبب الفرق']);
        $shift = CashShift::findOrFail(SubmittedOnce::run($data['client_key'] ?? null, 'cash-shift-close:'.$shift->id, fn () => $this->shifts->close($shift, $data['counted_cash'], $data['close_note'] ?? null, $request->user())->id));

        return back()->with('success', 'أُقفلت الوردية '.$shift->number.'. المتوقع '.$shift->expected_cash.'، المعدود '.$shift->counted_cash.'، الفرق '.$shift->difference.'.');
    }

    /** One shift as its closing report (printable): opening, what passed through it by method, the count and the difference. */
    public function show(Request $request, CashShift $shift): Response
    {
        $user = $request->user();
        abort_unless($user->can('cash_shifts.view') || $user->can('cash_shifts.manage'), 403);
        $shift->load('account:id,name', 'opener:id,name', 'closer:id,name', 'branch:id,name');

        return Inertia::render('Finance/CashShiftShow', [
            'shift' => $this->row($shift) + [
                'summary' => $shift->isOpen() ? $this->shifts->summary($shift) : $shift->totals,
                'movements' => $shift->movements()->with('counterAccount:id,name', 'author:id,name')->oldest('id')->get()
                    ->map(fn ($m) => ['id' => $m->id, 'direction' => $m->direction, 'amount' => $m->amount, 'account' => $m->counterAccount?->name, 'reason' => $m->reason, 'by' => $m->author?->name, 'at' => $m->created_at->format('Y-m-d H:i')]),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(CashShift $s): array
    {
        return [
            'id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'drawer' => $s->account?->name, 'branch' => $s->branch?->name,
            'opening_float' => $s->opening_float, 'opened_at' => $s->opened_at->format('Y-m-d H:i'), 'opened_by' => $s->opener?->name,
            'expected_cash' => $s->expected_cash, 'counted_cash' => $s->counted_cash, 'difference' => $s->difference, 'close_note' => $s->close_note,
            'closed_at' => $s->closed_at?->format('Y-m-d H:i'), 'closed_by' => $s->closer?->name,
        ];
    }
}
