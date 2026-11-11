<?php

namespace Tests\Feature;

use App\Core\Tenancy\SiteDomains;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The app answers only on its own hosts: the panel's (APP_URL), the home gym's website and any club domain entered in
 * the settings, with or without «www.». A request naming another Host (Host: evil.example) is refused (400 Bad Request)
 * on every path, before the club's domain is looked up for it and before any redirect or link is built from it; a
 * forwarded Host is held to the same list. Laravel leaves this check off in tests, so these run it as production does,
 * then put it back.
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
            config(['app.debug' => $debug, 'trustedproxy.proxies' => null]);
            Request::setTrustedHosts([]);
            Request::setTrustedProxies([], Request::getTrustedHeaderSet());
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

    /** A refusal: 400, no redirect, nothing in the page naming the Host it was sent with. */
    private function assertRefused($response, string $what, string $host): void
    {
        $this->assertSame(400, $response->getStatusCode(), $what.' was served');
        $this->assertNull($response->headers->get('Location'), $what.': redirected');
        $this->assertStringNotContainsString($host, (string) $response->getContent(), $what.': a link was built from the Host');
    }

    public function test_every_path_the_root_included_refuses_another_host_before_any_redirect(): void
    {
        $this->split();

        $this->live(function () {
            foreach (['evil.example', 'evil.aloperagym.om', 'app.aloperagym.om.evil.example', '165.245.218.225'] as $host) {
                foreach (['/', '/?a=1', '/en', '/plans', '/login', '/up', '/portal/login', '/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1'] as $uri) {
                    $this->assertRefused($this->get('https://'.$host.$uri), $host.$uri, $host);
                }
                $this->assertRefused($this->postJson('https://'.$host.'/webhooks/paymob', []), $host.' POST /webhooks/paymob', $host);
            }
        });
    }

    public function test_another_host_is_refused_before_its_club_domain_is_looked_up(): void
    {
        $this->split();

        $this->live(fn () => $this->assertRefused($this->get('https://evil.example/plans'), 'evil.example/plans', 'evil.example'));

        $this->assertFalse(Cache::has('custom-domain:evil.example'), 'a forged Host was looked up and cached before it was refused');
    }

    public function test_a_forwarded_host_is_held_to_the_same_list(): void
    {
        $this->split();

        $this->live(function () {
            // As in production, no proxy is trusted: X-Forwarded-Host is ignored, the Host itself is checked.
            $page = $this->withHeader('X-Forwarded-Host', 'evil.example')->get(self::SITE.'/');
            $page->assertOk();
            $this->assertStringNotContainsString('evil.example', (string) $page->getContent());
            $this->assertRefused($this->withHeader('X-Forwarded-Host', 'aloperagym.om')->get('https://evil.example/'), 'evil.example behind a forged X-Forwarded-Host', 'evil.example');

            // Behind a trusted proxy the forwarded Host is the one checked, before anything else reads it.
            config(['trustedproxy.proxies' => '*']);
            $this->assertRefused($this->withHeader('X-Forwarded-Host', 'evil.example')->get(self::SITE.'/'), 'a proxied evil.example', 'evil.example');
            Request::setTrustedHosts([]); // a new PHP-FPM request starts with none listed, as the proxy settings are read
            $this->withHeader('X-Forwarded-Host', 'aloperagym.om')->get('https://127.0.0.1/en')->assertOk();
        });

        $this->assertFalse(Cache::has('custom-domain:evil.example'));
    }
}
