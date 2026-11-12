<?php

namespace App\Http\Middleware;

use App\Core\Auth\SessionPassword;
use Closure;
use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

/**
 * auth.session (#135): Laravel's check, closed for the one case it lets through.
 *
 * A session whose password fingerprint no longer matches ends on its next request, as in Laravel. Laravel also lets a
 * session with no fingerprint in, storing the current password for it. That session would pass even after the password
 * changed: one re-opened by a remember cookie before this check ran, or opened before sign-ins stored the fingerprint.
 * Here it passes only if its own remember cookie proves the current password (SessionPassword). Otherwise it signs in
 * again, once: the session is emptied and the next page is the sign-in form. A session that can be checked is checked
 * as before, so a deploy signs out nobody whose session proves the current password.
 */
class AuthenticateSession extends LaravelAuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if (! SessionPassword::holds($request, $this->auth->getDefaultDriver())) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
