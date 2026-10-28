<?php

namespace App\Http\Middleware;

use App\Core\Auth\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes of an optional module answer 404 while the module is switched off (config gym.modules.*). */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(PermissionRegistry::moduleEnabled($module), 404);

        return $next($request);
    }
}
