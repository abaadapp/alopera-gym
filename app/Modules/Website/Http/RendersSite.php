<?php

namespace App\Modules\Website\Http;

use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Models\MembershipPlan;
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
        // The home page's sections are worked out here too, so the menu on every page links only to sections that show.
        $rendered ??= app(SiteRenderer::class)->render(app(SiteContent::class)->live($site));

        return Inertia::render($component, $props + [
            'site' => $rendered['site'] + [
                'is_published' => $site->is_published,
                'logo_url' => $tenant->logoUrl(),
                // «اشترك الآن» links on every page (portal sign-in, timetable, offers) show only when the sign-up page has
                // a plan to choose; with none yet it would open a page that says there is nothing to sign up for.
                'signup_enabled' => (bool) $tenant->setting('member_signup_enabled', true) && MembershipPlan::public()->exists(),
                'shop_open' => app(ShopSettings::class)->shopOpen($tenant),
            ],
            'chrome' => $rendered['chrome'],
            'seo' => [
                // From the content being shown (published, or the draft in the preview): never the starter text a
                // new club was set up with, which promised services the club may not offer.
                'title' => $title ? $title.' | '.$tenant->name : (($rendered['seo']['title'] ?? '') ?: $tenant->name),
                'description' => $description ?: (($rendered['seo']['description'] ?? '') ?: null),
                'canonical' => $this->canonical(),
                'image' => $image ?: (SiteSetting::url($site->og_image_path ?: $site->hero_image_path) ?? $tenant->logoUrl()),
                'jsonld' => $jsonld,
            ],
        ]);
    }

    /**
     * An inner page of the site headed by the owner's words for it (SitePages, edited in «إدارة الموقع»), which are
     * also its title and description in search results.
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>|null  $jsonld
     */
    protected function sitePage(string $key, string $component, array $props, ?array $jsonld = null): Response
    {
        $rendered = app(SiteRenderer::class)->render(app(SiteContent::class)->live($this->site()));
        $text = $rendered['chrome']['pages'][$key];

        return $this->page($component, $props, $text['title'], $text['subtitle'] ?: null, null, $jsonld, $rendered);
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
