<?php

use App\Modules\Inventory\Http\Controllers\InventoryController;
use App\Modules\Inventory\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Http\Controllers\StockOperationsController;
use App\Modules\Inventory\Http\Controllers\SupplierBillController;
use App\Modules\Inventory\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

// The inventory and purchases sections (GYM_INVENTORY_MODULE). Seeing is one permission; counting and adjusting,
// approving, moving stock, creating purchases, approving them, receiving goods and paying suppliers are each their own.

Route::prefix('inventory')->name('inventory.')->group(function () {
    Route::middleware('can:inventory.view')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('index');
        Route::get('/log', [InventoryController::class, 'movements'])->name('movements');
        Route::get('/counts', [StockOperationsController::class, 'counts'])->name('counts');
        Route::get('/counts/{stocktake}', [StockOperationsController::class, 'showCount'])->whereNumber('stocktake')->name('counts.show');
        Route::get('/transfers', [StockOperationsController::class, 'transfers'])->name('transfers');
        Route::get('/transfers/{transfer}', [StockOperationsController::class, 'showTransfer'])->whereNumber('transfer')->name('transfers.show');
        Route::get('/supply', [StockOperationsController::class, 'supply'])->name('supply');
        Route::get('/supply/{supplyRequest}', [StockOperationsController::class, 'showSupply'])->whereNumber('supplyRequest')->name('supply.show');
    });
    Route::middleware('can:inventory.adjust')->group(function () {
        Route::post('/adjust', [InventoryController::class, 'adjust'])->middleware('throttle:stock')->name('adjust');
        Route::post('/counts', [StockOperationsController::class, 'startCount'])->name('counts.store');
        Route::put('/counts/{stocktake}', [StockOperationsController::class, 'saveCount'])->whereNumber('stocktake')->name('counts.save');
        Route::post('/counts/{stocktake}/cancel', [StockOperationsController::class, 'cancelCount'])->whereNumber('stocktake')->name('counts.cancel');
    });
    Route::middleware('can:inventory.transfer')->group(function () {
        Route::post('/transfers', [StockOperationsController::class, 'storeTransfer'])->middleware('throttle:stock')->name('transfers.store');
        Route::post('/supply', [StockOperationsController::class, 'storeSupply'])->middleware('throttle:stock')->name('supply.store');
    });
    // Each step checks its own permission (approve/reject: inventory.approve; the rest: inventory.transfer) and branch.
    Route::post('/transfers/{transfer}/{step}', [StockOperationsController::class, 'transferStep'])->whereNumber('transfer')
        ->whereIn('step', ['approve', 'dispatch', 'receive', 'resolve', 'reject', 'cancel'])->middleware(['can:inventory.view', 'throttle:stock'])->name('transfers.step');
    Route::post('/supply/{supplyRequest}/{decision}', [StockOperationsController::class, 'reviewSupply'])->whereNumber('supplyRequest')
        ->whereIn('decision', ['approve', 'reject', 'cancel'])->middleware(['can:inventory.view', 'throttle:stock'])->name('supply.review');

    // Goods receipt notes: listed here, recorded from a purchase order, approved by purchases.approve.
    Route::middleware('can:purchases.view')->group(function () {
        Route::get('/receipts', [SupplierBillController::class, 'receipts'])->name('receipts');
        Route::get('/receipts/{goodsReceipt}', [SupplierBillController::class, 'showReceipt'])->whereNumber('goodsReceipt')->name('receipts.show');
    });
    Route::post('/receipts/{goodsReceipt}/{decision}', [SupplierBillController::class, 'decideReceipt'])->whereNumber('goodsReceipt')
        ->whereIn('decision', ['approve', 'reject'])->middleware(['can:purchases.approve', 'throttle:stock'])->name('receipts.decide');
});

Route::prefix('purchases')->name('purchases.')->group(function () {
    Route::middleware('can:purchases.view')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'register'])->name('register');
        Route::get('/orders', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->name('show');
        Route::get('/orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->whereNumber('purchaseOrder')->name('print');
        Route::get('/bills', [SupplierBillController::class, 'index'])->name('bills');
        Route::get('/bills/{supplierBill}', [SupplierBillController::class, 'show'])->whereNumber('supplierBill')->name('bills.show');
        Route::get('/bills/{supplierBill}/attachment', [SupplierBillController::class, 'attachment'])->whereNumber('supplierBill')->name('bills.attachment');
    });
    Route::middleware('can:purchases.manage')->group(function () {
        Route::get('/orders/create', [PurchaseOrderController::class, 'create'])->name('create');
        Route::post('/orders', [PurchaseOrderController::class, 'store'])->middleware('throttle:stock')->name('store');
        Route::get('/orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->whereNumber('purchaseOrder')->name('edit');
        Route::put('/orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->name('update');
        Route::post('/bills', [SupplierBillController::class, 'store'])->middleware('throttle:stock')->name('bills.store');
    });
    Route::middleware('can:purchases.approve')->group(function () {
        Route::post('/orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->whereNumber('purchaseOrder')->name('approve');
        Route::post('/orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('purchaseOrder')->name('cancel');
        Route::post('/orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->whereNumber('purchaseOrder')->name('close');
        Route::post('/bills/{supplierBill}/{decision}', [SupplierBillController::class, 'decide'])->whereNumber('supplierBill')->whereIn('decision', ['approve', 'reject', 'void'])->middleware('throttle:stock')->name('bills.decide');
    });
    Route::post('/orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('purchaseOrder')->middleware(['can:purchases.receive', 'throttle:stock'])->name('receive');
    Route::middleware(['can:purchases.pay', 'throttle:stock'])->group(function () {
        Route::post('/bills/{supplierBill}/pay', [SupplierBillController::class, 'pay'])->whereNumber('supplierBill')->name('bills.pay');
        Route::post('/payments/{supplierPayment}/reverse', [SupplierBillController::class, 'reversePayment'])->whereNumber('supplierPayment')->name('payments.reverse');
    });

    Route::middleware('can:suppliers.manage')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('suppliers.destroy');
    });
});

// Older addresses of these screens.
Route::get('/suppliers', fn () => redirect()->route('club.purchases.suppliers'));
