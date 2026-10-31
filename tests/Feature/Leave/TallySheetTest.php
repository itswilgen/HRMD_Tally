<?php

declare(strict_types=1);

use App\Enums\DayPart;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| A fixed "today"
|--------------------------------------------------------------------------
|
| The sheet is built around the current year and the current month, so the
| clock is pinned. Without this, "the sheet defaults to this year" would be a
| test that quietly changes meaning every January.
|
*/

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-31 09:00:00'));
});

/**
 * Encodes days straight into the table, bypassing the encoding rules.
 *
 * The tally's job is to read what is on file and format it; what may be put
 * on file is LeaveEncodingTest's subject. Writing rows directly keeps the two
 * apart, so a change to the encoding rules cannot silently rewrite the
 * expectations about the cell text.
 *
 * @param  list<string>  $dates
 */
function encoded(Employee $employee, LeaveType $type, array $dates, DayPart $dayPart = DayPart::Whole): void
{
    foreach ($dates as $date) {
        LeaveRecord::factory()
            ->for($employee)
            ->for($type, 'leaveType')
            ->on($date)
            ->dayPart($dayPart)
            ->create();
    }
}

/**
 * The 0-based index of a month in a tally row's `months` list.
 */
function monthAt(int $month): int
{
    return $month - 1;
}

/*
|--------------------------------------------------------------------------
| Who may open the sheet
|--------------------------------------------------------------------------
*/

it('sends guests to the sign-in page', function (): void {
    $this->get(route('tally.index'))->assertRedirect(route('login'));
});

it('opens the sheet for both HR roles', function (string $role): void {
    $user = $role === 'admin' ? hrAdmin() : hrStaff();

    $this->actingAs($user)
        ->get(route('tally.index'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('tally/index'));
})->with(['admin', 'staff']);

it('keeps the sheet away from an account with no role', function (): void {
    seedRolesAndPermissions();

    $this->actingAs(User::factory()->create())
        ->get(route('tally.index'))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Rule 5: how a month cell reads
|--------------------------------------------------------------------------
*/

it('compresses consecutive whole days into one range', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'MFL', 'sort_order' => 1]);

    encoded($employee, $type, ['2026-03-23', '2026-03-24', '2026-03-25']);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(sprintf('tally.%d.months.%d.entries.0.text', $employee->id, monthAt(3)), '23–25 MFL')
        );
});

it('separates days that do not run together with commas', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'SL', 'sort_order' => 1]);

    encoded($employee, $type, ['2026-03-06', '2026-03-09']);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(sprintf('tally.%d.months.%d.entries.0.text', $employee->id, monthAt(3)), '6, 9 SL')
        );
});

it('marks a half day with AM or PM and never folds it into a range', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'SPL', 'sort_order' => 1]);

    encoded($employee, $type, ['2026-03-27'], DayPart::Pm);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(sprintf('tally.%d.months.%d.entries.0.text', $employee->id, monthAt(3)), '27 PM SPL')
        );
});

it('breaks a run of whole days where a half day falls in the middle', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);

    encoded($employee, $type, ['2026-03-23']);
    encoded($employee, $type, ['2026-03-24'], DayPart::Am);
    encoded($employee, $type, ['2026-03-25']);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(sprintf('tally.%d.months.%d.entries.0.text', $employee->id, monthAt(3)), '23, 24 AM, 25 VL')
        );
});

it('gives each leave type its own chip in the same month', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $vacation = LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);
    $sick = LeaveType::factory()->create(['code' => 'SL', 'sort_order' => 2]);

    encoded($employee, $sick, ['2026-03-09']);
    encoded($employee, $vacation, ['2026-03-23', '2026-03-24']);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(function (AssertableInertia $page) use ($employee): void {
            $path = sprintf('tally.%d.months.%d.entries', $employee->id, monthAt(3));

            // Chip order follows the leave-type columns, not the dates, so a
            // row reads in the same order as the totals beside it.
            $page->where($path.'.0.text', '23–24 VL')
                ->where($path.'.1.text', '9 SL');
        });
});

