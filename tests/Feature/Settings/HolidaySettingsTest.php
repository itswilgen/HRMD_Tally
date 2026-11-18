<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Enums\HolidayKind;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Services\HolidayCalendar;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/*
| Settings → Holidays (PROJECT_CONTEXT section 5.3). No holidays exist unless a
| test creates one. Calendar used below: 2027-03-24 is a Wednesday, so the 25th
| and 26th (Maundy Thursday, Good Friday in 2027) are Thursday and Friday and
| the 27th and 28th the weekend.
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function holidayPayload(array $overrides = []): array
{
    return [
        'date' => '2027-03-25',
        'name' => 'Maundy Thursday',
        'kind' => HolidayKind::RegularMovable->value,
        ...$overrides,
    ];
}

function leaveOn(string $date, ?Employee $employee = null): LeaveRecord
{
    return LeaveRecord::factory()
        ->for($employee ?? Employee::factory()->create())
        ->for(LeaveType::query()->where('code', 'VL')->firstOrFail(), 'leaveType')
        ->on($date)
        ->create();
}

it('sends guests to the sign-in page', function (): void {
    $this->get(route('holidays.index'))->assertRedirect(route('login'));
});

it('lists one year in date order, with fixed-date holidays in every year and the leave on file', function (): void {
    Holiday::factory()->recurring()->on('2026-12-25', 'Christmas Day')->create();
    Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);
    Holiday::factory()->on('2026-04-02', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);
    $employee = Employee::factory()->create();
    leaveOn('2027-03-25', $employee);

    $this->actingAs(hrAdmin())
        ->get(route('holidays.index', ['year' => 2027]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/holidays')
            ->where('year', 2027)
            ->has('holidays', 2)
            ->where('holidays.0.date', '2027-03-25')
            ->where('holidays.0.recursYearly', false)
            ->where('holidays.0.leaveRecords', 1)
            ->where('holidays.0.leaveEmployees', 1)
            ->where('holidays.1.date', '2027-12-25')
            ->where('holidays.1.name', 'Christmas Day')
            ->where('holidays.1.recursYearly', true)
            ->where('holidays.1.leaveRecords', 0)
            ->has('kinds', 3)
            ->where('can.manage', true)
        );
});

it('opens on this year, and on this year again for a year it cannot show', function (?string $asked): void {
    $this->actingAs(hrAdmin())
        ->get(route('holidays.index', $asked === null ? [] : ['year' => $asked]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('year', (int) now()->format('Y')));
})->with(['no year' => null, 'not a number' => 'next', 'too early' => '1999', 'too late' => '2101']);

it('shows HR Staff the holidays without the controls to change them', function (): void {
    Holiday::factory()->on('2027-03-25')->create();

    $this->actingAs(hrStaff())
        ->get(route('holidays.index', ['year' => 2027]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('holidays', 1)->where('can.manage', false));
});

it('refuses every holiday change to HR Staff', function (string $method, string $route): void {
    $holiday = Holiday::factory()->on('2027-03-25')->create();
    $url = $route === 'holidays.store' ? route($route) : route($route, $holiday);

    $this->actingAs(hrStaff())->{$method}($url, holidayPayload(['date' => '2027-03-26']))->assertForbidden();

    expect(Holiday::query()->count())->toBe(1)
        ->and($holiday->fresh()?->date->format('Y-m-d'))->toBe('2027-03-25')
        ->and(Activity::query()->where('log_name', 'holiday')->exists())->toBeFalse();
})->with([
    'add' => ['post', 'holidays.store'],
    'edit' => ['patch', 'holidays.update'],
    'remove' => ['delete', 'holidays.destroy'],
]);

it('adds a holiday for one year and logs it', function (): void {
    $admin = hrAdmin();

    $this->actingAs($admin)
        ->post(route('holidays.store'), holidayPayload(['name' => '  Maundy   Thursday ']))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Maundy Thursday is added on Thu, March 25, 2027. Encoding skips it from now on.');

    $holiday = Holiday::query()->sole();

    expect($holiday->name)->toBe('Maundy Thursday')
        ->and($holiday->recurs_yearly)->toBeFalse();

    $log = Activity::query()->where('log_name', 'holiday')->sole();

    expect($log->event)->toBe('created')
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->description)->toBe('Added holiday Maundy Thursday (Thu, March 25, 2027)');
});

/*
| The Phase 1 gate: an HR Admin enters next year's holidays on screen, and
| encoding skips them straight away.
*/
it('makes encoding skip the holidays an HR Admin adds on screen', function (): void {
    $admin = hrAdmin();
    $employee = Employee::factory()->create();
    $type = LeaveType::query()->where('code', 'VL')->firstOrFail();

    $this->actingAs($admin)->post(route('holidays.store'), holidayPayload())->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('holidays.store'), holidayPayload(['date' => '2027-03-26', 'name' => 'Good Friday']))->assertSessionHasNoErrors();

    $this->actingAs(hrStaff())
        ->post(route('leave.store'), [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'date_from' => '2027-03-24',
            'date_to' => '2027-03-29',
            'day_part' => DayPart::Whole->value,
        ])
        ->assertSessionHasNoErrors();

    expect(LeaveRecord::query()->orderBy('leave_date')->pluck('leave_date')->map(fn (Carbon $date): string => $date->format('Y-m-d'))->all())
        ->toBe(['2027-03-24', '2027-03-29']);
});

