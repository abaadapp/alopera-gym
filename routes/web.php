<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\LandingController;
use App\Modules\Messaging\Http\Controllers\WhatsAppWebhookController;
use App\Modules\Payments\Http\Controllers\PaymobWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Route map
|   /                 platform landing (off unless GYM_MARKETING_SITE=true: opens the staff sign-in)
|   /login            staff sign-in (platform admins and club staff)
|   /platform/*       super admin (off unless GYM_PLATFORM_PANEL=true; clubs are managed with php artisan clubs:*)
|   /app/*            club panel — tenant = the signed-in user's club
|   /c/{club}/*       club website and member portal — tenant = slug
|   /webhooks/*       Paymob and WhatsApp callbacks (signature-verified)
|   /api/devices/v1/* access-control hardware (routes/api.php)
*/

Route::get('/', LandingController::class)->name('landing');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:public-forms')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:public-forms')->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::prefix('platform')->name('platform.')->middleware(['module:platform_panel', 'auth', 'platform'])
    ->group(app_path('Modules/Platform/Http/routes.php'));

Route::prefix('app')->name('club.')->middleware(['auth', 'tenant.user', 'tenant.active'])->group(function () {
    require app_path('Http/club-routes.php');
    require app_path('Modules/Gym/Http/routes.php');
    require app_path('Modules/Finance/Http/routes.php');
    require app_path('Modules/Accounting/Http/routes.php');
    require app_path('Modules/Payroll/Http/routes.php');
    require app_path('Modules/Documents/Http/routes.php');
    require app_path('Modules/Integrations/Http/routes.php');
    // Sales (products, point of sale, website orders) and, separately, suppliers and purchases — each behind its
    // own switch (GYM_SALES_MODULE, GYM_INVENTORY_MODULE); a switched-off module answers 404, its data is kept.
    Route::middleware('module:sales')->group(app_path('Modules/Inventory/Http/routes.php'));
    Route::middleware('module:inventory')->group(app_path('Modules/Inventory/Http/purchasing-routes.php'));
    require app_path('Modules/Messaging/Http/routes.php');
    require app_path('Modules/Website/Http/cms-routes.php');
    require app_path('Modules/Reports/Http/routes.php');
});

Route::prefix('c/{club}')->middleware('site.tenant')
    ->where(['club' => '[a-z0-9-]+'])
    ->group(app_path('Modules/Website/Http/site-routes.php'));

Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:webhooks')->group(function () {
    Route::post('/paymob', PaymobWebhookController::class)->name('paymob');
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'receive'])->name('whatsapp');
});
