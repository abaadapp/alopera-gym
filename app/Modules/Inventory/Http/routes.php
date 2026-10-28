<?php

use App\Modules\Inventory\Http\Controllers\PosController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:products.manage')->group(function () {
    Route::post('/products/{product}/adjust', [ProductController::class, 'adjust'])->name('products.adjust');
    Route::get('/products/{product}/movements', [ProductController::class, 'movements'])->name('products.movements');
    Route::resource('products', ProductController::class)->except(['show', 'create', 'edit']);
});
Route::resource('suppliers', SupplierController::class)->except(['show', 'create', 'edit'])->middleware('can:suppliers.manage');

Route::middleware('can:purchases.manage')->group(function () {
    Route::get('/purchases', [PurchaseOrderController::class, 'index'])->name('purchases.index');
    Route::post('/purchases', [PurchaseOrderController::class, 'store'])->name('purchases.store');
    Route::get('/purchases/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchases.show');
    Route::post('/purchases/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    Route::post('/purchases/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchases.cancel');
});

Route::middleware('can:pos.sell')->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
    Route::get('/pos/sales', [PosController::class, 'sales'])->name('pos.sales');
});
Route::post('/pos/sales/{sale}/void', [PosController::class, 'void'])->middleware('can:invoices.void')->name('pos.void');
