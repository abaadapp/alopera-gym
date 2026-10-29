<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentSettingsController;
use App\Modules\Documents\Http\Controllers\DocumentTypeController;
use Illuminate\Support\Facades\Route;

// The business's documents, under their own address and names: members' documents keep /documents/{document}.
// Confidential ones are filtered per user in the controller.
Route::middleware('can:documents.view')->group(function () {
    Route::get('/business-documents', [DocumentController::class, 'index'])->name('business-documents.index');
    Route::get('/business-documents/renewals', [DocumentController::class, 'renewals'])->name('business-documents.renewals');
    Route::get('/business-documents/{document}/file', [DocumentController::class, 'download'])->whereNumber('document')->name('business-documents.download');
    Route::get('/business-documents/renewals/{renewal}/file', [DocumentController::class, 'downloadArchived'])->whereNumber('renewal')->name('business-documents.renewals.download');
    Route::post('/business-documents/alerts/{alert}/acknowledge', [DocumentController::class, 'acknowledge'])->whereNumber('alert')->name('business-documents.alerts.acknowledge');
});
Route::post('/business-documents', [DocumentController::class, 'store'])->middleware('can:documents.create')->name('business-documents.store');
Route::post('/business-documents/{document}/renew', [DocumentController::class, 'renew'])->whereNumber('document')->middleware('can:documents.renew')->name('business-documents.renew');

Route::middleware('can:documents.manage')->group(function () {
    // POST (not PUT) so a replacement file can travel as multipart form data.
    Route::post('/business-documents/{document}', [DocumentController::class, 'update'])->whereNumber('document')->name('business-documents.update');
    Route::delete('/business-documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('business-documents.destroy');
    Route::get('/document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
    Route::post('/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
    Route::put('/document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');
    Route::delete('/document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');
    Route::get('/settings/documents', [DocumentSettingsController::class, 'edit'])->name('settings.documents');
    Route::put('/settings/documents', [DocumentSettingsController::class, 'update'])->name('settings.documents.update');
});