it('lists the days behind a chip so each one can be corrected', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);

    encoded($employee, $type, ['2026-03-23']);
    encoded($employee, $type, ['2026-03-24'], DayPart::Pm);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(function (AssertableInertia $page) use ($employee): void {
            $path = sprintf('tally.%d.months.%d.entries.0.records', $employee->id, monthAt(3));

            $page->has($path, 2)
                ->where($path.'.0.dayText', '23')
                ->where($path.'.1.dayText', '24 PM');
        });
});

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

it('totals each leave type for the year and adds them into a grand total', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $vacation = LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);
    $sick = LeaveType::factory()->create(['code' => 'SL', 'sort_order' => 2]);

    // 2 whole days in March and 1 in July: 3 days of vacation leave.
    encoded($employee, $vacation, ['2026-03-23', '2026-03-24', '2026-07-06']);

    // 1 whole day and one afternoon: 1.5 days of sick leave.
    encoded($employee, $sick, ['2026-09-07']);
    encoded($employee, $sick, ['2026-09-08'], DayPart::Pm);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(function (AssertableInertia $page) use ($employee, $vacation, $sick): void {
            $row = sprintf('tally.%d', $employee->id);

            $page->where($row.'.totalsText.'.$vacation->id, '3')
                // Written as "1.5", not "1.50" or "1,5" — the way it is
                // written on the paper sheet.
                ->where($row.'.totalsText.'.$sick->id, '1.5')
                ->where($row.'.grandTotal', 4.5)
                ->where($row.'.grandTotalText', '4.5');
        });
});

it('leaves a leave type with no days blank rather than printing a zero', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(sprintf('tally.%d.totalsText.%d', $employee->id, $type->id), '')
            ->where(sprintf('tally.%d.grandTotalText', $employee->id), '')
        );
});

it('counts only the year on screen', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $type = LeaveType::factory()->create(['code' => 'VL', 'sort_order' => 1]);

    encoded($employee, $type, ['2025-12-31', '2026-01-02', '2027-01-01']);

    $this->actingAs($staff)
        ->get(route('tally.index', ['year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('year', 2026)
            ->where(sprintf('tally.%d.grandTotalText', $employee->id), '1')
            ->where(sprintf('tally.%d.months.%d.entries.0.text', $employee->id, monthAt(1)), '2 VL')
        );
});

it('still shows a leave type that has been retired mid-year', function (): void {
    $staff = hrStaff();
    $employee = Employee::factory()->create();
    $retired = LeaveType::factory()->inactive()->create(['code' => 'OLD', 'sort_order' => 1]);

    encoded($employee, $retired, ['2026-03-23']);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            // A column on the sheet, because the days are real and the grand
            // total has to keep adding up …
            ->where('leaveTypes.0.code', 'OLD')
            ->where(sprintf('tally.%d.grandTotalText', $employee->id), '1')
            // … but not an option when encoding something new.
            ->where('encodableLeaveTypes', [])
        );
});

/*
|--------------------------------------------------------------------------
| Which rows the sheet lists
|--------------------------------------------------------------------------
*/

