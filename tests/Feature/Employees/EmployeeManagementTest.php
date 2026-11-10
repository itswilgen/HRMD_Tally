<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\Office;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * A valid payload. Tests override only the field they are about, so a rule
 * added later cannot quietly pass because every test omitted the field.
 *
 * @return array<string, mixed>
 */
function employeePayload(Office $office, array $overrides = []): array
{
    return [
        'employee_no' => '2026-00123',
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Pascual',
        'suffix' => 'Jr.',
        'position_title' => 'Administrative Aide IV',
        'salary_grade' => 4,
        'monthly_rate' => '23450.00',
        'office_id' => $office->id,
        'employment_status' => 'Permanent',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Who may reach the screens at all
|--------------------------------------------------------------------------
*/

it('sends guests to the sign-in page', function (): void {
    $this->get(route('employees.index'))->assertRedirect(route('login'));
});

it('lets HR Staff maintain the masterlist', function (string $method, string $route): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();

    $url = in_array($route, ['employees.index', 'employees.create'], true)
        ? route($route)
        : route($route, $employee);

    $this->actingAs($staff)->{$method}($url)->assertSuccessful();
})->with([
    ['get', 'employees.index'],
    ['get', 'employees.create'],
    ['get', 'employees.edit'],
]);

it('refuses to let HR Staff take an employee off the encoding list', function (string $route): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create(['is_active' => true]);

    $this->actingAs($staff)->patch(route($route, $employee))->assertForbidden();

    expect($employee->refresh()->is_active)->toBeTrue();
})->with(['employees.activate', 'employees.deactivate']);

it('has no route that erases an employee', function (): void {
    // Leave records point at employees, and the paper sheet was never thrown
    // away either. The masterlist's bulk action soft deletes (see the bulk
    // delete section below); nothing anywhere erases a row.
    expect(Route::has('employees.destroy'))->toBeFalse()
        ->and(Route::has('employees.force-delete'))->toBeFalse();

    $admin = hrAdmin();
    $employee = Employee::factory()->create();

    expect($admin->can('forceDelete', $employee))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Listing, searching and filtering
|--------------------------------------------------------------------------
*/

it('lists the employees currently being encoded for', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);

    // The middle name and suffix are pinned, not left to the factory: the
    // assertion below is about the list-name format, and a random middle
    // initial would make it pass or fail depending on the seed.
    Employee::factory()->inOffice($office)->create([
        'last_name' => 'Abad',
        'first_name' => 'Ana',
        'middle_name' => null,
        'suffix' => null,
    ]);
    Employee::factory()->inOffice($office)->inactive()->create(['last_name' => 'Zamora']);

    $this->actingAs($admin)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('employees/index')
            ->has('employees.data', 1)
            ->where('employees.data.0.listName', 'Abad, Ana')
            ->where('employees.data.0.officeName', 'City Treasurer')
            ->has('offices', 1)
        );
});