it('enters a fixed-date holiday once for every year', function (): void {
    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload(['date' => '2027-06-19', 'name' => 'Rizal Birthday', 'kind' => HolidayKind::RegularFixed->value]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Rizal Birthday is added, every June 19. Encoding skips it from now on.');

    $calendar = app(HolidayCalendar::class);

    expect($calendar->forYear(2027))->toHaveKey('2027-06-19')
        ->and($calendar->forYear(2031))->toHaveKey('2031-06-19');
});

it('validates a holiday', function (array $data, string $field): void {
    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload($data))
        ->assertSessionHasErrors($field);

    expect(Holiday::query()->exists())->toBeFalse();
})->with([
    'no date' => [['date' => ''], 'date'],
    'not a date' => [['date' => '2027-02-30'], 'date'],
    'before 2000' => [['date' => '1999-12-31'], 'date'],
    'after 2100' => [['date' => '2101-01-01'], 'date'],
    'no name' => [['name' => '   '], 'name'],
    'a name over 150 characters' => [['name' => str_repeat('a', 151)], 'name'],
    'no kind' => [['kind' => ''], 'kind'],
    'an unknown kind' => [['kind' => 'LOCAL'], 'kind'],
    'every year on February 29' => [['date' => '2028-02-29', 'kind' => HolidayKind::RegularFixed->value], 'date'],
]);

it('keeps one holiday per day', function (Closure $existing, array $data, string $message): void {
    $existing();

    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload($data))
        ->assertSessionHasErrors(['date' => $message]);

    expect(Holiday::query()->count())->toBe(1);
})->with([
    'a dated holiday on the same date' => [
        fn () => Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(),
        ['name' => 'Something else'],
        'Thu, March 25, 2027 is already Maundy Thursday. One day can hold only one holiday.',
    ],
    'a dated holiday on a fixed-date one' => [
        fn () => Holiday::factory()->recurring()->on('2026-12-25', 'Christmas Day')->create(),
        ['date' => '2027-12-25', 'name' => 'Office party'],
        'December 25 is already Christmas Day, every year.',
    ],
    'a fixed-date holiday on another' => [
        fn () => Holiday::factory()->recurring()->on('2026-12-25', 'Christmas Day')->create(),
        ['date' => '2030-12-25', 'name' => 'Christmas', 'kind' => HolidayKind::RegularFixed->value],
        'December 25 is already Christmas Day, every year.',
    ],
    'a fixed-date holiday on a day a dated one already has' => [
        fn () => Holiday::factory()->on('2026-08-21', 'Ninoy Aquino Day')->create(),
        ['date' => '2027-08-21', 'name' => 'Ninoy Aquino Day', 'kind' => HolidayKind::RegularFixed->value],
        'Fri, August 21, 2026 is already Ninoy Aquino Day. One day can hold only one holiday.',
    ],
]);

it('asks before adding a holiday on a day with leave on file, and leaves the records alone', function (): void {
    $record = leaveOn('2027-03-25');
    leaveOn('2027-03-25');

    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload())
        ->assertSessionHasErrors(['confirm_encoded' => '2 employees already have leave on Thu, March 25, 2027 (2 records). The holiday will not change them: check them on the tally. Tick the box to save anyway.']);

    expect(Holiday::query()->exists())->toBeFalse();

    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload(['confirm_encoded' => true]))
        ->assertSessionHasNoErrors();

    expect(Holiday::query()->count())->toBe(1)
        ->and(LeaveRecord::query()->count())->toBe(2)
        ->and(LeaveRecord::query()->find($record->id))->not->toBeNull();
});

