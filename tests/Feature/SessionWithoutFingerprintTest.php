<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\SignsInDevices;
use Tests\TestCase;

/**
 * #135, sessions that cannot show which password they were signed in with. Every sign-in stores the password's
 * fingerprint in its session, but a session re-opened by a remember cookie, or opened before sign-ins stored it, has
 * none. Laravel would store the current password for it on its next request and let it in, even after the password
 * changed. Here such a session passes only if its own remember cookie proves the current password. Otherwise it signs
 * in again, once, without a redirect loop. Sessions that hold their fingerprint are checked as before, so the deploy
 * signs out nobody whose session can be checked.
 */
class SessionWithoutFingerprintTest extends TestCase
{
    use BuildsClubs, RefreshDatabase, SignsInDevices;

    private const NEW = 'N3w-Pass!word-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
    }

    /** @return array{string, string} the device's session and its remember cookie */
    private function rememberedSignIn(User $user): array
    {
        $this->freshSignInLimits();
        $sid = null;
        $login = $this->hit($sid, 'POST', route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        return [$sid, (string) $login->getCookie(Auth::guard('web')->getRecallerName())?->getValue()];
    }

    /** @return array{string, string} */
    private function rememberedPortalSignIn(Tenant $club, Member $member): array
    {
        $this->freshSignInLimits();
        $sid = null;
        $login = $this->hit($sid, 'POST', route('portal.login.store', ['club' => $club->slug]), ['login' => $member->phone, 'password' => 'old-pass-123', 'remember' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        return [$sid, (string) $login->getCookie(Auth::guard('member')->getRecallerName())?->getValue()];
    }

    /** Follows the redirects one device is sent, as a browser does: where it lands, and the page there. */
    private function land(string $sid, string $uri): array
    {
        for ($step = 0; $step < 5; $step++) {
            $response = $this->hit($sid, 'GET', $uri);
            if (! $response->isRedirect()) {
                return [$uri, $response];
            }
            $uri = (string) $response->headers->get('Location');
        }
        $this->fail("still redirected after five steps, at $uri");
    }

    private function changeOwnPassword(string $sid): void
    {
        $this->hit($sid, 'PUT', route('club.account.password'), ['current_password' => 'password', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function staffSetPassword(string $sid, User $user): TestResponse
    {
        return $this->hit($sid, 'PUT', route('club.users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id, 'is_active' => true, 'password' => self::NEW,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_session_that_cannot_show_its_password_signs_in_again_once_whatever_the_role(): void
    {
        $club = $this->club();
        $accounts = [
            'owner' => $this->owner($club), 'manager' => $this->staff($club, 'manager'),
            'reception' => $this->staff($club, 'reception'), 'trainer' => $this->staff($club, 'trainer'),
        ];

        foreach ($accounts as $role => $user) {
            $kept = $this->signIn($user);
            $old = $this->withoutFingerprint($this->signIn($user));

            [$where, $page] = $this->land($old, route('club.account.show'));
            $this->assertSame(route('login'), $where, "$role: sent to the sign-in form");
            $page->assertOk();
            $this->assertPanel($old, false, "$role: a guest from now on");
            $this->assertPanel($kept, true, "$role: a session that holds its fingerprint is not touched");
            $this->signIn($user);
        }
    }

    public function test_a_password_changed_before_the_old_sessions_first_request_after_the_deploy_signs_it_out(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $reception = $this->staff($club, 'reception');
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $bystander = $this->staff($club, 'reception');
        // Sessions the server holds from before the deploy.
        $ownerOld = $this->withoutFingerprint($this->signIn($owner));
        $managerOld = $this->withoutFingerprint($this->signIn($manager));
        $receptionOld = $this->withoutFingerprint($this->signIn($reception));
        $memberOld = $this->withoutFingerprint($this->signInPortal($club, $member), 'member');
        [$bystanderOld, $bystanderCookie] = $this->rememberedSignIn($bystander);
        $this->withoutFingerprint($bystanderOld);

        // Each password changes before that session's next request: the owner's own, from another device; the desk's,
        // by the owner; the manager's, by a reset link; the member's, by staff.
        $device = $this->signIn($owner);
        $this->changeOwnPassword($device);
        $this->staffSetPassword($device, $reception);
        $none = null;
        $this->hit($none, 'POST', route('password.update'), [
            'token' => Password::broker('users')->createToken($manager), 'email' => $manager->email, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->hit($device, 'POST', route('club.members.portal-password', $member), ['password' => 'new-pass-456'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertPanel($ownerOld, false, 'the owner\'s old session');
        $this->assertPanel($managerOld, false, 'the manager\'s old session');
        $this->assertPanel($receptionOld, false, 'the desk\'s old session');
        $this->assertPortal($club, $memberOld, false);
        $this->assertPanel($device, true, 'the device that made the changes');
        // An account whose password did not change and whose remember cookie proves it stays signed in.
        $this->hit($bystanderOld, 'GET', route('club.account.show'), cookies: [Auth::guard('web')->getRecallerName() => $bystanderCookie])->assertOk();
    }

    public function test_a_session_without_a_fingerprint_stays_when_its_remember_cookie_proves_the_password(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['password' => 'old-pass-123']);

        [$sid, $cookie] = $this->rememberedSignIn($owner);
        $this->withoutFingerprint($sid);
        $this->hit($sid, 'GET', route('club.account.show'), cookies: [Auth::guard('web')->getRecallerName() => $cookie])->assertOk();
        $this->assertPanel($sid, true, 'it stored its fingerprint and needs the cookie no more');

        [$portal, $portalCookie] = $this->rememberedPortalSignIn($club, $member);
        $this->withoutFingerprint($portal, 'member');
        $this->hit($portal, 'GET', route('portal.dashboard', ['club' => $club->slug]), cookies: [Auth::guard('member')->getRecallerName() => $portalCookie])->assertOk();
        $this->assertPortal($club, $portal, true);
    }

    public function test_a_remember_cookie_proves_nothing_once_the_password_changed_or_when_it_is_not_this_accounts(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $colleague = $this->staff($club, 'reception');
        $recaller = Auth::guard('web')->getRecallerName();
        [, $cookie] = $this->rememberedSignIn($owner);
        [, $theirs] = $this->rememberedSignIn($colleague);

        // Another account's cookie, and this account's token with a fingerprint of another password.
        $forged = $owner->id.'|'.$owner->fresh()->getRememberToken().'|'.hash_hmac('sha256', 'another password', 'key');
        foreach (['another account\'s cookie' => $theirs, 'a forged fingerprint' => $forged] as $case => $value) {
            $old = $this->withoutFingerprint($this->signIn($owner));
            $this->hit($old, 'GET', route('club.account.show'), cookies: [$recaller => $value])->assertRedirect(route('login'));
            $this->assertPanel($old, false, $case);
        }

        // The account's own cookie, after its password changed (a new remember token, a new fingerprint).
        $old = $this->withoutFingerprint($this->signIn($owner));
        $this->changeOwnPassword($this->signIn($owner));
        $this->hit($old, 'GET', route('club.account.show'), cookies: [$recaller => $cookie])->assertRedirect(route('login'));
        $none = null;
        $this->hit($none, 'GET', route('club.account.show'), cookies: [$recaller => $cookie])->assertRedirect(route('login'));
    }

    public function test_a_member_session_that_cannot_show_its_password_is_a_guest_on_the_site_and_signs_in_again_on_the_portal(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $site = route('site.home', ['club' => $club->slug]);
        $cart = route('site.shop.cart', ['club' => $club->slug]);
        $fine = $this->signInPortal($club, $member);
        $old = $this->withoutFingerprint($this->signInPortal($club, $member), 'member');
        $stale = $this->signInPortal($club, $member);
        $this->hit($fine, 'PUT', route('portal.profile.update', ['club' => $club->slug]), [
            'current_password' => 'old-pass-123', 'password' => 'new-pass-456', 'password_confirmation' => 'new-pass-456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->hit($fine, 'GET', $site)->assertOk()->assertInertia(fn ($page) => $page->where('member.id', $member->id));
        $this->hit($fine, 'GET', $cart)->assertOk()->assertInertia(fn ($page) => $page->where('prefill.phone', $member->phone));
        foreach (['no fingerprint' => $old, 'a password changed since' => $stale] as $case => $sid) {
            $this->hit($sid, 'GET', $site)->assertOk()->assertInertia(fn ($page) => $page->where('member', null));
            $this->hit($sid, 'GET', $cart)->assertOk()->assertInertia(fn ($page) => $page->where('prefill', null));
            [$where, $page] = $this->land($sid, route('portal.dashboard', ['club' => $club->slug]));
            $this->assertSame(route('portal.login', ['club' => $club->slug]), $where, $case);
            $page->assertOk();
        }
        $this->assertPortal($club, $fine, true);
    }

    public function test_the_sign_in_and_password_pages_open_at_once_for_an_old_session_and_sign_it_in_again(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $pages = [
            route('login'), route('password.request'), route('password.reset', ['token' => str_repeat('a', 64)]),
            route('site.home', ['club' => $club->slug]), route('portal.login', ['club' => $club->slug]),
        ];

        foreach ($pages as $page) {
            $old = $this->withoutFingerprint($this->signIn($owner));
            $this->hit($old, 'GET', $page)->assertOk();
            $memberOld = $this->withoutFingerprint($this->signInPortal($club, $member), 'member');
            $this->hit($memberOld, 'GET', $page)->assertOk();
        }

        // Signed in again on the same device, from the page it was shown.
        $old = $this->withoutFingerprint($this->signIn($owner));
        $this->hit($old, 'GET', route('login'))->assertOk();
        $this->freshSignInLimits();
        $this->hit($old, 'POST', route('login.store'), ['email' => $owner->email, 'password' => 'password'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertPanel($old, true, 'signed in again');
        $memberOld = $this->withoutFingerprint($this->signInPortal($club, $member), 'member');
        $this->hit($memberOld, 'GET', route('portal.login', ['club' => $club->slug]))->assertOk();
        $this->freshSignInLimits();
        $this->hit($memberOld, 'POST', route('portal.login.store', ['club' => $club->slug]), ['login' => $member->phone, 'password' => 'old-pass-123'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertPortal($club, $memberOld, true);
    }

    public function test_no_password_fingerprint_session_or_cookie_reaches_the_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        Log::info('probe');
        $this->assertCount(1, $logged, 'the logs are listened to');

        $club = $this->club();
        $owner = $this->owner($club);
        $staff = $this->staff($club, 'reception');
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $secrets = [$owner->password, Auth::guard('web')->hashPasswordForCookie($owner->password), $member->password];
        [$a, $cookie] = $this->rememberedSignIn($owner);
        $secrets[] = $owner->fresh()->getRememberToken();
        $b = $this->withoutFingerprint($this->signIn($owner));
        $s = $this->signIn($staff);
        $m = $this->withoutFingerprint($this->signInPortal($club, $member), 'member');
        $this->changeOwnPassword($a);
        $this->staffSetPassword($a, $staff);
        $this->hit($a, 'POST', route('club.members.portal-password', $member), ['password' => 'new-pass-456'])->assertRedirect();
        $this->assertPanel($b, false, 'old session');
        $this->assertPanel($s, false, 'staff');
        $this->assertPortal($club, $m, false);
        $none = null;
        $this->hit($none, 'GET', route('club.account.show'), cookies: [Auth::guard('web')->getRecallerName() => $cookie])->assertRedirect(route('login'));

        $owner->refresh();
        array_push($secrets, $a, $b, $s, $m, $cookie, self::NEW, 'new-pass-456', $owner->password, $owner->getRememberToken(),
            Auth::guard('web')->hashPasswordForCookie($owner->password), $staff->fresh()->password, $member->fresh()->password);
        $written = implode("\n", $logged).json_encode(DB::table('audit_logs')->get());
        $this->assertNotEmpty(DB::table('audit_logs')->count(), 'the sign-ins and changes were audited');
        foreach (array_filter($secrets) as $i => $secret) {
            $this->assertStringNotContainsString($secret, $written, "secret #$i was written to the logs");
        }
    }

    public function test_every_signed_in_route_checks_the_password_its_session_holds(): void
    {
        $aliases = app(Kernel::class)->getMiddlewareAliases();
        $this->assertSame(AuthenticateSession::class, $aliases['auth.session']);
        $this->assertSame(RedirectIfAuthenticated::class, $aliases['guest']);

        [$checked, $unchecked] = [0, []];
        foreach (app('router')->getRoutes() as $route) {
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $signedIn = collect($middleware)->contains(fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:'));
            // Signing out needs no proof: it ends the session either way.
            if ($signedIn && $route->getName() !== 'logout') {
                in_array('auth.session', $middleware, true) ? $checked++ : $unchecked[] = $route->uri();
            }
            // The website and the portal resolve their member in site.tenant, which applies the same check.
            if (str_starts_with($route->uri(), 'c/{club}') && ! in_array('site.tenant', $middleware, true)) {
                $unchecked[] = $route->uri();
            }
        }

        $this->assertSame([], $unchecked);
        $this->assertGreaterThan(300, $checked);
    }
}
