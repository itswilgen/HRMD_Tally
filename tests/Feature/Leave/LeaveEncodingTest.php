<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveEncodingService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The calendar these tests are written against
|--------------------------------------------------------------------------
|
| March 2026: the 1st is a Sunday, so the 2nd is a Monday and the 7th and 8th
| are the weekend. The 23rd, 24th and 25th are Monday to Wednesday. No holidays
| exist unless a test creates one, which keeps every expectation below readable
| without a calendar in hand.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function leavePayload(Employee $employee, LeaveType $type, array $overrides = []): array
{
    return [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'date_from' => '2026-03-23',
        'date_to' => '2026-03-25',
        'day_part' => DayPart::Whole->value,
        'remarks' => 'Approved leave form no. 2026-0481',
        ...$overrides,
    ];
}

/**
 * Every encoded day, in date order — the shape the assertions below read in.
 *
 * @return list<string>
 */
function encodedDates(): array
{
    return array_values(
        LeaveRecord::query()
            ->orderBy('leave_date')
            ->pluck('leave_date')
            ->map(static fn (Carbon $date): string => $date->format('Y-m-d'))
            ->all()
    );
}

/*
|--------------------------------------------------------------------------
| Expanding a range into days
|--------------------------------------------------------------------------
*/

it('writes one row per working day in the range', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'VL']);

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type))
        ->assertRedirect();

    expect(encodedDates())->toBe(['2026-03-23', '2026-03-24', '2026-03-25']);
});

it('skips the weekend inside a range', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'SL']);

    // Friday the 6th to Monday the 9th: the 7th and 8th are the weekend.
    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, ['date_from' => '2026-03-06', 'date_to' => '2026-03-09']))
        ->assertRedirect();

    expect(encodedDates())->toBe(['2026-03-06', '2026-03-09']);
});

it('skips a holiday that recurs on the same date every year', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    // Entered once, on a year nobody is encoding for.
    Holiday::factory()->recurring()->on('2020-12-25', 'Christmas Day')->create();

    // Thursday 24 to Monday 28 December 2026: the 25th is the holiday and the
    // 26th and 27th are the weekend.
    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, ['date_from' => '2026-12-24', 'date_to' => '2026-12-28']))
        ->assertRedirect();

    expect(encodedDates())->toBe(['2026-12-24', '2026-12-28']);
});

it('refuses a range that is entirely weekends and holidays', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, ['date_from' => '2026-03-07', 'date_to' => '2026-03-08']))
        ->assertSessionHasErrors('date_from');

    expect(LeaveRecord::query()->count())->toBe(0);
});

it('refuses a range longer than a year rather than writing thousands of rows', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, ['date_from' => '2026-01-02', 'date_to' => '2027-01-11']))
        ->assertSessionHasErrors('date_to');

    expect(LeaveRecord::query()->count())->toBe(0);
});

it('lets a range cross new year, leaving each day in its own year', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    // Wednesday 30 December 2026 to Friday 1 January 2027.
    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, ['date_from' => '2026-12-30', 'date_to' => '2027-01-01']))
        ->assertRedirect();

    expect(LeaveRecord::query()->forYear(2026)->count())->toBe(2)
        ->and(LeaveRecord::query()->forYear(2027)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Half days
|--------------------------------------------------------------------------
*/

it('records a half day as 0.5 of a day', function (string $dayPart): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, [
            'date_from' => '2026-03-27',
            'date_to' => '2026-03-27',
            'day_part' => $dayPart,
        ]))
        ->assertRedirect();

    $record = LeaveRecord::query()->sole();

    expect($record->days())->toBe(0.5);
})->with([DayPart::Am->value, DayPart::Pm->value]);

it('refuses a half day spread over a range', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, ['day_part' => DayPart::Am->value]))
        ->assertSessionHasErrors('date_to');

    expect(LeaveRecord::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Double encoding (business rule 4)
|--------------------------------------------------------------------------
*/

it('blocks a second entry that cannot share the date', function (string $existing, string $attempted): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'VL']);

    LeaveRecord::factory()
        ->for($employee)
        ->for($type, 'leaveType')
        ->on('2026-03-23')
        ->dayPart(DayPart::from($existing))
        ->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, [
            'date_from' => '2026-03-23',
            'date_to' => '2026-03-23',
            'day_part' => $attempted,
        ]))
        ->assertSessionHasErrors('date_from');

    expect(LeaveRecord::query()->count())->toBe(1);
})->with([
    'whole day over a whole day' => ['WHOLE', 'WHOLE'],
    'half day over a whole day' => ['WHOLE', 'AM'],
    'whole day over a half day' => ['AM', 'WHOLE'],
    'the same half of the day' => ['PM', 'PM'],
]);

