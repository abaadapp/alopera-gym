<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Club panel: the tenant is always the signed-in staff member's own club.
 * It is never taken from the URL or the request body.
 */
class ResolveTenantFromUser
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            Auth::guard('web')->logout();

            return redirect()->route('login');
        }

        // An account that belongs to no gym (an old platform account) has no screen here.
        $tenant = $user->tenant_id ? $user->tenant()->first() : null;
        if (! $tenant) {
            Auth::guard('web')->logout();

            return redirect()->route('login');
        }

        $this->tenants->set($tenant);
        $user->setRelation('tenant', $tenant);
        $user->loadMissing('role');

        return $next($request);
    }
}
