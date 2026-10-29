<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Reports\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $data = $reports->dashboard();

        // Money figures only for those allowed to see them.
        if (! $request->user()->can('reports.financial')) {
            foreach (['revenue_month', 'revenue_today', 'outstanding'] as $key) {
                $data['kpis'][$key] = null;
            }
            $data['revenue_trend'] = [];
        }

        $data['documents'] = $request->user()->can('documents.view') ? $this->documentAlerts($request) : null;

        return Inertia::render('Club/Dashboard', $data);
    }

    /** Document alerts not yet looked at, for the documents this user may see. */
    private function documentAlerts(Request $request): array
    {
        $today = CarbonImmutable::today();
        $soon = DocumentService::soonDays(app(TenantContext::class)->require());
        $active = fn () => BusinessDocument::visibleTo($request->user())->where('status', 'active');

        return [
            'expiring' => $active()->whereBetween('expiry_date', [$today, $today->addDays($soon)])->count(),
            'expired' => $active()->where('expiry_date', '<', $today)->count(),
            'alerts' => DocumentAlert::with('document:id,name,expiry_date')->whereNull('acknowledged_at')
                ->whereIn('document_id', $active()->select('id'))->latest('id')->limit(5)->get()
                ->map(fn (DocumentAlert $a) => ['id' => $a->id, 'name' => $a->document?->name, 'expiry_date' => $a->expiry_date->toDateString(), 'days_left' => $a->document?->daysLeft($today)]),
        ];
    }
}
