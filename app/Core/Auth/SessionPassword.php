<?php

namespace App\Core\Auth;

use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Does a signed-in session still hold the account's current password? (#135)
 *
 * Every sign-in stores a keyed fingerprint of the password in the session (`password_hash_<guard>`, Laravel's
 * SessionGuard::login). A session that has one holds the current password when it still matches. A session that has
 * none (re-opened by a remember cookie, or opened before sign-ins stored it) shows nothing by itself. It holds the
 * password only when the remember cookie it carries is this account's, with the current remember token and password.
 * Every password change rotates that token. Only fingerprints are compared, and none of them is ever logged.
 */
final class SessionPassword
{
    public static function key(string $guard): string
    {
        return 'password_hash_'.$guard;
    }

    /** True when nobody is signed in to $guard, the account has no password, or the session holds the current one. */
    public static function holds(Request $request, string $guard): bool
    {
        if (! $request->hasSession()) {
            return true;
        }
        /** @var SessionGuard $auth */
        $auth = Auth::guard($guard);
        $user = $auth->user();
        if (! $user || ! $user->getAuthPassword()) {
            return true;
        }

        $stored = $request->session()->get(self::key($guard));

        return is_string($stored)
            ? self::matches($auth, $user->getAuthPassword(), $stored)
            : self::rememberCookieProves($request, $auth, $user);
    }

    /**
     * A session signed in to $guard that does not hold the current password is signed out of it, on this device only.
     * Its other devices keep their own check, and the rest of the session (the CSRF token) stays.
     */
    public static function forgetStale(Request $request, string $guard): void
    {
        if (! self::holds($request, $guard)) {
            Auth::guard($guard)->logoutCurrentDevice();
            $request->session()->forget(self::key($guard));
        }
    }

    /** The request's own remember cookie: this account, its current remember token and its current password. */
    public static function rememberCookieProves(Request $request, SessionGuard $auth, Authenticatable $user): bool
    {
        $cookie = $request->cookies->get($auth->getRecallerName());
        if (! is_string($cookie) || ! ($recaller = new Recaller($cookie))->valid()) {
            return false;
        }
        $token = (string) $user->getRememberToken();

        return (string) $recaller->id() === (string) $user->getAuthIdentifier()
            && $token !== '' && hash_equals($token, $recaller->token())
            && self::matches($auth, $user->getAuthPassword(), $recaller->hash());
    }

    /** The keyed fingerprint Laravel stores, or the raw hash older sessions stored (as Laravel's own check accepts). */
    private static function matches(SessionGuard $auth, string $password, string $stored): bool
    {
        return hash_equals($auth->hashPasswordForCookie($password), $stored) || hash_equals($password, $stored);
    }
}
