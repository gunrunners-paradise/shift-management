<?php

use App\Http\Controllers\Admin\BlockedDateController;
use App\Http\Controllers\Admin\CalendarEventController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftCalendarController;
use App\Http\Controllers\TodayInformationController;
use App\Http\Controllers\WorkerReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::get('/avatars/{user}', [AvatarController::class, 'show'])->whereNumber('user')->name('avatars.show');

    Route::middleware('role:worker')->group(function (): void {
        Route::get('/worker', [ShiftCalendarController::class, 'index'])->name('worker.home');
        Route::get('/worker/today', TodayInformationController::class)->name('worker.today');
        Route::put('/worker/receipts', [WorkerReceiptController::class, 'update'])->name('worker.receipts.update');
        Route::post('/worker/shifts', [ShiftCalendarController::class, 'store'])->name('worker.shifts.store');
        Route::delete('/worker/shifts/{date}', [ShiftCalendarController::class, 'destroy'])->name('worker.shifts.destroy');
    });

    Route::middleware('role:manager')->group(function (): void {
        Route::get('/admin', [UserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('admin.users.deactivate');
        Route::post('/admin/blocks', [BlockedDateController::class, 'store'])->name('admin.blocks.store');
        Route::delete('/admin/blocks/{date}', [BlockedDateController::class, 'destroy'])->name('admin.blocks.destroy');
        Route::post('/admin/events', [CalendarEventController::class, 'store'])->name('admin.events.store');
        Route::patch('/admin/events/{calendarEvent}', [CalendarEventController::class, 'update'])->whereNumber('calendarEvent')->name('admin.events.update');
        Route::delete('/admin/events/{calendarEvent}', [CalendarEventController::class, 'destroy'])->whereNumber('calendarEvent')->name('admin.events.destroy');
    });
});
