<?php

namespace Tests\Feature;

use App\Core\Auth\DemoAccounts;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AloperaDemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The published demo credentials must never open a production system. */
class ProductionSafetyTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
        // Laravel only skips CSRF checks when env is "testing"; that check is not what is under test here.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_reserved_demo_domains_are_recognised(): void
    {
        foreach (['owner@alopera.test', 'a@b.example', 'x@sub.example.com', 'X@EXAMPLE.ORG', 'u@host.localhost', 'u@y.invalid'] as $email) {
            $this->assertTrue(DemoAccounts::isReservedEmail($email), $email);
        }
        foreach (['owner@alopera.om', 'a@gmail.com', 'a@test.com', 'a@myexample.com', 'not-an-email', ''] as $email) {
            $this->assertFalse(DemoAccounts::isReservedEmail($email), $email);
        }
    }

    public function test_demo_staff_credentials_are_refused_in_production(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $owner->forceFill(['email' => 'owner@alopera.test'])->save();

        // Works locally…
        $this->post(route('login.store'), ['email' => 'owner@alopera.test', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);
        $this->post(route('logout'));

        // …but never in production, even with the right password.
        $this->asProduction();
        $this->post(route('login.store'), ['email' => 'owner@alopera.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.demo_blocked']);
    }

    public function test_demo_platform_admin_is_refused_in_production(): void
    {
        $admin = new User(['name' => 'Admin', 'email' => 'admin@alopera.test', 'password' => 'password']);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->asProduction();
        $this->post(route('login.store'), ['email' => 'admin@alopera.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_demo_member_cannot_sign_in_to_the_portal_in_production(): void
    {
        $club = $this->club();
        $this->member($club, ['email' => 'member@alopera.test', 'password' => 'password1']);

        $this->asProduction();
        $this->post(route('portal.login.store', ['club' => $club->slug]), ['login' => 'member@alopera.test', 'password' => 'password1'])
            ->assertSessionHasErrors('login');
        $this->assertGuest('member');
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->asProduction();
        $this->artisan('db:seed', ['--class' => AloperaDemoSeeder::class, '--force' => true]);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Tenant::count());
    }

    public function test_create_admin_command_enforces_production_rules(): void
    {
        $this->asProduction();

        $this->artisan('platform:create-admin', ['email' => 'admin@alopera.test'])->assertFailed();
        $this->artisan('platform:create-admin', ['email' => 'owner@alopera.om', '--password' => 'password'])->assertFailed();
        $this->assertSame(0, User::count());

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
        $this->artisan('platform:create-admin', ['email' => 'owner@alopera.om', '--password' => 'Str0ng-Passw0rd!'])->assertSuccessful();
        $this->assertTrue(User::where('email', 'owner@alopera.om')->value('is_platform_admin'));
    }

    public function test_reserved_emails_cannot_be_given_to_new_accounts_in_production(): void
    {
        $club = $this->club();
        $this->asProduction();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        $this->actingAs($this->owner($club))
            ->post(route('club.users.store'), ['name' => 'X', 'email' => 'x@alopera.test', 'role_id' => $this->owner($club)->role_id, 'password' => 'Str0ng-Passw0rd!'])
            ->assertSessionHasErrors('email');
    }

    public function test_preflight_blocks_a_release_with_usable_demo_accounts_and_can_deactivate_them(): void
    {
        $club = $this->club();
        $demo = $this->owner($club);
        $demo->forceFill(['email' => 'owner@alopera.test'])->save();
        $real = new User(['name' => 'Admin', 'email' => 'admin@platform.om', 'password' => 'Str0ng-Passw0rd!']);
        $real->forceFill(['is_platform_admin' => true])->save();
        config(['app.debug' => false, 'app.url' => 'https://app.platform.om', 'session.secure' => true, 'session.encrypt' => true, 'queue.default' => 'database']);

        // Locally the demo rows are expected and never touched.
        $this->artisan('platform:preflight', ['--deactivate-demo' => true])->assertFailed();
        $this->assertTrue($demo->fresh()->is_active);

        $this->app['env'] = 'production';
        $this->artisan('platform:preflight')->expectsOutputToContain('No active demo staff/admin accounts')->assertFailed();

        $this->artisan('platform:preflight', ['--deactivate-demo' => true])->expectsOutputToContain('Deactivated 1 demo account(s).')->assertSuccessful();
        $this->assertFalse($demo->fresh()->is_active);
        $this->assertTrue($real->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'platform.demo_deactivated']);
        $this->artisan('platform:preflight')->assertSuccessful();
    }
}
