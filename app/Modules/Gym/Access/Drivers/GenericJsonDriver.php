<?php

namespace App\Modules\Gym\Access\Drivers;

use App\Modules\Gym\Models\AccessCredential;
use Illuminate\Http\Request;

/** The system's own protocol (docs/DEVICES.md): `{"credential_type": "...", "credential": "..."}` over HTTPS. */
final class GenericJsonDriver implements DeviceDriver
{
    public function reading(Request $request, array $types): array
    {
        $type = (string) $request->input('credential_type');
        $data = $request->validate([
            'credential_type' => ['required', 'in:'.implode(',', $types)],
            'credential' => array_merge(['required', 'string', 'max:128'], $type === 'fingerprint' ? ['regex:'.AccessCredential::FINGERPRINT_ID] : []),
        ]);

        return ['type' => $data['credential_type'], 'value' => $data['credential']];
    }
}
