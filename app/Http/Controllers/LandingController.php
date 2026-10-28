<?php

namespace App\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Models\PlatformPlan;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        // The platform's marketing page is switched off (config gym.modules.marketing_site): / opens the staff sign-in.
        if (! PermissionRegistry::moduleEnabled('marketing_site')) {
            return redirect()->route('login');
        }

        return Inertia::render('Landing', [
            'plans' => PlatformPlan::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'description', 'price_monthly', 'price_yearly', 'currency', 'max_members', 'max_branches', 'features']),
            'clubs' => Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->where('settings->listed_on_landing', true)->orderBy('name')->limit(12)->get(['name', 'slug', 'city', 'logo_path'])
                ->map(fn (Tenant $t) => ['name' => $t->name, 'slug' => $t->slug, 'city' => $t->city, 'logo_url' => $t->logoUrl()]),
            'contactEmail' => config('gym.platform_sales_email'),
            'seo' => [
                'title' => config('app.name').' — منصة إدارة الأندية الرياضية النسائية',
                'description' => 'إدارة العضويات والحصص والحجوزات والمدفوعات وواتساب لنوادي اللياقة النسائية.',
            ],
        ]);
    }
}
