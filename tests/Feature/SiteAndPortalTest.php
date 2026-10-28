<?php

namespace Tests\Feature;

use App\Models\TenantIntegration;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Website\Models\ContactMessage;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 4: website pages read live data, online sign-up, member portal, SEO. */
class SiteAndPortalTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_public_pages_render_with_server_side_seo_and_live_plans(): void
    {
        $club = $this->club('Alopera');
        $this->plan($club, ['name' => 'الباقة الذهبية', 'price' => 99]);
        $this->plan($club, ['name' => 'باقة مخفية', 'is_public' => false]);

        foreach (['site.home', 'site.plans', 'site.programs', 'site.trainers', 'site.schedule', 'site.news', 'site.contact', 'site.join'] as $name) {
            $this->get(route($name, ['club' => $club->slug]))->assertOk();
        }

        $html = $this->get(route('site.plans', ['club' => $club->slug]))->assertOk()->getContent();
        $this->assertStringContainsString('<title inertia>الباقات والأسعار | '.e($club->name).'</title>', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringContainsString('application/ld+json', $html);

        $this->get(route('site.plans', ['club' => $club->slug]))
            ->assertInertia(fn ($page) => $page->component('Site/Plans')->has('plans', 1)->where('plans.0.name', 'الباقة الذهبية'));

        $this->get(route('site.sitemap', ['club' => $club->slug]))->assertOk()->assertHeader('Content-Type', 'application/xml');
        $this->get('/c/no-such-club')->assertNotFound();
    }

    public function test_online_signup_creates_the_member_and_a_pending_membership_visible_to_staff(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);

        $this->post(route('site.join.store', ['club' => $club->slug]), [
            'membership_plan_id' => $plan->id, 'full_name' => 'نورة الكندية', 'phone' => '9123 4567',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'accept_terms' => true, 'pay_now' => false,
        ])->assertRedirect(route('portal.dashboard', ['club' => $club->slug]));

        $this->asTenant($club, function () {
            $member = Member::where('phone', '96891234567')->firstOrFail();
            $this->assertSame('website', $member->source);
            $this->assertFalse($member->marketing_opt_in, 'Marketing consent is opt-in only.');
            $this->assertSame(Membership::PENDING, $member->memberships()->first()->status);
        });
        $this->assertAuthenticated('member');

        $this->actingAs($this->owner($club))->get(route('club.members.index', ['membership' => 'pending']))
            ->assertInertia(fn ($page) => $page->where('members.total', 1));
    }

    public function test_signup_rejects_honeypot_and_duplicate_phone(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $this->member($club, ['phone' => '96891234567']);
        $data = ['membership_plan_id' => $plan->id, 'full_name' => 'X', 'phone' => '91234567', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'accept_terms' => true];

        $this->post(route('site.join.store', ['club' => $club->slug]), $data)->assertSessionHasErrors('phone');
        $this->post(route('site.join.store', ['club' => $club->slug]), ['website' => 'spam', 'phone' => '92222222'] + $data)->assertSessionHasErrors('website');
    }

    public function test_portal_shows_membership_and_allows_renewal_invoice(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['password' => 'secret123']);
        $plan = $this->plan($club);
        $this->activeMembership($club, $member, $plan);

        $this->actingAs($member, 'member');
        $this->get(route('portal.dashboard', ['club' => $club->slug]))
            ->assertInertia(fn ($page) => $page->component('Portal/Dashboard')->where('current.status', 'active')->has('profile.qr_token'));

        $this->post(route('portal.renew.store', ['club' => $club->slug]), ['membership_plan_id' => $plan->id])->assertRedirect();
        $this->assertSame(1, $this->asTenant($club, fn () => Membership::where('member_id', $member->id)->where('status', 'pending')->count()));
        $this->post(route('portal.renew.store', ['club' => $club->slug]), ['membership_plan_id' => $plan->id])->assertSessionHasErrors('membership_plan_id');
    }

    public function test_contact_form_is_stored_for_the_club(): void
    {
        $club = $this->club();
        $this->post(route('site.contact.send', ['club' => $club->slug]), ['name' => 'Sara', 'phone' => '96890000000', 'message' => 'Hello'])->assertSessionHas('success');
        $this->assertSame(1, $this->asTenant($club, fn () => ContactMessage::count()));
    }

    public function test_club_custom_domain_serves_its_site_at_the_root(): void
    {
        $club = $this->club();
        $club->update(['domain' => 'alopera.om']);
        $this->plan($club, ['name' => 'Domain Plan']);

        $this->get('http://alopera.om/plans')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/Plans')->where('plans.0.name', 'Domain Plan'));
        $this->get('http://alopera.om/')->assertOk()->assertInertia(fn ($page) => $page->component('Site/Home'));
    }

    public function test_home_page_promises_online_payment_only_when_the_club_can_take_it(): void
    {
        $club = $this->club();
        $titles = fn () => collect($this->get(route('site.home', ['club' => $club->slug]))->viewData('page')['props']['features'])->pluck('title');

        $this->assertNotContains('دفع إلكتروني آمن', $titles());
        $this->assertContains('اشتراك سهل', $titles());

        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'h', 'integration_ids' => '111'],
        ]));
        $this->assertContains('دفع إلكتروني آمن', $titles());
    }

    public function test_club_edits_the_badge_and_why_us_cards(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->post(route('club.website.update'), [
            'hero_badge' => 'نادٍ عائلي',
            'features' => [['title' => 'مسبح داخلي', 'text' => 'مسبح مدفأ طوال العام.']],
        ])->assertSessionHasNoErrors();

        $this->get(route('site.home', ['club' => $club->slug]))->assertInertia(fn ($page) => $page
            ->where('hero.badge', 'نادٍ عائلي')
            ->has('features', 1)->where('features.0.title', 'مسبح داخلي'));

        // Clearing both hides them on the site.
        $this->actingAs($this->owner($club))->post(route('club.website.update'), ['hero_badge' => '', 'features' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->asTenant($club, fn () => SiteSetting::first()->features));
        $this->get(route('site.home', ['club' => $club->slug]))->assertInertia(fn ($page) => $page->where('hero.badge', null)->has('features', 0));
    }
}
