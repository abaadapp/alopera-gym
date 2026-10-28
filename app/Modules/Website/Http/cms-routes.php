<?php

use App\Modules\Website\Http\Controllers\CmsController;
use App\Modules\Website\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:website.manage')->prefix('website')->name('website.')->group(function () {
    Route::get('/', [CmsController::class, 'edit'])->name('edit');
    Route::post('/', [CmsController::class, 'update'])->name('update');
    Route::post('/media', [CmsController::class, 'uploadMedia'])->name('media.store');
    Route::delete('/media/{media}', [CmsController::class, 'deleteMedia'])->name('media.destroy');
    Route::get('/messages', [CmsController::class, 'messages'])->name('messages');
    Route::post('/messages/{message}/read', [CmsController::class, 'markRead'])->name('messages.read');
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    Route::post('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
});
