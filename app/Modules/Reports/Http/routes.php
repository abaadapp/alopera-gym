<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Reports\Services\ReportCatalog;
use Illuminate\Support\Facades\Route;

// The reports page and each report (ReportCatalog). `reports.any`: the user may open at least one report; each report
// checks its own permissions in the controller, and its file also needs reports.export.
Route::get('/reports', [ReportController::class, 'index'])->middleware('can:reports.any')->name('reports.index');
Route::get('/reports/{report}/export', [ReportController::class, 'export'])->whereIn('report', ReportCatalog::keys())
    ->middleware(['can:reports.export', 'throttle:exports'])->name('reports.export');
Route::get('/reports/{report}', [ReportController::class, 'show'])->whereIn('report', ReportCatalog::keys())
    ->middleware('can:reports.any')->name('reports.show');
