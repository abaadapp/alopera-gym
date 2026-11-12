<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\Concerns\SignsInDevices;
use Tests\TestCase;

/**
 * #135 at the same moment, in separate processes on PostgreSQL, each device a real session: a sign-in with the old
 * password while the change is being saved, and a request already running when the password changes. Whatever the
 * timing, no session signed in with the old password outlives the change: its next request after the change is saved
 * signs in again, while the device that made the change stays. Nothing waits on anything else, so nothing deadlocks.
 */
class PasswordChangeConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently, SignsInDevices;

    private const NEW = 'N3w-Pass!word-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
        $this->useDatabaseSessions();
    }

    public function test_a_sign_in_with_the_old_password_while_the_change_is_being_saved_ends_at_its_next_request(): void
    {
        $club = $this->club();
        $staff = $this->staff($club, 'reception');

        $results = $this->concurrently([
            // The new password and remember token (what every change path writes), held one second before they are saved.
            function (string $dir) use ($staff) {
                DB::transaction(function () use ($staff, $dir) {
                    User::whereKey($staff->id)->update(['password' => Hash::make(self::NEW), 'remember_token' => Str::random(60)]);
                    file_put_contents("$dir/changing", '1');
                    usleep(1_000_000);
                });
                file_put_contents("$dir/saved", '1');

                return 'saved';
            },
            // A sign-in with the old password meanwhile: it reads the password still saved, so it goes through.
            function (string $dir) use ($staff) {
                self::waitFor("$dir/changing");
                $this->freshSignInLimits();
                $sid = null;
                $this->hit($sid, 'POST', route('login.store'), ['email' => $staff->email, 'password' => 'password']);
                $signedIn = ! $this->app['session']->driver()->has('errors');
                self::waitFor("$dir/saved");
                $next = $this->hit($sid, 'GET', route('club.account.show'));

                return [$signedIn, $next->getStatusCode(), $next->headers->get('Location')];
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        [$signedIn, $status, $location] = $results[1]['value'];
        $this->assertTrue($signedIn, 'the sign-in read the password before the change was saved');
        $this->assertSame([302, route('login')], [$status, $location], 'its next request after the change signs in again');
        $this->assertTrue(Hash::check(self::NEW, $staff->fresh()->password));
    }

    public function test_a_request_already_running_when_the_password_changes_is_that_sessions_last(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$a, $b] = [$this->signIn($owner), $this->signIn($owner)];

        $results = $this->concurrently([
            // Device B: a request that has read the account, slowed down while the change lands.
            function (string $dir) use ($b) {
                self::pauseAfter('select * from "users"', $dir, 1.0);
                $first = $this->hit($b, 'GET', route('club.account.show'))->getStatusCode();
                self::waitFor("$dir/changed");
                $next = $this->hit($b, 'GET', route('club.account.show'));

                return [$first, $next->getStatusCode(), $next->headers->get('Location')];
            },
            // Device A changes the password meanwhile, and stays signed in.
            function (string $dir) use ($a) {
                self::waitFor("$dir/inside");
                $change = $this->hit($a, 'PUT', route('club.account.password'), ['current_password' => 'password', 'password' => self::NEW, 'password_confirmation' => self::NEW]);
                $refused = $this->app['session']->driver()->has('errors');
                file_put_contents("$dir/changed", '1');

                return [$change->getStatusCode(), $refused, $this->hit($a, 'GET', route('club.account.show'))->getStatusCode()];
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame([302, false, 200], $results[1]['value'], 'the change went through and its device stays');
        [$first, $status, $location] = $results[0]['value'];
        $this->assertSame(200, $first, 'the request that started before the change finishes');
        $this->assertSame([302, route('login')], [$status, $location], 'and is that session\'s last');
        $this->assertTrue(Hash::check(self::NEW, $owner->fresh()->password));
    }
}
