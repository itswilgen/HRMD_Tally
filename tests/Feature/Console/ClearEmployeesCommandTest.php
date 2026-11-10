<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * Emptying the masterlist from the console.
 *
 * The command exists to do what the screens refuse to, so the tests are mostly
 * about the two things that make it safe: it does not run without the typed
 * confirmation, and it leaves the reference data alone.
 */
const CONFIRM_QUESTION = 'Type DELETE to remove these records permanently';

/**
 * An employee with leave on file, a soft-deleted employee and a soft-deleted
 * leave record — the three shapes the foreign key and the UI trip on.
 *
 * @return array{employees: int, leaveRecords: int}
 */
function seedRecordsToClear(): array
{
    $withLeave = Employee::factory()->create();

    // Dates pinned, not left to the factory: the unique index on
    // (employee_id, leave_date, day_part) makes random dates for the same
    // employee an intermittent collision.
    LeaveRecord::factory()->create(['employee_id' => $withLeave->id, 'leave_date' => '2026-03-10']);
    LeaveRecord::factory()->create(['employee_id' => $withLeave->id, 'leave_date' => '2026-03-11']);

    $trashedLeave = LeaveRecord::factory()->create(['employee_id' => $withLeave->id, 'leave_date' => '2026-03-12']);
    $trashedLeave->delete();

    Employee::factory()->create();

    $trashed = Employee::factory()->create();
    $trashed->delete();

    return ['employees' => 3, 'leaveRecords' => 3];
}

it('clears every employee and leave record, including the deleted ones', function (): void {
    $counts = seedRecordsToClear();

    $this->artisan('hrmd:clear-employees')
        ->expectsQuestion(CONFIRM_QUESTION, 'DELETE')
        ->assertSuccessful();

    // withTrashed is the assertion that matters: a soft delete would pass a
    // plain count, and the foreign key cannot see deleted_at either.
    expect(Employee::withTrashed()->count())->toBe(0)
        ->and(LeaveRecord::withTrashed()->count())->toBe(0)
        ->and($counts['employees'])->toBe(3);
});

it('keeps the offices, leave types, holidays and user accounts', function (): void {
    seedRecordsToClear();
    Office::factory()->count(2)->create();
    LeaveType::factory()->create(['code' => 'VL']);
    Holiday::factory()->create();
    $users = User::query()->count();

    $this->artisan('hrmd:clear-employees')
        ->expectsQuestion(CONFIRM_QUESTION, 'DELETE')
        ->assertSuccessful();

    // The reference data HR maintains is not sample noise, and an employee's
    // office is a foreign key into it — clearing the masterlist must not take
    // the lookups with it.
    expect(Office::query()->count())->toBeGreaterThan(0)
        ->and(LeaveType::query()->count())->toBeGreaterThan(0)
        ->and(Holiday::query()->count())->toBeGreaterThan(0)
        ->and(User::query()->count())->toBe($users);
});

it('removes nothing when the confirmation is not typed', function (): void {
    seedRecordsToClear();

    $this->artisan('hrmd:clear-employees')
        ->expectsQuestion(CONFIRM_QUESTION, 'yes')
        ->assertFailed();

    expect(Employee::withTrashed()->count())->toBe(3)
        ->and(LeaveRecord::withTrashed()->count())->toBe(3)
        ->and(Activity::query()->where('event', 'cleared')->exists())->toBeFalse();
});

it('removes nothing when the prompt is dismissed', function (): void {
    seedRecordsToClear();

    $this->artisan('hrmd:clear-employees')
        ->expectsQuestion(CONFIRM_QUESTION, null)
        ->assertFailed();

    expect(Employee::withTrashed()->count())->toBe(3);
});

it('accepts the confirmation whatever case it is typed in', function (): void {
    seedRecordsToClear();

    $this->artisan('hrmd:clear-employees')
        ->expectsQuestion(CONFIRM_QUESTION, ' delete ')
        ->assertSuccessful();

    expect(Employee::withTrashed()->count())->toBe(0);
});

it('skips the prompt entirely with --force', function (): void {
    seedRecordsToClear();

    // No expectsQuestion: asking would fail the test, which is the assertion.
    $this->artisan('hrmd:clear-employees', ['--force' => true])->assertSuccessful();

    expect(Employee::withTrashed()->count())->toBe(0)
        ->and(LeaveRecord::withTrashed()->count())->toBe(0);
});

it('records the clear in the activity log with both counts', function (): void {
    seedRecordsToClear();

    $this->artisan('hrmd:clear-employees', ['--force' => true])->assertSuccessful();

    $activity = Activity::query()->where('event', 'cleared')->sole();

    // Once the rows are gone this entry is the only record that the masterlist
    // was ever cleared, so it carries the counts and names no causer, because a
    // console run has no signed-in user.
    expect($activity->causer_id)->toBeNull()
        ->and($activity->properties['employees'])->toBe(3)
        ->and($activity->properties['leaveRecords'])->toBe(3)
        ->and($activity->description)->toBe(
            'Cleared the masterlist from the console: 3 employee records and 3 leave records removed'
        );
});

it('says so and asks nothing when the masterlist is already empty', function (): void {
    $this->artisan('hrmd:clear-employees')
        ->expectsOutputToContain('The masterlist is already empty.')
        ->assertSuccessful();

    expect(Activity::query()->where('event', 'cleared')->exists())->toBeFalse();
});

it('leaves an app that still renders with an empty masterlist', function (): void {
    seedRecordsToClear();
    $admin = hrAdmin();

    $this->artisan('hrmd:clear-employees', ['--force' => true])->assertSuccessful();

    // The state the office server starts in on its first day, and the one the
    // tally and the dashboard are least likely to have been tried against:
    // every page has to render an empty masterlist rather than fall over on a
    // missing employee.
    $this->actingAs($admin)->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->count('employees.data', 0));

    $this->actingAs($admin)->get(route('employees.index', ['status' => 'deleted']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->count('employees.data', 0));

    $this->actingAs($admin)->get(route('employees.create'))->assertOk();
    $this->actingAs($admin)->get(route('tally.index'))->assertOk();
    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
});

it('warns against seeding again afterwards', function (): void {
    seedRecordsToClear();

    // EmployeeSeeder skips only when the table is not empty, so seeding after a
    // clear puts every fictional employee straight back. The warning is the
    // only thing standing between an HR Admin and that surprise.
    $this->artisan('hrmd:clear-employees', ['--force' => true])
        ->expectsOutputToContain('EmployeeSeeder would recreate the sample employees')
        ->assertSuccessful();
});
