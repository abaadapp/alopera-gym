<?php

use App\Modules\Inventory\Http\Controllers\OrderController;
use App\Modules\Inventory\Http\Controllers\PosController;
use App\Modules\Inventory\Http\Controllers\PosDeviceController;
use App\Modules\Inventory\Http\Controllers\PosDeviceSetupController;
use App\Modules\Inventory\Http\Controllers\ProductAddonController;
use App\Modules\Inventory\Http\Controllers\ProductCategoryController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Http\Controllers\SalesSettingsController;
use Illuminate\Support\Facades\Route;

// Sales: the point of sale, sales from both sources and website orders (GYM_SALES_MODULE).
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
    // This browser as a point-of-sale device: paired with a one-time code, or activated by a device manager.
    Route::get('/pos/device', [PosDeviceSetupController::class, 'show'])->name('pos.device');
    Route::post('/pos/device/pair', [PosDeviceSetupController::class, 'pair'])->middleware('throttle:pos-pairing')->name('pos.device.pair');
    Route::post('/pos/device/activate', [PosDeviceSetupController::class, 'activate'])->middleware('throttle:pos-pairing')->name('pos.device.activate');
});

// Point-of-sale devices and their peripherals, in Settings: their own permission, separate from selling.
Route::middleware('can:pos_devices.manage')->prefix('settings/pos-devices')->name('settings.pos-devices')->group(function () {
    Route::get('/', [PosDeviceController::class, 'index']);
    Route::post('/', [PosDeviceController::class, 'store'])->name('.store');
    Route::put('/policy', [PosDeviceController::class, 'policy'])->name('.policy');
    Route::put('/{device}', [PosDeviceController::class, 'update'])->whereNumber('device')->name('.update');
    Route::post('/{device}/revoke', [PosDeviceController::class, 'revoke'])->whereNumber('device')->name('.revoke');
    Route::post('/{device}/code', [PosDeviceController::class, 'code'])->whereNumber('device')->middleware('throttle:pos-pairing')->name('.code');
    Route::delete('/{device}', [PosDeviceController::class, 'destroy'])->whereNumber('device')->name('.destroy');
    Route::post('/{device}/peripherals', [PosDeviceController::class, 'storePeripheral'])->whereNumber('device')->name('.peripherals.store');
    Route::put('/{device}/peripherals/{peripheral}', [PosDeviceController::class, 'updatePeripheral'])->whereNumber(['device', 'peripheral'])->name('.peripherals.update');
    Route::delete('/{device}/peripherals/{peripheral}', [PosDeviceController::class, 'destroyPeripheral'])->whereNumber(['device', 'peripheral'])->name('.peripherals.destroy');
    Route::get('/{device}/peripherals/{peripheral}/test', [PosDeviceController::class, 'testPrint'])->whereNumber(['device', 'peripheral'])->name('.peripherals.test');
});

Route::middleware('can:orders.manage')->prefix('sales/orders')->name('sales.orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::post('/{sale}/status', [OrderController::class, 'status'])->whereNumber('sale')->name('status');
    Route::post('/{sale}/deliver', [OrderController::class, 'deliver'])->whereNumber('sale')->middleware('throttle:20,1')->name('deliver');
    Route::post('/{sale}/cancel', [OrderController::class, 'cancel'])->whereNumber('sale')->middleware('throttle:20,1')->name('cancel');
});

// Products: the section of its own and the only place products, their categories and add-ons are managed. Selling
// them (pos.sell, the website shop) needs none of this.
Route::middleware('can:products.manage')->group(function () {
    Route::get('/products/categories', [ProductCategoryController::class, 'index'])->name('product-categories.index');
    Route::post('/products/categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
    Route::post('/products/categories/reorder', [ProductCategoryController::class, 'reorder'])->name('product-categories.reorder');
    Route::put('/products/categories/{category}', [ProductCategoryController::class, 'update'])->whereNumber('category')->name('product-categories.update');
    Route::delete('/products/categories/{category}', [ProductCategoryController::class, 'destroy'])->whereNumber('category')->name('product-categories.destroy');

    Route::get('/products/addons', [ProductAddonController::class, 'index'])->name('product-addons.index');
    Route::post('/products/addons', [ProductAddonController::class, 'store'])->name('product-addons.store');
    Route::put('/products/addons/{addon}', [ProductAddonController::class, 'update'])->whereNumber('addon')->name('product-addons.update');
    Route::patch('/products/addons/{addon}/toggle', [ProductAddonController::class, 'toggle'])->whereNumber('addon')->name('product-addons.toggle');
    Route::delete('/products/addons/{addon}', [ProductAddonController::class, 'destroy'])->whereNumber('addon')->name('product-addons.destroy');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
    Route::patch('/products/{product}/toggle', [ProductController::class, 'toggle'])->whereNumber('product')->name('products.toggle');
    Route::post('/products/{product}/photos/{index}', [ProductController::class, 'photo'])->whereNumber(['product', 'index'])->name('products.photo');
    Route::post('/products/{product}/adjust', [ProductController::class, 'adjust'])->whereNumber('product')->name('products.adjust');
    Route::get('/products/{product}/movements', [ProductController::class, 'movements'])->whereNumber('product')->name('products.movements');

    // Addresses a product page may have been reached by before the section was split out of sales.
    Route::get('/sales/products', fn () => redirect()->route('club.products.index', request()->query(), 301));
    Route::get('/products/{product}', fn (int $product) => redirect()->route('club.products.edit', $product))->whereNumber('product');
});

Route::get('/settings/sales', [SalesSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.sales');
Route::put('/settings/sales', [SalesSettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.sales.update');
