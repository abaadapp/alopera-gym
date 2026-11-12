<?php

namespace Tests\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Devices signed in at once (#135). Each "device" is a real session in the `sessions` table (the database driver, as on
 * the server), signed in through the real sign-in form. Before every request what a fresh PHP-FPM process would not
 * have is dropped: the guards and their cached user, the default guard, the session store, the tenant and the client's
 * cookies. A request then carries only that device's own cookies, so a device is signed in only if its own session row
 * says so.
 */
trait SignsInDevices
{
    protected function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /** One request from one device, as a fresh process sees it: only $sid (and a remember cookie, if given) is sent. */
    protected function hit(?string &$sid, string $method, string $uri, array $data = [], array $cookies = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('redirect');
        Facade::clearResolvedInstances();
        app(TenantContext::class)->set(null);
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        if ($sid !== null) {
            $this->withCookie(config('session.cookie'), $sid);
        }
        foreach ($cookies as $name => $value) {
            $this->withCookie($name, $value);
        }
        $response = match ($method) {
            'GET' => $this->get($uri),
            'POST' => $this->post($uri, $data),
            'PUT' => $this->put($uri, $data),
            'DELETE' => $this->delete($uri, $data),
        };
        $sid = $this->app['session']->driver()->getId();
        app(TenantContext::class)->set(null);

        return $response;
    }

    /** The sign-in limit (five a minute per account) is not what these tests are about: each sign-in starts afresh. */
    protected function freshSignInLimits(): void
    {
        $this->app['cache']->store()->flush();
    }

    protected function signIn(User $user, string $password = 'password', bool $remember = false): string
    {
        $this->freshSignInLimits();
        $sid = null;
        $this->hit($sid, 'POST', route('login.store'), ['email' => $user->email, 'password' => $password, 'remember' => $remember])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->hit($sid, 'GET', route('club.account.show'))->assertOk();

        return $sid;
    }

    protected function signInPortal(Tenant $club, Member $member, string $password = 'old-pass-123'): string
    {
        $this->freshSignInLimits();
        $sid = null;
        $this->hit($sid, 'POST', route('portal.login.store', ['club' => $club->slug]), ['login' => $member->phone, 'password' => $password])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->hit($sid, 'GET', route('portal.dashboard', ['club' => $club->slug]))->assertOk();

        return $sid;
    }

    /**
     * The device's session as the server may still hold one from before the deploy: signed in, with no password
     * fingerprint (re-opened by a remember cookie, or opened before sign-ins stored it).
     */
    protected function withoutFingerprint(string $sid, string $guard = 'web'): string
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $store = $this->app['session']->driver();
        $store->setId($sid);
        $store->start();
        if (! $store->has('password_hash_'.$guard)) {
            throw new AssertionFailedError("the sign-in stored no $guard fingerprint to take away");
        }
        $store->forget('password_hash_'.$guard);
        $store->save();

        return $sid;
    }

    protected function assertPanel(string $sid, bool $signedIn, string $who): void
    {
        $response = $this->hit($sid, 'GET', route('club.account.show'));
        try {
            $signedIn ? $response->assertOk() : $response->assertRedirect(route('login'));
        } catch (AssertionFailedError $e) {
            throw new AssertionFailedError($who.': '.$e->getMessage());
        }
    }

    protected function assertPortal(Tenant $club, string $sid, bool $signedIn): void
    {
        $response = $this->hit($sid, 'GET', route('portal.dashboard', ['club' => $club->slug]));
        $signedIn ? $response->assertOk() : $response->assertRedirect(route('portal.login', ['club' => $club->slug]));
    }
}