it('counts every year when asking about a fixed-date holiday', function (): void {
    $employee = Employee::factory()->create();
    leaveOn('2025-06-19', $employee);
    leaveOn('2026-06-19', $employee);

    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload(['date' => '2027-06-19', 'name' => 'Rizal Birthday', 'kind' => HolidayKind::RegularFixed->value]))
        ->assertSessionHasErrors(['confirm_encoded' => '1 employee already has leave on June 19 (in 2 years) (2 records). The holiday will not change them: check them on the tally. Tick the box to save anyway.']);
});

it('ignores deleted leave when asking', function (): void {
    leaveOn('2027-03-25')->delete();

    $this->actingAs(hrAdmin())
        ->post(route('holidays.store'), holidayPayload())
        ->assertSessionHasNoErrors();
});

it('renames a holiday with leave on its day without asking again', function (): void {
    $holiday = Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);
    leaveOn('2027-03-25');

    $this->actingAs(hrAdmin())
        ->patch(route('holidays.update', $holiday), holidayPayload(['name' => 'Holy Thursday']))
        ->assertSessionHasNoErrors();

    expect($holiday->fresh()?->name)->toBe('Holy Thursday');

    $log = Activity::query()->where('log_name', 'holiday')->sole();

    expect($log->event)->toBe('updated')
        ->and($log->properties['old'])->toBe(['name' => 'Maundy Thursday'])
        ->and($log->properties['attributes'])->toBe(['name' => 'Holy Thursday']);
});

it('asks again when a holiday moves onto a day with leave on file', function (): void {
    $holiday = Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);
    leaveOn('2027-03-24');

    $this->actingAs(hrAdmin())
        ->patch(route('holidays.update', $holiday), holidayPayload(['date' => '2027-03-24']))
        ->assertSessionHasErrors('confirm_encoded');

    expect($holiday->fresh()?->date->format('Y-m-d'))->toBe('2027-03-25');
});

it('lets a holiday keep its own day on edit', function (): void {
    $holiday = Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);

    $this->actingAs(hrAdmin())
        ->patch(route('holidays.update', $holiday), holidayPayload(['kind' => HolidayKind::SpecialNonWorking->value]))
        ->assertSessionHasNoErrors();

    expect($holiday->fresh()?->kind)->toBe(HolidayKind::SpecialNonWorking);
});

it('keeps the stored date when a fixed-date holiday is renamed from another year', function (): void {
    $holiday = Holiday::factory()->recurring()->on('2026-12-25', 'Christmas Day')->create();

    $this->actingAs(hrAdmin())
        ->patch(route('holidays.update', $holiday), ['date' => '2029-12-25', 'name' => 'Christmas', 'kind' => HolidayKind::RegularFixed->value])
        ->assertSessionHasNoErrors();

    expect($holiday->fresh()?->date->format('Y-m-d'))->toBe('2026-12-25')
        ->and($holiday->fresh()?->name)->toBe('Christmas')
        ->and(Activity::query()->where('log_name', 'holiday')->sole()->properties['attributes'])->toBe(['name' => 'Christmas']);
});

it('logs nothing when an edit changes nothing', function (): void {
    $holiday = Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);

    $this->actingAs(hrAdmin())
        ->patch(route('holidays.update', $holiday), holidayPayload())
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'holiday')->exists())->toBeFalse();
});

it('removes a holiday, making the day a working day again, and logs it', function (): void {
    $holiday = Holiday::factory()->on('2027-03-25', 'Maundy Thursday')->create(['kind' => HolidayKind::RegularMovable]);

    $this->actingAs(hrAdmin())
        ->delete(route('holidays.destroy', $holiday))
        ->assertSessionHasNoErrors();

    expect(Holiday::query()->exists())->toBeFalse()
        ->and(app(HolidayCalendar::class)->isHoliday(Carbon::parse('2027-03-25')))->toBeFalse();

    $log = Activity::query()->where('log_name', 'holiday')->sole();

    expect($log->event)->toBe('deleted')
        ->and($log->description)->toBe('Removed holiday Maundy Thursday (Thu, March 25, 2027)');
});
