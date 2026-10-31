<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\PosPeripheral;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Point-of-sale devices, in Settings (pos_devices.manage): the club's tills and tablets, their branch, status and
 * last contact, pairing codes, revoking, the bank a card terminal pays into, and their peripherals. Separate from
 * the access devices at the gates (integrations). The device page (/app/pos/device) is where a browser is paired.
 */
class PosDeviceController extends Controller
{
    public function __construct(private readonly PosDevices $devices, private readonly TenantContext $tenants) {}

    public function index(Request $request): Response
    {
        $current = $this->devices->current($request);
        $devices = PosDevice::with(['branch:id,name', 'activator:id,name', 'settlementAccount:id,name', 'peripherals'])->withCount('sales')->orderBy('name')->get();

        return Inertia::render('Club/Settings/PosDevices', [
            'devices' => $devices->map(fn (PosDevice $d) => $d->only('id', 'name', 'status', 'branch_id', 'settlement_account_id', 'sales_count', 'revoke_reason') + [
                'branch' => $d->branch?->name, 'activated_by' => $d->activator?->name, 'activated_at' => $d->activated_at?->format('Y-m-d H:i'),
                'last_seen' => $d->last_seen_at?->diffForHumans(), 'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i'), 'settlement_account' => $d->settlementAccount?->name,
                'code_expires_at' => $d->status === PosDevice::PENDING ? $d->pairing_expires_at?->format('H:i') : null,
                'code_expired' => $d->status === PosDevice::PENDING && ! $d->pairing_expires_at?->isFuture(),
                'is_this' => $current?->id === $d->id, 'auto_print' => (bool) $d->setting('auto_print', false),
                'errors' => $d->peripherals->where('last_status', 'error')->pluck('last_error')->filter()->values(),
                'peripherals' => $d->peripherals->map(fn (PosPeripheral $p) => $p->only('id', 'type', 'name', 'brand', 'model', 'connection', 'address', 'port', 'paper_width', 'is_default', 'auto_print', 'print_website_orders', 'is_active', 'notes', 'last_status', 'last_error') + ['last_checked_at' => $p->last_checked_at?->format('Y-m-d H:i')]),
            ]),
            'code' => session('pairing_code'),
            'branches' => app(BranchContext::class)->choices(),
            'accounts' => Account::money()->postable()->where('kind', 'bank')->orderBy('code')->get(['id', 'name']),
            'policy' => ['require_device' => ShopSettings::of($this->tenants->require())['pos_require_device']],
            'types' => PosPeripheral::TYPES, 'drivable' => PosPeripheral::DRIVABLE, 'connections' => PosPeripheral::CONNECTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'settlement_account_id' => ['nullable', TenantRule::exists('gym_accounts')->where('kind', 'bank')],
        ]);
        [$device, $code] = $this->devices->create($data['name'], (int) $data['branch_id'], $data['settlement_account_id'] ?? null);

