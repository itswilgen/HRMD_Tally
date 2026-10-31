<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->employee = Employee::factory()->create();
    $this->leaveType = LeaveType::factory()->create(['code' => 'SL']);
});

it('blocks a second record for the same employee, date and day part', function (): void {
    LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Whole,
    ]);

    expect(fn (): LeaveRecord => LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Whole,
    ]))->toThrow(QueryException::class);
});

it('blocks a duplicate even when the leave type differs', function (): void {
    // The unique key deliberately excludes leave_type_id: an employee cannot be
    // on two kinds of leave for the same half of the same day.
    $other = LeaveType::factory()->create(['code' => 'VL']);

    LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Am,
    ]);

    expect(fn (): LeaveRecord => LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $other->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Am,
    ]))->toThrow(QueryException::class);
});

it('allows AM and PM of the same date', function (): void {
    LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Am,
    ]);

    LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Pm,
    ]);

    expect(LeaveRecord::query()->count())->toBe(2);
});

it('allows the same date for different employees', function (): void {
    $other = Employee::factory()->create();

    foreach ([$this->employee, $other] as $employee) {
        LeaveRecord::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType->id,
            'leave_date' => '2026-03-10',
            'day_part' => DayPart::Whole,
        ]);
    }

    expect(LeaveRecord::query()->count())->toBe(2);
});

it('allows re-encoding a date after the record was soft deleted', function (): void {
    // This is why the unique index is partial (WHERE deleted_at IS NULL). A plain
    // unique index would let a deleted row hold the slot forever, so HR could
    // never re-encode a day they deleted by mistake.
    $record = LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Whole,
    ]);

    $record->delete();

    LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Whole,
    ]);

    expect(LeaveRecord::query()->count())->toBe(1)
        ->and(LeaveRecord::withTrashed()->count())->toBe(2);
});

it('rejects a day part outside WHOLE, AM and PM', function (): void {
    // Inserted through the query builder to bypass the DayPart cast and prove the
    // database itself refuses the value.
    expect(fn (): bool => DB::table('leave_records')->insert([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => 'EVENING',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to hard delete a leave type that has records', function (): void {
    LeaveRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->leaveType->id,
        'leave_date' => '2026-03-10',
        'day_part' => DayPart::Whole,
    ]);

    expect(fn (): ?bool => $this->leaveType->delete())->toThrow(QueryException::class);
});
