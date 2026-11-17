<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page reached by a secret link (a gift's card, #192): never indexed nor followed by search engines, and never kept
 * by a shared cache. The link's path is not sent to other sites either way: the site's Referrer-Policy
 * (strict-origin-when-cross-origin, SecurityHeaders and nginx) sends only the origin across sites, and the page itself
 * asks for no referrer at all (<meta name="referrer">).
 */
class PrivateLinkPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
