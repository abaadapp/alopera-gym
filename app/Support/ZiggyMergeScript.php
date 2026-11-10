<?php

namespace App\Support;

use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\SiteDomains;
use Tighten\Ziggy\Output\MergeScript;

/** Ziggy's output for a second @routes in the same process, with the same addresses as ZiggyScript. */
class ZiggyMergeScript extends MergeScript
{
    public function __toString(): string
    {
        $domains = app(SiteDomains::class);
        if (! $domains->enabled() && ! SiteLanguage::isEnglish()) {
            return parent::__toString();
        }
        $routes = $this->ziggy->toArray()['routes'];
        $routes = $domains->enabled() ? ZiggyScript::addresses($routes, $domains) : $routes;
        $routes = json_encode(SiteLanguage::isEnglish() ? ZiggyScript::inLanguage($routes, SiteLanguage::current()) : $routes);

        return <<<HTML
        <script type="text/javascript"{$this->nonce}>Object.assign(Ziggy.routes,{$routes});</script>
        HTML;
    }
}