it('shows deactivated employees behind their own filter', function (string $status, int $expected): void {
    $admin = hrAdmin();

    Employee::factory()->create(['last_name' => 'Abad']);
    Employee::factory()->inactive()->create(['last_name' => 'Zamora']);

    $this->actingAs($admin)
        ->get(route('employees.index', ['status' => $status]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', $expected));
})->with([
    'active by default' => ['', 1],
    'deactivated only' => ['inactive', 1],
    'everyone' => ['all', 2],
]);

it('finds an employee by name, position or employee number', function (string $term): void {
    $admin = hrAdmin();

    Employee::factory()->create([
        'employee_no' => '2019-00456',
        'last_name' => 'Macaraeg',
        'first_name' => 'Rowena',
        'position_title' => 'Local Revenue Collection Clerk I',
    ]);
    Employee::factory()->create(['last_name' => 'Santos', 'first_name' => 'Pedro', 'position_title' => 'Driver II']);

    $this->actingAs($admin)
        ->get(route('employees.index', ['search' => $term]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees.data', 1)
            ->where('employees.data.0.lastName', 'Macaraeg')
        );
})->with([
    'part of a surname' => ['macar'],
    'a surname in capitals' => ['MACARAEG'],
    'a first name' => ['rowena'],
    'part of a position' => ['revenue'],
    'an employee number' => ['2019-004'],
]);

it('filters by letter tab and lists only the letters in use', function (): void {
    $admin = hrAdmin();

    Employee::factory()->create(['last_name' => 'abad']);
    Employee::factory()->create(['last_name' => 'Santos']);

    $this->actingAs($admin)
        ->get(route('employees.index', ['letter' => 'A']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Lower case in the database still answers the A tab: the scope
            // matches the lowercased index, as PostgreSQL requires.
            ->has('employees.data', 1)
            ->where('employees.data.0.lastName', 'abad')
            ->where('letters', ['A', 'S'])
        );
});

it('ignores a letter filter that is not a single letter', function (string $letter): void {
    $admin = hrAdmin();
    Employee::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('employees.index', ['letter' => $letter]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees.data', 2)
            ->where('filters.letter', '')
        );
})->with([
    'a word' => ['AB'],
    'a digit' => ['1'],
    'a wildcard' => ['%'],
]);

it('filters by office', function (): void {
    $admin = hrAdmin();
    $treasurer = Office::factory()->create(['name' => 'City Treasurer']);
    $engineering = Office::factory()->create(['name' => 'City Engineering']);

    Employee::factory()->inOffice($treasurer)->create(['last_name' => 'Abad']);
    Employee::factory()->inOffice($engineering)->create(['last_name' => 'Santos']);

    $this->actingAs($admin)
        ->get(route('employees.index', ['office' => $treasurer->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees.data', 1)
            ->where('employees.data.0.lastName', 'Abad')
            ->where('filters.office', (string) $treasurer->id)
        );
});

it('paginates the list', function (): void {
    $admin = hrAdmin();
    Employee::factory()->count(25)->create();

    $this->actingAs($admin)
        ->get(route('employees.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees.data', 20)
            ->where('employees.meta.total', 25)
            ->has('employees.links')
        );
});

it('counts the leave records an employee already has', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create();

    LeaveRecord::factory()->count(3)->create(['employee_id' => $employee->id]);

    $this->actingAs($admin)
        ->get(route('employees.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employees.data.0.leaveRecordsCount', 3)
        );
});

/**
 * The office and the leave-record count are both loaded for the whole page, so
 * twenty rows cost the same number of queries as one. `preventLazyLoading()`
 * catches a missing eager load by throwing; this catches a per-row subquery
 * that would not throw.
 */
it('reads the masterlist in a constant number of queries', function (): void {
    $admin = hrAdmin();
    Employee::factory()->create();

    $countQueries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->get(route('employees.index'))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // One request first, so the permission cache is warm and not counted.
    $countQueries();

    $withOneEmployee = $countQueries();

    Employee::factory()->count(15)->create();

    expect($countQueries())->toBe($withOneEmployee);
});

/*
|--------------------------------------------------------------------------
| The salary rate: HR Admin only (RA 10173)
|--------------------------------------------------------------------------
*/

it('sends the monthly rate to an HR Admin', function (): void {
    $admin = hrAdmin();
    Employee::factory()->create(['monthly_rate' => '23450.00']);

    $this->actingAs($admin)
        ->get(route('employees.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employees.data.0.monthlyRate', '23450.00')
        );
});

it('leaves the monthly rate out of the page entirely for HR Staff', function (string $route): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create(['monthly_rate' => '23450.00']);

    $response = $route === 'employees.index'
        ? $this->actingAs($staff)->get(route($route))
        : $this->actingAs($staff)->get(route($route, $employee));

    // Missing, not null: a key sent as null would still be absent from the
    // screen but present in the page source for anyone who looked.
    $response->assertInertia(fn (AssertableInertia $page) => $page->missing(
        $route === 'employees.index' ? 'employees.data.0.monthlyRate' : 'employee.monthlyRate',
    ));

    $response->assertDontSee('23450', escape: false);
})->with(['employees.index', 'employees.edit']);

it('ignores a monthly rate posted by HR Staff', function (): void {
    $staff = hrStaff();
    $office = Office::factory()->create();
    $employee = Employee::factory()->inOffice($office)->create(['monthly_rate' => '23450.00']);

    $this->actingAs($staff)
        ->patch(route('employees.update', $employee), employeePayload($office, [
            'employee_no' => $employee->employee_no,
            'monthly_rate' => '99999.00',
        ]))
        ->assertRedirect(route('employees.index'));

    // Not forbidden, just inert: the field was never on their form, so a
    // posted value is a forged one and the stored rate must not move.
    expect($employee->refresh()->monthly_rate)->toBe('23450.00');
});

/*
|--------------------------------------------------------------------------
| Employment status: free text, typed by an HR Admin only
|--------------------------------------------------------------------------
*/

it('starts an employment status blank and lets an HR Admin type one in', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    $this->actingAs($admin)
        ->post(route('employees.store'), employeePayload($office, ['employment_status' => '']))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->sole();

    expect($employee->employment_status)->toBeNull();

    $this->actingAs($admin)
        ->patch(route('employees.update', $employee), employeePayload($office, [
            'employment_status' => '  Co-terminous  ',
        ]))
        ->assertSessionHasNoErrors();

    expect($employee->refresh()->employment_status)->toBe('Co-terminous');
});

it('offers an HR Admin the statuses already in use, most used first', function (): void {
    $admin = hrAdmin();

    Employee::factory()->count(2)->create(['employment_status' => 'Job Order']);
    Employee::factory()->create(['employment_status' => 'Permanent']);
    Employee::factory()->create(['employment_status' => null]);

    $this->actingAs($admin)
        ->get(route('employees.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employmentStatuses', ['Job Order', 'Permanent'])
            ->where('can.setEmploymentStatus', true)
        );
});

it('shows HR Staff the employment status without a way to change it', function (): void {
    $staff = hrStaff();
    $office = Office::factory()->create();
    $employee = Employee::factory()->inOffice($office)->create(['employment_status' => 'Permanent']);

    $this->actingAs($staff)
        ->get(route('employees.edit', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.employmentStatus', 'Permanent')
            ->where('can.setEmploymentStatus', false)
            // No suggestions: there is no control for them to suggest into.
            ->where('employmentStatuses', [])
        );

    $this->actingAs($staff)
        ->patch(route('employees.update', $employee), employeePayload($office, [
            'employee_no' => $employee->employee_no,
            'employment_status' => 'Casual',
        ]))
        ->assertRedirect(route('employees.index'));

    expect($employee->refresh()->employment_status)->toBe('Permanent');
});

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

it('adds an employee to the masterlist, active and ready to encode for', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    $this->actingAs($admin)
        ->post(route('employees.store'), employeePayload($office))
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', 'Juan P. Dela Cruz Jr. has been added to the masterlist.');

    $employee = Employee::query()->sole();

    expect($employee->is_active)->toBeTrue()
        ->and($employee->employee_no)->toBe('2026-00123')
        ->and($employee->list_name)->toBe('Dela Cruz, Juan P. Jr.')
        ->and($employee->office_id)->toBe($office->id)
        ->and($employee->monthly_rate)->toBe('23450.00')
        ->and($employee->employment_status)->toBe('Permanent');
});

it('stores a blank middle name, suffix or salary grade as nothing at all', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    $this->actingAs($admin)
        ->post(route('employees.store'), employeePayload($office, [
            'middle_name' => '  ',
            'suffix' => '',
            'salary_grade' => '',
            'monthly_rate' => '',
        ]))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->sole();

    expect($employee->middle_name)->toBeNull()
        ->and($employee->suffix)->toBeNull()
        ->and($employee->salary_grade)->toBeNull()
        ->and($employee->monthly_rate)->toBeNull()
        // No middle initial to print, and the listing name must not gain a
        // double space because of it.
        ->and($employee->list_name)->toBe('Dela Cruz, Juan');
});

it('trims a name typed with stray spaces', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    $this->actingAs($admin)
        ->post(route('employees.store'), employeePayload($office, [
            'last_name' => '  Dela Cruz ',
            'first_name' => ' Juan ',
        ]))
        ->assertSessionHasNoErrors();

    expect(Employee::query()->sole()->last_name)->toBe('Dela Cruz');
});

it('saves an employee who has no office yet', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    // Which is how every imported employee arrives: HR's plantilla sheet names
    // no office, so the record is completed here rather than guessed at import.
    $this->actingAs($admin)
        ->post(route('employees.store'), employeePayload($office, ['office_id' => '']))
        ->assertSessionHasNoErrors();

    expect(Employee::query()->sole()->office_id)->toBeNull();
});

