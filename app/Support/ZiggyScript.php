<?php

namespace App\Support;

use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\SiteDomains;
use Tighten\Ziggy\Output\Script;

/**
 * The route list the pages build links from (@routes), with each route on its own address when the website and the
 * panel are apart (SiteDomains): website and portal routes on the club's domain with clean paths (plans, not
 * c/{club}/plans), every other route on APP_URL's host. Without the split the list is Ziggy's own.
 */
class ZiggyScript extends Script
{
    public function __toString(): string
    {
        $domains = app(SiteDomains::class);
        $english = SiteLanguage::isEnglish();
        if (! $domains->enabled() && ! $english) {
            return parent::__toString();
        }

        $data = $this->ziggy->jsonSerialize();
        if ($domains->enabled()) {
            $data['routes'] = self::addresses($data['routes'], $domains);
            $data['defaults'] = (object) array_diff_key((array) $data['defaults'], ['club' => true]);
        }
        if ($english) {
            $data['routes'] = self::inLanguage($data['routes'], SiteLanguage::current());
        }
        $json = json_encode($data);

        return <<<HTML
        <script type="text/javascript"{$this->nonce}>const Ziggy={$json};{$this->function}</script>
        HTML;
    }

    /**
     * A page served in English builds English links (/en/plans): the website and portal routes get the language
     * after the club (c/{club}/en/plans) or, on the club's domain, at the front (en/plans). See SiteLocale.
     *
     * @param  array<string, array<string, mixed>>  $routes
     * @return array<string, array<string, mixed>>
     */
    public static function inLanguage(array $routes, string $locale): array
    {
        foreach ($routes as $name => $route) {
            $uri = (string) $route['uri'];
            if (str_starts_with($uri, 'c/{club}')) {
                $route['uri'] = 'c/{club}/'.$locale.substr($uri, strlen('c/{club}'));
            } elseif (($route['domain'] ?? null) !== null && $route['domain'] === app(SiteDomains::class)->siteHost()) {
                $route['uri'] = trim($locale.'/'.$uri, '/');
            }
            $routes[$name] = $route;
        }

        return $routes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $routes
     * @return array<string, array<string, mixed>>
     */
    public static function addresses(array $routes, SiteDomains $domains): array
    {
        foreach ($routes as $name => $route) {
            if (str_starts_with($route['uri'], 'c/{club}')) {
                $route['uri'] = ltrim(substr($route['uri'], strlen('c/{club}')), '/');
                $route['domain'] = $domains->siteHost();
                $route['parameters'] = array_values(array_diff($route['parameters'] ?? [], ['club']));
                unset($route['wheres']['club'], $route['bindings']['club']);
            } else {
                $route['domain'] = $domains->adminHost();
            }
            $routes[$name] = $route;
        }

        return $routes;
    }
}