it('keeps a deactivated employee on the sheet for a year they have leave on', function (): void {
    $staff = hrStaff();
    $resigned = Employee::factory()->inactive()->create(['last_name' => 'Zamora']);
    $type = LeaveType::factory()->create();

    encoded($resigned, $type, ['2026-03-23']);

    $this->actingAs($staff)
        ->get(route('tally.index', ['year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 1));
});

it('drops a deactivated employee from a year they have no leave on', function (): void {
    $staff = hrStaff();
    $resigned = Employee::factory()->inactive()->create();
    $type = LeaveType::factory()->create();

    encoded($resigned, $type, ['2026-03-23']);

    $this->actingAs($staff)
        ->get(route('tally.index', ['year' => 2025]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 0));
});

it('narrows the sheet to one person', function (string $filter): void {
    $staff = hrStaff();
    $office = Office::factory()->create(['name' => 'City Treasurer']);

    Employee::factory()->inOffice($office)->create(['last_name' => 'Bautista', 'first_name' => 'Analyn']);
    Employee::factory()->create(['last_name' => 'Zamora', 'first_name' => 'Rene']);

    $query = match ($filter) {
        'search' => ['search' => 'bautis'],
        'letter' => ['letter' => 'B'],
        default => ['office' => $office->id],
    };

    $this->actingAs($staff)
        ->get(route('tally.index', $query))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees.data', 1)
            ->where('employees.data.0.lastName', 'Bautista')
        );
})->with(['search', 'letter', 'office']);

it('ignores a letter filter that is not a single letter', function (): void {
    $staff = hrStaff();
    Employee::factory()->create();

    $this->actingAs($staff)
        ->get(route('tally.index', ['letter' => 'ABC']))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.letter', '')
            ->has('employees.data', 1)
        );
});

it('offers only the letters that have someone behind them', function (): void {
    $staff = hrStaff();
    Employee::factory()->create(['last_name' => 'Bautista']);
    Employee::factory()->create(['last_name' => 'Reyes']);

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('letters', ['B', 'R']));
});

/*
|--------------------------------------------------------------------------
| The year picker and the current month
|--------------------------------------------------------------------------
*/

it('opens on this year and accents the month it is', function (): void {
    $staff = hrStaff();

    $this->actingAs($staff)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('year', 2026)
            ->where('currentMonth', 10)
            ->where('asOf', '2026-10-31')
        );
});

it('accents no month on a sheet that is not this year', function (): void {
    $staff = hrStaff();

    $this->actingAs($staff)
        ->get(route('tally.index', ['year' => 2024]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('year', 2024)
            ->where('currentMonth', null)
        );
});

it('falls back to this year when the url asks for one off the picker', function (string $year): void {
    $staff = hrStaff();

    $this->actingAs($staff)
        ->get(route('tally.index', ['year' => $year]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('year', 2026));
})->with(['1998', '2099', 'last-year']);

/*
|--------------------------------------------------------------------------
| Reading cost
|--------------------------------------------------------------------------
*/

/**
 * The whole page of leave is read in one query and grouped in PHP, so a sheet
 * of 25 employees costs what a sheet of one costs. `preventLazyLoading()`
 * catches a missing eager load by throwing; this catches a per-row query that
 * would not throw.
 */
it('reads the sheet in a constant number of queries', function (): void {
    $staff = hrStaff();
    $type = LeaveType::factory()->create();
    $first = Employee::factory()->create();

    encoded($first, $type, ['2026-03-23', '2026-03-24']);

    $countQueries = function () use ($staff): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($staff)->get(route('tally.index'))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // One request first, so the permission cache is warm and not counted.
    $countQueries();

    $withOneRow = $countQueries();

    foreach (Employee::factory()->count(12)->create() as $employee) {
        encoded($employee, $type, ['2026-03-23', '2026-04-06', '2026-09-07']);
    }

    expect($countQueries())->toBe($withOneRow);
});

/*
|--------------------------------------------------------------------------
| The salary rate: HR Admin only (RA 10173)
|--------------------------------------------------------------------------
*/

it('leaves the monthly rate out of the sheet entirely for HR Staff', function (): void {
    $staff = hrStaff();
    Employee::factory()->create(['monthly_rate' => '23450.00']);

    $response = $this->actingAs($staff)->get(route('tally.index'));

    // Missing, not null: a key sent as null would still be absent from the
    // screen but present in the page source for anyone who looked.
    $response->assertInertia(fn (AssertableInertia $page) => $page->missing('employees.data.0.monthlyRate'));
    $response->assertDontSee('23450', escape: false);
});

it('sends the monthly rate to an HR Admin', function (): void {
    $admin = hrAdmin();
    Employee::factory()->create(['monthly_rate' => '23450.00']);

    $this->actingAs($admin)
        ->get(route('tally.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employees.data.0.monthlyRate', '23450.00')
        );
});
