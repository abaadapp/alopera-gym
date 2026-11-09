<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The club website's sections (order and visibility on the home page) and identity (logo and colours), for whoever
 * holds website.manage, like the rest of the website. The public page keeps its design; it only
 * follows the order and shows what is switched on.
 */
class SiteDesignController extends Controller
{
    public function branding(TenantContext $tenants): Response
    {
        $tenant = $tenants->require();

        return Inertia::render('Website/Branding', [
            'club' => ['name' => $tenant->name, 'logo_url' => $tenant->logoUrl(), 'primary_color' => $tenant->primary_color, 'secondary_color' => $tenant->secondary_color],
            'siteUrl' => route('site.home', ['club' => $tenant->slug]),
        ]);
    }

    public function updateBranding(Request $request, TenantContext $tenants, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], ['primary_color' => 'اللون الرئيسي', 'secondary_color' => 'اللون الثانوي']);

        $tenant = $tenants->require();
        $before = $tenant->only('primary_color', 'secondary_color');
        $tenant->forceFill(['primary_color' => $data['primary_color'], 'secondary_color' => $data['secondary_color']])->save();
        $audit->log('website.branding_updated', $tenant, [], $before, $tenant->only('primary_color', 'secondary_color'));

        return back()->with('success', 'حُفظت الهوية. تظهر في الموقع ولوحة النادي وبوابة الأعضاء.');
    }

    private function site(): SiteSetting
    {
        return SiteSetting::firstOrCreate([]);
    }
}
