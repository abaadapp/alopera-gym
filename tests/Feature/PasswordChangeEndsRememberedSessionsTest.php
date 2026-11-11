<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Changing one's own password (staff «حسابي», the member portal's profile) ends the sessions remembered on other devices,
 * as the reset paths already do: a «remember me» cookie copied before the change no longer signs in. The session in use
 * stays signed in.
 */
class PasswordChangeEndsRememberedSessionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_staff_member_changing_her_password_ends_her_remembered_sessions(): void
    {
        $owner = $this->owner($this->club());
        $owner->forceFill(['remember_token' => 'stolen-remember-token-value'])->save();

        $this->actingAs($owner)->put(route('club.account.password'), [
            'current_password' => 'password', 'password' => 'N3w-Pass!word-2026', 'password_confirmation' => 'N3w-Pass!word-2026',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotSame('stolen-remember-token-value', $owner->fresh()->getRememberToken());
        $this->get(route('club.dashboard'))->assertOk(); // still signed in here
    }

    public function test_a_member_changing_her_portal_password_ends_her_remembered_sessions(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['password' => 'old-pass-123']);
        $this->asTenant($club, fn () => Member::whereKey($member->id)->update(['remember_token' => 'stolen-remember-token-value']));

        $this->actingAs($member->fresh(), 'member')->put(route('portal.profile.update', ['club' => $club->slug]), [
            'current_password' => 'old-pass-123', 'password' => 'new-pass-456', 'password_confirmation' => 'new-pass-456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotSame('stolen-remember-token-value', $this->asTenant($club, fn () => Member::findOrFail($member->id)->getRememberToken()));
    }
}
