<?php

namespace App\Modules\Inventory\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\PosPeripheral;
use App\Modules\Inventory\Models\PosPrintLog;

/**
 * The print log: what the panel printed and how it ended, and the one claim an automatic print takes.
 *
 * The server never reaches a printer (it is on the club's local network); the page or the club's app prints and says
 * here what happened. A failure that may have reached the paper is never printed again by itself: the cashier decides.
 */
final class PrintLog
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Take the automatic print of a paper: the first device to ask gets it, any later ask (a reloaded page, a second
     * screen, a double event) is told no. ON CONFLICT DO NOTHING, so a lost race is an answer, not an error.
     */
    public function claim(User $user, string $document, int $documentId, ?string $number, ?PosDevice $device, ?PosPeripheral $printer): ?PosPrintLog
    {
        $key = $document.':'.$documentId;
        $now = now();
        $taken = PosPrintLog::query()->insertOrIgnore([
            'tenant_id' => $this->tenants->id(), 'pos_device_id' => $device?->id, 'pos_peripheral_id' => $printer?->id, 'user_id' => $user->id,
            'document' => $document, 'document_id' => $documentId, 'number' => $number, 'trigger' => 'auto', 'status' => PosPrintLog::CLAIMED,
            'paper' => $printer?->paperSize(), 'auto_key' => $key, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $taken === 1 ? PosPrintLog::where('auto_key', $key)->first() : null;
    }

    /**
     * How a print ended. «printed» only for the app's direct print the printer took; a dialog opened is «opened».
     *
     * @param  array{via: string, ok: bool, printed?: bool, code?: ?string, may_have_printed?: bool, connected?: ?bool, effective_port?: ?int}  $outcome
     */
    public function complete(PosPrintLog $log, array $outcome): PosPrintLog
    {
        $log->forceFill([
            'via' => $outcome['via'],
            'status' => $outcome['ok'] ? (($outcome['via'] === 'native' && ($outcome['printed'] ?? false)) ? PosPrintLog::PRINTED : PosPrintLog::OPENED) : PosPrintLog::FAILED,
            'code' => $outcome['ok'] ? null : ($outcome['code'] ?? 'INTERNAL'),
            'may_have_printed' => ! $outcome['ok'] && ($outcome['may_have_printed'] ?? false),
            'connected' => $outcome['connected'] ?? null,
            'effective_port' => $outcome['effective_port'] ?? null,
        ])->save();

        // A printer's state is what the app saw when it reached it (a direct print or a test), not a dialog opened.
        $printer = $log->printer;
        if ($printer && ($log->via === 'native' || $log->trigger === 'test') && $log->code !== 'NATIVE_REQUIRED') {
            $printer->forceFill([
                'last_status' => $log->status === PosPrintLog::FAILED ? 'error' : 'ok',
                'last_error' => $log->status === PosPrintLog::FAILED ? $log->message() : null,
                'last_checked_at' => now(),
            ])->save();
        }

        return $log;
    }
}
