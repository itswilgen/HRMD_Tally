<?php

declare(strict_types=1);

use App\Exports\LeaveCreditsSheet;
use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| Credits export (PROJECT_CONTEXT 6.1, rule 22): granted · taken · left per
| employee and leave code for one year. Every test reads the real .xlsx back.
| The current year is used throughout: it is always on the year picker.
*/

function exportCreditType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}

function exportCreditYear(): int
{
    return (int) now()->format('Y');
}

/** A weekday in March of the current year, for leave records. */
function exportCreditDay(int $nth): string
{
    $day = now()->setDate(exportCreditYear(), 3, 1)->startOfDay();

    while ($day->isWeekend()) {
        $day->addDay();
    }

    for ($i = 0; $i < $nth; $i++) {
        $day->addWeekday();
    }

    return $day->toDateString();
}

/**
 * @param  array<string, mixed>  $query
 */
function exportCreditSheet(User $user, array $query = []): Worksheet
{
    $response = test()->actingAs($user)->get(route('credits.export', ['year' => exportCreditYear(), ...$query]));
    $response->assertOk();

    /** @var BinaryFileResponse $file */
    $file = $response->baseResponse;

    return IOFactory::load($file->getFile()->getPathname())->getSheet(0);
}

/**
 * Column number of a leave code's Granted column ("VL — Vacation Leave").
 */
function exportGrantedColumn(Worksheet $sheet, string $code): int
{
    $row = LeaveCreditsSheet::CODE_ROW;

    foreach ($sheet->rangeToArray('A'.$row.':'.$sheet->getHighestColumn().$row)[0] as $index => $value) {
        if (is_string($value) && ($value === $code || str_starts_with($value, $code.' — '))) {
            return $index + 1;
        }
    }

    throw new RuntimeException("{$code} has no columns");
}

it('sends guests to the sign-in page', function (): void {
    $this->get(route('credits.export'))->assertRedirect(route('login'));
});

it('refuses an account that cannot read credits', function (): void {
    seedRolesAndPermissions();

    $this->actingAs(User::factory()->create())->get(route('credits.export'))->assertForbidden();
});

it('downloads an xlsx named after the year, and logs it', function (): void {
    $admin = hrAdmin();
    Employee::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('credits.export', ['year' => exportCreditYear()]))
        ->assertOk()
        ->assertDownload('leave-credits-'.exportCreditYear().'-all-offices.xlsx');

    $log = Activity::query()->where('log_name', 'export')->sole();

    expect($log->event)->toBe('exported')
        ->and($log->properties['what'])->toBe('credits')
        ->and($log->description)->toBe('Exported the '.exportCreditYear().' leave credits to Excel (All offices)');
});

it('lets HR Staff export too', function (): void {
    Employee::factory()->create();

    $this->actingAs(hrStaff())->get(route('credits.export'))->assertOk();
});

it('offers the export on the credits page to both roles', function (string $role): void {
    $user = $role === 'admin' ? hrAdmin() : hrStaff();

    $this->actingAs($user)
        ->get(route('credits.index'))
        ->assertInertia(fn ($page) => $page->where('can.export', true));
})->with(['admin', 'staff']);

