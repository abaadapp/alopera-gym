<?php

use App\Modules\Payroll\Http\Controllers\EmployeeController;
use App\Modules\Payroll\Http\Controllers\JobTitleController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayrollSettingsController;
use Illuminate\Support\Facades\Route;

/*
| الرواتب والموظفون — staff files, job titles, payroll runs and payouts (club panel, /app/...).
| Every part has its own permission: seeing a file is not seeing its salary, editing a file is not changing a salary,
| and each step of a payroll run (prepare, edit a draft, approve, pay, payslips, reports) is separate.
*/

Route::middleware('can:employees.view')->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');
    Route::get('/job-titles', [JobTitleController::class, 'index'])->name('job-titles.index');
});

Route::middleware('can:employees.create')->group(function () {
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::post('/employees/import-trainers', [EmployeeController::class, 'importTrainers'])->name('employees.import-trainers');
});

// Basic data (employees.manage) or salary and bank (payroll.compensation): the controller writes only what each allows.
Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->whereNumber('employee')->name('employees.edit');
Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('employees.update');

Route::middleware('can:employees.access')->group(function () {
    Route::put('/employees/{employee}/access', [EmployeeController::class, 'access'])->whereNumber('employee')->name('employees.access');
    Route::post('/employees/{employee}/password', [EmployeeController::class, 'password'])->whereNumber('employee')->middleware('throttle:10,1')->name('employees.password');
});
Route::post('/employees/{employee}/attendance', [EmployeeController::class, 'attendance'])->whereNumber('employee')->middleware('can:staff_attendance.manage')->name('employees.attendance');

Route::middleware('can:employees.manage')->group(function () {
    Route::post('/job-titles', [JobTitleController::class, 'store'])->name('job-titles.store');
    Route::put('/job-titles/{jobTitle}', [JobTitleController::class, 'update'])->name('job-titles.update');
    Route::delete('/job-titles/{jobTitle}', [JobTitleController::class, 'destroy'])->name('job-titles.destroy');
});

Route::prefix('payroll')->name('payroll.')->whereNumber(['run', 'item'])->group(function () {
    Route::get('/', [PayrollRunController::class, 'index'])->name('index');
    Route::get('/payouts', [PayrollRunController::class, 'payouts'])->name('payouts');
    Route::post('/', [PayrollRunController::class, 'store'])->middleware('can:payroll.prepare')->name('store');
    Route::put('/items/{item}', [PayrollRunController::class, 'updateItem'])->middleware('can:payroll.edit')->name('items.update');
    Route::delete('/items/{item}', [PayrollRunController::class, 'destroyItem'])->middleware('can:payroll.edit')->name('items.destroy');
    Route::delete('/{run}', [PayrollRunController::class, 'destroy'])->middleware('can:payroll.edit')->name('destroy');
    Route::post('/{run}/approve', [PayrollRunController::class, 'approve'])->middleware('can:payroll.approve')->name('approve');
    Route::post('/{run}/pay', [PayrollRunController::class, 'pay'])->middleware(['can:payroll.pay', 'throttle:20,1'])->name('pay');
    // The printed run sheet or one payslip (?item=): the paper engine, with payroll.payslips.
    Route::get('/{run}/print', [PayrollRunController::class, 'print'])->middleware(['can:payroll.payslips', 'can:papers.print'])->name('print');
    Route::get('/{run}/export', [PayrollRunController::class, 'export'])->middleware('can:payroll.reports')->name('export');
});

Route::get('/settings/payroll', [PayrollSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.payroll');
Route::put('/settings/payroll', [PayrollSettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.payroll.update');
