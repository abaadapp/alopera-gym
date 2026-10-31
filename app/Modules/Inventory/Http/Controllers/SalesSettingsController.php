<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** إعدادات المبيعات والمتجر: the website shop, how website orders can be paid, and the online payment window. */
class SalesSettingsController extends Controller
{
    public function edit(TenantContext $tenants, OnlinePaymentService $online): Response
    {
        return Inertia::render('Club/Settings/Sales', [
            'settings' => ShopSettings::of($tenants->require()),
            'paymobReady' => $online->available(),
        ]);
    }

    public function update(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'shop_enabled' => ['boolean'],
            'pickup_enabled' => ['boolean'],
            'online_payment_enabled' => ['boolean'],
            'payment_window_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
            'pickup_note' => ['nullable', 'string', 'max:300'],
        ]);
        $tenant = $tenants->require();
        $settings = $tenant->settings ?? [];
        // Merged: the point-of-sale device policy is kept in the same settings and set on the devices page.
        $settings['sales'] = [...(array) ($settings['sales'] ?? []),
            'shop_enabled' => (bool) ($data['shop_enabled'] ?? false),
            'pickup_enabled' => (bool) ($data['pickup_enabled'] ?? false),
            'online_payment_enabled' => (bool) ($data['online_payment_enabled'] ?? false),
            'payment_window_minutes' => (int) $data['payment_window_minutes'],
            'pickup_note' => $data['pickup_note'] ?? null,
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('success', 'حُفظت إعدادات المبيعات والمتجر.');
    }
}
