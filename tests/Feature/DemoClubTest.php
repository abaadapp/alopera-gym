<?php

namespace Tests\Feature;

use App\Core\Tenancy\HomeGym;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The demo club (php artisan gym:demo): a club of its own beside the gym, full of made-up data, whose owner signs in to
 * the same panel and sees only it. Nothing else of the system opens to it, and it never touches the gym's rows.
 */
class DemoClubTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function build(array $options = []): int
    {
        return Artisan::call('gym:demo', $options + ['--email' => 'demo@demo-owner.om', '--password' => '12345678', '--members' => 20]);
    }

    public function test_the_demo_owner_signs_in_to_a_full_club_of_their_own_and_the_gym_is_untouched(): void
    {
        $gym = $this->club('Alopera');
        $this->member($gym);
        config(['gym.home_club' => $gym->slug, 'gym.demo_club' => 'demo']);
        $before = $this->asTenant($gym, fn () => [Member::count(), Invoice::count()]);

        $this->assertSame(0, $this->build(), Artisan::output());
        $this->assertStringNotContainsString('SKIPPED', Artisan::output());

        $demo = Tenant::where('slug', 'demo')->sole();
        $this->assertTrue((bool) $demo->setting('is_demo'));
        $this->assertTrue($gym->is(HomeGym::tenant()));
        $this->assertSame($before, $this->asTenant($gym, fn () => [Member::count(), Invoice::count()]));
        $this->assertSame(20, $this->asTenant($demo, fn () => Member::count()));
        $this->assertGreaterThan(20, $this->asTenant($demo, fn () => Invoice::count()));

        // Signs in to the panel, sees the demo club alone; its website, portal and scheduled jobs stay closed.
        $this->post(route('login'), ['email' => 'demo@demo-owner.om', 'password' => '12345678'])->assertRedirect();
        $this->assertAuthenticated('web');
        $this->get(route('club.dashboard'))->assertOk();
        $this->get(route('club.members.index'))->assertOk()->assertInertia(fn ($page) => $page->where('members.total', 20)->etc());
        $this->get(route('site.home', ['club' => 'demo']))->assertNotFound();
        Artisan::call('notifications:sync');
        $this->assertStringNotContainsString('[demo]', Artisan::output());

        // Only the demo owner can sign in: the other demo staff have random passwords.
        $this->assertSame(1, User::where('tenant_id', $demo->id)->get()->filter(fn (User $u) => Hash::check('12345678', $u->password))->count());

        // Rebuilt from scratch: the demo's rows replaced, the gym's still the same.
        $this->assertSame(1, $this->build(), 'refuses without --fresh');
        $this->assertSame(0, $this->build(['--fresh' => true]), Artisan::output());
        $this->assertStringNotContainsString('SKIPPED', Artisan::output());
        $this->assertSame(20, $this->asTenant($demo, fn () => Member::count()));
        $this->assertSame($before, $this->asTenant($gym, fn () => [Member::count(), Invoice::count()]));
    }

    public function test_it_never_takes_the_gym_or_an_unmarked_club_for_the_demo(): void
    {
        $gym = $this->club('Alopera');
        $other = $this->club('Old club');

        config(['gym.home_club' => $gym->slug, 'gym.demo_club' => $gym->slug]);
        $this->assertSame(1, $this->build());

        config(['gym.demo_club' => $other->slug]);
        $this->assertSame(1, $this->build(['--fresh' => true]));
        $this->assertSame(1, User::where('tenant_id', $other->id)->count());

        // An unmarked club named as the demo still has no panel.
        $this->post(route('login'), ['email' => $this->owner($other)->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }
}
