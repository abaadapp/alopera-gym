<?php

use App\Modules\Inventory\Http\Controllers\OrderController;
use App\Modules\Inventory\Http\Controllers\PosController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Http\Controllers\SalesSettingsController;
use Illuminate\Support\Facades\Route;

// Sales: products, the point of sale, sales from both sources, website orders, reports (GYM_SALES_MODULE).
Route::middleware('can:sales.view')->group(function () {
    Route::get('/sales', [SalesController::class, 'summary'])->name('sales.summary');
    Route::get('/sales/list', [SalesController::class, 'index'])->name('sales.index');
    Route::get('/sales/reports', [SalesController::class, 'reports'])->name('sales.reports');
    Route::get('/sales/reports/export', [SalesController::class, 'export'])->middleware('throttle:exports')->name('sales.reports.export');
});
Route::get('/sales/{sale}/receipt', [SalesController::class, 'receipt'])->whereNumber('sale')->name('sales.receipt');
Route::post('/sales/{sale}/void', [PosController::class, 'void'])->whereNumber('sale')->middleware(['can:sales.refund', 'throttle:20,1'])->name('pos.void');

Route::middleware('can:pos.sell')->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/checkout', [PosController::class, 'checkout'])->middleware('throttle:30,1')->name('pos.checkout');
});

Route::middleware('can:orders.manage')->prefix('sales/orders')->name('sales.orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::post('/{sale}/status', [OrderController::class, 'status'])->whereNumber('sale')->name('status');
    Route::post('/{sale}/deliver', [OrderController::class, 'deliver'])->whereNumber('sale')->middleware('throttle:20,1')->name('deliver');
    Route::post('/{sale}/cancel', [OrderController::class, 'cancel'])->whereNumber('sale')->middleware('throttle:20,1')->name('cancel');
});

Route::middleware('can:products.manage')->group(function () {
    Route::post('/products/{product}/adjust', [ProductController::class, 'adjust'])->name('products.adjust');
    Route::get('/products/{product}/movements', [ProductController::class, 'movements'])->name('products.movements');
    Route::resource('products', ProductController::class)->except(['show', 'create', 'edit']);
});

Route::get('/settings/sales', [SalesSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.sales');
Route::put('/settings/sales', [SalesSettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.sales.update');
