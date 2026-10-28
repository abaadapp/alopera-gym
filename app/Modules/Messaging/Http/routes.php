<?php

use App\Modules\Messaging\Http\Controllers\AutoReplyController;
use App\Modules\Messaging\Http\Controllers\CampaignController;
use App\Modules\Messaging\Http\Controllers\InboxController;
use App\Modules\Messaging\Http\Controllers\MessageLogController;
use App\Modules\Messaging\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:whatsapp.inbox')->group(function () {
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('/inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
    Route::post('/inbox/{conversation}/close', [InboxController::class, 'close'])->name('inbox.close');
    Route::get('/messages', MessageLogController::class)->name('messages.index');
});
Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply'])->middleware(['can:whatsapp.send', 'throttle:60,1'])->name('inbox.reply');
Route::post('/members/{member}/whatsapp', [InboxController::class, 'sendTemplate'])->middleware(['can:whatsapp.send', 'throttle:30,1'])->name('members.whatsapp');

Route::middleware('can:templates.manage')->group(function () {
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/automation', [TemplateController::class, 'automation'])->name('automation.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::resource('auto-replies', AutoReplyController::class)->except(['index', 'show', 'create', 'edit']);
});

Route::middleware('can:campaigns.manage')->group(function () {
    Route::get('/campaigns/audience', [CampaignController::class, 'audience'])->name('campaigns.audience');
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
    Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
});
