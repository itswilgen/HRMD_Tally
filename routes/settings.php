<?php

declare(strict_types=1);

use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\SignatoryController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    /*
     * No self-delete route. The starter kit lets a user delete their own
     * account from this page, which would let the last HR Admin lock the
     * office out of the system — exactly what UserPolicy exists to prevent.
     * Accounts are removed under Settings → User accounts instead.
     */
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    /*
     * Offices. Everyone may read the list, because the tally sheet is filtered
     * by office; only an HR Admin may add, rename or remove one, which is the
     * settings.manage row of the matrix. OfficePolicy enforces both.
     */
    Route::prefix('settings/offices')->name('offices.')->group(function () {
        Route::get('/', [OfficeController::class, 'index'])->name('index');
        Route::post('/', [OfficeController::class, 'store'])->name('store');
        Route::patch('{office}', [OfficeController::class, 'update'])->name('update');
        Route::delete('{office}', [OfficeController::class, 'destroy'])->name('destroy');
    });

    /*
     * Leave types (business rule 8). Same shape as offices: everyone may read
     * the codes, only an HR Admin changes them. LeaveTypePolicy enforces both,
     * including that a code already on records is retired, never deleted.
     * The order route comes before {leaveType} so "order" is never taken for
     * an id.
     */
    Route::prefix('settings/leave-types')->name('leave-types.')->group(function () {
        Route::get('/', [LeaveTypeController::class, 'index'])->name('index');
        Route::post('/', [LeaveTypeController::class, 'store'])->name('store');
        Route::put('order', [LeaveTypeController::class, 'reorder'])->name('reorder');
        Route::patch('{leaveType}', [LeaveTypeController::class, 'update'])->name('update');
        Route::patch('{leaveType}/retire', [LeaveTypeController::class, 'retire'])->name('retire');
        Route::patch('{leaveType}/restore', [LeaveTypeController::class, 'restore'])->name('restore');
        Route::delete('{leaveType}', [LeaveTypeController::class, 'destroy'])->name('destroy');
    });

    /*
     * Holidays (section 5.3), one year at a time via ?year=. Encoding reads
     * this table on every request, so a change here counts at once.
     * HolidayPolicy keeps changes to an HR Admin.
     */
    Route::prefix('settings/holidays')->name('holidays.')->group(function () {
        Route::get('/', [HolidayController::class, 'index'])->name('index');
        Route::post('/', [HolidayController::class, 'store'])->name('store');
        Route::patch('{holiday}', [HolidayController::class, 'update'])->name('update');
        Route::delete('{holiday}', [HolidayController::class, 'destroy'])->name('destroy');
    });

    /*
     * Signatories (section 5.7): who signs the printed tally, left to right.
     * SignatoryPolicy keeps changes to an HR Admin.
     */
    Route::prefix('settings/signatories')->name('signatories.')->group(function () {
        Route::get('/', [SignatoryController::class, 'index'])->name('index');
        Route::post('/', [SignatoryController::class, 'store'])->name('store');
        Route::patch('{signatory}', [SignatoryController::class, 'update'])->name('update');
        Route::patch('{signatory}/move', [SignatoryController::class, 'move'])->name('move');
        Route::delete('{signatory}', [SignatoryController::class, 'destroy'])->name('destroy');
    });

    /*
     * User accounts. HR Admin only — UserPolicy is what enforces that, on every
     * action, including the ones that would leave the office with no
     * administrator able to sign in.
     */
    Route::prefix('settings/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::patch('{user}', [UserController::class, 'update'])->name('update');
        Route::patch('{user}/activate', [UserController::class, 'activate'])->name('activate');
        Route::patch('{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
        Route::delete('{user}', [UserController::class, 'destroy'])->name('destroy');
    });
});
