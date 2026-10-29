<?php

use App\Modules\Inventory\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

// Suppliers and purchase orders: their own switch (GYM_INVENTORY_MODULE), off by default.
Route::resource('suppliers', SupplierController::class)->except(['show', 'create', 'edit'])->middleware('can:suppliers.manage');

Route::middleware('can:purchases.manage')->group(function () {
    Route::get('/purchases', [PurchaseOrderController::class, 'index'])->name('purchases.index');
    Route::post('/purchases', [PurchaseOrderController::class, 'store'])->name('purchases.store');
    Route::get('/purchases/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchases.show');
    Route::post('/purchases/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    Route::post('/purchases/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchases.cancel');
});
