<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Papers\Paper;
use App\Core\Papers\PaperFormat;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\PaperSize;
use App\Core\Papers\PaperTypes;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\PosPeripheral;
use App\Modules\Inventory\Models\PosPrintLog;
use App\Modules\Inventory\Rules\PrinterAddress;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\PrinterHealth;
use App\Modules\Inventory\Services\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
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
        // The print log of each device: its latest results, errors included, with who printed what.
        // What keeps a device's receipts from coming out as its settings say — told, never mended here.
        $health = PrinterHealth::assess($devices);
        $logs = PosPrintLog::with(['user:id,name', 'printer:id,name'])->whereIn('pos_device_id', $devices->pluck('id'))->latest('id')->limit(40 * max(1, $devices->count()))->get()->groupBy('pos_device_id');

        return Inertia::render('Club/Settings/PosDevices', [
            'devices' => $devices->map(fn (PosDevice $d) => $d->only('id', 'name', 'status', 'branch_id', 'settlement_account_id', 'sales_count', 'revoke_reason') + [
                'branch' => $d->branch?->name, 'activated_by' => $d->activator?->name, 'activated_at' => $d->activated_at?->format('Y-m-d H:i'),
                'last_seen' => $d->last_seen_at?->diffForHumans(), 'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i'), 'settlement_account' => $d->settlementAccount?->name,
                'code_expires_at' => $d->status === PosDevice::PENDING ? $d->pairing_expires_at?->format('H:i') : null,
                'code_expired' => $d->status === PosDevice::PENDING && ! $d->pairing_expires_at?->isFuture(),
                'is_this' => $current?->id === $d->id, 'auto_print' => (bool) $d->setting('auto_print', false),
                'errors' => $d->peripherals->where('last_status', 'error')->pluck('last_error')->filter()->values(),
                'warnings' => $health[$d->id] ?? [],
                'peripherals' => $d->peripherals->map(fn (PosPeripheral $p) => $p->only('id', 'type', 'name', 'brand', 'model', 'connection', 'address', 'port', 'paper_width', 'is_default', 'auto_print', 'print_website_orders', 'is_active', 'notes', 'last_status', 'last_error') + [
                    'last_checked_at' => $p->last_checked_at?->format('Y-m-d H:i'), 'paper' => $p->type === 'printer' ? $p->paperSize() : null,
                    'direct' => $p->printsDirect(), 'printing' => $p->type === 'printer' ? $p->forPrinting() : null,
                ]),
                'logs' => ($logs[$d->id] ?? collect())->take(20)->map(fn (PosPrintLog $l) => [
                    'id' => $l->id, 'at' => $l->created_at->format('Y-m-d H:i'), 'document' => $l->document, 'number' => $l->number,
                    'trigger' => PosPrintLog::TRIGGERS[$l->trigger] ?? $l->trigger, 'via' => $l->via ? (PosPrintLog::VIAS[$l->via] ?? $l->via) : null,
                    'status' => $l->status, 'message' => $l->message(), 'may_have_printed' => $l->may_have_printed, 'printer' => $l->printer?->name, 'by' => $l->user?->name,
                ])->values(),
            ]),
            'code' => session('pairing_code'),
            'branches' => app(BranchContext::class)->choices(),
            'accounts' => Account::money()->postable()->where('kind', 'bank')->orderBy('code')->get(['id', 'name']),
            'policy' => ['require_device' => ShopSettings::of($this->tenants->require())['pos_require_device']],
            'types' => PosPeripheral::TYPES, 'drivable' => PosPeripheral::DRIVABLE, 'connections' => PosPeripheral::CONNECTIONS,
            'printerConnections' => PosPeripheral::PRINTER_CONNECTIONS, 'papers' => PosPeripheral::PAPERS, 'defaultPort' => PosPeripheral::DEFAULT_PORT,
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
        $data = $this->peripheral($request);
        DB::transaction(function () use ($device, $data) {
            $this->takeDefault($device, $data);
            $device->peripherals()->create($data);
        });

        return back()->with('success', 'أُضيف الملحق.');
    }

    public function updatePeripheral(Request $request, PosDevice $device, PosPeripheral $peripheral): RedirectResponse
    {
        abort_unless($peripheral->pos_device_id === $device->id, 404);
        $data = $this->peripheral($request);
        DB::transaction(function () use ($device, $peripheral, $data) {
            $this->takeDefault($device, $data, $peripheral);
            $peripheral->update($data);
        });

        return back()->with('success', 'حُفظ الملحق.');
    }

    public function destroyPeripheral(PosDevice $device, PosPeripheral $peripheral): RedirectResponse
    {
        abort_unless($peripheral->pos_device_id === $device->id, 404);
        $peripheral->delete();

        return back()->with('success', 'حُذف الملحق.');
    }

    /**
     * A printer's test page, drawn by the paper engine on the printer's own paper (strip or sheet) with the club's logo,
     * Arabic, English, digits and an amount: the print page for the browser's dialog, or with ?format=pdf the PDF the
     * app hands to the system's print dialog. Whether it reached the paper is for the person at the printer to see; a
     * network printer is tested for real by the app itself (it connects and prints, and says what happened).
     */
    public function testPrint(Request $request, PosDevice $device, PosPeripheral $peripheral): HttpResponse
    {
        abort_unless($peripheral->pos_device_id === $device->id && $peripheral->type === 'printer', 404);
        $club = $this->tenants->require();
        $paper = $peripheral->paperSize();
        $port = $peripheral->port ?: PosPeripheral::DEFAULT_PORT;
        $doc = [
            'title' => 'اختبار الطابعة', 'number' => 'TEST-'.now()->format('His'), 'status' => null,
            'meta' => array_values(array_filter([
                ['label' => 'الطابعة', 'value' => $peripheral->name],
                filled($peripheral->brand.$peripheral->model) ? ['label' => 'الطراز', 'value' => trim($peripheral->brand.' '.$peripheral->model)] : null,
                ['label' => 'الورق', 'value' => PosPeripheral::PAPERS[$paper] ?? $paper],
                ['label' => 'الاتصال', 'value' => $peripheral->connection === 'network' ? 'شبكة' : 'حوار الطباعة'],
                $peripheral->connection === 'network' ? ['label' => 'العنوان', 'value' => $peripheral->address.':'.$port, 'ltr' => true] : null,
                ['label' => 'الجهاز', 'value' => $device->name],
                ['label' => 'الفرع', 'value' => $device->branch?->name],
                ['label' => 'الوقت', 'value' => now()->format('Y-m-d H:i:s'), 'ltr' => true],
            ])),
            'parties' => [],
            'table' => ['rows' => [
                ['name' => 'عربي: اشتراك شهري — تدريب شخصي', 'qty' => '1', 'unit' => '12.500', 'total' => '12.500'],
                ['name' => 'English: Monthly membership', 'qty' => '2', 'unit' => '1.250', 'total' => '2.500'],
            ], 'empty' => ''],
            'totals' => [['label' => 'الإجمالي', 'value' => '15.000', 'currency' => PaperFormat::currency((string) ($club->currency ?? 'OMR')), 'grand' => true]],
            'panels' => [['caption' => 'الأرقام', 'text' => '١٢٣٤٥٦٧٨٩٠ — 1234567890', 'rows' => []]],
            'signatures' => [],
        ];
        $tpl = ['footer' => 'إن قرأت هذا كاملًا فالطابعة جاهزة · If you can read this, the printer is ready.'] + PaperTypes::settings($club, 'sale');

        return (new Paper($club, 'sale', $doc, $tpl, PaperIssuer::resolve($club), $paper, 'printer-test'))->respond($request);
    }

    /** @return array<string, mixed> */
    private function peripheral(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PosPeripheral::TYPES))],
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            // A printer is reached on the network (the app prints to it) or through the print dialog; nothing else is offered.
            'connection' => ['required', Rule::in(array_keys($request->input('type') === 'printer' ? PosPeripheral::PRINTER_CONNECTIONS : PosPeripheral::CONNECTIONS))],
            'address' => ['nullable', 'required_if:connection,network', 'string', 'max:120', new PrinterAddress],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'paper' => ['nullable', Rule::in(array_keys(PosPeripheral::PAPERS))],
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
            $data['paper_width'] = $data['paper'] = null;
            $data['is_default'] = $data['auto_print'] = $data['print_website_orders'] = false;
        } else {
            // The paper names the printer's kind: a strip keeps its width beside it, a sheet has none.
            $data['paper'] ??= ($data['paper_width'] ?? 80) === 58 ? '58mm' : '80mm';
            $data['paper_width'] = PaperSize::isStrip($data['paper']) ? (int) $data['paper'] : null;
            $data['address'] = isset($data['address']) ? trim($data['address']) : null;
        }

        return $data;
    }

    /**
     * One default printer per device, held by the database too (pos_peripherals_one_default_printer): the printer saved
     * as default takes the other's place before it is written, and the device's row is locked first, so two saves at
     * the same moment queue instead of meeting the index. A save that does not make a printer default touches no other.
     *
     * @param  array<string, mixed>  $data  the validated printer (is_default is false for anything but a printer)
     */
    private function takeDefault(PosDevice $device, array $data, ?PosPeripheral $self = null): void
    {
        if (! ($data['is_default'] ?? false)) {
            return;
        }
        PosDevice::whereKey($device->id)->lockForUpdate()->first();
        $device->peripherals()->where('type', 'printer')->where('is_default', true)
            ->when($self, fn ($q) => $q->whereKeyNot($self->id))->update(['is_default' => false]);
    }
}
