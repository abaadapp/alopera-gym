<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** الإعدادات المالية: which cash, bank or clearing account each payment method uses. */
class FinanceSettingsController extends Controller
{
    public function edit(TenantContext $tenants, ChartOfAccounts $chart): Response
    {
        $chart->ensure();
        $methods = array_intersect_key(Payment::METHOD_LABELS, array_flip(['cash', 'card', 'bank_transfer', 'paymob', 'other']));
        $current = fn (string $purpose, array $defaults) => collect($defaults)->map(fn ($key, $method) => $chart->moneyFor($method, $purpose)->id)->all();

        return Inertia::render('Club/Settings/Finance', [
            'methods' => $methods,
            'receipt' => $current('receipt', ChartOfAccounts::RECEIPT_DEFAULTS),
            'spending' => $current('spending', ChartOfAccounts::SPENDING_DEFAULTS),
            'accounts' => Account::money()->postable()->orderBy('code')->get(['id', 'code', 'name', 'kind']),
            'vatRate' => $tenants->require()->setting('vat_rate', 0),
        ]);
    }

    public function update(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'receipt' => ['required', 'array'],
            'receipt.*' => ['nullable', 'integer'],
            'spending' => ['required', 'array'],
            'spending.*' => ['nullable', 'integer'],
        ]);
        $money = Account::money()->postable()->get()->keyBy('id');
        $clean = fn (array $map, array $methods, bool $paying) => collect($map)->only($methods)
            ->filter(fn ($id) => $id && isset($money[$id]) && (! $paying || in_array($money[$id]->kind, Account::PAYING_KINDS, true)))
            ->map(fn ($id) => (int) $id)->all();

        $tenant = $tenants->require();
        $settings = $tenant->settings ?? [];
        $settings['finance'] = [
            'receipt' => $clean($data['receipt'], array_keys(ChartOfAccounts::RECEIPT_DEFAULTS), false),
            'spending' => $clean($data['spending'], array_keys(ChartOfAccounts::SPENDING_DEFAULTS), true),
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('success', 'حُفظت الإعدادات المالية. تُطبَّق على العمليات الجديدة.');
    }
}
