<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A password set for a staff account from «المستخدمون» ends that account's «remember me» logins, as a password changed
 * from «حسابي» does (#117): whoever kept the old remember cookie of a colleague who left, or of a stolen phone, is not
 * let back in by it. Saving the account without a new password changes nothing.
 */
class UserPasswordSetByManagerTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function form($user, array $extra = []): array
    {
        return $extra + ['name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id, 'branch_id' => $user->branch_id, 'is_active' => true];
    }

    private function signedInByRememberCookie($user, string $token, string $passwordHash): bool
    {
        // A fresh device: no session, only the remember cookie.
        Auth::forgetGuards();
        $this->app['session']->driver()->flush();
        $recaller = Auth::guard('web')->getRecallerName();
        $response = $this->withCookie($recaller, $user->id.'|'.$token.'|'.$passwordHash)->get(route('club.dashboard'));
        Auth::forgetGuards();
        $this->app['session']->driver()->flush();

        return $response->isOk();
    }

    public function test_a_password_set_by_the_owner_ends_the_accounts_remember_me_logins(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $manager->forceFill(['remember_token' => 'old-device-token'])->save();
        $oldHash = $manager->fresh()->getAuthPassword();
        $this->assertTrue($this->signedInByRememberCookie($manager, 'old-device-token', $oldHash), 'precondition: the old cookie signs in');

        $this->actingAs($owner)->put(route('club.users.update', $manager), $this->form($manager, ['password' => 'New-Pass-2026!', 'password_confirmation' => 'New-Pass-2026!']))
            ->assertSessionHasNoErrors();

        $this->assertNotSame('old-device-token', $manager->fresh()->remember_token);
        $this->assertFalse($this->signedInByRememberCookie($manager, 'old-device-token', $oldHash), 'the old remember cookie still signs in after the password was set');
    }

    public function test_saving_the_account_without_a_new_password_keeps_its_logins(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $manager->forceFill(['remember_token' => 'kept-token'])->save();

        $this->actingAs($owner)->put(route('club.users.update', $manager), $this->form($manager, ['name' => 'مديرة جديدة']))->assertSessionHasNoErrors();

        $this->assertSame(['kept-token', 'مديرة جديدة'], [$manager->fresh()->remember_token, $manager->fresh()->name]);
    }
}
