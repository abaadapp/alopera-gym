<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Access\AccessDecision;
use App\Modules\Gym\Access\Drivers\DeviceDrivers;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Integrations\Services\IntegrationHub;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Door readers, fingerprint terminals and turnstiles — the devices card of «التطبيقات التكاملية». The device token
 * is shown once after creation/regeneration and never again.
 */
class DeviceController extends Controller
{
    public function index(IntegrationHub $hub): Response
    {
        $devices = BranchContext::scope(AccessDevice::with('branch:id,name'))->orderBy('name')->get();

        return Inertia::render('Integrations/Devices', [
            'devices' => $devices->map(fn (AccessDevice $d) => $d->only(
                'id', 'name', 'type', 'driver', 'vendor', 'model', 'serial_number', 'direction', 'purpose', 'token_last4', 'is_active', 'last_ip', 'branch_id'
            ) + ['branch' => $d->branch?->name, 'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i'), 'online' => $d->isOnline()]),
            'status' => $hub->devices($devices),
            'events' => $this->events($devices->pluck('id')->all()),
            'simulation' => session('device_simulation'),
            'drivers' => DeviceDrivers::catalog(),
            'staffCredentials' => AccessCredential::where('holder_type', 'user')->whereIn('holder_id', $this->reachableStaff()->select('id'))->get()->map(fn ($c) => ['identifier' => $c->shownIdentifier()] + $c->only('id', 'type', 'label', 'holder_id', 'is_active')),
            'staff' => $this->reachableStaff()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => app(BranchContext::class)->choices(),
            'types' => AccessDevice::TYPES,
            'apiBase' => url('/api/devices/v1'),
            'onlineMinutes' => AccessDevice::ONLINE_MINUTES,
        ]);
    }

    /**
     * Try a reading as if this device had sent it, without the device and without recording anything: who the
     * credential belongs to and what the device would be told. Nothing is logged as an entry or attendance, and no
     * door is opened — the system never sends an open command.
     */
    public function simulate(Request $request, AccessDevice $device, AccessControlService $access): RedirectResponse
    {
        $reading = DeviceDrivers::for($device)->reading($request, ['qr', 'card', 'fingerprint', 'pin', 'barcode']);
        $holder = $access->resolve($reading['type'], $reading['value']);

        if (! $device->is_active) {
            $result = ['allowed' => false, 'message' => 'الجهاز موقوف: يُرفض اتصاله قبل قراءة أي شيء.', 'holder' => null];
        } elseif ($holder instanceof User) {
            $result = $device->acceptsStaff()
                ? ['allowed' => true, 'message' => 'موظف: يُسجَّل حضوره أو انصرافه.', 'holder' => $holder->name]
                : ['allowed' => false, 'message' => 'الجهاز غير مخصص للموظفين.', 'holder' => $holder->name];
        } else {
            $member = $holder instanceof Member ? $holder : null;
            $decision = $device->acceptsMembers() ? $access->evaluate($member) : AccessDecision::deny('device_not_for_members', $member);
            $result = ['allowed' => $decision->allowed, 'message' => $decision->message(), 'holder' => $member?->full_name];
        }

        return back()->with('device_simulation', $result + [
            'device' => $device->name,
            'type' => $reading['type'],
            'credential' => AccessControlService::mask($reading['value']),
            'at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * The latest readings from the devices on the page: members' entries (allowed, denied, repeated) and staff punches,
     * newest first.
     *
     * @param  list<int>  $devices
     * @return list<array<string, mixed>>
     */
    private function events(array $devices): array
    {
        $entries = CheckIn::with(['member:id,full_name', 'device:id,name'])->whereIn('access_device_id', $devices)->latest('checked_in_at')->limit(30)->get()
            ->map(fn (CheckIn $c) => [
                'key' => 'c'.$c->id, 'at' => $c->checked_in_at, 'device' => $c->device?->name, 'who' => $c->member?->full_name ?? 'غير معروف',
                'kind' => 'member', 'result' => $c->result, 'reason' => AccessDecision::REASONS[$c->reason] ?? $c->reason,
                'method' => $c->method, 'hint' => $c->credential_hint,
            ]);
        $punches = StaffAttendance::with(['user:id,name', 'device:id,name'])->whereIn('access_device_id', $devices)->latest('clock_in_at')->limit(20)->get()
            ->map(fn (StaffAttendance $a) => [
                'key' => 's'.$a->id, 'at' => $a->clock_out_at ?? $a->clock_in_at, 'device' => $a->device?->name, 'who' => $a->user?->name,
                'kind' => 'staff', 'result' => 'allowed', 'reason' => $a->clock_out_at ? 'انصراف موظف' : 'حضور موظف',
                'method' => $a->method, 'hint' => null,
            ]);

        return $entries->concat($punches)->sortByDesc('at')->take(30)
            ->map(fn (array $e) => ['at' => $e['at']->format('Y-m-d H:i')] + $e)->values()->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $device = new AccessDevice($this->validated($request));
        $device->branch_id = app(BranchContext::class)->assign($device->branch_id);
        $token = $device->issueToken();
        $device->save();

        return back()->with('success', 'تمت إضافة الجهاز. انسخ رمز الجهاز الآن؛ لن يظهر مرة أخرى.')->with('device_token', $token);
    }

    public function update(Request $request, AccessDevice $device): RedirectResponse
    {
        $device->update($this->validated($request, $device));

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
            'holder_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', $tenants->require()->id)->where(fn ($q) => $this->narrowToBranch($q))],
            'type' => ['required', 'in:'.implode(',', AccessCredential::TYPES)],
            'label' => ['nullable', 'string', 'max:80'],
        ]) + $request->validate(AccessCredential::identifierRules((string) $request->input('type')), AccessCredential::IDENTIFIER_MESSAGES);
        $request->validate(['identifier' => [TenantRule::unique('gym_access_credentials', 'identifier')->where('type', $data['type'])]],
            ['identifier.unique' => 'هذا المعرّف مسجّل لشخص آخر.']);

        AccessCredential::create($data + ['holder_type' => 'user']);

        return back()->with('success', 'تم ربط بصمة/بطاقة الموظف.');
    }

    public function destroyStaffCredential(AccessCredential $credential): RedirectResponse
    {
        abort_unless($credential->holder_type === 'user' && $this->reachableStaff()->whereKey($credential->holder_id)->exists(), 404);
        $credential->delete();

        return back()->with('success', 'تم حذف وسيلة دخول الموظف.');
    }

    /** The club's staff a user may give a door card to: all of them, or for a user tied to a branch that branch's. */
    private function reachableStaff(): Builder
    {
        return $this->narrowToBranch(User::ofTenant(app(TenantContext::class)->require()->id));
    }

    /**
     * @template T of BuilderContract
     *
     * @param  T  $query  users
     * @return T
     */
    private function narrowToBranch(BuilderContract $query): BuilderContract
    {
        $branch = Auth::user()?->branch_id;

        return $branch ? app(BranchContext::class)->narrow($query, (int) $branch) : $query;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AccessDevice $device = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', AccessDevice::TYPES)],
            'driver' => ['sometimes', 'in:'.implode(',', array_keys(DeviceDrivers::ALL))],
            'vendor' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'direction' => ['required', 'in:in,out,both'],
            'purpose' => ['required', 'in:members,staff,both'],
            'branch_id' => ['nullable', ...app(BranchContext::class)->rule($device?->branch_id)],
            'is_active' => ['boolean'],
        ]);
    }
}
