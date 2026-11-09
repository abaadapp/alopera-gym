<?php

namespace App\Modules\Website\Http;

use App\Core\Tenancy\TenantContext;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Website\Content\SiteContent;
use App\Modules\Website\Content\SiteRenderer;
use App\Modules\Website\Models\SiteSetting;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every public page and portal page gets the club's site settings and a
 * server-rendered SEO block (title, description, canonical, OG image, JSON-LD).
 */
trait RendersSite
{
    protected function site(): SiteSetting
    {
        return SiteSetting::firstOrCreate([]);
    }

    /** @param array<string, mixed> $props */
    /**
     * @param  array<string, mixed>|null  $rendered  SiteRenderer output for the page (the home page and the preview
     *                                               pass it); otherwise the published content's menu, footer and
     *                                               contact details are rendered here.
     */
    protected function page(string $component, array $props, ?string $title = null, ?string $description = null, ?string $image = null, ?array $jsonld = null, ?array $rendered = null): Response
    {
        $tenant = app(TenantContext::class)->require();
        $site = $this->site();
        $rendered ??= app(SiteRenderer::class)->render(app(SiteContent::class)->live($site), withSections: false);

        return Inertia::render($component, $props + [
            'site' => $rendered['site'] + [
                'is_published' => $site->is_published,
                'logo_url' => $tenant->logoUrl(),
                'signup_enabled' => (bool) $tenant->setting('member_signup_enabled', true),
                'shop_open' => app(ShopSettings::class)->shopOpen($tenant),
            ],
            'chrome' => $rendered['chrome'],
            'seo' => [
                'title' => $title ? $title.' | '.$tenant->name : ($site->seo_title ?: $tenant->name),
                'description' => $description ?: ($site->seo_description ?: $site->hero_subtitle),
                'canonical' => $this->canonical(),
                'image' => $image ?: (SiteSetting::url($site->og_image_path ?: $site->hero_image_path) ?? $tenant->logoUrl()),
                'jsonld' => $jsonld,
            ],
        ]);
    }

    private function canonical(): string
    {
        $tenant = app(TenantContext::class)->require();
        $url = url()->current();

        if ($tenant->domain) {
            $path = preg_replace('#^/c/'.preg_quote($tenant->slug, '#').'#', '', (string) parse_url($url, PHP_URL_PATH)) ?: '/';

            return 'https://'.$tenant->domain.$path;
        }

        return $url;
    }
}
