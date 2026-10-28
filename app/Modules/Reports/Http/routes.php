<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');
Route::get('/reports/export/{report}', [ReportController::class, 'export'])
    ->whereIn('report', ['memberships', 'revenue', 'classes', 'sales', 'expenses', 'members'])
    ->middleware(['can:reports.export', 'throttle:exports'])
    ->name('reports.export');
