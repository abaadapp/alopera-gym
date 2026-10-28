<?php

namespace App\Http\Controllers;

use App\Models\PlatformPlan;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Landing', [
            'plans' => PlatformPlan::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'description', 'price_monthly', 'price_yearly', 'currency', 'max_members', 'max_branches', 'features']),
            'clubs' => Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->orderBy('name')->limit(12)->get(['name', 'slug', 'city', 'logo_path'])
                ->map(fn (Tenant $t) => ['name' => $t->name, 'slug' => $t->slug, 'city' => $t->city, 'logo_url' => $t->logoUrl()]),
            'seo' => [
                'title' => config('app.name').' — منصة إدارة الأندية الرياضية النسائية',
                'description' => 'إدارة العضويات والحصص والحجوزات والمدفوعات وواتساب لنوادي اللياقة النسائية.',
            ],
        ]);
    }
}
