<?php

namespace App\Modules\Gym\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic JSON protocol for access-control hardware (docs/DEVICES.md).
 *
 * The device (or a small bridge next to it) sends what it read; we answer
 * allow/deny and log the decision. Opening the relay is the device's job.
 */
class DeviceApiController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var AccessDevice $device */
        $device = $request->attributes->get('device');

        return response()->json(['ok' => true, 'device' => $device->name, 'server_time' => now()->toIso8601String()]);
    }

    public function access(Request $request, AccessControlService $access): JsonResponse
    {
        $data = $request->validate([
            'credential_type' => ['required', 'in:qr,card,fingerprint,pin,barcode'],
            'credential' => ['required', 'string', 'max:128'],
        ]);
        /** @var AccessDevice $device */
        $device = $request->attributes->get('device');

        $holder = $access->resolve($data['credential_type'], $data['credential']);
        $hint = AccessControlService::mask($data['credential']);

        // A staff credential on a door that admits staff: record attendance and open.
        if ($holder instanceof User) {
            if (! $device->acceptsStaff()) {
                return response()->json(['allowed' => false, 'reason' => 'device_not_for_staff', 'message' => 'الجهاز غير مخصص للموظفين'], 200);
            }
            $record = $access->staffPunch($holder, $device, $data['credential_type']);

            return response()->json(['allowed' => true, 'reason' => 'staff', 'message' => $record->clock_out_at ? 'انصراف' : 'حضور', 'staff' => ['id' => $holder->id, 'name' => $holder->name]]);
        }

        $decision = $access->checkIn($holder instanceof Member ? $holder : null, $data['credential_type'], $device, $hint);

        return response()->json($decision->toArray());
    }

    public function staffPunch(Request $request, AccessControlService $access): JsonResponse
    {
        $data = $request->validate([
            'credential_type' => ['required', 'in:card,fingerprint,pin'],
            'credential' => ['required', 'string', 'max:128'],
        ]);
        /** @var AccessDevice $device */
        $device = $request->attributes->get('device');

        $holder = $access->resolve($data['credential_type'], $data['credential']);
        if (! $holder instanceof User || ! $device->acceptsStaff()) {
            return response()->json(['ok' => false, 'reason' => 'unknown_staff'], 404);
        }

        $record = $access->staffPunch($holder, $device, $data['credential_type']);

        return response()->json(['ok' => true, 'type' => $record->clock_out_at ? 'out' : 'in', 'name' => $holder->name, 'at' => now()->toIso8601String()]);
    }
}
