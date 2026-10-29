<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');
Route::get('/reports/export/{report}', [ReportController::class, 'export'])
    ->whereIn('report', ['memberships', 'revenue', 'classes', 'sales', 'expenses', 'members', 'trainers', 'branches'])
    ->middleware(['can:reports.export', 'throttle:exports'])
    ->name('reports.export');
// A period report (ReportCatalog::DETAIL); each checks its own permission in the controller.
Route::get('/reports/{report}', [ReportController::class, 'show'])->whereIn('report', ['finance', 'members', 'classes', 'trainers', 'branches'])
    ->middleware('can:reports.view')->name('reports.show');
