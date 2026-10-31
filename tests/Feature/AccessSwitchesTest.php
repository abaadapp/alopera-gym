<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** No hosting-platform screens: no platform panel, no marketing page, one gym set up from the console; the website by grant. */
class AccessSwitchesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_there_are_no_platform_screens_and_a_platform_account_cannot_sign_in(): void
    {
        $admin = User::factory()->platformAdmin()->create(['password' => 'Str0ng-Passw0rd!']);

        foreach (['/platform', '/platform/tenants', '/platform/plans', '/platform/invoices'] as $address) {
            $this->actingAs($admin)->get($address)->assertNotFound();
        }
        auth()->logout();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'Str0ng-Passw0rd!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_bare_address_opens_the_gyms_website_or_the_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $gym = $this->club('Alopera');
        $this->get('/')->assertRedirect(route('site.home', ['club' => $gym->slug]));

        // With more than one record, the configured gym; without one, the sign-in.
        $this->club('Other');
        $this->get('/')->assertRedirect(route('login'));
        config(['gym.home_club' => $gym->slug]);
        $this->get('/')->assertRedirect(route('site.home', ['club' => $gym->slug]));
    }

    public function test_the_gym_is_installed_once_from_the_console(): void
    {
        $this->assertSame(0, Artisan::call('gym:install', ['--name' => 'Nadi Fit', '--slug' => 'nadi-fit', '--owner-name' => 'Sara', '--owner-email' => 'sara@nadi.om', '--owner-password' => 'Str0ng-Passw0rd!']));
        $gym = Tenant::where('slug', 'nadi-fit')->firstOrFail();
        $this->assertTrue(User::where('email', 'sara@nadi.om')->where('tenant_id', $gym->id)->exists());
        $this->assertSame(Tenant::STATUS_ACTIVE, $gym->status);
        $this->assertSame(['cashier', 'finance', 'manager', 'owner', 'reception', 'trainer'], $this->asTenant($gym, fn () => Role::orderBy('key')->pluck('key')->all()));

        $this->assertSame(1, Artisan::call('gym:install', ['--name' => 'Second', '--owner-name' => 'X', '--owner-email' => 'x@nadi.om', '--owner-password' => 'Str0ng-Passw0rd!']));
        $this->assertSame(1, Tenant::count());
    }

    public function test_the_website_is_the_owners_or_whoever_the_owner_grants_it_to(): void
    {
        $club = $this->club();
        $manager = $this->staff($club, 'manager');
        $custom = $this->asTenant($club, fn () => Role::create(['key' => 'web', 'name' => 'Web', 'permissions' => ['members.view']]));
        $webUser = $this->staff($club, 'reception');
        $webUser->forceFill(['role_id' => $custom->id])->save();

        $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk();
        // No role holds it by default, the manager included.
        $this->actingAs($manager)->get(route('club.website.edit'))->assertForbidden();
        $this->actingAs($webUser->fresh())->get(route('club.website.edit'))->assertForbidden();

        // The role editor offers it, and the owner grants it explicitly.
        $this->actingAs($this->owner($club))->get(route('club.roles.index'))->assertInertia(fn ($page) => $page
            ->where('groups', fn ($groups) => collect($groups)->pluck('permissions')->flatten(1)->pluck('key')->contains('website.manage')));
        $this->actingAs($this->owner($club))->put(route('club.roles.update', $custom), ['name' => 'Web', 'permissions' => ['website.manage', 'members.view']])->assertSessionHasNoErrors();
        $this->actingAs($webUser->fresh())->get(route('club.website.edit'))->assertOk();
        $this->actingAs($webUser->fresh())->get(route('club.website.posts.index'))->assertOk();
        // The manager cannot hand it on: nobody gives what they do not hold.
        $this->actingAs($manager)->put(route('club.roles.update', $custom), ['name' => 'Web', 'permissions' => ['members.view']]);
        $this->assertContains('website.manage', $custom->fresh()->expandedPermissions());
    }
}
