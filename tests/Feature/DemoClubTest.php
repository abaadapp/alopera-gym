<?php

namespace Tests\Feature;

use App\Core\Tenancy\HomeGym;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

        // Nothing recorded before payment: no invoice left to pay, no membership waiting, no order waiting, no link.
        $this->asTenant($demo, function () {
            $this->assertSame(0, Invoice::whereIn('status', [...Invoice::OPEN_STATUSES, Invoice::DRAFT])->count());
            $this->assertSame(0, Membership::where('status', Membership::PENDING)->count());
            $this->assertSame(0, PurchaseOrder::where('status', '!=', PurchaseOrder::RECEIVED)->count());
            $this->assertSame(0, DB::table('payment_links')->where('tenant_id', app(TenantContext::class)->id())->count());
        });

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

    public function test_the_gyms_setup_is_copied_to_the_demo_and_kept_in_step_one_way(): void
    {
        $gym = $this->club('Alopera');
        config(['gym.home_club' => $gym->slug, 'gym.demo_club' => 'demo']);
        $gold = $this->plan($gym, ['name' => 'Gold', 'price' => 30]);
        $silver = $this->plan($gym, ['name' => 'Silver', 'price' => 20]);
        $spin = $this->program($gym, ['name' => 'Spin', 'slug' => 'spin']);
        $this->asTenant($gym, function () use ($gold, $spin) {
            $gold->programs()->sync([$spin->id]);
            Product::create(['name' => 'Water', 'price' => 0.3, 'sku' => 'W-1']);
            SiteSetting::query()->update(['hero_title' => 'Alopera — the real one']);
        });
        $gym->forceFill(['primary_color' => '#123456', 'settings' => array_replace($gym->settings ?? [], ['vat_rate' => 5])])->save();

        $this->assertSame(0, $this->build(), Artisan::output());
        $this->assertStringNotContainsString('SKIPPED', Artisan::output());
        $demo = Tenant::where('slug', 'demo')->sole();

        // The demo sells the gym's own plans and products, with members of its own.
        $this->asTenant($demo, function () {
            $this->assertEqualsCanonicalizing(['Gold', 'Silver'], MembershipPlan::pluck('name')->all());
            $this->assertSame('30.000', MembershipPlan::where('name', 'Gold')->sole()->price);
            $this->assertSame(['spin'], MembershipPlan::where('name', 'Gold')->sole()->programs()->pluck('slug')->all());
            $this->assertSame(['Water'], Product::pluck('name')->all());
            $this->assertSame('Alopera — the real one', SiteSetting::sole()->hero_title);
            $this->assertSame(20, Member::count());
        });
        $this->assertSame('#123456', $demo->fresh()->primary_color);
        $this->assertSame(5, (int) $demo->fresh()->setting('vat_rate'));
        $this->assertTrue((bool) $demo->fresh()->setting('is_demo'));

        // The owner edits the gym: a price, the website, a new plan, a program removed. The next run copies it all.
        $this->asTenant($gym, function () use ($gold, $spin) {
            $gold->update(['price' => 35]);
            MembershipPlan::create(['name' => 'Yearly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 200]);
            SiteSetting::query()->update(['hero_title' => 'New title']);
            $gold->programs()->detach();
            $spin->delete();
        });
        $this->assertSame(0, Artisan::call('gym:demo-sync'), Artisan::output());
        $this->asTenant($demo, function () {
            $this->assertSame('35.000', MembershipPlan::where('name', 'Gold')->sole()->price);
            $this->assertTrue(MembershipPlan::where('name', 'Yearly')->exists());
            $this->assertSame('New title', SiteSetting::sole()->hero_title);
            $this->assertSame([], MembershipPlan::where('name', 'Gold')->sole()->programs()->pluck('slug')->all());
            $this->assertFalse(Program::where('slug', 'spin')->exists());
            $this->assertSame(20, Member::count(), 'the demo\'s own records stay');
        });
        $this->assertSame(0, Artisan::call('gym:demo-sync'));
        $this->assertStringContainsString('in step', Artisan::output());

        // One way: a change in the demo never reaches the gym.
        $this->asTenant($demo, fn () => MembershipPlan::where('name', 'Silver')->update(['price' => 1]));
        $this->assertSame('20.000', $this->asTenant($gym, fn () => $silver->fresh()->price));
        Artisan::call('gym:demo-sync');
        $this->assertSame('20.000', $this->asTenant($demo, fn () => MembershipPlan::where('name', 'Silver')->sole()->price), 'the gym\'s price comes back');
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
