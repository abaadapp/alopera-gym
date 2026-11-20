<?php

use App\Modules\Payments\Http\Controllers\PaymentLinkController;
use Illuminate\Support\Facades\Route;

// Payment links: an amount and a description, issued as a club invoice whose secure link opens a Paymob checkout.
Route::middleware('can:payments.links')->group(function () {
    Route::get('/payment-links', [PaymentLinkController::class, 'index'])->name('payment-links.index');
    Route::post('/payment-links', [PaymentLinkController::class, 'store'])->middleware('throttle:60,1')->name('payment-links.store');
    // Cancelling voids the link's invoice: it takes the permission that voids invoices too.
    Route::post('/payment-links/{paymentLink}/cancel', [PaymentLinkController::class, 'cancel'])->whereNumber('paymentLink')->middleware('can:invoices.void')->name('payment-links.cancel');
});
