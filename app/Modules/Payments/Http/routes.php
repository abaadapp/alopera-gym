<?php

use App\Modules\Payments\Http\Controllers\PaymentLinkController;
use Illuminate\Support\Facades\Route;

// Payment links: an amount and a description, issued as a club invoice whose secure link opens a Paymob checkout.
Route::middleware('can:payments.links')->group(function () {
    Route::get('/payment-links', [PaymentLinkController::class, 'index'])->name('payment-links.index');
    Route::post('/payment-links', [PaymentLinkController::class, 'store'])->middleware('throttle:60,1')->name('payment-links.store');
    // Cancelling ends the link only (never its invoice): the links permission, not the one that voids invoices.
    // A link for an invoice already issued (its page), or in place of its link whose time is over.
    Route::post('/invoices/{invoice}/payment-links', [PaymentLinkController::class, 'open'])->whereNumber('invoice')->middleware('throttle:60,1')->name('payment-links.open');
    Route::post('/payment-links/{paymentLink}/cancel', [PaymentLinkController::class, 'cancel'])->whereNumber('paymentLink')->middleware('can:payments.links')->name('payment-links.cancel');
});
