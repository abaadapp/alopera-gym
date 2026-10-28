<?php

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\CrossTenantWriteException;
use App\Core\Tenancy\MissingTenantException;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantIsOperational;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\ResolveSiteTenant;
use App\Http\Middleware\ResolveTenantFromUser;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->prepend(ResolveCustomDomain::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'tenant.user' => ResolveTenantFromUser::class,
            'tenant.active' => EnsureTenantIsOperational::class,
            'platform' => EnsurePlatformAdmin::class,
            'site.tenant' => ResolveSiteTenant::class,
            'device' => AuthenticateDevice::class,
        ]);

        // The tenant must be known before route-model binding runs: the tenant
        // scope fails closed, so binding without it would 404 every record.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveSiteTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenantFromUser::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthenticateDevice::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            return $request->routeIs('portal.*') ? route('portal.login', ['club' => $request->route('club') ?? $request->segment(2)]) : route('login');
        });

        // Signed-in users opening a login page go to their own dashboard, not the marketing page.
        $middleware->redirectUsersTo(function (Request $request) {
            if ($request->routeIs('portal.*')) {
                return route('portal.dashboard', ['club' => $request->route('club') ?? $request->segment(2)]);
            }

            return $request->user() instanceof User ? route($request->user()->homeRoute()) : '/';
        });

        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Business rules read like validation errors in forms and as 422 in JSON.
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'rule' => $e->rule], 422);
            }

            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        });

        $exceptions->render(function (MissingTenantException|CrossTenantWriteException $e, Request $request) {
            report($e);
            abort(404);
        });
    })->create();
