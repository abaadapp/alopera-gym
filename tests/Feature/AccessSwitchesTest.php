<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** What is switched off (platform screens, marketing page), the console instead, and the owner-only website. */
class AccessSwitchesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_the_platform_screens_are_off_and_a_platform_admin_cannot_sign_in(): void
    {
        $admin = User::factory()->platformAdmin()->create(['password' => 'Str0ng-Passw0rd!']);

        $this->actingAs($admin)->get('/platform')->assertNotFound();
        auth()->logout();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'Str0ng-Passw0rd!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_marketing_page_is_off_and_opens_the_staff_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        config(['gym.modules.marketing_site' => true]);
        $this->get('/')->assertOk();
    }

    public function test_clubs_are_managed_from_the_console(): void
    {
        $this->assertSame(0, Artisan::call('clubs', ['action' => 'create', '--name' => 'Nadi Fit', '--slug' => 'nadi-fit', '--owner-name' => 'Sara', '--owner-email' => 'sara@nadi.om', '--owner-password' => 'Str0ng-Passw0rd!']));
        $club = Tenant::where('slug', 'nadi-fit')->firstOrFail();
        $this->assertTrue(User::where('email', 'sara@nadi.om')->where('tenant_id', $club->id)->exists());

        Artisan::call('clubs', ['action' => 'suspend', 'slug' => 'nadi-fit']);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $club->fresh()->status);
        Artisan::call('clubs', ['action' => 'activate', 'slug' => 'nadi-fit']);
        $this->assertSame(Tenant::STATUS_ACTIVE, $club->fresh()->status);

        Artisan::call('clubs', ['action' => 'list']);
        $this->assertStringContainsString('nadi-fit', Artisan::output());
    }

    public function test_only_the_owner_manages_the_club_website_whatever_a_role_holds(): void
    {
        $club = $this->club();
        $manager = $this->staff($club, 'manager');
        $custom = $this->asTenant($club, fn () => Role::create(['key' => 'web', 'name' => 'Web', 'permissions' => ['website.manage', 'members.view']]));
        $webUser = $this->staff($club, 'reception');
        $webUser->forceFill(['role_id' => $custom->id])->save();

        $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk();
        $this->actingAs($manager)->get(route('club.website.edit'))->assertForbidden();
        $this->actingAs($webUser->fresh())->get(route('club.website.edit'))->assertForbidden();
        $this->actingAs($webUser->fresh())->get(route('club.website.posts.index'))->assertForbidden();

        // The role editor neither offers nor stores it.
        $this->actingAs($this->owner($club))->get(route('club.roles.index'))->assertInertia(fn ($page) => $page
            ->where('groups', fn ($groups) => collect($groups)->pluck('permissions')->flatten(1)->pluck('key')->doesntContain('website.manage')));
        $this->actingAs($this->owner($club))->put(route('club.roles.update', $custom), ['name' => 'Web', 'permissions' => ['website.manage', 'members.view']]);
        $this->assertNotContains('website.manage', $custom->fresh()->expandedPermissions());
    }
}
