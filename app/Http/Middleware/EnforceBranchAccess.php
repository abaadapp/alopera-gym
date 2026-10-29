<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\BranchOwned;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every record a club-panel route names (after route-model binding) must be one the user's branch may reach: a
 * user tied to a branch gets 404 for another branch's invoice, sale, document or device, as if it did not exist.
 */
class EnforceBranchAccess
{
    public function __construct(private readonly BranchContext $branches) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->branch_id) {
            foreach ($request->route()?->parameters() ?? [] as $value) {
                if ($value instanceof BranchOwned) {
                    abort_unless($this->branches->allows($user, $value->owningBranchId()), 404);
                }
            }
        }

        return $next($request);
    }
}
