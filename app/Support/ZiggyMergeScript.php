<?php

namespace App\Support;

use App\Core\Tenancy\SiteDomains;
use Tighten\Ziggy\Output\MergeScript;

/** Ziggy's output for a second @routes in the same process, with the same addresses as ZiggyScript. */
class ZiggyMergeScript extends MergeScript
{
    public function __toString(): string
    {
        $domains = app(SiteDomains::class);
        if (! $domains->enabled()) {
            return parent::__toString();
        }
        $routes = json_encode(ZiggyScript::addresses($this->ziggy->toArray()['routes'], $domains));

        return <<<HTML
        <script type="text/javascript"{$this->nonce}>Object.assign(Ziggy.routes,{$routes});</script>
        HTML;
    }
}
