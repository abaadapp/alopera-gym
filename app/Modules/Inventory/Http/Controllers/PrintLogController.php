<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Tenancy\BranchContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PosPeripheral;
use App\Modules\Inventory\Models\PosPrintLog;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\PrintLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What the page or the club's app printed, and the claim of an automatic print (papers.print).
 *
 * The paper named must be one the user may print (its branch, its permission); a printer named must be on a device the
 * user's branch reaches; a test print is for whoever manages the devices. Nothing here prints: the server only keeps
 * the record and the printer's last state.
 */
class PrintLogController extends Controller
{
    private const AUTO = ['sale', 'receipt'];

    private const CODES = '/^[A-Z_]{2,30}$/';

    public function __construct(private readonly PrintLog $log, private readonly PosDevices $devices) {}

    /** The one automatic print of a receipt: { claimed, log } — false when another screen or a reload took it first. */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document' => ['required', Rule::in(self::AUTO)],
            'document_id' => ['required', 'integer', 'min:1'],
        ]);
        $record = $this->printable($request, $data['document'], (int) $data['document_id']);
        $device = $this->devices->current($request)?->loadMissing('peripherals');
        $log = $this->log->claim($request->user(), $data['document'], (int) $data['document_id'], $this->number($record), $device, $device?->printer());

        return response()->json(['claimed' => $log !== null, 'log' => $log?->id]);
    }

    /** How a print ended — a new record, or the claimed automatic one completed. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'log' => ['nullable', 'integer'],
            'document' => ['required_without:log', 'nullable', Rule::in([...array_keys(Papers::BUILDERS), 'test'])],
            'document_id' => ['nullable', 'integer', 'min:1'],
            'peripheral_id' => ['nullable', 'integer'],
            'trigger' => ['required_without:log', 'nullable', Rule::in(['manual', 'reprint', 'test'])],
            'via' => ['required', Rule::in(array_keys(PosPrintLog::VIAS))],
            'ok' => ['required', 'boolean'],
            'printed' => ['boolean'],
            'code' => ['nullable', 'string', 'regex:'.self::CODES],
            'may_have_printed' => ['boolean'],
            'connected' => ['nullable', 'boolean'],
            'effective_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        if (isset($data['log'])) {
            // Only the claim this user took, and only while it waits for its outcome.
            $log = PosPrintLog::whereKey($data['log'])->where('user_id', $request->user()->id)->where('status', PosPrintLog::CLAIMED)->firstOrFail();
        } else {
            $printer = isset($data['peripheral_id']) ? $this->printer($request, (int) $data['peripheral_id']) : null;
            if ($data['document'] === 'test') {
                abort_unless($request->user()->can('pos_devices.manage') && $printer, 403);
                $record = null;
            } else {
                abort_unless(isset($data['document_id']), 422);
                $record = $this->printable($request, $data['document'], (int) $data['document_id']);
            }
            $device = $printer?->device ?? $this->devices->current($request);
            $log = PosPrintLog::create([
                'pos_device_id' => $device?->id, 'pos_peripheral_id' => $printer?->id, 'user_id' => $request->user()->id,
                'document' => $data['document'], 'document_id' => $record?->getKey(), 'number' => $record ? $this->number($record) : null,
                'trigger' => $data['trigger'], 'status' => PosPrintLog::CLAIMED, 'paper' => $printer?->paperSize(),
            ]);
        }

        $log = $this->log->complete($log, [
            'via' => $data['via'], 'ok' => (bool) $data['ok'], 'printed' => (bool) ($data['printed'] ?? false), 'code' => $data['code'] ?? null,
            'may_have_printed' => (bool) ($data['may_have_printed'] ?? false), 'connected' => $data['connected'] ?? null, 'effective_port' => $data['effective_port'] ?? null,
        ]);

        return response()->json(['log' => $log->id, 'status' => $log->status]);
    }

    /** The paper's record, if this user may print it (else 404 for another branch, 403 without the permission). */
    private function printable(Request $request, string $document, int $id): Model
    {
        $builder = Papers::builder($document);
        $record = $builder::find($id);
        abort_if($record === null || ! Papers::reachable($request->user(), $record), 404);
        $allowed = $builder::allows($request->user(), $record);
        abort_unless($allowed, 403);

        return $record;
    }

    private function printer(Request $request, int $id): PosPeripheral
    {
        $printer = PosPeripheral::with('device')->where('type', 'printer')->findOrFail($id);
        abort_unless($printer->device && app(BranchContext::class)->allows($request->user(), $printer->device->branch_id), 404);

        return $printer;
    }

    /** The paper's own number: a receipt's voucher number, else the document's. */
    private function number(Model $record): ?string
    {
        return $record->getAttribute('receipt_number') ?? $record->getAttribute('number') ?? null;
    }
}
