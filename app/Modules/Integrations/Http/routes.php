<?php

use App\Modules\Integrations\Http\Controllers\IntegrationController;
use Illuminate\Support\Facades\Route;

// «التطبيقات التكاملية»: the hub of three cards (anyone who manages integrations or devices), Paymob and WhatsApp
// settings (integrations.manage — the owner's unless a role is given it). The devices screen sits under the same
// prefix but belongs to the Gym module (devices.manage).
Route::prefix('integrations')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->name('integrations.index');

    Route::middleware('can:integrations.manage')->group(function () {
        Route::get('/paymob', [IntegrationController::class, 'paymob'])->name('integrations.paymob');
        Route::get('/whatsapp', [IntegrationController::class, 'whatsapp'])->name('integrations.whatsapp');
        Route::put('/{provider}', [IntegrationController::class, 'update'])->whereIn('provider', ['whatsapp', 'paymob'])->name('integrations.update');
        Route::post('/{provider}/verify', [IntegrationController::class, 'verify'])->whereIn('provider', ['whatsapp', 'paymob'])
            ->middleware('throttle:10,1')->name('integrations.verify');
    });
});
