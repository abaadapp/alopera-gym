<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\WhatsAppReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «واتساب ← التقارير» (whatsapp.view_reports). */
class ReportController extends Controller
{
    public function __invoke(Request $request, WhatsAppReports $reports): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(29);
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return Inertia::render('Messaging/Reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'figures' => $reports->figures($from, $to),
        ]);
    }
}
