<?php

use App\Http\Controllers\Club\AccountController;
use App\Http\Controllers\Club\AuditController;
use App\Http\Controllers\Club\BackupController;
use App\Http\Controllers\Club\BillingController;
use App\Http\Controllers\Club\BranchController;
use App\Http\Controllers\Club\BranchSwitchController;
use App\Http\Controllers\Club\DashboardController;
use App\Http\Controllers\Club\NotificationController;
use App\Http\Controllers\Club\PaperController;
use App\Http\Controllers\Club\PaperTemplateController;
use App\Http\Controllers\Club\RoleController;
use App\Http\Controllers\Club\SearchController;
use App\Http\Controllers\Club\SettingsController;
use App\Http\Controllers\Club\UserController;
use Illuminate\Support\Facades\Route;

// Club-wide (shared) screens: dashboard, and the settings area — a hub of cards at /settings, each
// opening one settings screen under /settings/*. Route names are kept from the flat layout.

// No can: here — the controller sends whoever has no dashboard to their own first screen.
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::put('/dashboard/tiles', [DashboardController::class, 'saveTiles'])->middleware('can:dashboard.view')->name('dashboard.tiles');
Route::post('/branch', BranchSwitchController::class)->middleware('throttle:30,1')->name('branch.switch');

// The top bar's quick search; each group of results checks its own permission.
Route::get('/search', SearchController::class)->middleware('throttle:120,1')->name('search');

// «حسابي»: the signed-in user's own account (no permission needed — it is their own).
Route::get('/account', [AccountController::class, 'show'])->name('account.show');
Route::put('/account', [AccountController::class, 'update'])->name('account.update');
Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1')->name('account.password');

// The bell: every staff member's own notifications (no permission — each sees only their own rows).
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('/notifications/feed', [NotificationController::class, 'feed'])->middleware('throttle:30,1')->name('notifications.feed');
Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->whereNumber('notification')->name('notifications.open');
Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');

// Papers: one print page and one PDF per document, for every type; each type checks its own permission and branch.
Route::middleware('throttle:papers')->group(function () {
    Route::get('/papers/{type}/{id}', [PaperController::class, 'show'])->whereNumber('id')->where('type', '[a-z_]+')->name('papers.show');
    Route::get('/papers/{type}/{id}/pdf', [PaperController::class, 'pdf'])->whereNumber('id')->where('type', '[a-z_]+')->name('papers.pdf');
});

Route::prefix('settings')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('settings.index');

    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/club', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/club', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/notifications', [SettingsController::class, 'notifications'])->name('settings.notifications');
        Route::put('/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    });

    // قوالب الأوراق: printing templates (not WhatsApp templates, not the business documents section).
    Route::middleware('can:papers.manage')->group(function () {
        Route::get('/papers', [PaperTemplateController::class, 'index'])->name('settings.papers');
        Route::get('/papers/{type}', [PaperTemplateController::class, 'edit'])->where('type', '[a-z_]+')->name('settings.papers.edit');
        Route::post('/papers/{type}/preview', [PaperTemplateController::class, 'preview'])->where('type', '[a-z_]+')->middleware('throttle:120,1')->name('settings.papers.preview');
        Route::put('/papers/{type}', [PaperTemplateController::class, 'update'])->where('type', '[a-z_]+')->name('settings.papers.update');
        Route::delete('/papers/{type}', [PaperTemplateController::class, 'reset'])->where('type', '[a-z_]+')->name('settings.papers.reset');
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
