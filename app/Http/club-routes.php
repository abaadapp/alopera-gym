<?php

use App\Http\Controllers\Club\AuditController;
use App\Http\Controllers\Club\BackupController;
use App\Http\Controllers\Club\BillingController;
use App\Http\Controllers\Club\BranchController;
use App\Http\Controllers\Club\DashboardController;
use App\Http\Controllers\Club\IntegrationController;
use App\Http\Controllers\Club\RoleController;
use App\Http\Controllers\Club\SettingsController;
use App\Http\Controllers\Club\UserController;
use Illuminate\Support\Facades\Route;

// Club-wide (shared) screens: dashboard, settings, staff, roles, integrations, audit, backups, billing.

Route::get('/', [DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');

Route::middleware('can:settings.manage')->group(function () {
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

Route::resource('branches', BranchController::class)->except(['show', 'create', 'edit'])->middleware('can:branches.manage');
Route::resource('users', UserController::class)->except(['show', 'create', 'edit'])->middleware('can:users.manage');
Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit'])->middleware('can:roles.manage');

Route::middleware('can:integrations.manage')->group(function () {
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::put('/integrations/{provider}', [IntegrationController::class, 'update'])->whereIn('provider', ['whatsapp', 'paymob'])->name('integrations.update');
});

Route::get('/audit', [AuditController::class, 'index'])->middleware('can:audit.view')->name('audit.index');

Route::middleware('can:backups.manage')->group(function () {
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
    Route::get('/backups/{file}', [BackupController::class, 'download'])->where('file', '[A-Za-z0-9_.-]+')->name('backups.download');
    Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
});

Route::get('/billing', [BillingController::class, 'index'])->middleware('can:billing.view')->name('billing');