it('refuses an employee record that is missing or out of range', function (array $payload, string $field): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    $this->actingAs($admin)
        ->from(route('employees.create'))
        ->post(route('employees.store'), employeePayload($office, $payload))
        ->assertSessionHasErrors($field);

    expect(Employee::query()->count())->toBe(0);
})->with([
    'no employee number' => [['employee_no' => ''], 'employee_no'],
    'no last name' => [['last_name' => ''], 'last_name'],
    'no first name' => [['first_name' => ''], 'first_name'],
    'no position' => [['position_title' => ''], 'position_title'],
    'an office that does not exist' => [['office_id' => 9999], 'office_id'],
    'salary grade zero' => [['salary_grade' => 0], 'salary_grade'],
    'salary grade above 33' => [['salary_grade' => 34], 'salary_grade'],
    'a salary grade that is not a number' => [['salary_grade' => 'four'], 'salary_grade'],
    'a negative monthly rate' => [['monthly_rate' => -1], 'monthly_rate'],
    'an employment status over 60 characters' => [['employment_status' => str_repeat('a', 61)], 'employment_status'],
]);

it('refuses an employee number another employee already has', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();
    Employee::factory()->create(['employee_no' => '2026-00123']);

    $this->actingAs($admin)
        ->from(route('employees.create'))
        ->post(route('employees.store'), employeePayload($office))
        ->assertSessionHasErrors(['employee_no' => 'Another employee already has this employee number.']);
});

