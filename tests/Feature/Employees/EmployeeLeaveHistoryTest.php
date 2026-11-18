<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveTallyService;
use Inertia\Testing\AssertableInertia;

/*
| Employee leave history (PROJECT_CONTEXT 5.6): every leave day of one
| employee across all years, read-only, open to both roles.
*/

function historyType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}

it('sends guests to the sign-in page', function (): void {
    $employee = Employee::factory()->create();

    $this->get(route('employees.leave', $employee))->assertRedirect(route('login'));
});

it('opens for both roles', function (Closure $user): void {
    $employee = Employee::factory()->create();

    $this->actingAs($user())
        ->get(route('employees.leave', $employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('employees/leave'));
})->with([
    'HR Admin' => fn () => hrAdmin(),
    'HR Staff' => fn () => hrStaff(),
]);

it('refuses an account with no role', function (): void {
    seedRolesAndPermissions();
    $employee = Employee::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('employees.leave', $employee))
        ->assertForbidden();
});

it('lists every year with leave, newest first, with totals per type and each day', function (): void {
    $admin = hrAdmin(['name' => 'Maria Encoder']);
    $employee = Employee::factory()->create();
    $vacation = historyType('VL');
    $sick = historyType('SL');

    LeaveRecord::factory()->for($employee)->for($vacation, 'leaveType')->on('2025-03-24')->create(['encoded_by' => $admin->id, 'remarks' => 'Form 2025-0012']);
    LeaveRecord::factory()->for($employee)->for($vacation, 'leaveType')->on('2025-03-25')->create(['encoded_by' => $admin->id]);
    LeaveRecord::factory()->for($employee)->for($sick, 'leaveType')->on('2026-08-04')->dayPart(DayPart::Pm)->create();

    $this->actingAs($admin)
        ->get(route('employees.leave', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.id', $employee->id)
            ->where('history.firstYear', 2025)
            ->where('history.totalDaysText', '2.5')
            ->has('history.years', 2)
            ->where('history.years.0.year', 2026)
            ->where('history.years.0.grandTotalText', '0.5')
            ->has('history.years.0.totals', 1)
            ->where('history.years.0.totals.0.code', 'SL')
            ->where('history.years.0.months.7.entries.0.text', '4 PM SL')
            ->where('history.years.0.days.0.dayPart', 'PM')
            ->where('history.years.1.year', 2025)
            ->where('history.years.1.totals.0.code', 'VL')
            ->where('history.years.1.totals.0.daysText', '2')
            ->where('history.years.1.months.2.entries.0.text', '24–25 VL')
            ->has('history.years.1.days', 2)
            ->where('history.years.1.days.0.date', '2025-03-24')
            ->where('history.years.1.days.0.remarks', 'Form 2025-0012')
            ->where('history.years.1.days.0.encodedBy', 'Maria Encoder')
            ->where('history.years.1.days.1.date', '2025-03-25')
        );
});

it('shows each year exactly as the tally does', function (): void {
    $employee = Employee::factory()->create();
    $type = historyType('MFL');

    foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-09', '2026-11-13'] as $date) {
        LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on($date)->create();
    }

    $tally = app(LeaveTallyService::class)->forEmployees([$employee->id], 2026)[$employee->id];

    $this->actingAs(hrStaff())
        ->get(route('employees.leave', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.years.0.months.2.entries.0.text', $tally['months'][2]['entries'][0]['text'])
            ->where('history.years.0.months.10.entries.0.text', $tally['months'][10]['entries'][0]['text'])
            ->where('history.years.0.grandTotalText', $tally['grandTotalText'])
        );
});

it('leaves out deleted days and other employees', function (): void {
    $employee = Employee::factory()->create();
    $other = Employee::factory()->create();
    $type = historyType('VL');

    LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-02')->create();
    LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-03')->create()->delete();
    LeaveRecord::factory()->for($other)->for($type, 'leaveType')->on('2026-03-04')->create();
    LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2024-05-06')->create()->delete();

    $this->actingAs(hrAdmin())
        ->get(route('employees.leave', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('history.years', 1)
            ->has('history.years.0.days', 1)
            ->where('history.years.0.days.0.date', '2026-03-02')
        );
});

it('keeps a retired leave type in the years it was used', function (): void {
    $employee = Employee::factory()->create();
    $wellness = historyType('WL');
    LeaveRecord::factory()->for($employee)->for($wellness, 'leaveType')->on('2025-06-02')->create();
    $wellness->update(['is_active' => false]);

    $this->actingAs(hrAdmin())
        ->get(route('employees.leave', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.years.0.totals.0.code', 'WL')
            ->where('history.years.0.days.0.code', 'WL')
        );
});

it('shows an employee with no leave yet as an empty history', function (): void {
    $employee = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->get(route('employees.leave', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('history.years', 0)
            ->where('history.firstYear', null)
            ->where('history.totalDays', 0)
        );
});

it('never sends the monthly rate, even to an HR Admin', function (): void {
    $employee = Employee::factory()->create(['monthly_rate' => '45000.00']);

    $this->actingAs(hrAdmin())
        ->get(route('employees.leave', $employee))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('employee.monthlyRate')
            ->where('can.viewCredits', true)
            ->where('can.editEmployee', true)
        );
});

it('does not open a deleted employee', function (): void {
    $employee = Employee::factory()->create();
    $employee->delete();

    $this->actingAs(hrAdmin())
        ->get(route('employees.leave', $employee))
        ->assertNotFound();
});
