<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** A suspended club can still sign in and see its platform billing, nothing else. */
class EnsureTenantIsOperational
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenants->require();

        if ($tenant->isOperational() || $request->routeIs('club.billing', 'logout')) {
            return $next($request);
        }

        return Inertia::render('Club/Suspended', [
            'reason' => $tenant->suspension_reason,
            'status' => $tenant->status,
        ])->toResponse($request)->setStatusCode(402);
    }
}