        return back()->with('success', 'أُضيف «'.$device->name.'». أدخل رمز الربط على الجهاز خلال 30 دقيقة.')->with('pairing_code', ['device' => $device->id, 'code' => $code]);
    }

    public function update(Request $request, PosDevice $device): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'branch_id' => ['required', ...app(BranchContext::class)->rule($device->branch_id)],
            'settlement_account_id' => ['nullable', TenantRule::exists('gym_accounts')->where('kind', 'bank')],
            'auto_print' => ['boolean'],
        ]);
        $updated = $this->devices->update($device, $data + ['settlement_account_id' => null]);

        return back()->with('success', $updated->status === PosDevice::REVOKED && (int) $data['branch_id'] !== (int) $device->branch_id ? 'نُقل الجهاز إلى الفرع الجديد وأُلغي تفعيله؛ أصدر له رمز ربط جديدًا.' : 'حُفظ الجهاز.');
    }

    public function revoke(Request $request, PosDevice $device): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->devices->revoke($device, $data['reason'] ?? '');

        return back()->with('success', 'أُلغي تفعيل «'.$device->name.'»؛ لم يعد يستطيع البيع.');
    }

    public function code(PosDevice $device): RedirectResponse
    {
        $code = $this->devices->newCode($device);

        return back()->with('success', 'رمز ربط جديد لـ«'.$device->name.'» صالح 30 دقيقة.')->with('pairing_code', ['device' => $device->id, 'code' => $code]);
    }

    /** A record of a device never used for a sale may go; one with sales stays (revoked). */
    public function destroy(PosDevice $device): RedirectResponse
    {
        abort_if($device->status === PosDevice::ACTIVE, 422, 'ألغِ تفعيل الجهاز أولًا.');
        if ($device->sales()->exists()) {
            return back()->with('error', 'للجهاز عمليات بيع مسجلة؛ يبقى في السجل ملغى التفعيل.');
        }
        $device->delete();

        return back()->with('success', 'حُذف الجهاز.');
    }

    public function policy(Request $request): RedirectResponse
    {
        $data = $request->validate(['require_device' => ['required', 'boolean']]);
        $tenant = $this->tenants->require();
        $settings = $tenant->settings ?? [];
        $settings['sales'] = [...(array) ($settings['sales'] ?? []), 'pos_require_device' => (bool) $data['require_device']];
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('success', $data['require_device'] ? 'البيع الآن من أجهزة نقاط البيع المفعّلة فقط.' : 'يمكن البيع من أي متصفح لمن يملك صلاحية البيع.');
    }

    // ── Peripherals ──────────────────────────────────────────────────────────

    public function storePeripheral(Request $request, PosDevice $device): RedirectResponse
    {
        $device->peripherals()->create($this->peripheral($request));
        $this->singleDefault($device, $request);

        return back()->with('success', 'أُضيف الملحق.');
    }

    public function updatePeripheral(Request $request, PosDevice $device, PosPeripheral $peripheral): RedirectResponse
    {
        abort_unless($peripheral->pos_device_id === $device->id, 404);
        $peripheral->update($this->peripheral($request));
        $this->singleDefault($device, $request, $peripheral);

        return back()->with('success', 'حُفظ الملحق.');
    }

    public function destroyPeripheral(PosDevice $device, PosPeripheral $peripheral): RedirectResponse
    {
        abort_unless($peripheral->pos_device_id === $device->id, 404);
        $peripheral->delete();

        return back()->with('success', 'حُذف الملحق.');
    }

    /** A test receipt for a printer, printed through the browser's print dialog; the time is noted. */
    public function testPrint(PosDevice $device, PosPeripheral $peripheral): Response
    {
        abort_unless($peripheral->pos_device_id === $device->id && $peripheral->type === 'printer', 404);
        $peripheral->forceFill(['last_checked_at' => now()])->save();
        $club = $this->tenants->require();

        return Inertia::render('Club/Settings/PosTestPrint', [
            'printer' => $peripheral->only('name', 'brand', 'model', 'connection', 'paper_width'),
            'device' => ['name' => $device->name, 'branch' => $device->branch?->name],
            'club' => ['name' => $club->name, 'phone' => $club->phone, 'logo' => $club->logoUrl()],
            'at' => now()->format('Y-m-d H:i'),
        ]);
    }

    /** @return array<string, mixed> */
    private function peripheral(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PosPeripheral::TYPES))],
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'connection' => ['required', Rule::in(array_keys(PosPeripheral::CONNECTIONS))],
            'address' => ['nullable', 'required_if:connection,network', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-:]+$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'paper_width' => ['nullable', Rule::in([58, 80])],
            'is_default' => ['boolean'],
            'auto_print' => ['boolean'],
            'print_website_orders' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['address.required_if' => 'أدخل عنوان IP لطابعة الشبكة.']);
        // Only what the type and connection use is kept.
        if ($data['connection'] !== 'network') {
            $data['address'] = $data['port'] = null;
        }
        if ($data['type'] !== 'printer') {
            $data['paper_width'] = null;
            $data['is_default'] = $data['auto_print'] = $data['print_website_orders'] = false;
        } else {
            $data['paper_width'] ??= 80;
        }

        return $data;
    }

    /** One default printer per device. */
    private function singleDefault(PosDevice $device, Request $request, ?PosPeripheral $keep = null): void
    {
        if ($request->boolean('is_default') && $request->input('type') === 'printer') {
            $id = $keep?->id ?? $device->peripherals()->latest('id')->value('id');
            $device->peripherals()->where('type', 'printer')->whereKeyNot($id)->update(['is_default' => false]);
        }
    }
}
