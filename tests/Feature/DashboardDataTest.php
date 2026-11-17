<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/**
 * The dashboard: today's facts, read from the records.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-12 09:00:00'));
});

function dashboardLeave(Employee $employee, LeaveType $type, string $date, DayPart $dayPart = DayPart::Whole): LeaveRecord
{
    return LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on($date)->dayPart($dayPart)->create();
}

it('lists who is on leave today and counts this month the way the sheet does', function (): void {
    $vl = LeaveType::factory()->create(['code' => 'VL']);
    $out = Employee::factory()->create(['last_name' => 'Abalos', 'first_name' => 'Maria', 'middle_name' => null, 'suffix' => null]);
    $half = Employee::factory()->create();
    $other = Employee::factory()->create();

    dashboardLeave($out, $vl, '2026-11-12');
    dashboardLeave($half, $vl, '2026-11-12', DayPart::Pm);
    dashboardLeave($other, $vl, '2026-11-03');
    // Outside the month: not counted.
    dashboardLeave($other, $vl, '2026-10-30');

    $this->actingAs(hrStaff())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('today', '2026-11-12')
            ->where('onLeaveToday.total', 2)
            ->has('onLeaveToday.people', 2)
            ->where('onLeaveToday.people.0.name', 'Abalos, Maria')
            ->where('onLeaveToday.people.1.dayPart', 'PM')
            ->where('facts.monthDaysText', '2.5')
            ->has('recent', 4));
});

it('keeps employee names off the dashboard for an account without the tally', function (): void {
    $vl = LeaveType::factory()->create();
    dashboardLeave(Employee::factory()->create(), $vl, '2026-11-12');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('onLeaveToday', null)
            ->where('recent', null)
            ->where('facts.monthDaysText', null)
            ->where('facts.activeEmployees', 1));
});

it('says nought rather than nothing when the month has no leave', function (): void {
    $this->actingAs(hrAdmin())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('facts.monthDaysText', '0')
            ->where('onLeaveToday.total', 0)
            ->where('recent', []));
});
