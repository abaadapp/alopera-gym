<?php

use App\Http\Controllers\Club\AuditController;
use App\Http\Controllers\Club\BackupController;
use App\Http\Controllers\Club\BillingController;
use App\Http\Controllers\Club\BranchController;
use App\Http\Controllers\Club\DashboardController;
use App\Http\Controllers\Club\RoleController;
use App\Http\Controllers\Club\SettingsController;
use App\Http\Controllers\Club\UserController;
use Illuminate\Support\Facades\Route;

// Club-wide (shared) screens: dashboard, and the settings area — a hub of cards at /settings, each
// opening one settings screen under /settings/*. Route names are kept from the flat layout.

Route::get('/', [DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');

Route::prefix('settings')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('settings.index');

    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/club', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/club', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/notifications', [SettingsController::class, 'notifications'])->name('settings.notifications');
        Route::put('/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    });

    Route::resource('branches', BranchController::class)->except(['show', 'create', 'edit'])->middleware('can:branches.manage');
    Route::resource('users', UserController::class)->except(['show', 'create', 'edit'])->middleware('can:users.manage');
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit'])->middleware('can:roles.manage');

    // Integrations moved to their own section (/app/integrations); the old address still leads there.
    Route::redirect('/integrations', '/app/integrations');

    Route::get('/audit', [AuditController::class, 'index'])->middleware('can:audit.view')->name('audit.index');

    Route::middleware('can:backups.manage')->group(function () {
        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('/backups/{file}', [BackupController::class, 'download'])->where('file', '[A-Za-z0-9_.-]+')->name('backups.download');
        Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
    });

    Route::get('/billing', [BillingController::class, 'index'])->middleware('can:billing.view')->name('billing');
});

// Settings screens used to live at the top level; keep old bookmarks working.
foreach (['branches', 'users', 'roles', 'audit', 'backups', 'billing'] as $screen) {
    Route::redirect("/{$screen}", "/app/settings/{$screen}");
}
