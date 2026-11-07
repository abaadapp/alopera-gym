<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\SiteDomains;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a club's website on its own domain (e.g. aloperagym.om) and keeps it apart from the panel's (APP_URL).
 *
 * Runs before routing. On the club's domain a clean path (/plans) is mapped onto the club's site routes
 * (/c/{slug}/plans), so the same routes, middleware and tests cover both; the visitor's address stays clean (the
 * original path is kept for Inertia in the request's "site_uri" attribute). When the split is on (SiteDomains):
 *
 * - the club's domain does not open the panel or the staff sign-in: a GET goes to the same path on the panel's
 *   address (301), anything else is not found — a posted form is never redirected, its body would be lost;
 * - the panel's address does not show the website: a GET to /c/{slug}/... goes to the clean path on the club's
 *   domain (301); a form posted there is still served;
 * - an old /c/{slug}/... link on the club's domain goes to its clean path (GET, 301), and www to the bare domain.
 *
 * Built files, uploads, the health check, webhooks and the devices API are served on both, as integrations
 * registered with either address keep working.
 */
class ResolveCustomDomain
{
    /** Served as they are on every address. */
    private const SHARED = ['webhooks', 'api', 'up', 'build', 'storage', 'favicon.ico', 'robots.txt'];

    /** The panel and the staff sign-in: the admin address only. */
    private const ADMIN = ['app', 'platform', 'login', 'logout', 'forgot-password', 'reset-password'];

    public function __construct(private readonly SiteDomains $domains) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $path = ltrim($request->getPathInfo(), '/');
        $read = $request->isMethodSafe();

        if ($this->domains->isAdminHost($host)) {
            if ($read && ($clean = $this->domains->cleanPath($path)) !== null) {
                return $this->moved($this->domains->siteRoot().$clean, $request);
            }

            return $next($request);
        }

        if ($this->isPlatformHost($host)) {
            return $next($request);
        }

        // The home gym's domain is known however it was typed in (www., https://, capitals): a stored form that
        // differs from the host must not leave the domain unserved, as / would then send the visitor back to it.
        $split = $this->domains->isSiteHost($host);
        $slug = $split ? (string) $this->domains->slug() : Cache::remember('custom-domain:'.$host, 300, fn () => Tenant::where('domain', $host)
            ->orWhere('domain', preg_replace('/^www\./', '', $host))
            ->value('slug') ?? '');

        if ($slug === '') {
            return $next($request);
        }

        if ($split && $read && str_starts_with($host, 'www.')) {
            return $this->moved($this->domains->siteRoot().($this->domains->cleanPath($path) ?? '/'.$path), $request);
        }

        // An old address with the club in the path.
        if ($path === 'c/'.$slug || str_starts_with($path, 'c/'.$slug.'/')) {
            if ($split && $read) {
                return $this->moved($this->domains->siteRoot().(substr($path, strlen('c/'.$slug)) ?: '/'), $request);
            }
            $request->attributes->set('custom_domain', $host);

            return $next($request);
        }

        if ($this->under($path, self::SHARED) || str_starts_with($path, 'c/')) {
            return $next($request);
        }

        if ($this->under($path, self::ADMIN)) {
            if (! $split) {
                return $next($request);
            }
            abort_unless($read, 404);

            return $this->moved($this->domains->adminRoot().'/'.$path, $request);
        }

        $original = $request->getRequestUri();
        $query = $request->getQueryString();
        $server = $request->server->all();
        $server['REQUEST_URI'] = '/c/'.$slug.($path === '' ? '' : '/'.$path).($query ? '?'.$query : '');
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $server, $request->getContent());
        $request->attributes->set('custom_domain', $host);
        $request->attributes->set('site_uri', $original);

        return $next($request);
    }

    /** @param list<string> $prefixes */
    private function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function moved(string $url, Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse($url.($query ? '?'.$query : ''), 301);
    }

    private function isPlatformHost(string $host): bool
    {
        $platform = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return in_array($host, [$platform, 'localhost', '127.0.0.1'], true);
    }
}
