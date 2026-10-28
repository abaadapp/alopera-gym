<?php

use App\Modules\Website\Http\Controllers\CmsController;
use App\Modules\Website\Http\Controllers\PostController;
use App\Modules\Website\Http\Controllers\SiteDesignController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:website.manage')->prefix('website')->name('website.')->group(function () {
    Route::get('/', [CmsController::class, 'edit'])->name('edit');
    Route::post('/', [CmsController::class, 'update'])->name('update');
    Route::post('/media', [CmsController::class, 'uploadMedia'])->name('media.store');
    Route::delete('/media/{media}', [CmsController::class, 'deleteMedia'])->name('media.destroy');
    Route::get('/sections', [SiteDesignController::class, 'sections'])->name('sections');
    Route::put('/sections', [SiteDesignController::class, 'updateSections'])->name('sections.update');
    Route::get('/branding', [SiteDesignController::class, 'branding'])->name('branding');
    Route::post('/branding', [SiteDesignController::class, 'updateBranding'])->name('branding.update');
    Route::get('/messages', [CmsController::class, 'messages'])->name('messages');
    Route::post('/messages/{message}/read', [CmsController::class, 'markRead'])->name('messages.read');
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    Route::post('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
});
