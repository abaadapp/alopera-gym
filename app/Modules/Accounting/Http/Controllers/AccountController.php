<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** شجرة الحسابات: the tree with balances (a heading shows the sum of what is under it). */
class AccountController extends Controller
{
    public function index(ChartOfAccounts $chart, FinanceReports $reports): Response
    {
        $chart->ensure();
        $totals = $reports->totals();
        $accounts = Account::withCount('lines')->orderBy('code')->get();
        $balance = [];
        foreach ($accounts->where('is_group', false) as $a) {
            $balance[$a->id] = $reports->signed($a, $totals);
        }

        return Inertia::render('Finance/Accounts', [
            'accounts' => $accounts->map(fn (Account $a) => [
                'id' => $a->id, 'parent_id' => $a->parent_id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'normal_side' => $a->normal_side,
                'is_group' => $a->is_group, 'is_active' => $a->is_active, 'system' => $a->system_key !== null, 'kind' => $a->kind,
                'balance' => $balance[$a->id] ?? null, 'used' => $a->lines_count > 0,
            ]),
            'types' => Account::TYPES,
            'canManage' => request()->user()->hasPermission('accounting.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['required', TenantRule::exists('gym_accounts')->where('is_group', true)],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/', TenantRule::unique('gym_accounts', 'code')],
            'is_group' => ['boolean'],
        ], [], ['parent_id' => 'الحساب الرئيسي', 'name' => 'اسم الحساب', 'code' => 'الرمز']);
        $parent = Account::findOrFail($data['parent_id']);

        $code = $data['code'] ?? null;
        if (! $code) {
            $siblings = Account::where('parent_id', $parent->id)->pluck('code')->map(fn ($c) => (int) $c);
            $code = (string) max($siblings->max() ?? 0, (int) ($parent->code.(strlen($parent->code) === 1 ? '0' : '').'00')) + 1;
            while (Account::where('code', $code)->exists()) {
                $code = (string) ((int) $code + 1);
            }
        }

        Account::create([
            'parent_id' => $parent->id, 'code' => $code, 'name' => $data['name'], 'type' => $parent->type,
            'normal_side' => in_array($parent->type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_group' => (bool) ($data['is_group'] ?? false), 'is_active' => true,
        ]);

        return back()->with('success', 'أُضيف الحساب.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'is_active' => ['boolean']]);
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $account->system_key !== null) {
            throw new BusinessRuleException('accounting.system_account', 'هذا حساب أساسي يستخدمه النظام ولا يُوقف.', 'is_active');
        }
        $account->update($data);

        return back()->with('success', 'حُفظ الحساب.');
    }
}
