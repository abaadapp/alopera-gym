<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** إعدادات الرواتب والحضور: working day, overtime rate, the default payout account, and the duplicate-punch window. */
class PayrollSettingsController extends Controller
{
    public const DEFAULTS = ['work_hours' => 8, 'overtime_rate' => null, 'payout_account_id' => null, 'punch_window_seconds' => 60];

    public function edit(TenantContext $tenants): Response
    {
        return Inertia::render('Club/Settings/Payroll', [
            'settings' => array_merge(self::DEFAULTS, $tenants->require()->settings['payroll'] ?? []),
            'accounts' => Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->orderBy('code')->get(['id', 'code', 'name', 'kind']),
        ]);
    }

    public function update(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'work_hours' => ['required', 'numeric', 'min:1', 'max:16'],
            'overtime_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'payout_account_id' => ['nullable', 'integer'],
            'punch_window_seconds' => ['required', 'integer', 'min:0', 'max:900'],
        ], [], ['work_hours' => 'ساعات العمل اليومية', 'overtime_rate' => 'أجر الساعة الإضافية', 'punch_window_seconds' => 'مهلة البصمة المكررة']);

        if ($data['payout_account_id'] && ! Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->whereKey($data['payout_account_id'])->exists()) {
            $data['payout_account_id'] = null;
        }

        $tenant = $tenants->require();
        $settings = $tenant->settings ?? [];
        $settings['payroll'] = $data;
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('success', 'حُفظت إعدادات الرواتب والحضور.');
    }
}
