<?php

namespace App\Core\Tenancy;

use App\Models\Tenant;

/**
 * The one gym this system serves: the one named in config gym.home_club (GYM_HOME_CLUB), or else the only gym on record.
 * Any other club record (an old one, a test one) is kept with its data but not served: its staff do not sign in, its
 * website and portal are not found, its devices are refused and no scheduled job runs for it. With several clubs on
 * record and none named (a development copy, the test suite) there is no home gym and nothing is limited; the release
 * gate warns about that state.
 */
final class HomeGym
{
    public static function tenant(): ?Tenant
    {
        $slug = config('gym.home_club');
        if ($slug) {
            return Tenant::where('slug', $slug)->first();
        }

        return Tenant::query()->count() === 1 ? Tenant::query()->first() : null;
    }

    public static function id(): ?int
    {
        return self::tenant()?->id;
    }

    /** Whether this club is served: the home gym, or any while there is none. */
    public static function allows(?int $tenantId): bool
    {
        $home = self::id();

        return $home === null || $tenantId === $home;
    }
}
