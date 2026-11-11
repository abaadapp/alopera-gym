<?php

namespace Tests\Feature;

use App\Core\Tenancy\SiteDomains;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The app answers only on its own hosts: the panel's (APP_URL), the home gym's website and any club domain entered in
 * the settings, with or without «www.». A request naming another Host (Host: evil.example) is refused (400 Bad Request)
 * before any link is built from it. Laravel leaves this check off in tests, so these run it as production does, then
 * put it back.
 */
class UntrustedHostTest extends TestCase
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

    /** As in production: the trusted-host check on (Laravel skips it while testing), no debug page. */
    private function live(callable $requests): void
    {
        [$env, $debug] = [$this->app['env'], config('app.debug')];
        $this->app['env'] = 'production';
        config(['app.debug' => false]);
        try {
            $requests();
        } finally {
            $this->app['env'] = $env;
            config(['app.debug' => $debug]);
            Request::setTrustedHosts([]);
        }
    }

    public function test_the_panel_the_website_and_a_club_domain_answer_as_before(): void
    {
        $this->split();

        $this->live(function () {
            $this->get(self::ADMIN.'/up')->assertOk();
            $this->get(self::ADMIN.'/login')->assertOk();
            $this->get(self::SITE.'/')->assertOk();
            $this->get(self::SITE.'/en')->assertOk();
            $this->get(self::SITE.'/up')->assertOk();
            $this->get('https://www.aloperagym.om/plans')->assertStatus(301)->assertRedirect(self::SITE.'/plans');
        });
    }

    public function test_another_host_is_refused_before_any_link_is_built_from_it(): void
    {
        $this->split();

        $this->live(function () {
            foreach (['https://evil.example/up', 'https://evil.example/login', 'https://app.aloperagym.om.evil.example/up', 'https://evil.aloperagym.om/', 'https://165.245.218.225/up'] as $url) {
                $response = $this->get($url);
                $this->assertSame(400, $response->getStatusCode(), $url.' was served');
                $this->assertStringNotContainsString('evil.example', (string) $response->getContent(), $url.': a link was built from the Host');
            }
        });
    }

    public function test_a_domain_saved_in_a_clubs_settings_is_answered_at_once(): void
    {
        $this->split();
        $other = $this->club('New club');
        $this->live(fn () => $this->get('https://newclub.om/up')->assertStatus(400));

        $other->update(['domain' => 'newclub.om']);

        $this->live(fn () => $this->get('https://newclub.om/up')->assertOk());
        $this->assertContains('^(www\.)?newclub\.om$', app(SiteDomains::class)->trustedHosts());
    }
}
