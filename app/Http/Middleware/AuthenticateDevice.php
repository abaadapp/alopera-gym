<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Gym\Models\AccessDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Access-control hardware authenticates with `Authorization: Bearer <device token>`. */
class AuthenticateDevice
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        $device = $token === '' ? null : $this->tenants->bypass(
            fn () => AccessDevice::where('token_hash', hash('sha256', $token))->first()
        );

        if (! $device || ! $device->is_active) {
            return response()->json(['allowed' => false, 'error' => 'unauthenticated_device'], 401);
        }

        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant || ! $tenant->isOperational()) {
            return response()->json(['allowed' => false, 'error' => 'club_unavailable'], 403);
        }

        $this->tenants->set($tenant);
        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
