<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateSession;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\SignsInDevices;
use Tests\TestCase;

/**
 * #135: a new password ends every session signed in with the old one, on its next request (auth.session on the panel
 * and the portal), except the device that made the change itself. Someone else's change (a manager, a reset link)
 * ends every session of that account and none of the person who made it, nor of any other account or club.
 * Each device is a real session of its own (SignsInDevices).
 */
class PasswordChangeEndsOtherSessionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase, SignsInDevices;

    private const NEW = 'N3w-Pass!word-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
    }

    private function changeOwnPassword(string $sid, string $current = 'password'): void
    {
        $this->hit($sid, 'PUT', route('club.account.password'), ['current_password' => $current, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_each_sign_in_is_its_own_session_and_no_cookie_is_a_guest(): void
    {
        $owner = $this->owner($this->club());
        $this->assertNotSame($this->signIn($owner), $this->signIn($owner));
        $none = null;
        $this->hit($none, 'GET', route('club.account.show'))->assertRedirect(route('login'));
    }

    public function test_your_own_change_in_my_account_signs_out_your_other_devices_and_nobody_else(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $colleague = $this->staff($club, 'reception');
        $elsewhere = $this->owner($this->club('Other'));
        [$a, $b, $c, $d] = [$this->signIn($owner), $this->signIn($owner), $this->signIn($colleague), $this->signIn($elsewhere)];

        $this->changeOwnPassword($a);
        $this->assertTrue(Hash::check(self::NEW, $owner->fresh()->password));

        $this->assertPanel($a, true, 'the device that changed it stays');
        $this->assertPanel($b, false, 'the other device is signed out');
        $this->assertPanel($c, true, 'a colleague is not touched');
        $this->assertPanel($d, true, 'another club is not touched');
        $this->assertPanel($a, true, 'and stays on the next requests');
        // The other device signs in again with the new password only.
        $this->signIn($owner, self::NEW);
    }

    public function test_your_own_change_on_the_users_screen_keeps_this_device_and_signs_out_the_others(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$a, $b] = [$this->signIn($owner), $this->signIn($owner)];

        $this->hit($a, 'PUT', route('club.users.update', $owner), [
            'name' => $owner->name, 'email' => $owner->email, 'role_id' => $owner->role_id, 'is_active' => true, 'password' => self::NEW,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertPanel($a, true, 'this device');
        $this->assertPanel($b, false, 'the other device');
    }

    public function test_a_manager_setting_a_staff_password_signs_out_that_account_only(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $staff = $this->staff($club, 'reception');
        $colleague = $this->staff($club, 'reception');
        [$o, $s1, $s2, $c] = [$this->signIn($owner), $this->signIn($staff), $this->signIn($staff), $this->signIn($colleague)];

        $this->hit($o, 'PUT', route('club.users.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email, 'role_id' => $staff->role_id, 'is_active' => true, 'password' => self::NEW,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertPanel($o, true, 'the manager');
        $this->assertPanel($s1, false, 'the staff member, first device');
        $this->assertPanel($s2, false, 'the staff member, second device');
        $this->assertPanel($c, true, 'another staff member');
        $this->signIn($staff, self::NEW);
    }

    public function test_a_temporary_password_from_the_employee_screen_signs_out_that_account_only(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $staff = $this->staff($club, 'reception');
        $employee = $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '300.000', 'status' => 'active', 'payment_method' => 'cash', 'user_id' => $staff->id]));
        [$o, $s] = [$this->signIn($owner), $this->signIn($staff)];

        $this->hit($o, 'POST', route('club.employees.password', $employee))->assertSessionHas('password');

        $this->assertPanel($o, true, 'the manager');
        $this->assertPanel($s, false, 'the employee');
    }

    public function test_a_reset_by_email_link_signs_out_every_device(): void
    {
        $owner = $this->owner($this->club());
        [$a, $b] = [$this->signIn($owner), $this->signIn($owner)];
        $token = Password::broker('users')->createToken($owner);

        $none = null;
        $this->hit($none, 'POST', route('password.update'), ['token' => $token, 'email' => $owner->email, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertPanel($a, false, 'first device');
        $this->assertPanel($b, false, 'second device');
        $this->signIn($owner, self::NEW);
    }

    public function test_a_remembered_device_does_not_sign_back_in_with_its_old_cookie(): void
    {
        $owner = $this->owner($this->club());
        $a = $this->signIn($owner);
        $b = null;
        $login = $this->hit($b, 'POST', route('login.store'), ['email' => $owner->email, 'password' => 'password', 'remember' => true]);
        $recaller = Auth::guard('web')->getRecallerName();
        $cookie = $login->getCookie($recaller)?->getValue();
        $this->assertNotEmpty($cookie, 'the sign-in remembered the device');
        // Before the change the cookie alone signs the device back in (a new session, no session cookie sent).
        $fresh = null;
        $this->hit($fresh, 'GET', route('club.account.show'), cookies: [$recaller => $cookie])->assertOk();

        $this->changeOwnPassword($a);

        $fresh = null;
        $this->hit($fresh, 'GET', route('club.account.show'), cookies: [$recaller => $cookie])->assertRedirect(route('login'));
        $this->hit($b, 'GET', route('club.account.show'), cookies: [$recaller => $cookie])->assertRedirect(route('login'));
        $this->assertPanel($a, true, 'the device that changed it');
    }

    public function test_a_members_own_change_in_the_portal_signs_out_her_other_devices_only(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $other = $this->member($club, ['password' => 'old-pass-123']);
        [$a, $b, $o] = [$this->signInPortal($club, $member), $this->signInPortal($club, $member), $this->signInPortal($club, $other)];

        $this->hit($a, 'PUT', route('portal.profile.update', ['club' => $club->slug]), [
            'current_password' => 'old-pass-123', 'password' => 'new-pass-456', 'password_confirmation' => 'new-pass-456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertPortal($club, $a, true);
        $this->assertPortal($club, $b, false);
        $this->assertPortal($club, $o, true);
        $this->signInPortal($club, $member, 'new-pass-456');
    }

    public function test_staff_setting_a_members_portal_password_signs_out_that_member_only(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $other = $this->member($club, ['password' => 'old-pass-123']);
        [$m, $x, $o] = [$this->signInPortal($club, $member), $this->signInPortal($club, $other), $this->signIn($owner)];

        $this->hit($o, 'POST', route('club.members.portal-password', $member), ['password' => 'new-pass-456'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertPortal($club, $m, false);
        $this->assertPortal($club, $x, true);
        $this->assertPanel($o, true, 'the staff member who set it');
    }

    public function test_no_one_ends_sessions_of_another_club_or_without_the_permission(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $target = $this->staff($club, 'reception');
        $otherClub = $this->club('Other');
        $theirMember = $this->member($otherClub, ['password' => 'old-pass-123']);
        [$o, $r, $t, $m] = [$this->signIn($owner), $this->signIn($reception), $this->signIn($target), $this->signInPortal($otherClub, $theirMember)];

        // Another club's member is out of reach (not found), and her session stays.
        $this->hit($o, 'POST', route('club.members.portal-password', $theirMember), ['password' => 'new-pass-456'])->assertNotFound();
        $this->assertTrue(Hash::check('old-pass-123', $theirMember->fresh()->password));
        $this->assertPortal($otherClub, $m, true);

        // Without users.manage nobody sets a colleague's password, and the colleague stays signed in.
        $this->hit($r, 'PUT', route('club.users.update', $target), [
            'name' => $target->name, 'email' => $target->email, 'role_id' => $target->role_id, 'is_active' => true, 'password' => self::NEW,
        ])->assertForbidden();
        $this->assertTrue(Hash::check('password', $target->fresh()->password));
        $this->assertPanel($t, true, 'the colleague');
        $this->assertPanel($r, true, 'the one refused');
    }

    public function test_a_deactivated_account_is_still_signed_out_at_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $staff = $this->staff($club, 'reception');
        [$o, $s] = [$this->signIn($owner), $this->signIn($staff)];

        $this->hit($o, 'DELETE', route('club.users.destroy', $staff))->assertRedirect();

        $this->assertPanel($s, false, 'the deactivated account');
        $this->assertPanel($o, true, 'the manager');
    }

    /**
     * The deploy: a session opened before auth.session existed stored its password's fingerprint when it signed in, so it
     * keeps working, and one whose password changed since is signed out.
     */
    public function test_sessions_signed_in_before_this_change_stay_signed_in(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $staff = $this->staff($club, 'reception');
        $this->withoutMiddleware(AuthenticateSession::class);
        [$a, $b, $s] = [$this->signIn($owner), $this->signIn($owner), $this->signIn($staff)];
        $this->changeOwnPassword($a);
        $this->assertPanel($b, true, 'before the deploy the other device stayed in');
        $this->app->forgetInstance(AuthenticateSession::class);
        $this->withMiddleware(AuthenticateSession::class);

        $this->assertPanel($s, true, 'an account whose password did not change');
        $this->assertPanel($b, false, 'a session with a password changed since');
    }
}
