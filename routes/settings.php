<?php

declare(strict_types=1);

use App\Http\Controllers\OfficeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
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
