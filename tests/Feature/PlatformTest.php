<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\PlatformInvoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Platform\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** SaaS: super admin manages clubs and their (separate) platform subscriptions. */
class PlatformTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The platform owner's screens and the marketing page are off by default (clubs are managed with
        // php artisan clubs); these tests cover them for when they are switched back on.
        config(['gym.modules.platform_panel' => true, 'gym.modules.marketing_site' => true]);
    }

    public function test_super_admin_creates_a_ready_to_use_club(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $plan = $this->platformPlan();

        $this->actingAs($admin)->post(route('platform.tenants.store'), [
            'name' => 'Lotus Ladies Gym', 'slug' => 'lotus', 'owner_name' => 'Lotus Owner', 'owner_email' => 'owner@lotus.test',
            'owner_password' => 'Sup3r-Secret-99', 'platform_plan_id' => $plan->id, 'billing_cycle' => 'monthly',
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'lotus')->firstOrFail();
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->status);
        $this->asTenant($tenant, function () {
            $this->assertSame(['finance', 'manager', 'owner', 'reception', 'trainer'], Role::orderBy('key')->pluck('key')->all());
            $this->assertGreaterThan(5, MessageTemplate::count());
        });
        $owner = User::where('email', 'owner@lotus.test')->firstOrFail();
        $this->assertTrue($owner->isOwner());

        $this->actingAs($owner)->get(route('club.dashboard'))->assertOk();
    }

    public function test_suspended_club_sees_only_the_suspension_and_billing_pages(): void
    {
        $club = $this->club();
        app(SubscriptionService::class)->suspend($club, 'unpaid');

        $this->actingAs($this->owner($club))->get(route('club.members.index'))->assertStatus(402);
        $this->actingAs($this->owner($club))->get(route('club.billing'))->assertOk();
        $this->get(route('site.home', ['club' => $club->slug]))->assertNotFound();
    }

    public function test_paying_the_platform_invoice_activates_the_club(): void
    {
        $club = $this->club();
        $plan = $this->platformPlan(['price_yearly' => 400]);
        $subscription = app(SubscriptionService::class)->subscribe($club, $plan, 'yearly');
        $invoice = PlatformInvoice::where('tenant_subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('400.000', $invoice->amount);

        $this->actingAs(User::factory()->platformAdmin()->create())
            ->post(route('platform.invoices.pay', $invoice->id), ['method' => 'bank_transfer', 'reference' => 'TRX'])->assertSessionHas('success');

        $this->assertSame(Tenant::STATUS_ACTIVE, $club->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_unpaid_platform_invoices_suspend_the_club_after_grace(): void
    {
        $club = $this->club();
        app(SubscriptionService::class)->subscribe($club, $this->platformPlan(), 'monthly');

        $this->travel(20)->days();
        $result = app(SubscriptionService::class)->runDaily();

        $this->assertSame(1, $result['suspended']);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $club->fresh()->status);
    }

    public function test_platform_plan_member_limit_is_enforced(): void
    {
        $club = $this->club('Small', $this->platformPlan(['max_members' => 1]));
        $this->member($club);

        $this->expectException(BusinessRuleException::class);
        $this->asTenant($club, fn () => app(MemberService::class)->create(['full_name' => 'Two', 'phone' => '96899990000']));
    }

    public function test_gym_memberships_and_platform_subscription_are_separate_ledgers(): void
    {
        $club = $this->club();
        $this->activeMembership($club, $this->member($club));

        $this->assertSame(0, PlatformInvoice::count());
        $this->assertSame(1, $this->asTenant($club, fn () => Invoice::count()));
    }

    public function test_clubs_appear_on_the_platform_landing_page_only_after_opting_in(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $club = $this->club('Lotus Ladies Gym');
        $listed = fn () => collect($this->get(route('landing'))->viewData('page')['props']['clubs'])->pluck('slug');

        $this->assertNotContains($club->slug, $listed());

        $this->actingAs($admin)->put(route('platform.tenants.update', $club), ['name' => $club->name, 'listed_on_landing' => true])->assertSessionHasNoErrors();
        $this->assertContains($club->slug, $listed());

        $club->refresh()->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->assertNotContains($club->slug, $listed());
    }
}
