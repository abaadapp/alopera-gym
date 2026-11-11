<?php

namespace Tests\Feature;

use App\Core\Tenancy\SiteDomains;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Website\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The website on the club's own domain (aloperagym.om) and the panel on APP_URL (app.aloperagym.om): each shows only
 * its own pages, old /c/{club} links and wrong-address GETs move (301) to the right place, posted forms are never
 * redirected, links name their own address wherever they are made, and webhooks and files answer on both.
 */
class SiteDomainSplitTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const SITE = 'https://aloperagym.om';

    private const ADMIN = 'https://app.aloperagym.om';

    private function split(): Tenant
    {
        $club = $this->club();
        $club->update(['domain' => 'aloperagym.om']);
        config(['app.url' => self::ADMIN, 'gym.home_club' => $club->slug]);
        app(SiteDomains::class)->flush();

        return $club->fresh();
    }

    public function test_the_website_opens_on_the_club_domain_with_clean_addresses(): void
    {
        $club = $this->split();
        $this->plan($club, ['name' => 'خطة الموقع']);

        $page = $this->get(self::SITE.'/plans')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Site/Plans')->where('plans.0.name', 'خطة الموقع')->where('tenant.site_url', self::SITE));
        $this->assertSame('/plans', $page->viewData('page')['url'], 'the browser keeps the clean address');
        // The links the page builds (Ziggy): the website on its domain, nothing with the club in the path.
        $page->assertSee('"domain":"aloperagym.om"', false)->assertSee('"domain":"app.aloperagym.om"', false)->assertDontSee('{club}', false);

        $this->get(self::SITE.'/')->assertOk()->assertInertia(fn ($p) => $p->component('Site/Home'));
        $this->get(self::SITE.'/join')->assertOk()->assertInertia(fn ($p) => $p->component('Site/Join'));
    }

    public function test_old_club_links_on_the_domain_move_to_the_clean_address_and_a_posted_form_is_still_served(): void
    {
        $club = $this->split();

        $this->get(self::SITE.'/c/'.$club->slug.'/plans?from=old')->assertStatus(301)->assertRedirect(self::SITE.'/plans?from=old');
        $this->get(self::SITE.'/c/'.$club->slug)->assertStatus(301)->assertRedirect(self::SITE.'/');
        $this->get('https://www.aloperagym.om/plans')->assertStatus(301)->assertRedirect(self::SITE.'/plans');

        // A form opened before the change still posts to the old address: served, not redirected (its body is kept).
        $this->post(self::SITE.'/c/'.$club->slug.'/contact', ['name' => 'Sara', 'phone' => '96890000000', 'message' => 'Hello'])
            ->assertSessionHas('success');
        $this->assertSame(1, $this->asTenant($club, fn () => ContactMessage::count()));
    }

    public function test_a_domain_typed_with_www_or_https_serves_the_website_without_a_redirect_loop(): void
    {
        $club = $this->split();
        $club->update(['domain' => 'https://www.AloperaGym.om/']);

        // Unknown to the host lookup, / would go to the website's home, i.e. back to itself.
        $this->get(self::SITE.'/')->assertOk()->assertInertia(fn ($p) => $p->component('Site/Home'));
        $this->get(self::SITE.'/plans')->assertOk();
        // www with an old address: one move, straight to the clean one.
        $this->get('https://www.aloperagym.om/c/'.$club->slug.'/plans?x=1')->assertStatus(301)->assertRedirect(self::SITE.'/plans?x=1');
    }

    public function test_form_errors_survive_the_move_from_the_served_address_to_the_clean_one(): void
    {
        $this->split();

        $this->get(self::SITE.'/contact')->assertOk();
        // Laravel sends a refused form back to the address it served (/c/{club}/contact); that moves on to /contact.
        $back = $this->post(self::SITE.'/contact', ['name' => '', 'phone' => '', 'message' => ''])->assertRedirect()->headers->get('Location');
        if ($back !== self::SITE.'/contact') {
            $this->get($back)->assertStatus(301)->assertRedirect(self::SITE.'/contact');
        }
        $this->get(self::SITE.'/contact')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Site/Contact')->has('errors.name')->where('errors.name', fn ($m) => $m !== ''));
    }

    public function test_the_panel_and_staff_sign_in_are_not_on_the_club_domain(): void
    {
        $this->split();

        $this->get(self::SITE.'/app/members?page=2')->assertStatus(301)->assertRedirect(self::ADMIN.'/app/members?page=2');
        $this->get(self::SITE.'/login')->assertStatus(301)->assertRedirect(self::ADMIN.'/login');
        // A posted sign-in is refused, not redirected: a redirect would drop the password and the form token.
        $this->post(self::SITE.'/login', ['email' => 'x@y.z', 'password' => 'secret'])->assertNotFound();
        $this->post(self::SITE.'/logout')->assertNotFound();
    }

    public function test_the_admin_address_shows_no_website_and_signs_staff_in(): void
    {
        $club = $this->split();
        $owner = $this->owner($club);

        $this->get(self::ADMIN.'/')->assertRedirect(self::ADMIN.'/login');
        $this->get(self::ADMIN.'/c/'.$club->slug.'/plans')->assertStatus(301)->assertRedirect(self::SITE.'/plans');
        $this->get(self::ADMIN.'/c/'.$club->slug)->assertStatus(301)->assertRedirect(self::SITE.'/');

        $this->get(self::ADMIN.'/login')->assertOk();
        $this->post(self::ADMIN.'/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);
        $this->get(self::ADMIN.'/app')->assertOk();
        // Signed in, the address on its own goes on to the dashboard, never to the website.
        $this->get(self::ADMIN.'/')->assertRedirect(self::ADMIN.'/login');
    }

    public function test_links_name_their_own_address_wherever_they_are_made(): void
    {
        $club = $this->split();

        // As a queued job or an e-mail makes them: no request host to lean on.
        $this->assertSame(self::SITE.'/portal/login', route('portal.login', ['club' => $club->slug]));
        $this->assertSame(self::SITE, route('site.home', ['club' => $club->slug]));
        $this->assertSame(self::SITE.'/pay/return/REF-1', route('site.pay.return', ['club' => $club->slug, 'reference' => 'REF-1']));
        $this->assertSame(self::SITE.'/invoice/'.str_repeat('a', 48), route('site.invoice', ['club' => $club->slug, 'token' => str_repeat('a', 48)]));
        $this->assertSame(self::ADMIN.'/app', route('club.dashboard'));
        $this->assertSame(self::ADMIN.'/webhooks/paymob', route('webhooks.paymob'));
        $this->assertSame(self::ADMIN.'/login', route('login'));

        // The same from a page on the club's domain: panel links still go to the admin address.
        $this->get(self::SITE.'/plans')->assertOk();
        $this->assertSame(self::ADMIN.'/app', route('club.dashboard'));
        $this->assertSame(self::SITE.'/plans', route('site.plans'));

        $this->plan($club); // the plans page has something to show, so the sitemap lists it
        $sitemap = $this->get(self::SITE.'/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.self::SITE.'/plans</loc>', $sitemap);
        $this->assertStringNotContainsString('/c/', $sitemap);
    }

    public function test_members_sign_up_and_use_the_portal_on_the_club_domain(): void
    {
        $club = $this->split();
        $plan = $this->plan($club);

        $this->post(self::SITE.'/join', [
            'membership_plan_id' => $plan->id, 'full_name' => 'نورة الكندية', 'phone' => '9123 4567',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'accept_terms' => true, 'pay_now' => false,
        ])->assertRedirect(self::SITE.'/portal');
        $this->assertAuthenticated('member');

        $this->get(self::SITE.'/portal')->assertOk()->assertInertia(fn ($p) => $p->component('Portal/Dashboard'));
        $this->assertSame(Membership::PENDING, $this->asTenant($club, fn () => Member::where('phone', '96891234567')->firstOrFail()->memberships()->first()->status));

        $this->post(self::SITE.'/portal/logout')->assertRedirect();
        $this->assertGuest('member');
        $this->get(self::SITE.'/portal')->assertRedirect(self::SITE.'/portal/login');

        // Back to the page asked for before signing in. Laravel keeps it as served (/c/{club}/portal); that address
        // moves on to the clean one, so the browser ends on /portal.
        $back = $this->post(self::SITE.'/portal/login', ['login' => '91234567', 'password' => 'secret-pass'])->assertRedirect()->headers->get('Location');
        $this->assertAuthenticated('member');
        $this->assertContains($back, [self::SITE.'/portal', self::SITE.'/c/'.$club->slug.'/portal']);
        if ($back !== self::SITE.'/portal') {
            $this->get($back)->assertStatus(301)->assertRedirect(self::SITE.'/portal');
        }
        $this->get(self::SITE.'/portal')->assertOk();
    }

    public function test_webhooks_and_the_health_check_answer_on_both_addresses(): void
    {
        $this->split();
        config(['services.whatsapp.app_secret' => 'secret']);

        foreach ([self::SITE, self::ADMIN] as $root) {
            $this->get($root.'/up')->assertOk();
            // Reached the webhook (an unsigned body is refused by it), neither moved nor hidden.
            $this->postJson($root.'/webhooks/whatsapp', ['entry' => []])->assertStatus(401);
        }
    }

    public function test_without_a_club_domain_nothing_changes(): void
    {
        $club = $this->club();
        config(['gym.home_club' => $club->slug]);
        app(SiteDomains::class)->flush();

        $this->assertFalse(app(SiteDomains::class)->enabled());
        $this->assertStringEndsWith('/c/'.$club->slug.'/plans', route('site.plans', ['club' => $club->slug]));
        $this->get('/')->assertRedirect(route('site.home', ['club' => $club->slug]));
        $this->get(route('site.plans', ['club' => $club->slug]))->assertOk();
    }
}
