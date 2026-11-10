<?php

declare(strict_types=1);

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
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
     * decides, and deactivating is HR Admin only.
     *
     * Someone who leaves the city government is deactivated, never deleted, so
     * their past tallies still read correctly — which is what the paper sheet
     * did too. bulk-destroy is for the other case: a record created by mistake,
     * a duplicate, a row imported twice. It soft deletes and it refuses anyone
     * who already has leave on file.
     *
     * erase is the one route that destroys a record, and it is reachable only
     * from the deleted records list: an id that is still on the live masterlist
     * fails validation, so erasing is always a second decision about a row
     * someone already deleted. It needs employees.erase, which is its own
     * permission, and it refuses leave on file as well. restore is the other
     * half of that screen and the reason deleting is safe to offer.
     */
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('create', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        // Before the {employee} routes, so "bulk" is never read as an id.
        Route::delete('bulk', [EmployeeController::class, 'destroyMany'])->name('bulk-destroy');
        Route::delete('erase', [EmployeeController::class, 'eraseMany'])->name('erase');
        Route::get('import/template', [EmployeeImportController::class, 'template'])->name('import.template');
        Route::post('import/preview', [EmployeeImportController::class, 'preview'])->name('import.preview');
        Route::post('import', [EmployeeImportController::class, 'store'])->name('import.store');
        Route::get('{employee}/edit', [EmployeeController::class, 'edit'])->name('edit');
        Route::patch('{employee}', [EmployeeController::class, 'update'])->name('update');
        Route::patch('{employee}/activate', [EmployeeController::class, 'activate'])->name('activate');
        Route::patch('{employee}/deactivate', [EmployeeController::class, 'deactivate'])->name('deactivate');
        // withTrashed, or the binding would 404 on the only kind of employee
        // this route is ever asked about.
        Route::patch('{employee}/restore', [EmployeeController::class, 'restore'])->name('restore')->withTrashed();
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
