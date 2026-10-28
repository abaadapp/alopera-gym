<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a club's website on its own domain (e.g. alopera.om).
 *
 * Runs before routing. When the host is a club's registered domain, the
 * request path is mapped onto that club's site routes (/c/{slug}/...), so the
 * same routes, middleware and tests cover both addresses. Platform hosts
 * (APP_URL, localhost) are left untouched, and so are the panel, platform,
 * webhooks and API paths.
 */
class ResolveCustomDomain
{
    private const PASSTHROUGH = ['c/', 'app', 'platform', 'login', 'logout', 'forgot-password', 'reset-password', 'webhooks', 'api', 'up', 'build', 'storage', 'favicon.ico', 'robots.txt'];

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($this->isPlatformHost($host)) {
            return $next($request);
        }

        $slug = Cache::remember('custom-domain:'.$host, 300, fn () => Tenant::where('domain', $host)
            ->orWhere('domain', preg_replace('/^www\./', '', $host))
            ->value('slug') ?? '');

        if ($slug === '') {
            return $next($request);
        }

        $path = ltrim($request->getPathInfo(), '/');
        foreach (self::PASSTHROUGH as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, rtrim($prefix, '/').'/')) {
                return $next($request);
            }
        }

        $query = $request->getQueryString();
        $server = $request->server->all();
        $server['REQUEST_URI'] = '/c/'.$slug.($path === '' ? '' : '/'.$path).($query ? '?'.$query : '');
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $server, $request->getContent());
        $request->attributes->set('custom_domain', $host);

        return $next($request);
    }

    private function isPlatformHost(string $host): bool
    {
        $platform = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return in_array($host, [$platform, 'localhost', '127.0.0.1'], true);
    }
}
