<?php

namespace App\Http\Middleware;

use App\Core\Auth\SessionPassword;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as LaravelRedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * guest: the sign-in and password pages. A session that does not hold its account's current password (#135) is a
 * guest here at once. Without this, Laravel would send it to its dashboard, which would sign it out and send it back to
 * the sign-in form: a reset link opened on such a device would be lost on the way. Only the sign-in is dropped, so the
 * page's CSRF token stays valid and the form it shows can be sent.
 */
class RedirectIfAuthenticated extends LaravelRedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        foreach ($guards ?: [Auth::getDefaultDriver()] as $guard) {
            SessionPassword::forgetStale($request, $guard);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
