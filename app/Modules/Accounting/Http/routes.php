<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\FinanceController;
use App\Modules\Accounting\Http\Controllers\FinanceSettingsController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\MoneyAccountController;
use Illuminate\Support\Facades\Route;

/*
| المالية — the books: summary, money movement, cash & bank accounts, chart of accounts, journal, statements.
| Invoices, payments, dues and expenses keep their own routes (Finance module); they sit beside these as tabs.
*/

Route::prefix('finance')->name('finance.')->whereNumber(['account', 'entry'])->group(function () {
    Route::middleware('can:accounting.view')->group(function () {
        Route::get('/', [FinanceController::class, 'summary'])->name('summary');
        Route::get('/movement', [FinanceController::class, 'movement'])->name('movement');
        Route::get('/statements', [FinanceController::class, 'statements'])->name('statements');
        Route::get('/statements/export', [FinanceController::class, 'export'])->name('statements.export');
        Route::get('/banks', [MoneyAccountController::class, 'index'])->name('banks');
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
        Route::get('/journal', [JournalController::class, 'index'])->name('journal');
    });

    Route::middleware('can:accounting.manage')->group(function () {
        Route::post('/banks', [MoneyAccountController::class, 'store'])->name('banks.store');
        Route::put('/banks/{account}', [MoneyAccountController::class, 'update'])->name('banks.update');
        Route::post('/banks/{account}/opening', [MoneyAccountController::class, 'opening'])->name('banks.opening');
        Route::post('/transfers', [MoneyAccountController::class, 'transfer'])->middleware('throttle:20,1')->name('transfers.store');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('/journal', [JournalController::class, 'store'])->middleware('throttle:20,1')->name('journal.store');
        Route::post('/journal/{entry}/reverse', [JournalController::class, 'reverse'])->name('journal.reverse');
    });
});

Route::get('/settings/finance', [FinanceSettingsController::class, 'edit'])->middleware('can:accounting.manage')->name('settings.finance');
Route::put('/settings/finance', [FinanceSettingsController::class, 'update'])->middleware('can:accounting.manage')->name('settings.finance.update');