it('keeps an employee number reserved after the record is removed', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();

    // Soft-deleted, not gone: their leave records still point at that number,
    // so giving it to someone new would merge two people's histories.
    Employee::factory()->create(['employee_no' => '2026-00123'])->delete();

    $this->actingAs($admin)
        ->from(route('employees.create'))
        ->post(route('employees.store'), employeePayload($office))
        ->assertSessionHasErrors('employee_no');
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('shows the edit form with the record and the policy decisions', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    $employee = Employee::factory()->inOffice($office)->create(['last_name' => 'Macaraeg']);

    $this->actingAs($admin)
        ->get(route('employees.edit', $employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('employees/edit')
            ->where('employee.lastName', 'Macaraeg')
            ->where('employee.officeId', $office->id)
            ->where('employee.officeName', 'City Treasurer')
            ->where('can.viewSalary', true)
            ->where('can.deactivate', true)
        );
});

it('updates a record and moves an employee to another office', function (): void {
    $admin = hrAdmin();
    $from = Office::factory()->create();
    $to = Office::factory()->create();
    $employee = Employee::factory()->inOffice($from)->create(['employee_no' => '2026-00123']);

    $this->actingAs($admin)
        ->patch(route('employees.update', $employee), employeePayload($to, [
            'position_title' => 'Administrative Officer II',
            'salary_grade' => 15,
        ]))
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', "Juan P. Dela Cruz Jr.'s record has been updated.");

    $employee->refresh();

    expect($employee->office_id)->toBe($to->id)
        ->and($employee->position_title)->toBe('Administrative Officer II')
        ->and($employee->salary_grade)->toBe(15);
});

it('lets an employee keep their own employee number on update', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();
    $employee = Employee::factory()->inOffice($office)->create(['employee_no' => '2026-00123']);

    $this->actingAs($admin)
        ->patch(route('employees.update', $employee), employeePayload($office))
        ->assertSessionHasNoErrors();
});

