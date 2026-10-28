<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public website and member portal: the club comes from the {club} slug.
 * A member session from one club is never valid on another club's portal —
 * the tenant scope makes the member lookup return nothing.
 */
class ResolveSiteTenant
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('club');
        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant || ! $tenant->isOperational()) {
            abort(404);
        }

        $this->tenants->set($tenant);
        URL::defaults(['club' => $tenant->slug]);
        $request->route()->forgetParameter('club');

        // Resolve the member now that the tenant scope is in place.
        $member = Auth::guard('member')->user();
        if ($member && (int) $member->tenant_id !== $tenant->id) {
            Auth::guard('member')->logout();
        }

        return $next($request);
    }
}
