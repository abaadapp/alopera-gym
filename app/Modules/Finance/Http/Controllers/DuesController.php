<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Outstanding balances and arrears with aging (spec 1.5). */
class DuesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $open = Invoice::open()->orderBy('due_date')->get();
        $today = today();

        $aging = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '60_plus' => 0.0];
        foreach ($open as $invoice) {
            $days = $invoice->due_date && $invoice->due_date->lt($today) ? (int) $invoice->due_date->diffInDays($today) : 0;
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                default => '60_plus',
            };
            $aging[$bucket] += (float) $invoice->balance();
        }

        return Inertia::render('Finance/Dues', [
            'invoices' => $open->when($request->boolean('overdue'), fn ($c) => $c->filter(fn ($i) => $i->due_date && $i->due_date->lt($today)))
                ->take(200)->values()->map(fn (Invoice $i) => InvoiceController::row($i) + [
                    'days_overdue' => $i->due_date && $i->due_date->lt($today) ? (int) $i->due_date->diffInDays($today) : 0,
                ]),
            'aging' => array_map(fn ($v) => round($v, 3), $aging),
            'total' => round(array_sum($aging), 3),
            'filters' => ['overdue' => $request->boolean('overdue')],
        ]);
    }
}
