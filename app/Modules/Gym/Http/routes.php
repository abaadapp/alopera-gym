<?php

use App\Modules\Gym\Http\Controllers\BookingController;
use App\Modules\Gym\Http\Controllers\CheckInController;
use App\Modules\Gym\Http\Controllers\CouponController;
use App\Modules\Gym\Http\Controllers\DeviceController;
use App\Modules\Gym\Http\Controllers\MemberController;
use App\Modules\Gym\Http\Controllers\MemberCredentialController;
use App\Modules\Gym\Http\Controllers\MemberDocumentController;
use App\Modules\Gym\Http\Controllers\MemberNoteController;
use App\Modules\Gym\Http\Controllers\MembershipController;
use App\Modules\Gym\Http\Controllers\PlanController;
use App\Modules\Gym\Http\Controllers\ProgramController;
use App\Modules\Gym\Http\Controllers\PtController;
use App\Modules\Gym\Http\Controllers\PtPackageController;
use App\Modules\Gym\Http\Controllers\ReceptionController;
use App\Modules\Gym\Http\Controllers\ScheduleController;
use App\Modules\Gym\Http\Controllers\SessionController;
use App\Modules\Gym\Http\Controllers\StaffAttendanceController;
use App\Modules\Gym\Http\Controllers\TrainerController;
use App\Modules\Gym\Http\Controllers\TrainerPanelController;
use Illuminate\Support\Facades\Route;

// ─── Role home screens ─────────────────────────────────────────────────
Route::get('/reception', [ReceptionController::class, 'index'])->middleware('can:checkin.perform')->name('reception');
Route::get('/trainer', [TrainerPanelController::class, 'index'])->middleware('can:classes.view')->name('trainer');
Route::get('/trainer/sessions/{session}', [TrainerPanelController::class, 'session'])->middleware('can:classes.view')->name('trainer.session');

// ─── Members ───────────────────────────────────────────────────────────
Route::get('/members/search', [MemberController::class, 'search'])->middleware('can:members.view')->name('members.search');
Route::get('/members/export', [MemberController::class, 'export'])->middleware(['can:members.export', 'throttle:exports'])->name('members.export');
Route::get('/members', [MemberController::class, 'index'])->middleware('can:members.view')->name('members.index');
Route::get('/members/create', [MemberController::class, 'create'])->middleware('can:members.manage')->name('members.create');
Route::post('/members', [MemberController::class, 'store'])->middleware('can:members.manage')->name('members.store');
Route::get('/members/{member}', [MemberController::class, 'show'])->middleware('can:members.view')->name('members.show');
Route::get('/members/{member}/edit', [MemberController::class, 'edit'])->middleware('can:members.manage')->name('members.edit');
Route::put('/members/{member}', [MemberController::class, 'update'])->middleware('can:members.manage')->name('members.update');
Route::delete('/members/{member}', [MemberController::class, 'destroy'])->middleware('can:members.delete')->name('members.destroy');
Route::post('/members/{member}/portal-password', [MemberController::class, 'portalPassword'])->middleware('can:members.manage')->name('members.portal-password');
Route::post('/members/{member}/rotate-qr', [MemberController::class, 'rotateQr'])->middleware('can:members.manage')->name('members.rotate-qr');

Route::post('/members/{member}/documents', [MemberDocumentController::class, 'store'])->middleware('can:members.documents')->name('members.documents.store');
Route::get('/documents/{document}', [MemberDocumentController::class, 'download'])->middleware('can:members.documents')->name('documents.download');
Route::delete('/documents/{document}', [MemberDocumentController::class, 'destroy'])->middleware('can:members.documents')->name('documents.destroy');
Route::post('/members/{member}/notes', [MemberNoteController::class, 'store'])->middleware('can:members.manage')->name('members.notes.store');
Route::delete('/notes/{note}', [MemberNoteController::class, 'destroy'])->middleware('can:members.manage')->name('notes.destroy');
Route::post('/members/{member}/credentials', [MemberCredentialController::class, 'store'])->middleware('can:members.manage')->name('members.credentials.store');
Route::delete('/credentials/{credential}', [MemberCredentialController::class, 'destroy'])->middleware('can:members.manage')->name('credentials.destroy');

// ─── Memberships ───────────────────────────────────────────────────────
Route::get('/memberships', [MembershipController::class, 'index'])->middleware('can:memberships.view')->name('memberships.index');
Route::get('/memberships/quote', [MembershipController::class, 'quote'])->middleware('can:memberships.sell')->name('memberships.quote');
Route::post('/members/{member}/memberships', [MembershipController::class, 'store'])->middleware('can:memberships.sell')->name('memberships.store');
Route::post('/memberships/{membership}/freeze', [MembershipController::class, 'freeze'])->middleware('can:memberships.freeze')->name('memberships.freeze');
Route::post('/memberships/{membership}/resume', [MembershipController::class, 'resume'])->middleware('can:memberships.freeze')->name('memberships.resume');
Route::post('/memberships/{membership}/cancel', [MembershipController::class, 'cancel'])->middleware('can:memberships.cancel')->name('memberships.cancel');
Route::post('/memberships/{membership}/upgrade', [MembershipController::class, 'upgrade'])->middleware('can:memberships.upgrade')->name('memberships.upgrade');

