<?php

namespace App\Modules\Website\Http;

use App\Core\Tenancy\TenantContext;
use App\Modules\Inventory\Services\ShopSettings;
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
    protected function page(string $component, array $props, ?string $title = null, ?string $description = null, ?string $image = null, ?array $jsonld = null): Response
    {
        $tenant = app(TenantContext::class)->require();
        $site = $this->site();

        return Inertia::render($component, $props + [
            'site' => [
                'is_published' => $site->is_published,
                'announcement' => $site->announcement,
                'contact_phone' => $site->contact_phone ?: $tenant->phone,
                'whatsapp_number' => $site->whatsapp_number,
                'contact_email' => $site->contact_email ?: $tenant->email,
                'address' => $site->address ?: $tenant->address,
                'map_url' => $site->map_url,
                'working_hours' => $site->working_hours ?? [],
                'social_links' => $site->social_links ?? [],
                'logo_url' => $tenant->logoUrl(),
                'signup_enabled' => (bool) $tenant->setting('member_signup_enabled', true),
                'shop_open' => app(ShopSettings::class)->shopOpen($tenant),
            ],
            'seo' => [
                'title' => $title ? $title.' | '.$tenant->name : ($site->seo_title ?: $tenant->name),
                'description' => $description ?: ($site->seo_description ?: $site->hero_subtitle),
                'canonical' => $this->canonical(),
                'image' => $image ?: (SiteSetting::url($site->og_image_path ?: $site->hero_image_path) ?? $tenant->logoUrl()),
                'jsonld' => $jsonld,
            ],
        ]);
    }

    /** Canonical URL: the club's own domain when it has one. */
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
