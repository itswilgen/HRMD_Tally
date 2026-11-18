<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Signatory;
use App\Models\User;
use App\Services\LeaveTallyService;
use App\Services\TallyFilters;
use App\Services\TallySheetPrint;
use Spatie\Activitylog\Models\Activity;

/*
| Print (PDF) of the tally sheet (PROJECT_CONTEXT 5.7): long bond, landscape,
| one sheet per office, same rows and cell text as the screen.
*/

function printType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}

/**
 * @return list<array<string, mixed>>
 */
function printSheets(TallyFilters $filters, bool $showRate = true): array
{
    return app(TallySheetPrint::class)->sheets($filters, $showRate);
}

it('sends guests to the sign-in page', function (): void {
    $this->get(route('tally.print'))->assertRedirect(route('login'));
});

it('refuses an account without the export permission', function (): void {
    seedRolesAndPermissions();

    $this->actingAs(User::factory()->create())->get(route('tally.print'))->assertForbidden();
});

it('returns a PDF to open in the browser, and logs the print', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->count(3)->inOffice($office)->create();

    $response = $this->actingAs($admin)->get(route('tally.print', ['year' => 2026, 'office' => $office->id]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="leave-tally-2026-city-treasurer.pdf"');

    expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');

    $log = Activity::query()->where('log_name', 'export')->sole();

    expect($log->event)->toBe('printed')
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->description)->toBe('Printed the 2026 leave tally sheet (City Treasurer)')
        ->and($log->properties['employees'])->toBe(3);
});

it('lets HR Staff print too', function (): void {
    Employee::factory()->create();

    $this->actingAs(hrStaff())->get(route('tally.print'))->assertOk();
});

it('prints one sheet per office, A–Z, with no office last', function (): void {
    $treasurer = Office::factory()->create(['name' => 'City Treasurer']);
    $assessor = Office::factory()->create(['name' => 'City Assessor']);
    Employee::factory()->inOffice($treasurer)->create(['last_name' => 'Cruz']);
    Employee::factory()->inOffice($assessor)->create(['last_name' => 'Abad']);
    Employee::factory()->inOffice($assessor)->create(['last_name' => 'Bautista']);
    Employee::factory()->create(['last_name' => 'Diaz', 'office_id' => null]);

    $sheets = printSheets(new TallyFilters(2026));

    expect(array_column($sheets, 'office'))->toBe(['City Assessor', 'City Treasurer', 'No office'])
        ->and(array_column($sheets[0]['rows'], 'lastName'))->toBe(['Abad', 'Bautista'])
        ->and(array_column($sheets[0]['rows'], 'no'))->toBe([1, 2])
        ->and($sheets[1]['rows'][0]['no'])->toBe(1);
});

it('prints the selected office alone, and says so even when it is empty', function (): void {
    $treasurer = Office::factory()->create(['name' => 'City Treasurer']);
    $assessor = Office::factory()->create(['name' => 'City Assessor']);
    Employee::factory()->inOffice($treasurer)->create();

    $sheets = printSheets(new TallyFilters(2026, officeId: $assessor->id));

    expect($sheets)->toHaveCount(1)
        ->and($sheets[0]['office'])->toBe('City Assessor')
        ->and($sheets[0]['rows'])->toBe([]);
});

it('prints the screen text in the month cells and the totals', function (): void {
    $employee = Employee::factory()->create(['first_name' => 'Juan', 'suffix' => 'Jr.', 'middle_name' => 'Santos']);
    $mfl = printType('MFL');
    $sick = printType('SL');

    foreach (['2026-03-23', '2026-03-24', '2026-03-25'] as $date) {
        LeaveRecord::factory()->for($employee)->for($mfl, 'leaveType')->on($date)->create();
    }
    LeaveRecord::factory()->for($employee)->for($sick, 'leaveType')->on('2026-03-27')->create();

    $row = printSheets(new TallyFilters(2026))[0]['rows'][0];
    $tally = app(LeaveTallyService::class)->forEmployees([$employee->id], 2026)[$employee->id];

    expect($row['firstName'])->toBe('Juan Jr.')
        ->and($row['middleName'])->toBe('Santos')
        ->and($row['months'][2])->toBe(array_column($tally['months'][2]['entries'], 'text'))
        ->and($row['months'][2])->toBe(['23–25 MFL', '27 SL'])
        ->and($row['grandTotal'])->toBe('4');
});

it('prints a total column only for the leave types used on that sheet', function (): void {
    $employee = Employee::factory()->create();
    LeaveRecord::factory()->for($employee)->for(printType('VL'), 'leaveType')->on('2026-03-23')->create();
    LeaveRecord::factory()->for($employee)->for(printType('CTO'), 'leaveType')->on('2026-03-24')->create();

    $sheet = printSheets(new TallyFilters(2026))[0];

    expect(array_column($sheet['typeColumns'], 'code'))->toBe(['VL', 'CTO'])
        ->and(array_values($sheet['rows'][0]['totals']))->toBe(['1', '1']);
});

it('leaves the rate out for anyone who cannot see it on screen', function (): void {
    Employee::factory()->create(['monthly_rate' => '23456.70']);

    expect(printSheets(new TallyFilters(2026), showRate: true)[0]['rows'][0]['rate'])->toBe('23,456.70')
        ->and(printSheets(new TallyFilters(2026), showRate: false)[0]['rows'][0]['rate'])->toBe('');

    $html = view('pdf.tally-sheet', [
        'sheets' => printSheets(new TallyFilters(2026), showRate: false),
        'year' => 2026,
        'showRate' => false,
        'months' => ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'],
        'filtersNote' => '',
        'signatories' => [],
        'seal' => '',
    ])->render();

    expect($html)->not->toContain('>Rate<')
        ->and($html)->not->toContain('23,456.70');
});

it('uses the same rows as the screen: letter and search filters, and rule 7', function (): void {
    Employee::factory()->create(['last_name' => 'Abad']);
    Employee::factory()->create(['last_name' => 'Bautista']);
    $resigned = Employee::factory()->create(['last_name' => 'Aquino', 'is_active' => false]);
    Employee::factory()->create(['last_name' => 'Arce', 'is_active' => false]);
    LeaveRecord::factory()->for($resigned)->for(printType('VL'), 'leaveType')->on('2026-02-02')->create();

    // The factory gives each employee an office, so read across every sheet.
    $names = array_merge(...array_map(
        static fn (array $sheet): array => array_column($sheet['rows'], 'lastName'),
        printSheets(new TallyFilters(2026, letter: 'A')),
    ));
    sort($names);

    expect($names)->toBe(['Abad', 'Aquino']);
});

it('prints the signatories in order after each office', function (): void {
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->inOffice($office)->create();
    Signatory::query()->create(['label' => 'Certified correct by', 'name' => 'Pedro Santos', 'position' => 'CHRMO', 'sort_order' => 2]);
    Signatory::query()->create(['label' => 'Prepared by', 'name' => 'Ana Reyes', 'position' => 'Administrative Aide IV', 'sort_order' => 1]);

    $html = view('pdf.tally-sheet', [
        'sheets' => printSheets(new TallyFilters(2026)),
        'year' => 2026,
        'showRate' => true,
        'months' => ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'],
        'filtersNote' => '',
        'signatories' => Signatory::query()->ordered()->get(['label', 'name', 'position'])->toArray(),
        'seal' => '',
    ])->render();

    expect(strpos($html, 'Ana Reyes'))->toBeLessThan(strpos($html, 'Pedro Santos'))
        ->and($html)->toContain('Prepared by:')
        ->and($html)->toContain('City Treasurer');
});
