<?php

declare(strict_types=1);

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveRecordController;
use App\Http\Controllers\TallyController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * There is no public landing page: this is an internal HRMD tool, so the root
 * URL just puts you where you were going — the tally sheet if you are signed
 * in, the sign-in screen if you are not.
 */
Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    /*
     * The yearly tally sheet and the leave behind it. Both roles encode, so
     * LeaveRecordPolicy — not the route list — is what separates them; see
     * App\Policies\LeaveRecordPolicy.
     *
     * There is no destroy-forever route: removing a day soft deletes it, so a
     * mistake is recoverable and the log keeps who did it (business rule 9).
     */
    Route::get('tally', [TallyController::class, 'index'])->name('tally.index');

    Route::prefix('leave')->name('leave.')->group(function () {
        Route::post('preview', [LeaveRecordController::class, 'preview'])->name('preview');
        Route::post('/', [LeaveRecordController::class, 'store'])->name('store');
        Route::patch('{leave_record}', [LeaveRecordController::class, 'update'])->name('update');
        Route::delete('{leave_record}', [LeaveRecordController::class, 'destroy'])->name('destroy');
    });

    /*
     * The employee masterlist. Both roles maintain it; EmployeePolicy is what
     * decides, and deactivating is HR Admin only. There is no destroy route:
     * an employee who leaves is deactivated so their past tallies still read
     * correctly, which is what the paper sheet did too.
     */
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('create', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('{employee}/edit', [EmployeeController::class, 'edit'])->name('edit');
        Route::patch('{employee}', [EmployeeController::class, 'update'])->name('update');
        Route::patch('{employee}/activate', [EmployeeController::class, 'activate'])->name('activate');
        Route::patch('{employee}/deactivate', [EmployeeController::class, 'deactivate'])->name('deactivate');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
