<?php

use App\Modules\Payroll\Http\Controllers\EmployeeController;
use App\Modules\Payroll\Http\Controllers\JobTitleController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayrollSettingsController;
use Illuminate\Support\Facades\Route;

/*
| الرواتب والموظفين — staff files, job titles, payroll runs and payouts (club panel, /app/...).
| Confidential salary fields are filtered in the controllers (payroll.salaries); every run action has its own permission.
*/

Route::middleware('can:employees.view')->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/job-titles', [JobTitleController::class, 'index'])->name('job-titles.index');
});

Route::middleware('can:employees.manage')->group(function () {
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::post('/employees/import-trainers', [EmployeeController::class, 'importTrainers'])->name('employees.import-trainers');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/job-titles', [JobTitleController::class, 'store'])->name('job-titles.store');
    Route::put('/job-titles/{jobTitle}', [JobTitleController::class, 'update'])->name('job-titles.update');
    Route::delete('/job-titles/{jobTitle}', [JobTitleController::class, 'destroy'])->name('job-titles.destroy');
});

Route::prefix('payroll')->name('payroll.')->whereNumber(['run', 'item'])->group(function () {
    Route::get('/', [PayrollRunController::class, 'index'])->name('index');
    Route::get('/payouts', [PayrollRunController::class, 'payouts'])->name('payouts');
    Route::post('/', [PayrollRunController::class, 'store'])->middleware('can:payroll.prepare')->name('store');
    Route::put('/items/{item}', [PayrollRunController::class, 'updateItem'])->middleware('can:payroll.prepare')->name('items.update');
    Route::delete('/items/{item}', [PayrollRunController::class, 'destroyItem'])->middleware('can:payroll.prepare')->name('items.destroy');
    Route::delete('/{run}', [PayrollRunController::class, 'destroy'])->middleware('can:payroll.prepare')->name('destroy');
    Route::post('/{run}/approve', [PayrollRunController::class, 'approve'])->middleware('can:payroll.approve')->name('approve');
    Route::post('/{run}/pay', [PayrollRunController::class, 'pay'])->middleware(['can:payroll.pay', 'throttle:20,1'])->name('pay');
    Route::get('/{run}/print', [PayrollRunController::class, 'print'])->name('print');
    Route::get('/{run}/export', [PayrollRunController::class, 'export'])->middleware('can:payroll.reports')->name('export');
});

Route::get('/settings/payroll', [PayrollSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.payroll');
Route::put('/settings/payroll', [PayrollSettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.payroll.update');
