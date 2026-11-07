<?php

use App\Modules\Messaging\Http\Controllers\AttachmentController;
use App\Modules\Messaging\Http\Controllers\AutoReplyController;
use App\Modules\Messaging\Http\Controllers\CampaignController;
use App\Modules\Messaging\Http\Controllers\InboxController;
use App\Modules\Messaging\Http\Controllers\MessageLogController;
use App\Modules\Messaging\Http\Controllers\ReportController;
use App\Modules\Messaging\Http\Controllers\TemplateController;
use App\Modules\Messaging\Http\Controllers\WhatsAppSettingsController;
use Illuminate\Support\Facades\Route;

// «واتساب»: the inbox for whoever sees chats (their own or every one); each conversation is checked again in the
// controller (ConversationAccess), so an address or an id typed by hand reaches nothing more.
Route::middleware('can:whatsapp.access')->prefix('whatsapp')->name('whatsapp.')->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');
    Route::get('/feed', [InboxController::class, 'feed'])->middleware('throttle:240,1')->name('feed');
    Route::get('/conversations/{conversation}', [InboxController::class, 'show'])->name('show');
    Route::post('/conversations/{conversation}/reply', [InboxController::class, 'reply'])->middleware('throttle:60,1')->name('reply');
    Route::post('/conversations/{conversation}/media', [InboxController::class, 'media'])->middleware('throttle:20,1')->name('media');
    Route::post('/conversations/{conversation}/template', [InboxController::class, 'template'])->middleware('throttle:20,1')->name('template');
    Route::post('/conversations/{conversation}/notes', [InboxController::class, 'note'])->middleware('throttle:60,1')->name('notes');
    Route::post('/conversations/{conversation}/assign', [InboxController::class, 'assign'])->middleware('throttle:60,1')->name('assign');
    Route::post('/conversations/{conversation}/status', [InboxController::class, 'status'])->middleware('throttle:60,1')->name('status');
    Route::post('/conversations/{conversation}/member', [InboxController::class, 'link'])->middleware('throttle:30,1')->name('link');
    Route::get('/attachments/{attachment}', AttachmentController::class)->name('attachments.show');
});

// The inbox's earlier addresses (bookmarks, notifications already in the bell) open the new one.
Route::get('/inbox', [InboxController::class, 'legacy'])->middleware('can:whatsapp.access')->name('inbox.index');
Route::get('/inbox/{conversation}', [InboxController::class, 'legacy'])->middleware('can:whatsapp.access')->name('inbox.show');

Route::post('/members/{member}/whatsapp', [InboxController::class, 'sendTemplate'])->middleware(['can:whatsapp.send', 'throttle:30,1'])->name('members.whatsapp');

Route::middleware('can:whatsapp.view_reports')->group(function () {
    Route::get('/whatsapp/reports', ReportController::class)->name('whatsapp.reports');
    Route::get('/messages', MessageLogController::class)->name('messages.index');
});

Route::middleware('can:whatsapp.manage_templates')->group(function () {
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
});

Route::middleware('can:whatsapp.manage_automation')->group(function () {
    Route::get('/automation', [TemplateController::class, 'automation'])->name('automation.index');
    Route::put('/automation', [TemplateController::class, 'saveAutomation'])->name('automation.update');
    Route::resource('auto-replies', AutoReplyController::class)->except(['index', 'show', 'create', 'edit']);
});

// The connection's settings beyond the keys (Integrations → WhatsApp): imports from the phone, the 360dialog token.
Route::middleware(['can:whatsapp.settings', 'throttle:10,1'])->prefix('whatsapp/settings')->name('whatsapp.settings.')->group(function () {
    Route::put('/imports', [WhatsAppSettingsController::class, 'imports'])->name('imports');
    Route::post('/sync', [WhatsAppSettingsController::class, 'sync'])->name('sync');
    Route::post('/token', [WhatsAppSettingsController::class, 'token'])->name('token');
});

Route::middleware('can:campaigns.manage')->group(function () {
    Route::get('/campaigns/audience', [CampaignController::class, 'audience'])->name('campaigns.audience');
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
    Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
});
