<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\SignsInDevices;
use Tests\TestCase;

/**
 * «تذكرني» on the sign-in page is Laravel's own remember-me: unticked, the sign-in lasts as long as its session; ticked,
 * Laravel's remember cookie (the account id, a random token and a password fingerprint — never the password or the
 * e-mail) signs a new session in. Signing out and changing the password both end it. Each request here is one device
 * as a fresh process sees it (SignsInDevices).
 */
class LoginRememberMeTest extends TestCase
{
    use BuildsClubs, RefreshDatabase, SignsInDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
    }

    private function recaller(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /** @return array{string, TestResponse} */
    private function signInWith(User $user, ?bool $remember): array
    {
        $this->freshSignInLimits();
        $sid = null;
        $data = ['email' => $user->email, 'password' => 'password'] + ($remember === null ? [] : ['remember' => $remember]);
        $response = $this->hit($sid, 'POST', route('login.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return [$sid, $response];
    }

    public function test_unticked_signs_in_for_this_session_only(): void
    {
        $owner = $this->owner($this->club());

        foreach ([false, null] as $remember) {
            [$sid, $response] = $this->signInWith($owner, $remember);
            $response->assertCookieMissing($this->recaller());
            $this->hit($sid, 'GET', route('club.dashboard'))->assertOk();
            $this->assertEmpty($owner->fresh()->getRememberToken(), 'no remember token is made');

            // Another browser (no session of its own) is not signed in.
            $none = null;
            $this->hit($none, 'GET', route('club.dashboard'))->assertRedirect(route('login'));
        }
    }

    public function test_ticked_sets_laravels_remember_cookie_that_signs_a_new_session_in_and_holds_no_password(): void
    {
        $owner = $this->owner($this->club());
        [$sid, $response] = $this->signInWith($owner, true);

        $cookie = (string) $response->getCookie($this->recaller())?->getValue();
        $this->assertNotSame('', $cookie);
        $this->assertNotEmpty($owner->fresh()->getRememberToken());
        $this->assertStringStartsWith($owner->id.'|'.$owner->fresh()->getRememberToken().'|', $cookie);
        $this->assertStringNotContainsString($owner->email, $cookie);
        $this->assertStringNotContainsString('password', $cookie);
        $this->hit($sid, 'GET', route('club.dashboard'))->assertOk();

        // The browser closed (its session gone): the remember cookie alone signs it in again.
        $fresh = null;
        $this->hit($fresh, 'GET', route('club.dashboard'), cookies: [$this->recaller() => $cookie])->assertOk();
    }

    public function test_signing_out_ends_the_remembered_session_too(): void
    {
        $owner = $this->owner($this->club());
        [$sid, $response] = $this->signInWith($owner, true);
        $cookie = (string) $response->getCookie($this->recaller())?->getValue();
        $token = $owner->fresh()->getRememberToken();

        $this->hit($sid, 'POST', route('logout'), cookies: [$this->recaller() => $cookie])
            ->assertRedirect(route('login'))
            ->assertCookieExpired($this->recaller());
        $this->assertNotSame($token, $owner->fresh()->getRememberToken(), 'the token is replaced, so a copied cookie is dead');

        // The same session, and a copy of the old cookie in another browser: neither is signed in.
        $this->hit($sid, 'GET', route('club.dashboard'))->assertRedirect(route('login'));
        $copy = null;
        $this->hit($copy, 'GET', route('club.dashboard'), cookies: [$this->recaller() => $cookie])->assertRedirect(route('login'));
    }

    public function test_changing_the_password_ends_the_remembered_sessions_elsewhere(): void
    {
        $owner = $this->owner($this->club());
        [, $response] = $this->signInWith($owner, true);
        $cookie = (string) $response->getCookie($this->recaller())?->getValue();

        $here = $this->signIn($owner);
        $this->hit($here, 'PUT', route('club.account.password'), [
            'current_password' => 'password', 'password' => 'N3w-Pass!word-2026', 'password_confirmation' => 'N3w-Pass!word-2026',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $elsewhere = null;
        $this->hit($elsewhere, 'GET', route('club.dashboard'), cookies: [$this->recaller() => $cookie])->assertRedirect(route('login'));
        $this->hit($here, 'GET', route('club.dashboard'))->assertOk(); // the session that changed it stays signed in
    }

    public function test_a_wrong_password_with_the_box_ticked_signs_nobody_in_and_sets_no_cookie(): void
    {
        $owner = $this->owner($this->club());
        $this->freshSignInLimits();
        $sid = null;

        $this->hit($sid, 'POST', route('login.store'), ['email' => $owner->email, 'password' => 'wrong', 'remember' => true])
            ->assertSessionHasErrors('email')
            ->assertCookieMissing($this->recaller());
        $this->assertEmpty($owner->fresh()->getRememberToken());
    }
}
