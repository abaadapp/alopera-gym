<?php

namespace App\Modules\Gym\Access\Drivers;

use App\Modules\Gym\Models\AccessDevice;

/**
 * The drivers the system has. Only the generic protocol exists today: no maker's own protocol is assumed to work
 * before it is known and tested on its hardware. A device of any maker connects when it — or a bridge next to it —
 * speaks the generic protocol.
 */
final class DeviceDrivers
{
    /** @var array<string, array{label: string, transport: string, description: string, driver: class-string<DeviceDriver>}> */
    public const ALL = [
        'generic_json' => [
            'label' => 'البروتوكول العام للنظام',
            'transport' => 'HTTPS · JSON · Bearer token',
            'description' => 'الجهاز أو برنامج ربط صغير بجانبه يرسل ما قرأه، ويتلقى قرار السماح أو الرفض. يعمل مع أي جهاز يدعم إرسال طلبات HTTP أو يمكن ربطه بجسر.',
            'driver' => GenericJsonDriver::class,
        ],
    ];

    public static function for(AccessDevice $device): DeviceDriver
    {
        return app(self::ALL[$device->driver]['driver'] ?? GenericJsonDriver::class);
    }

    /** @return list<array{key: string, label: string, transport: string, description: string}> */
    public static function catalog(): array
    {
        return collect(self::ALL)->map(fn (array $d, string $key) => ['key' => $key] + collect($d)->except('driver')->all())->values()->all();
    }
}