it('refuses an employee number that belongs to someone else', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create();
    Employee::factory()->create(['employee_no' => '2026-00123']);
    $employee = Employee::factory()->inOffice($office)->create(['employee_no' => '2026-00999']);

    $this->actingAs($admin)
        ->from(route('employees.edit', $employee))
        ->patch(route('employees.update', $employee), employeePayload($office))
        ->assertSessionHasErrors('employee_no');

    expect($employee->refresh()->employee_no)->toBe('2026-00999');
});

/*
|--------------------------------------------------------------------------
| Deactivating and reactivating
|--------------------------------------------------------------------------
*/

it('takes an employee off the encoding list and puts them back', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create(['last_name' => 'Macaraeg', 'first_name' => 'Rowena', 'middle_name' => null, 'suffix' => null]);

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->patch(route('employees.deactivate', $employee))
        ->assertRedirect(route('employees.index'))
        ->assertSessionHas('success', 'Rowena Macaraeg has been deactivated. Their past leave records are unchanged.');

    expect($employee->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->patch(route('employees.activate', $employee))
        ->assertSessionHas('success', 'Rowena Macaraeg is on the encoding list again.');

    expect($employee->refresh()->is_active)->toBeTrue();
});

it('leaves the leave records of a deactivated employee alone', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create();
    LeaveRecord::factory()->count(2)->create(['employee_id' => $employee->id]);

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->patch(route('employees.deactivate', $employee))
        ->assertSessionHasNoErrors();

    // The whole point of deactivating instead of deleting: last year's tally
    // sheet has to keep printing the same numbers.
    expect($employee->leaveRecords()->count())->toBe(2)
        ->and(Employee::query()->whereKey($employee->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Bulk delete from the masterlist
|--------------------------------------------------------------------------
|
| The tick-boxes on the employees list. "Delete" is a soft delete for a record
| created by mistake; anyone with leave on file is kept, because their days are
| printed on tally sheets that have to keep reading correctly.
*/

it('soft deletes the ticked employees without erasing them', function (): void {
    $admin = hrAdmin();
    $abad = Employee::factory()->create(['last_name' => 'Abad', 'first_name' => 'Maria']);
    $santos = Employee::factory()->create(['last_name' => 'Santos', 'first_name' => 'Pedro']);
    $untouched = Employee::factory()->create(['last_name' => 'Zamora']);

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => [$abad->id, $santos->id]])
        ->assertRedirect(route('employees.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '2 employees removed from the masterlist. An HR Admin can restore them.');

    // Gone from every list the app builds...
    expect(Employee::query()->whereKey([$abad->id, $santos->id])->count())->toBe(0)
        // ...but still in the table, which is what makes it recoverable.
        ->and(Employee::withTrashed()->whereKey([$abad->id, $santos->id])->count())->toBe(2)
        ->and(Employee::withTrashed()->find($abad->id)?->deleted_at)->not->toBeNull()
        ->and(Employee::query()->whereKey($untouched->id)->exists())->toBeTrue();
});

it('keeps an employee who already has leave on file and deletes the rest', function (): void {
    $admin = hrAdmin();
    $mistake = Employee::factory()->create(['last_name' => 'Duplicado', 'first_name' => 'Juan']);
    // Middle name and suffix pinned, so the name in the message is the whole
    // list_name and not whatever the factory happened to invent.
    $encoded = Employee::factory()->create([
        'last_name' => 'Reyes',
        'first_name' => 'Ana',
        'middle_name' => null,
        'suffix' => null,
    ]);
    LeaveRecord::factory()->count(2)->create(['employee_id' => $encoded->id]);

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => [$mistake->id, $encoded->id]])
        ->assertSessionHasNoErrors()
        // Both halves are reported: what went, and who was kept and why.
        ->assertSessionHas('success', '1 employee removed from the masterlist. An HR Admin can restore the record.')
        ->assertSessionHas(
            'error',
            '1 of the ticked employees has leave on file and was kept: Reyes, Ana. Deactivate them instead, so the tally sheets they appear on keep reading correctly.',
        );

    expect(Employee::query()->whereKey($mistake->id)->exists())->toBeFalse()
        ->and(Employee::query()->whereKey($encoded->id)->exists())->toBeTrue()
        ->and($encoded->leaveRecords()->count())->toBe(2);
});

it('deletes nothing when every ticked employee has leave on file', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create(['last_name' => 'Macaraeg', 'first_name' => 'Rowena']);
    LeaveRecord::factory()->create(['employee_id' => $employee->id]);

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => [$employee->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('success');

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeTrue();
});

it('refuses to let HR Staff delete from the masterlist', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();

    $this->actingAs($staff)
        ->delete(route('employees.bulk-destroy'), ['ids' => [$employee->id]])
        ->assertForbidden();

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeTrue();
});

