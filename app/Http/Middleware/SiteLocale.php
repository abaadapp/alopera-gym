<?php

namespace App\Http\Middleware;

use App\Core\Support\SiteLanguage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The English side of the website and portal (/en/plans, or /c/{club}/en/plans without a domain of its own).
 *
 * Runs before routing, after ResolveCustomDomain has put a clean path on the club's routes: the /en is taken out of
 * the path, so the same routes, middleware and tests serve both languages, and the page is made in English. The
 * address the visitor typed stays in the browser (Inertia reads it from the request's "site_uri"). Links made while
 * the page is served carry the /en on (AppServiceProvider, ZiggyScript), so a visitor stays in the language chosen.
 */
class SiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        [$locale, $path] = SiteLanguage::split('/'.ltrim($request->getPathInfo(), '/'));
        if ($locale === SiteLanguage::DEFAULT) {
            // Set every time: a process that serves more than one request (a test, a worker) starts each in Arabic.
            // (Not from config('app.locale'): setLocale() writes it, so it holds the last request's language.)
            app()->setLocale(SiteLanguage::DEFAULT);

            return $next($request);
        }

        $original = $request->attributes->get('site_uri') ?? $request->getRequestUri();
        $query = $request->getQueryString();
        $server = $request->server->all();
        $server['REQUEST_URI'] = $path.($query ? '?'.$query : '');
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $server, $request->getContent());
        $request->attributes->set('site_uri', $original);
        app()->setLocale($locale);

        return $next($request);
    }
}
