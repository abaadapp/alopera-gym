<?php

namespace App\Modules\Gym\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Access\Drivers\DeviceDrivers;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Access-control hardware API (docs/DEVICES.md). The device's driver reads its request; the generic JSON protocol
 * is the only one today.
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
        /** @var AccessDevice $device */
        $device = $request->attributes->get('device');
        $reading = DeviceDrivers::for($device)->reading($request, ['qr', 'card', 'fingerprint', 'pin', 'barcode']);
        $data = ['credential_type' => $reading['type'], 'credential' => $reading['value']];

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
        /** @var AccessDevice $device */
        $device = $request->attributes->get('device');
        $reading = DeviceDrivers::for($device)->reading($request, ['card', 'fingerprint', 'pin']);
        $data = ['credential_type' => $reading['type'], 'credential' => $reading['value']];

        $holder = $access->resolve($data['credential_type'], $data['credential']);
        if (! $holder instanceof User || ! $device->acceptsStaff()) {
            return response()->json(['ok' => false, 'reason' => 'unknown_staff'], 404);
        }

        $record = $access->staffPunch($holder, $device, $data['credential_type']);

        return response()->json(['ok' => true, 'type' => $record->clock_out_at ? 'out' : 'in', 'name' => $holder->name, 'at' => now()->toIso8601String()]);
    }
}
