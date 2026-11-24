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

        $clubs = Tenant::query()->when(self::demoSlug(), fn ($q, $demo) => $q->where('slug', '!=', $demo));

        return $clubs->count() === 1 ? $clubs->first() : null;
    }

    /** The demo club's web-address part, when one is set and it is not the gym itself. */
    public static function demoSlug(): ?string
    {
        $demo = config('gym.demo_club');

        return $demo && $demo !== config('gym.home_club') ? (string) $demo : null;
    }

    public static function demo(): ?Tenant
    {
        $slug = self::demoSlug();

        return $slug ? Tenant::where('slug', $slug)->first() : null;
    }

    public static function isDemo(?int $tenantId): bool
    {
        if ($tenantId === null || ! ($slug = self::demoSlug())) {
            return false;
        }
        $tenant = Tenant::find($tenantId);

        return $tenant !== null && $tenant->slug === $slug && (bool) $tenant->setting('is_demo', false) && $tenantId !== self::id();
    }

    /** Whether this club's staff may use the panel: the club served, or the demo club. */
    public static function allowsPanel(?int $tenantId): bool
    {
        return self::allows($tenantId) || self::isDemo($tenantId);
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
