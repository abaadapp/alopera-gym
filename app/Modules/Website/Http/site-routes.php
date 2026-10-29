<?php

use App\Modules\Inventory\Http\Controllers\ShopController;
use App\Modules\Website\Http\Controllers\PayController;
use App\Modules\Website\Http\Controllers\PortalAuthController;
use App\Modules\Website\Http\Controllers\PortalController;
use App\Modules\Website\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// Public website
Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/plans', [SiteController::class, 'plans'])->name('site.plans');
Route::get('/programs', [SiteController::class, 'programs'])->name('site.programs');
Route::get('/trainers', [SiteController::class, 'trainers'])->name('site.trainers');
Route::get('/schedule', [SiteController::class, 'schedule'])->name('site.schedule');
Route::get('/news', [SiteController::class, 'news'])->name('site.news');
Route::get('/news/{slug}', [SiteController::class, 'showPost'])->name('site.post');
Route::get('/contact', [SiteController::class, 'contact'])->name('site.contact');
Route::post('/contact', [SiteController::class, 'sendContact'])->middleware('throttle:public-forms')->name('site.contact.send');
Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('site.sitemap');

// Shop: the products the club shows on its website; orders land in the panel (sales module).
Route::get('/shop', [ShopController::class, 'index'])->name('site.shop');
Route::get('/shop/cart', [ShopController::class, 'cart'])->name('site.shop.cart');
Route::get('/shop/{product}', [ShopController::class, 'show'])->whereNumber('product')->name('site.shop.product');
Route::post('/shop/orders', [ShopController::class, 'store'])->middleware('throttle:public-forms')->name('site.shop.order.store');
Route::get('/shop/orders/{token}', [ShopController::class, 'order'])->where('token', '[A-Za-z0-9]{40}')->name('site.shop.order');
Route::post('/shop/orders/{token}/pay', [ShopController::class, 'pay'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:public-forms')->name('site.shop.order.pay');

// Sign-up with plan choice
Route::get('/join', [PortalAuthController::class, 'register'])->name('site.join');
Route::post('/join', [PortalAuthController::class, 'storeRegistration'])->middleware('throttle:public-forms')->name('site.join.store');

// Online payment: pay link (WhatsApp/portal) and gateway return page. The return page never activates anything.
Route::get('/pay/return/{reference}', [PayController::class, 'return'])->name('site.pay.return');
Route::get('/pay/status/{reference}', [PayController::class, 'status'])->name('site.pay.status');
Route::get('/pay/{token}', [PayController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->name('site.pay.show');
Route::post('/pay/{token}', [PayController::class, 'start'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:public-forms')->name('site.pay.start');
Route::get('/invoice/{token}', [PayController::class, 'invoice'])->where('token', '[A-Za-z0-9]{48}')->name('site.invoice');

// Member portal
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:member')->group(function () {
        Route::get('/login', [PortalAuthController::class, 'login'])->name('login');
        Route::post('/login', [PortalAuthController::class, 'authenticate'])->middleware('throttle:login')->name('login.store');
    });

    Route::middleware(['auth:member', 'throttle:portal'])->group(function () {
        Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/renew', [PortalController::class, 'renew'])->name('renew');
        Route::post('/renew', [PortalController::class, 'storeRenewal'])->name('renew.store');
        Route::get('/invoices', [PortalController::class, 'invoices'])->name('invoices');
        Route::post('/invoices/{invoice}/pay', [PortalController::class, 'pay'])->name('invoices.pay');
        Route::get('/classes', [PortalController::class, 'classes'])->name('classes');
        Route::post('/classes/{session}/book', [PortalController::class, 'book'])->name('classes.book');
        Route::post('/bookings/{booking}/cancel', [PortalController::class, 'cancelBooking'])->name('bookings.cancel');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::put('/profile', [PortalController::class, 'updateProfile'])->name('profile.update');
    });
});
