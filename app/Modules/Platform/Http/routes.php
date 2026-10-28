<?php

use App\Modules\Platform\Http\Controllers\AuditController;
use App\Modules\Platform\Http\Controllers\DashboardController;
use App\Modules\Platform\Http\Controllers\PlanController;
use App\Modules\Platform\Http\Controllers\PlatformInvoiceController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Platform\Http\Controllers\WebhookLogController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
Route::post('/tenants/{tenant}/subscribe', [TenantController::class, 'subscribe'])->name('tenants.subscribe');
Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');

Route::resource('plans', PlanController::class)->except(['show', 'create', 'edit']);

Route::get('/invoices', [PlatformInvoiceController::class, 'index'])->name('invoices.index');
Route::post('/invoices/{invoice}/pay', [PlatformInvoiceController::class, 'pay'])->name('invoices.pay');

Route::get('/webhooks', WebhookLogController::class)->name('webhooks');
Route::get('/audit', AuditController::class)->name('audit');
