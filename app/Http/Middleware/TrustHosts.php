<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\SiteDomains;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the panel's, the website's and the clubs' own domains are answered (SiteDomains::trustedHosts, each ± www.).
 *
 * Runs first, right after the proxy settings (bootstrap/app.php): a request naming any other Host is refused (400) here,
 * on every path, before the club's domain is looked up (and cached) for it and before any redirect or link is made from
 * it. Laravel's own middleware only lists the hosts and leaves the refusal to the first read of the Host, which a path
 * that never reads it (a webhook) skips. Off in local development and in tests, as Laravel does.
 */
class TrustHosts extends Middleware
{
    /** @return list<string> */
    public function hosts(): array
    {
        return app(SiteDomains::class)->trustedHosts();
    }

    public function handle(Request $request, $next): Response
    {
        if ($this->shouldSpecifyTrustedHosts()) {
            Request::setTrustedHosts(array_filter($this->hosts()));
            // Read now: an unknown Host throws SuspiciousOperationException, which Laravel answers with 400.
            $request->getHost();
        }

        return $next($request);
    }
}
