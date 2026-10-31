<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\PosDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * On the device itself: which point-of-sale device this browser is, pairing it with a code (anyone who sells), or
 * activating it directly (whoever manages devices).
 */
class PosDeviceSetupController extends Controller
{
    public function __construct(private readonly PosDevices $devices) {}

    public function show(Request $request): Response
    {
        $device = $this->devices->current($request);

        return Inertia::render('Inventory/PosDevice', [
            'device' => $device ? ['name' => $device->name, 'branch' => $device->branch?->name, 'activated_at' => $device->activated_at?->format('Y-m-d H:i')] : null,
            'canActivate' => $request->user()->can('pos_devices.manage'),
            'branches' => app(BranchContext::class)->choices(),
        ]);
    }

    public function pair(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $device = $this->devices->pair($data['code'], $request);

        return redirect()->route('club.pos.index')->with('success', 'فُعّل هذا المتصفح جهازًا لنقطة البيع: «'.$device->name.'».');
    }

    public function activate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('pos_devices.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'settlement_account_id' => ['nullable', TenantRule::exists('gym_accounts')->where('kind', 'bank')],
        ]);
        $device = $this->devices->activateHere($data['name'], (int) $data['branch_id'], $request, $data['settlement_account_id'] ?? null);

        return redirect()->route('club.pos.index')->with('success', 'فُعّل «'.$device->name.'» جهازًا لنقطة البيع.');
    }
}
