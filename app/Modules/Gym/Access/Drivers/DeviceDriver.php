<?php

namespace App\Modules\Gym\Access\Drivers;

use Illuminate\Http\Request;

/**
 * How one family of devices talks to the system. A driver turns the device's request into a reading (what was
 * scanned) and nothing more: the decision is the access-control service's, and opening a door or a turnstile stays
 * the device's own job once it is told `allowed`. The system never sends an "open" command.
 *
 * A new driver is added only for a device whose maker, model and protocol are known and tested on the real
 * hardware; until then a device connects through the generic protocol, directly or through a small bridge.
 */
interface DeviceDriver
{
    /**
     * What the device read. Throws a validation error for a malformed request.
     *
     * @param  list<string>  $types  the reading types this endpoint accepts
     * @return array{type: string, value: string}
     */
    public function reading(Request $request, array $types): array;
}