it('allows the two halves of one day', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    LeaveRecord::factory()
        ->for($employee)
        ->for($type, 'leaveType')
        ->on('2026-03-23')
        ->dayPart(DayPart::Am)
        ->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type, [
            'date_from' => '2026-03-23',
            'date_to' => '2026-03-23',
            'day_part' => DayPart::Pm->value,
        ]))
        ->assertSessionHasNoErrors();

    expect(LeaveRecord::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Who encoded it, and correcting it afterwards
|--------------------------------------------------------------------------
*/

it('records who encoded each day', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $this->actingAs($staff)->post(route('leave.store'), leavePayload($employee, $type));

    expect(LeaveRecord::query()->pluck('encoded_by')->unique()->values()->all())->toBe([$staff->id]);
});

it('moves a day that was typed wrong', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $record = LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-23')->create();

    $this->actingAs($staff)
        ->patch(route('leave.update', $record), [
            'leave_type_id' => $type->id,
            'leave_date' => '2026-03-24',
            'day_part' => DayPart::Pm->value,
            'remarks' => null,
        ])
        ->assertSessionHasNoErrors();

    $record->refresh();

    expect($record->leave_date->format('Y-m-d'))->toBe('2026-03-24')
        ->and($record->day_part)->toBe(DayPart::Pm);
});

it('refuses to move a day onto a weekend', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $record = LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-23')->create();

    $this->actingAs($staff)
        ->patch(route('leave.update', $record), [
            'leave_type_id' => $type->id,
            'leave_date' => '2026-03-08',
            'day_part' => DayPart::Whole->value,
        ])
        ->assertSessionHasErrors('leave_date');

    expect($record->refresh()->leave_date->format('Y-m-d'))->toBe('2026-03-23');
});

it('keeps a removed day recoverable instead of deleting it', function (): void {
    $staff = hrStaff();
    $record = LeaveRecord::factory()->create();

    $this->actingAs($staff)->delete(route('leave.destroy', $record))->assertRedirect();

    expect(LeaveRecord::query()->whereKey($record->id)->exists())->toBeFalse()
        ->and(LeaveRecord::withTrashed()->whereKey($record->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The preview behind the encoding panel
|--------------------------------------------------------------------------
*/

it('previews the dates a range would save, the days it skips and the clashes', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'SL']);

    LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-09')->create();

    $response = $this->actingAs($staff)->postJson(route('leave.preview'), [
        'employee_id' => $employee->id,
        'date_from' => '2026-03-06',
        'date_to' => '2026-03-09',
        'day_part' => DayPart::Whole->value,
    ])->assertSuccessful();

    expect($response->json('dates.*.date'))->toBe(['2026-03-06', '2026-03-09'])
        ->and($response->json('skipped.*.date'))->toBe(['2026-03-07', '2026-03-08'])
        ->and($response->json('skipped.*.reason'))->toBe(['Saturday', 'Sunday'])
        ->and($response->json('conflicts.*.date'))->toBe(['2026-03-09'])
        ->and($response->json('days'))->toBe(2);
});

it('does not report the record being edited as clashing with itself', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create();

    $record = LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on('2026-03-23')->create();

    $this->actingAs($staff)->postJson(route('leave.preview'), [
        'employee_id' => $employee->id,
        'date_from' => '2026-03-23',
        'date_to' => '2026-03-23',
        'day_part' => DayPart::Whole->value,
        'ignore_record_id' => $record->id,
    ])->assertSuccessful()->assertJsonCount(0, 'conflicts');
});

it('keeps the preview behind the same permission as encoding', function (): void {
    $employee = Employee::factory()->create();

    seedRolesAndPermissions();
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->postJson(route('leave.preview'), [
        'employee_id' => $employee->id,
        'date_from' => '2026-03-23',
        'date_to' => '2026-03-23',
        'day_part' => DayPart::Whole->value,
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| What may be encoded against
|--------------------------------------------------------------------------
*/

it('refuses leave for someone off the encoding list', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create(['is_active' => false]);
    $type = LeaveType::factory()->create();

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type))
        ->assertSessionHasErrors('employee_id');
});

it('refuses leave against a retired leave type', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['is_active' => false]);

    $this->actingAs($staff)
        ->post(route('leave.store'), leavePayload($employee, $type))
        ->assertSessionHasErrors('leave_type_id');
});

it('offers only active leave types to encode against', function (): void {
    LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);
    LeaveType::factory()->create(['code' => 'OLD', 'sort_order' => 2, 'is_active' => false]);

    $codes = array_column(app(LeaveEncodingService::class)->leaveTypeOptions(), 'code');

    expect($codes)->toBe(['VL']);
});