it('sends guests asking to delete to the sign-in page', function (): void {
    $employee = Employee::factory()->create();

    $this->delete(route('employees.bulk-destroy'), ['ids' => [$employee->id]])->assertRedirect(route('login'));

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeTrue();
});

it('rejects a delete payload that is empty, malformed or already deleted', function (array $ids, string $key): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => $ids])
        ->assertSessionHasErrors($key);
})->with([
    // An empty selection fails the array itself; a bad entry fails that entry.
    'nothing ticked' => [[], 'ids'],
    'not an id' => [['abc'], 'ids.0'],
    'no such employee' => [[987654], 'ids.0'],
]);

it('rejects a second delete of the same employee rather than deleting twice', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create();

    $this->actingAs($admin)->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => [$employee->id]])
        ->assertSessionHasNoErrors();

    // The exists rule is scoped to whereNull('deleted_at'), so a dialog that
    // was submitted twice is told the row is gone instead of silently passing.
    $this->actingAs($admin)->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => [$employee->id]])
        ->assertSessionHasErrors('ids.0');
});

it('refuses more than a hundred employees in one delete', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => range(1, 101)])
        ->assertSessionHasErrors('ids');
});

it('records the bulk delete in the activity log with the names', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create(['last_name' => 'Duplicado', 'first_name' => 'Juan']);

    $this->actingAs($admin)
        ->from(route('employees.index'))
        ->delete(route('employees.bulk-destroy'), ['ids' => [$employee->id]])
        ->assertSessionHasNoErrors();

    $activity = DB::table('activity_log')->where('event', 'deleted')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('employee')
        ->and($activity->causer_id)->toBe($admin->id)
        // The name has to be in the log: once the row is soft deleted this is
        // the only place the activity page can read who it was.
        ->and($activity->properties)->toContain('Duplicado, Juan');
});

it('hides the tick-boxes from a role that may not delete', function (): void {
    // The column is rendered from auth.user.can.deactivateEmployees, so the
    // permission being absent is what keeps the control off the screen.
    expect(hrStaff()->can('deleteMany', Employee::class))->toBeFalse()
        ->and(hrAdmin()->can('deleteMany', Employee::class))->toBeTrue();
});
