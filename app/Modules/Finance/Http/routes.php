<?php

use App\Modules\Finance\Http\Controllers\CreditNoteController;
use App\Modules\Finance\Http\Controllers\DuesController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('can:invoices.view')->name('invoices.index');
Route::get('/invoices/create', [InvoiceController::class, 'create'])->middleware('can:invoices.manage')->name('invoices.create');
Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('can:invoices.manage')->name('invoices.store');
// The manual invoice's live paper while it is filled in (nothing saved).
Route::post('/invoices/preview', [InvoiceController::class, 'preview'])->middleware(['can:invoices.manage', 'throttle:120,1'])->name('invoices.preview');
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('can:invoices.view')->name('invoices.show');
Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->middleware('can:invoices.view')->name('invoices.print');
Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('can:invoices.void')->name('invoices.void');
Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('can:whatsapp.send')->name('invoices.send');

// Partial refunds: a credit note lowers what is owed; paying money back is a separate, capped step.
Route::post('/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store'])->middleware('can:invoices.credit')->name('credit-notes.store');
Route::post('/credit-notes/{creditNote}/refund', [CreditNoteController::class, 'refund'])->middleware('can:payments.refund')->name('credit-notes.refund');
Route::get('/credit-notes/{creditNote}/print', [CreditNoteController::class, 'print'])->middleware('can:invoices.view')->name('credit-notes.print');

Route::get('/payments', [PaymentController::class, 'index'])->middleware('can:invoices.view')->name('payments.index');
Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware('can:payments.record')->name('payments.store');
Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm'])->middleware('can:payments.confirm')->name('payments.confirm');
Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->middleware('can:payments.confirm')->name('payments.reject');
Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('can:invoices.view')->name('payments.receipt');
Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->middleware('can:invoices.view')->name('payments.proof');

Route::get('/dues', DuesController::class)->middleware('can:dues.view')->name('dues');

Route::middleware('can:expenses.manage')->group(function () {
    Route::get('/expenses/export', [ExpenseController::class, 'export'])->middleware('throttle:exports')->name('expenses.export');
    Route::post('/expense-categories', [ExpenseController::class, 'storeCategory'])->name('expense-categories.store');
    Route::get('/expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->name('expenses.attachment');
    Route::resource('expenses', ExpenseController::class)->except(['show', 'create', 'edit']);
});
