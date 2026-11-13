<?php

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\CrossTenantWriteException;
use App\Core\Tenancy\MissingTenantException;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\EnforceBranchAccess;
use App\Http\Middleware\EnsureClubFinance;
use App\Http\Middleware\EnsureMemberActive;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepFlashForThePage;
use App\Http\Middleware\NormalizeNumbers;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\ResolveSiteTenant;
use App\Http\Middleware\ResolveTenantFromUser;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SiteLocale;
use App\Http\Middleware\TrustHosts;
use App\Models\User;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\TrustProxies;
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
            KeepFlashForThePage::class,
            HandleInertiaRequests::class,
        ]);
        // First of all, only the panel's, the site's and the clubs' own domains: a request naming any other Host is refused
        // (400) on every path, before anything is looked up, redirected or linked from it (TrustHosts; read after the proxy
        // settings, so a forwarded Host is held to the same list). Then the club's domain onto its site routes, then the
        // language out of the path (/en/plans → English /plans).
        $middleware->prepend([TrustProxies::class, TrustHosts::class, ResolveCustomDomain::class, SiteLocale::class]);
        $middleware->append(SecurityHeaders::class);
        // Figures typed on an Arabic keyboard (١٢٫٥) read as figures, everywhere a form or a device posts.
        $middleware->append(NormalizeNumbers::class);

        $middleware->alias([
            'tenant.user' => ResolveTenantFromUser::class,
            'site.tenant' => ResolveSiteTenant::class,
            'device' => AuthenticateDevice::class,
            'module' => EnsureModuleEnabled::class,
            'branch.guard' => EnforceBranchAccess::class,
            'club.finance' => EnsureClubFinance::class,
            'member.active' => EnsureMemberActive::class,
            // Laravel's auth.session, plus: a session that cannot show it holds the current password signs in again (#135).
            'auth.session' => AuthenticateSession::class,
            // Laravel's guest, plus: such a session is a guest on the sign-in and password pages at once (#135).
            'guest' => RedirectIfAuthenticated::class,
        ]);

        // The tenant must be known before route-model binding runs: the tenant
        // scope fails closed, so binding without it would 404 every record.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveSiteTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenantFromUser::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthenticateDevice::class);
        // The branch guard reads the bound records, so it runs after binding.
        $middleware->appendToPriorityList(after: SubstituteBindings::class, append: EnforceBranchAccess::class);

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
        // A refused form never carries a password back into the session, the account's new one included.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'account_password', 'account_password_confirmation']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Business rules read like validation errors in forms and as 422 in JSON.
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'rule' => $e->rule], 422);
            }

            // On the English website and portal, in English where the site has the words (lang/en.json).
            throw ValidationException::withMessages([$e->field => SiteLanguage::word($e->getMessage())]);
        });

        // More than PHP accepts in one request (post_max_size): nothing of it reached the app, so say so plainly.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = 'حجم ما أُرسل أكبر مما يقبله الخادم في طلب واحد. أرسل صورًا أقل أو أصغر في كل مرة.';
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message], 413);
            }

            return back()->with('error', $message);
        });

        // An image that was refused or could not be stored: the reason, on the field it came from; nothing was saved.
        $exceptions->render(function (ImageRejected $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field ?? 'image' => [$e->getMessage()]]], 422);
            }

            throw ValidationException::withMessages([$e->field ?? 'image' => $e->getMessage()]);
        });

        $exceptions->render(function (MissingTenantException|CrossTenantWriteException $e, Request $request) {
            report($e);
            abort(404);
        });
    })->create();
