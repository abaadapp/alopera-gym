<?php

use App\Modules\Gym\Http\Controllers\Api\DeviceApiController;
use Illuminate\Support\Facades\Route;

/*
| Access-control device API — see docs/DEVICES.md for the protocol.
| Devices authenticate with their own bearer token (not a user session).
*/
Route::prefix('devices/v1')->name('devices.')->middleware(['device', 'throttle:devices'])->group(function () {
    Route::post('/heartbeat', [DeviceApiController::class, 'heartbeat'])->name('heartbeat');
    Route::post('/access', [DeviceApiController::class, 'access'])->name('access');
    Route::post('/staff-punch', [DeviceApiController::class, 'staffPunch'])->name('staff-punch');
});
