<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;

it('casts leave_date to a date and day_part to the enum', function (): void {
    $record = LeaveRecord::factory()->create([
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Pm,
    ]);

    expect($record->fresh()->leave_date)->toBeInstanceOf(Carbon::class)
        ->and($record->fresh()->day_part)->toBe(DayPart::Pm);
});

it('reports the leave days it consumes', function (): void {
    $whole = LeaveRecord::factory()->dayPart(DayPart::Whole)->create();
    $half = LeaveRecord::factory()->dayPart(DayPart::Am)->create();

    expect($whole->days())->toBe(1.0)
        ->and($half->days())->toBe(0.5);
});

it('scopes to a year without crossing the boundary', function (): void {
    $employee = Employee::factory()->create();
    $leaveType = LeaveType::factory()->create();

    foreach (['2025-12-31', '2026-01-01', '2026-12-31', '2027-01-01'] as $date) {
        LeaveRecord::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'leave_date' => $date,
            'day_part' => DayPart::Whole,
        ]);
    }

    expect(LeaveRecord::query()->forYear(2026)->pluck('leave_date')
        ->map(fn (Carbon $date): string => $date->toDateString())
        ->all())
        ->toBe(['2026-01-01', '2026-12-31']);
});

it('filters the year by date range so the index can be used', function (): void {
    // Guards the performance rule: YEAR(leave_date) would discard the
    // (employee_id, leave_date) index and force a full scan.
    $sql = mb_strtolower(LeaveRecord::query()->forYear(2026)->toSql());

    expect($sql)->toContain('between')
        ->and($sql)->not->toContain('year(')
        ->and($sql)->not->toContain('extract(');
});

it('belongs to an employee, a leave type and an encoder', function (): void {
    $user = User::factory()->create();

    $record = LeaveRecord::factory()->create(['encoded_by' => $user->id]);

    expect($record->employee)->toBeInstanceOf(Employee::class)
        ->and($record->leaveType)->toBeInstanceOf(LeaveType::class)
        ->and($record->encoder->id)->toBe($user->id);
});

it('keeps the record when the encoder is deleted', function (): void {
    $user = User::factory()->create();
    $record = LeaveRecord::factory()->create(['encoded_by' => $user->id]);

    $user->delete();

    expect($record->fresh())->not->toBeNull()
        ->and($record->fresh()->encoded_by)->toBeNull();
});

it('soft deletes rather than removing the row', function (): void {
    $record = LeaveRecord::factory()->create();

    $record->delete();

    expect(LeaveRecord::query()->count())->toBe(0)
        ->and(LeaveRecord::withTrashed()->count())->toBe(1);
});