Route::resource('plans', PlanController::class)->except(['show', 'create', 'edit'])->middleware('can:plans.manage');
Route::resource('coupons', CouponController::class)->except(['show', 'create', 'edit'])->middleware('can:plans.manage');

// ─── Training ──────────────────────────────────────────────────────────
Route::resource('programs', ProgramController::class)->except(['show', 'create', 'edit'])->middleware('can:classes.manage');
Route::resource('trainers', TrainerController::class)->except(['show', 'create', 'edit'])->middleware('can:trainers.manage');
Route::put('/trainers/{trainer}/availability', [TrainerController::class, 'availability'])->middleware('can:trainers.manage')->name('trainers.availability');

Route::get('/schedule', [ScheduleController::class, 'index'])->middleware('can:classes.view')->name('schedule.index');
Route::post('/schedule', [ScheduleController::class, 'store'])->middleware('can:classes.manage')->name('schedule.store');
Route::put('/schedule/{schedule}', [ScheduleController::class, 'update'])->middleware('can:classes.manage')->name('schedule.update');
Route::delete('/schedule/{schedule}', [ScheduleController::class, 'destroy'])->middleware('can:classes.manage')->name('schedule.destroy');

Route::get('/sessions', [SessionController::class, 'index'])->middleware('can:classes.view')->name('sessions.index');
Route::post('/sessions', [SessionController::class, 'store'])->middleware('can:classes.manage')->name('sessions.store');
Route::post('/sessions/generate', [SessionController::class, 'generate'])->middleware('can:classes.manage')->name('sessions.generate');
Route::get('/sessions/{session}', [SessionController::class, 'show'])->middleware('can:classes.view')->name('sessions.show');
Route::put('/sessions/{session}', [SessionController::class, 'update'])->middleware('can:classes.manage')->name('sessions.update');
Route::post('/sessions/{session}/cancel', [SessionController::class, 'cancel'])->middleware('can:classes.manage')->name('sessions.cancel');

Route::post('/sessions/{session}/bookings', [BookingController::class, 'store'])->middleware('can:bookings.manage')->name('bookings.store');
Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->middleware('can:bookings.manage')->name('bookings.cancel');
Route::post('/bookings/{booking}/attendance', [BookingController::class, 'attendance'])->middleware('can:attendance.mark')->name('bookings.attendance');

Route::resource('pt-packages', PtPackageController::class)->except(['show', 'create', 'edit'])->middleware('can:pt.manage');
Route::middleware('can:pt.manage')->group(function () {
    Route::get('/pt', [PtController::class, 'index'])->name('pt.index');
    Route::post('/pt/{subscription}/sessions', [PtController::class, 'schedule'])->name('pt.schedule');
});
// Selling a package is a permission of its own (the point of sale sells them too); scheduling stays with pt.manage.
Route::post('/members/{member}/pt', [PtController::class, 'store'])->middleware('can:pt.sell')->name('pt.store');
Route::post('/pt-sessions/{ptSession}/close', [PtController::class, 'close'])->middleware('can:attendance.mark')->name('pt.close');

// ─── Access ────────────────────────────────────────────────────────────
Route::get('/checkins', [CheckInController::class, 'index'])->middleware('can:checkin.view')->name('checkins.index');
Route::post('/checkins', [CheckInController::class, 'store'])->middleware(['can:checkin.perform', 'throttle:120,1'])->name('checkins.store');
// Access devices are one of the integrations: their screen lives under /integrations/devices (route names kept).
Route::prefix('integrations')->middleware('can:devices.manage')->group(function () {
    Route::post('/devices/staff-credentials', [DeviceController::class, 'storeStaffCredential'])->name('devices.staff-credentials');
    Route::delete('/devices/staff-credentials/{credential}', [DeviceController::class, 'destroyStaffCredential'])->name('devices.staff-credentials.destroy');
    Route::resource('devices', DeviceController::class)->except(['show', 'create', 'edit']);
    Route::post('/devices/{device}/token', [DeviceController::class, 'token'])->name('devices.token');
    Route::post('/devices/{device}/simulate', [DeviceController::class, 'simulate'])->middleware('throttle:60,1')->name('devices.simulate');
});
Route::redirect('/devices', '/app/integrations/devices');
Route::get('/staff-attendance', [StaffAttendanceController::class, 'index'])->middleware('can:staff_attendance.view')->name('staff-attendance.index');
Route::post('/staff-attendance/punch', [StaffAttendanceController::class, 'punch'])->name('staff-attendance.punch');
