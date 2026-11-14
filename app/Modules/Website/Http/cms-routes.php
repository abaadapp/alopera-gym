<?php

use App\Http\Controllers\Club\ImageTicketController;
use App\Modules\Website\Http\Controllers\CmsController;
use App\Modules\Website\Http\Controllers\PostController;
use App\Modules\Website\Http\Controllers\SiteBuilderController;
use App\Modules\Website\Http\Controllers\SiteDesignController;
use Illuminate\Support\Facades\Route;

// «إدارة الموقع»: the owner, or staff granted website.manage by name. Everything below is scoped to the signed-in club.
Route::middleware('can:website.manage')->prefix('website')->name('website.')->group(function () {
    Route::get('/', [SiteBuilderController::class, 'show'])->name('edit');
    Route::put('/draft', [SiteBuilderController::class, 'saveDraft'])->name('draft.save');
    Route::post('/publish', [SiteBuilderController::class, 'publish'])->middleware('throttle:20,1')->name('publish');
    Route::post('/discard', [SiteBuilderController::class, 'discard'])->name('discard');
    Route::post('/revisions/{revision}/restore', [SiteBuilderController::class, 'restore'])->whereNumber('revision')->middleware('throttle:20,1')->name('revisions.restore');
    Route::get('/preview', [SiteBuilderController::class, 'preview'])->name('preview');
    Route::post('/media', [SiteBuilderController::class, 'uploadMedia'])->middleware('throttle:30,1')->name('media.store');
    Route::patch('/media/{media}', [SiteBuilderController::class, 'updateMedia'])->whereNumber('media')->name('media.update');
    Route::delete('/media/{media}', [SiteBuilderController::class, 'deleteMedia'])->whereNumber('media')->name('media.destroy');
    // With originals in Spaces: a signed address for the browser to put one original (404 while kept locally).
    Route::post('/media/uploads', ImageTicketController::class)->defaults('folder', 'site')->middleware('throttle:120,1')->name('media.uploads');
    Route::post('/posts/uploads', ImageTicketController::class)->defaults('folder', 'posts')->middleware('throttle:120,1')->name('posts.uploads');
    Route::get('/media/{media}/original', [SiteBuilderController::class, 'mediaOriginal'])->whereNumber('media')->name('media.original');
    // The old sections page now lives inside «إدارة الموقع».
    Route::get('/sections', fn () => redirect()->route('club.website.edit'))->name('sections');
    Route::get('/branding', [SiteDesignController::class, 'branding'])->name('branding');
    Route::post('/branding', [SiteDesignController::class, 'updateBranding'])->name('branding.update');
    Route::get('/messages', [CmsController::class, 'messages'])->name('messages');
    Route::post('/messages/{message}/read', [CmsController::class, 'markRead'])->name('messages.read');
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    Route::post('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::get('/posts/{post}/image', [PostController::class, 'image'])->name('posts.image');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
});
