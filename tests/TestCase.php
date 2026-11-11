<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Tests\Support\DatabaseIsolation;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tests empty their database (RefreshDatabase runs migrate:fresh, the concurrency tests truncate theirs), so
     * they start only on a test database on this computer, checked before anything touches it: not the production
     * server, APP_ENV testing, no cached configuration (phpunit.xml would be ignored), PostgreSQL on a local host
     * without DB_URL, a name with "test" that is not the .env one, and PostgreSQL's own answer once connected
     * (Tests\Support\DatabaseIsolation).
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        DatabaseIsolation::assert($app, ['testing'], 'The tests did not start, nothing was touched: ');

        return $app;
    }

    /**
     * As a sign-in does (SessionGuard::login): the session carries the password fingerprint of whoever acts now, so
     * auth.session (routes/web.php, the portal) treats a test that switches accounts like a real sign-in.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        parent::be($user, $guard);
        $name = $guard ?? $this->app['auth']->getDefaultDriver();
        if ($user->getAuthPassword()) {
            $this->app['session']->put('password_hash_'.$name, $this->app['auth']->guard($name)->hashPasswordForCookie($user->getAuthPassword()));
        }

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * A test run as production (env switched) passes the trusted-host check, which keeps its list in a static of the
     * request class: cleared, so the next test's hosts are not judged by it. Production clears it with each request.
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }
}
