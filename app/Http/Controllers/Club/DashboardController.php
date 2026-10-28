<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Services\ReportService;
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

        return Inertia::render('Club/Dashboard', $data);
    }
}
