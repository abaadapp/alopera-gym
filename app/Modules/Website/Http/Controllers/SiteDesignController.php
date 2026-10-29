<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The club website's sections (order and visibility on the home page) and identity (logo and colours). The
 * owner's alone, like the rest of the website (website.manage). The public page keeps its design; it only
 * follows the order and shows what is switched on.
 */
class SiteDesignController extends Controller
{
    public function sections(TenantContext $tenants): Response
    {
        return Inertia::render('Website/Sections', [
            'sections' => $this->site()->sectionLayout(),
            'siteUrl' => route('site.home', ['club' => $tenants->require()->slug]),
        ]);
    }

    public function updateSections(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.key' => ['required', Rule::in(array_keys(SiteSetting::SECTIONS))],
            'sections.*.visible' => ['required', 'boolean'],
        ]);
        $site = $this->site();
        $site->update(['sections' => collect($data['sections'])->unique('key')->map(fn ($s) => ['key' => $s['key'], 'visible' => (bool) $s['visible']])->values()->all()]);
        $audit->log('website.sections_updated', $site, ['visible' => $site->visibleSections()]);

        return back()->with('success', 'حُفظ ترتيب أقسام الموقع.');
    }

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
            'logo' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ], [], ['primary_color' => 'اللون الرئيسي', 'secondary_color' => 'اللون الثانوي', 'logo' => 'الشعار']);

        $tenant = $tenants->require();
        $before = $tenant->only('primary_color', 'secondary_color');
        if ($request->hasFile('logo')) {
            ImageStore::delete($tenant->logo_path);
            $tenant->logo_path = ImageStore::store($request->file('logo'), 'branding', 600);
        }
        $tenant->forceFill(['primary_color' => $data['primary_color'], 'secondary_color' => $data['secondary_color']])->save();
        $audit->log('website.branding_updated', $tenant, [], $before, $tenant->only('primary_color', 'secondary_color'));

        return back()->with('success', 'حُفظت الهوية. تظهر في الموقع ولوحة النادي وبوابة الأعضاء.');
    }

    private function site(): SiteSetting
    {
        return SiteSetting::firstOrCreate([]);
    }
}
