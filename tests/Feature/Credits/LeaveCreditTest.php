<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Services\EmployeeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * Recorded leave credits: the figure HR types, against what the tally counts
 * as taken (docs/PROJECT_CONTEXT.md, section 6.1).
 *
 * The clock is pinned for the same reason the tally tests pin it: which years
 * the picker offers depends on today.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-31 09:00:00'));
});

function creditType(string $code, bool $active = true, int $order = 1): LeaveType
{
    return LeaveType::query()->firstOrCreate(
        ['code' => $code],
        [
            'name' => "{$code} Leave",
            'color' => 'sky',
            'is_active' => $active,
            'sort_order' => $order,
        ],
    );
}

function creditFigure(Employee $employee, LeaveType $type, float $days, int $year = 2026): LeaveCredit
{
    return LeaveCredit::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => $year,
        'days' => $days,
    ]);
}

/**
 * @param  list<string>  $dates
 */
function creditTaken(Employee $employee, LeaveType $type, array $dates, DayPart $dayPart = DayPart::Whole): void
{
    foreach ($dates as $date) {
        LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on($date)->dayPart($dayPart)->create();
    }
}

function creditOnFile(Employee $employee, LeaveType $type, int $year = 2026): ?float
{
    return LeaveCredit::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', $year)
        ->value('days');
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('sends guests to the sign-in page', function (): void {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
});

it('shows each figure against the days the tally counts as taken', function (): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();

    creditFigure($employee, $vl, 15);
    creditTaken($employee, $vl, ['2026-03-02', '2026-03-03', '2026-03-04']);
    creditTaken($employee, $vl, ['2026-03-05'], DayPart::Am);
    // Another year's leave is not this year's balance.
    creditTaken($employee, $vl, ['2025-06-02']);

    $this->actingAs(hrAdmin())
        ->get(route('credits.index', ['year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('credits/index')
            ->where('year', 2026)
            ->where('can.manage', true)
            ->where("credits.{$employee->id}.cells.{$vl->id}.credits", 15)
            ->where("credits.{$employee->id}.cells.{$vl->id}.usedText", '3.5')
            ->where("credits.{$employee->id}.cells.{$vl->id}.remainingText", '11.5')
            ->where("credits.{$employee->id}.cells.{$vl->id}.isOverdrawn", false)
            ->where("credits.{$employee->id}.remainingTotalText", '11.5'));
});

it('shows an overdrawn balance rather than refusing it', function (): void {
    $sl = creditType('SL');
    $employee = Employee::factory()->create();

    creditFigure($employee, $sl, 1);
    creditTaken($employee, $sl, ['2026-04-06', '2026-04-07']);

    $this->actingAs(hrAdmin())
        ->get(route('credits.index', ['year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("credits.{$employee->id}.cells.{$sl->id}.remaining", -1)
            ->where("credits.{$employee->id}.cells.{$sl->id}.isOverdrawn", true)
            ->where("credits.{$employee->id}.isOverdrawn", true));
});

it('keeps blank and nought apart', function (): void {
    $vl = creditType('VL');
    $sl = creditType('SL', order: 2);
    $employee = Employee::factory()->create();

    creditFigure($employee, $vl, 0);

    $this->actingAs(hrAdmin())
        ->get(route('credits.index', ['year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("credits.{$employee->id}.cells.{$vl->id}.credits", 0)
            ->where("credits.{$employee->id}.cells.{$vl->id}.creditsText", '0')
            ->where("credits.{$employee->id}.cells.{$sl->id}.credits", null)
            ->where("credits.{$employee->id}.cells.{$sl->id}.creditsText", ''));
});

it('marks a retired leave type so the screen can lock new figures', function (): void {
    creditType('VL');
    $retired = creditType('XL', active: false, order: 2);
    Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->get(route('credits.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leaveTypes', fn (Collection $types): bool => $types->contains(
                fn (array $type): bool => $type['id'] === $retired->id && $type['isActive'] === false
            ) && $types->contains(
                fn (array $type): bool => $type['code'] === 'VL' && $type['isActive'] === true
            )));
});

it('keeps someone since resigned on the year they have credits for', function (): void {
    $vl = creditType('VL');
    $resigned = Employee::factory()->inactive()->create();
    // Resigned too, but with nothing on file for 2025: not on that sheet.
    Employee::factory()->inactive()->create();

    creditFigure($resigned, $vl, 15, 2025);

    $this->actingAs(hrAdmin())
        ->get(route('credits.index', ['year' => 2025]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees.data', 1)
            ->where('employees.data.0.id', $resigned->id));
});

it('falls back to this year for a year the picker does not offer', function (): void {
    $this->actingAs(hrAdmin())
        ->get(route('credits.index', ['year' => 1990]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('year', 2026));
});

it('shows one employee with their figures for the year', function (): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();

    creditFigure($employee, $vl, 15);
    creditTaken($employee, $vl, ['2026-02-02']);

    $this->actingAs(hrStaff())
        ->get(route('credits.show', ['employee' => $employee->id, 'year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('credits/show')
            ->where('employee.id', $employee->id)
            ->where("credits.cells.{$vl->id}.remainingText", '14')
            ->where('can.manage', false));
});

/*
|--------------------------------------------------------------------------
| Who may change them
|--------------------------------------------------------------------------
*/

it('lets HR Staff read the credits but change nothing', function (): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();
    $staff = hrStaff();

    $this->actingAs($staff)
        ->get(route('credits.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));

    $this->actingAs($staff)
        ->patch(route('credits.update'), ['year' => 2026, 'rows' => [['employee_id' => $employee->id, 'credits' => [$vl->id => 15]]]])
        ->assertForbidden();
    $this->actingAs($staff)
        ->put(route('credits.save', $employee), ['year' => 2026, 'credits' => [$vl->id => 15]])
        ->assertForbidden();
    $this->actingAs($staff)
        ->post(route('credits.grant'), ['year' => 2026, 'credits' => [$vl->id => 15]])
        ->assertForbidden();
    $this->actingAs($staff)
        ->post(route('credits.copy'), ['year' => 2026, 'from_year' => 2025])
        ->assertForbidden();
    $this->actingAs($staff)
        ->get(route('credits.import.template'))
        ->assertForbidden();

    expect(LeaveCredit::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Saving the grid and one employee
|--------------------------------------------------------------------------
*/

it('adds, changes and clears figures across the grid in one save', function (): void {
    $vl = creditType('VL');
    $sl = creditType('SL', order: 2);
    $first = Employee::factory()->create();
    $second = Employee::factory()->create();

    creditFigure($first, $vl, 15);
    creditFigure($first, $sl, 15);

    $this->actingAs(hrAdmin())
        ->from(route('credits.index'))
        ->patch(route('credits.update'), [
            'year' => 2026,
            'rows' => [
                ['employee_id' => $first->id, 'credits' => [$vl->id => '16', $sl->id => null]],
                ['employee_id' => $second->id, 'credits' => [$vl->id => '7.5']],
            ],
        ])
        ->assertRedirect(route('credits.index'))
        ->assertSessionHas('success');

    expect(creditOnFile($first, $vl))->toBe(16.0)
        ->and(creditOnFile($first, $sl))->toBeNull()
        ->and(creditOnFile($second, $vl))->toBe(7.5);

    $logged = Activity::query()->where('log_name', 'leave_credit')->get();

    expect($logged)->toHaveCount(2)
        ->and($logged->firstWhere('subject_id', $first->id)?->properties['old'])->toBe(['VL' => 15, 'SL' => 15])
        ->and($logged->firstWhere('subject_id', $first->id)?->properties['attributes'])->toBe(['VL' => 16, 'SL' => null]);
});

it('logs nothing when a save changes nothing', function (): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();
    creditFigure($employee, $vl, 15);

    $this->actingAs(hrAdmin())
        ->patch(route('credits.update'), ['year' => 2026, 'rows' => [['employee_id' => $employee->id, 'credits' => [$vl->id => '15.0']]]])
        ->assertSessionHas('success', 'Nothing had changed, so the 2026 credits were left as they were.');

    expect(Activity::query()->where('log_name', 'leave_credit')->count())->toBe(0);
});

it('takes only half days between 0 and 999', function (mixed $value): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->patch(route('credits.update'), ['year' => 2026, 'rows' => [['employee_id' => $employee->id, 'credits' => [$vl->id => $value]]]])
        ->assertSessionHasErrors("rows.0.credits.{$vl->id}");

    expect(LeaveCredit::count())->toBe(0);
})->with([
    'a quarter day' => '7.25',
    'a negative figure' => '-1',
    'more than 999' => '1000',
    'a word' => 'fifteen',
]);

it('refuses a year the picker does not offer', function (): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->patch(route('credits.update'), ['year' => 1990, 'rows' => [['employee_id' => $employee->id, 'credits' => [$vl->id => '15']]]])
        ->assertSessionHasErrors('year');
});

it('lets a retired leave type be corrected but not granted anew', function (): void {
    $retired = creditType('XL', active: false);
    $hasFigure = Employee::factory()->create();
    $hasNone = Employee::factory()->create();

    creditFigure($hasFigure, $retired, 5);

    $this->actingAs(hrAdmin())
        ->patch(route('credits.update'), ['year' => 2026, 'rows' => [['employee_id' => $hasFigure->id, 'credits' => [$retired->id => '4']]]])
        ->assertSessionHasNoErrors();

    $this->actingAs(hrAdmin())
        ->patch(route('credits.update'), ['year' => 2026, 'rows' => [['employee_id' => $hasNone->id, 'credits' => [$retired->id => '4']]]])
        ->assertSessionHasErrors("rows.0.credits.{$retired->id}");

    expect(creditOnFile($hasFigure, $retired))->toBe(4.0)
        ->and(creditOnFile($hasNone, $retired))->toBeNull();
});

it('saves one employee from their own page', function (): void {
    $vl = creditType('VL');
    $sl = creditType('SL', order: 2);
    $employee = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->from(route('credits.show', $employee))
        ->put(route('credits.save', $employee), ['year' => 2025, 'credits' => [$vl->id => '15', $sl->id => '0']])
        ->assertRedirect(route('credits.show', $employee))
        ->assertSessionHas('success');

    expect(creditOnFile($employee, $vl, 2025))->toBe(15.0)
        ->and(creditOnFile($employee, $sl, 2025))->toBe(0.0)
        ->and(creditOnFile($employee, $vl, 2026))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Grant to all and copy a year
|--------------------------------------------------------------------------
*/

it('grants the same figures to everyone, keeping figures already on file', function (): void {
    $vl = creditType('VL');
    $sl = creditType('SL', order: 2);
    $corrected = Employee::factory()->create();
    $blank = Employee::factory()->create();
    $resigned = Employee::factory()->inactive()->create();

    creditFigure($corrected, $vl, 10);

    $this->actingAs(hrAdmin())
        ->post(route('credits.grant'), ['year' => 2026, 'credits' => [$vl->id => '15', $sl->id => null]])
        ->assertSessionHas('success');

    expect(creditOnFile($corrected, $vl))->toBe(10.0)
        ->and(creditOnFile($blank, $vl))->toBe(15.0)
        // A blank in the dialog grants nothing; it is not a grant of nought.
        ->and(creditOnFile($blank, $sl))->toBeNull()
        ->and(creditOnFile($resigned, $vl))->toBeNull();

    $this->actingAs(hrAdmin())
        ->post(route('credits.grant'), ['year' => 2026, 'credits' => [$vl->id => '15'], 'overwrite' => true]);

    expect(creditOnFile($corrected, $vl))->toBe(15.0);
});

it('grants office by office when an office is chosen', function (): void {
    $vl = creditType('VL');
    $mayors = Office::factory()->create();
    $inOffice = Employee::factory()->inOffice($mayors)->create();
    $elsewhere = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->post(route('credits.grant'), ['year' => 2026, 'credits' => [$vl->id => '15'], 'office_id' => $mayors->id]);

    expect(creditOnFile($inOffice, $vl))->toBe(15.0)
        ->and(creditOnFile($elsewhere, $vl))->toBeNull();
});

it('refuses to grant against a retired leave type', function (): void {
    $retired = creditType('XL', active: false);
    Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->post(route('credits.grant'), ['year' => 2026, 'credits' => [$retired->id => '5']])
        ->assertSessionHasErrors("credits.{$retired->id}");

    expect(LeaveCredit::count())->toBe(0);
});

it('copies a year onto another, filling only the blanks', function (): void {
    $vl = creditType('VL');
    $sl = creditType('SL', order: 2);
    $employee = Employee::factory()->create();

    creditFigure($employee, $vl, 15, 2025);
    creditFigure($employee, $sl, 15, 2025);
    creditFigure($employee, $vl, 12, 2026);

    $this->actingAs(hrAdmin())
        ->post(route('credits.copy'), ['year' => 2026, 'from_year' => 2025])
        ->assertSessionHas('success');

    expect(creditOnFile($employee, $vl))->toBe(12.0)
        ->and(creditOnFile($employee, $sl))->toBe(15.0);

    $this->actingAs(hrAdmin())
        ->post(route('credits.copy'), ['year' => 2026, 'from_year' => 2025, 'overwrite' => true]);

    expect(creditOnFile($employee, $vl))->toBe(15.0);
});

it('will not copy a year onto itself', function (): void {
    $this->actingAs(hrAdmin())
        ->post(route('credits.copy'), ['year' => 2026, 'from_year' => 2026])
        ->assertSessionHasErrors('from_year');
});

/*
|--------------------------------------------------------------------------
| Erasing an employee
|--------------------------------------------------------------------------
*/

it('takes an erased employee\'s credits with them', function (): void {
    $vl = creditType('VL');
    $employee = Employee::factory()->create();
    creditFigure($employee, $vl, 15);
    $employee->delete();

    app(EmployeeService::class)->eraseMany([$employee->id], hrAdmin());

    expect(Employee::withTrashed()->find($employee->id))->toBeNull()
        ->and(LeaveCredit::count())->toBe(0);
});
