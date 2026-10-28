<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Door readers, fingerprint terminals and turnstiles. The device token is
 * shown once after creation/regeneration and never again.
 */
class DeviceController extends Controller
{
    public function index(TenantContext $tenants): Response
    {
        $tid = $tenants->require()->id;

        return Inertia::render('Gym/Devices/Index', [
            'devices' => AccessDevice::with('branch:id,name')->orderBy('name')->get()->map(fn (AccessDevice $d) => $d->only(
                'id', 'name', 'type', 'driver', 'vendor', 'model', 'serial_number', 'direction', 'purpose', 'token_last4', 'is_active', 'last_ip', 'branch_id'
            ) + ['branch' => $d->branch?->name, 'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i')]),
            'staffCredentials' => AccessCredential::where('holder_type', 'user')->get()->map(fn ($c) => $c->only('id', 'type', 'identifier', 'label', 'holder_id', 'is_active')),
            'staff' => User::ofTenant($tid)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
            'types' => AccessDevice::TYPES,
            'apiBase' => url('/api/devices/v1'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $device = new AccessDevice($this->validated($request));
        $token = $device->issueToken();
        $device->save();

        return back()->with('success', 'تمت إضافة الجهاز. انسخي رمز الجهاز الآن؛ لن يظهر مرة أخرى.')->with('device_token', $token);
    }

    public function update(Request $request, AccessDevice $device): RedirectResponse
    {
        $device->update($this->validated($request));

        return back()->with('success', 'تم حفظ الجهاز.');
    }

    public function destroy(AccessDevice $device): RedirectResponse
    {
        $device->delete();

        return back()->with('success', 'تم حذف الجهاز وإبطال رمزه.');
    }

    public function token(AccessDevice $device): RedirectResponse
    {
        $token = $device->issueToken();
        $device->save();

        return back()->with('success', 'تم إصدار رمز جديد؛ الرمز السابق لم يعد يعمل.')->with('device_token', $token);
    }

    public function storeStaffCredential(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'holder_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', $tenants->require()->id)],
            'type' => ['required', 'in:'.implode(',', AccessCredential::TYPES)],
            'identifier' => ['required', 'string', 'max:128'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);
        $request->validate(['identifier' => [TenantRule::unique('gym_access_credentials', 'identifier')->where('type', $data['type'])]],
            ['identifier.unique' => 'هذا المعرّف مسجّل لشخص آخر.']);

        AccessCredential::create($data + ['holder_type' => 'user']);

        return back()->with('success', 'تم ربط بصمة/بطاقة الموظفة.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', AccessDevice::TYPES)],
            'vendor' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'direction' => ['required', 'in:in,out,both'],
            'purpose' => ['required', 'in:members,staff,both'],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'is_active' => ['boolean'],
        ]);
    }
}
