<?php

namespace App\Core\Tenancy;

use App\Models\Tenant;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The two addresses of the one gym, from data already on record:
 *
 * - the website and member portal: the home gym's own domain (tenants.domain, e.g. aloperagym.om), with clean paths
 *   (/plans, /portal/login) instead of the /c/{club}/... form the routes are registered with;
 * - the panel and staff sign-in: the APP_URL host (e.g. app.aloperagym.om).
 *
 * The split is on only when both are known, they differ and APP_URL is not a local address: a development copy or the
 * test suite on localhost keeps the /c/{club}/... addresses on one host. Read once a minute (a queue worker keeps
 * running), and again after a club is saved.
 */
final class SiteDomains
{
    private const LOCAL = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** @var array{site: ?string, admin: ?string, slug: ?string, scheme: string}|null */
    private ?array $known = null;

    private int $readAt = 0;

    public function flush(): void
    {
        $this->known = null;
        try {
            Cache::forget('trusted-club-domains');
        } catch (Throwable) {
            // no cache store yet
        }
    }

    public function enabled(): bool
    {
        $k = $this->known();

        return $k['site'] !== null && $k['admin'] !== null && $k['site'] !== $k['admin'] && ! in_array($k['admin'], self::LOCAL, true);
    }

    public function siteHost(): ?string
    {
        return $this->known()['site'];
    }

    public function adminHost(): ?string
    {
        return $this->known()['admin'];
    }

    /**
     * The hosts this app answers on, as TrustHosts patterns: the panel's (APP_URL), the home gym's website and any other
     * club domain entered in its settings, each with or without «www.». A request naming any other Host is refused
     * before a link is built from it. A domain saved in a club's settings is answered at once (Tenant::saved flushes).
     *
     * @return list<string>
     */
    public function trustedHosts(): array
    {
        $hosts = [$this->adminHost(), $this->siteHost()];
        try {
            $hosts = [...$hosts, ...Cache::remember('trusted-club-domains', 300, fn () => Tenant::query()->whereNotNull('domain')->pluck('domain')->all())];
        } catch (Throwable) {
            // no database yet (a fresh install, a build step): the panel and the home gym's site only
        }

        return collect($hosts)->filter()->map(fn (string $host) => self::normalize($host))->filter()->unique()
            ->map(fn (string $host) => '^(www\.)?'.preg_quote($host).'$')->values()->all();
    }

    /** The home gym's slug: the {club} the website routes are registered with. */
    public function slug(): ?string
    {
        return $this->known()['slug'];
    }

    public function siteRoot(): string
    {
        return $this->known()['scheme'].'://'.$this->siteHost();
    }

    public function adminRoot(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function isSiteHost(string $host): bool
    {
        return $this->enabled() && self::normalize($host) === $this->siteHost();
    }

    public function isAdminHost(string $host): bool
    {
        return $this->enabled() && strtolower($host) === $this->adminHost();
    }

    /** A website or portal route: registered under /c/{club}. */
    public static function isSiteRoute(?Route $route): bool
    {
        return $route !== null && str_starts_with($route->uri(), 'c/{club}');
    }

    /** "/c/alopera/plans" → "/plans" for the home gym's slug; null for any other path. */
    public function cleanPath(string $path): ?string
    {
        $slug = $this->slug();
        $path = '/'.ltrim($path, '/');
        if ($slug === null) {
            return null;
        }
        if ($path === '/c/'.$slug || $path === '/c/'.$slug.'/') {
            return '/';
        }

        return str_starts_with($path, '/c/'.$slug.'/') ? substr($path, strlen('/c/'.$slug)) : null;
    }

    /** "https://www.AloperaGym.om/" → "aloperagym.om". */
    public static function normalize(string $domain): string
    {
        $host = strtolower(trim($domain));
        $host = (string) (parse_url(str_contains($host, '://') ? $host : 'http://'.$host, PHP_URL_HOST) ?? '');

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /** @return array{site: ?string, admin: ?string, slug: ?string, scheme: string} */
    private function known(): array
    {
        if ($this->known === null || time() - $this->readAt > 60) {
            $url = (string) config('app.url');
            $admin = strtolower((string) parse_url($url, PHP_URL_HOST)) ?: null;
            try {
                $gym = HomeGym::tenant();
            } catch (Throwable) {
                $gym = null; // no database yet (a fresh install, a build step): one host, as before
            }
            $site = $gym?->domain ? (self::normalize($gym->domain) ?: null) : null;
            $this->known = ['site' => $site, 'admin' => $admin, 'slug' => $gym?->slug, 'scheme' => parse_url($url, PHP_URL_SCHEME) ?: 'https'];
            $this->readAt = time();
        }

        return $this->known;
    }
}