it('writes granted, taken and left as the credits page shows them', function (): void {
    $employee = Employee::factory()->create(['last_name' => 'Abad']);
    $vacation = exportCreditType('VL');
    $sick = exportCreditType('SL');
    $wellness = exportCreditType('WL');

    LeaveCredit::factory()->for($employee)->for($vacation, 'leaveType')->forYear(exportCreditYear())->days(15)->create();
    LeaveCredit::factory()->for($employee)->for($sick, 'leaveType')->forYear(exportCreditYear())->days(1)->create();
    LeaveCredit::factory()->for($employee)->for($wellness, 'leaveType')->forYear(exportCreditYear())->days(0)->create();

    foreach ([0, 1, 2] as $n) {
        LeaveRecord::factory()->for($employee)->for($vacation, 'leaveType')->on(exportCreditDay($n))->create();
    }
    // Two sick days against a credit of one: overdrawn.
    LeaveRecord::factory()->for($employee)->for($sick, 'leaveType')->on(exportCreditDay(5))->create();
    LeaveRecord::factory()->for($employee)->for($sick, 'leaveType')->on(exportCreditDay(6))->create();

    $sheet = exportCreditSheet(hrAdmin());
    $row = LeaveCreditsSheet::HEADING_ROW + 1;
    $vl = exportGrantedColumn($sheet, 'VL');
    $sl = exportGrantedColumn($sheet, 'SL');
    $wl = exportGrantedColumn($sheet, 'WL');
    $total = exportGrantedColumn($sheet, 'All leave codes');

    expect($sheet->getCell([3, $row])->getValue())->toBe('Abad')
        ->and($sheet->getCell([$vl, $row])->getValue())->toEqual(15)
        ->and($sheet->getCell([$vl + 1, $row])->getValue())->toEqual(3)
        ->and($sheet->getCell([$vl + 2, $row])->getValue())->toEqual(12)
        ->and($sheet->getCell([$sl + 2, $row])->getValue())->toEqual(-1)
        // 0 granted is an answer, and stays 0.
        ->and($sheet->getCell([$wl, $row])->getValue())->toEqual(0)
        ->and($sheet->getCell([$wl + 2, $row])->getValue())->toEqual(0)
        ->and($sheet->getCell([$total, $row])->getValue())->toEqual(16)
        ->and($sheet->getCell([$total + 1, $row])->getValue())->toEqual(5)
        ->and($sheet->getCell([$total + 2, $row])->getValue())->toEqual(11)
        ->and($sheet->getCell([$sl + 2, $row])->getStyle()->getNumberFormat()->getFormatCode())->toContain('[Red]');
});

it('keeps a blank figure blank, never 0', function (): void {
    $employee = Employee::factory()->create();
    LeaveRecord::factory()->for($employee)->for(exportCreditType('VL'), 'leaveType')->on(exportCreditDay(0))->create();

    $sheet = exportCreditSheet(hrAdmin());
    $row = LeaveCreditsSheet::HEADING_ROW + 1;
    $vl = exportGrantedColumn($sheet, 'VL');

    expect($sheet->getCell([$vl, $row])->getValue())->toBeNull()
        ->and($sheet->getCell([$vl + 1, $row])->getValue())->toEqual(1)
        ->and($sheet->getCell([$vl + 2, $row])->getValue())->toBeNull();
});

it('gives columns only to leave codes used that year', function (): void {
    $employee = Employee::factory()->create();
    LeaveCredit::factory()->for($employee)->for(exportCreditType('CTO'), 'leaveType')->forYear(exportCreditYear())->days(2)->create();

    $sheet = exportCreditSheet(hrAdmin());
    $codes = array_values(array_filter($sheet->rangeToArray('A'.LeaveCreditsSheet::CODE_ROW.':'.$sheet->getHighestColumn().LeaveCreditsSheet::CODE_ROW)[0]));

    expect($codes)->toBe(['CTO — Compensatory Time-Off', 'All leave codes']);
});

it('lists the same people as the credits page: filters and rule 18', function (): void {
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->inOffice($office)->create(['last_name' => 'Abad']);
    Employee::factory()->inOffice($office)->create(['last_name' => 'Bautista']);
    $resigned = Employee::factory()->inOffice($office)->create(['last_name' => 'Aquino', 'is_active' => false]);
    Employee::factory()->inOffice($office)->create(['last_name' => 'Arce', 'is_active' => false]);
    LeaveCredit::factory()->for($resigned)->for(exportCreditType('VL'), 'leaveType')->forYear(exportCreditYear())->days(5)->create();

    $sheet = exportCreditSheet(hrAdmin(), ['office' => $office->id, 'letter' => 'A']);
    $first = LeaveCreditsSheet::HEADING_ROW + 1;

    expect([$sheet->getCell([3, $first])->getValue(), $sheet->getCell([3, $first + 1])->getValue()])->toBe(['Abad', 'Aquino'])
        ->and($sheet->getCell([3, $first + 2])->getValue())->toBeNull()
        ->and($sheet->getCell([6, $first])->getValue())->toBe('City Treasurer')
        ->and($sheet->getCell('A5')->getValue())->toBe('Filtered: Office: City Treasurer · Last names starting with A');
});

it('keeps the employee number as text', function (): void {
    Employee::factory()->create(['employee_no' => '00012']);

    $sheet = exportCreditSheet(hrAdmin());
    $cell = $sheet->getCell([2, LeaveCreditsSheet::HEADING_ROW + 1]);

    expect($cell->getDataType())->toBe('s')
        ->and($cell->getValue())->toBe('00012');
});
