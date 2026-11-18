<?php

declare(strict_types=1);

use App\Exports\TallyOfficeSheet;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Signatory;
use App\Models\User;
use App\Services\LeaveTallyService;
use App\Services\TallyFilters;
use App\Services\TallySheetPrint;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

/*
| Excel export of the tally (PROJECT_CONTEXT 5.7): one worksheet per office,
| the same rows and text as the PDF. Every test reads the real .xlsx back.
*/

function excelType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $query
 */
function downloadWorkbook(User $user, array $query = []): Spreadsheet
{
    $response = test()->actingAs($user)->get(route('tally.excel', ['year' => 2026, ...$query]));
    $response->assertOk();

    return loadWorkbook((string) $response->getContent());
}

/**
 * The heading row of a worksheet, as text.
 *
 * @return list<string>
 */
function headingRow(Worksheet $sheet): array
{
    $row = TallyOfficeSheet::HEADING_ROW;

    return array_values(array_filter(
        $sheet->rangeToArray("A{$row}:".$sheet->getHighestColumn().$row)[0],
        static fn (mixed $cell): bool => $cell !== null,
    ));
}

it('sends guests to the sign-in page', function (): void {
    $this->get(route('tally.excel'))->assertRedirect(route('login'));
});

it('refuses an account without the export permission', function (): void {
    seedRolesAndPermissions();

    $this->actingAs(User::factory()->create())->get(route('tally.excel'))->assertForbidden();
});

it('downloads an xlsx named after the year and office, and logs it', function (): void {
    $admin = hrAdmin();
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    Employee::factory()->count(2)->inOffice($office)->create();

    $this->actingAs($admin)
        ->get(route('tally.excel', ['year' => 2026, 'office' => $office->id]))
        ->assertOk()
        ->assertDownload('leave-tally-2026-city-treasurer.xlsx');

    $log = Activity::query()->where('log_name', 'export')->sole();

    expect($log->event)->toBe('exported')
        ->and($log->description)->toBe('Exported the 2026 leave tally sheet to Excel (City Treasurer)')
        ->and($log->properties['format'])->toBe('xlsx')
        ->and($log->properties['employees'])->toBe(2);
});

it('has one worksheet per office, in the PDF order', function (): void {
    $treasurer = Office::factory()->create(['name' => 'City Treasurer']);
    $assessor = Office::factory()->create(['name' => 'City Assessor']);
    Employee::factory()->inOffice($treasurer)->create();
    Employee::factory()->inOffice($assessor)->create();
    Employee::factory()->create(['office_id' => null]);

    $workbook = downloadWorkbook(hrAdmin());

    expect($workbook->getSheetNames())->toBe(['City Assessor', 'City Treasurer', 'No office'])
        ->and($workbook->getSheetNames())->toBe(array_column(app(TallySheetPrint::class)->sheets(new TallyFilters(2026), true), 'office'));
});

it('shortens long office names to valid, unique tab names', function (): void {
    $long = 'Office of the City Planning and Development Coordinator';
    Employee::factory()->inOffice(Office::factory()->create(['name' => $long.' North']))->create();
    Employee::factory()->inOffice(Office::factory()->create(['name' => $long.' South']))->create();
    Employee::factory()->inOffice(Office::factory()->create(['name' => 'Engineering: Roads/Bridges']))->create();

    $names = downloadWorkbook(hrAdmin())->getSheetNames();

    expect($names)->toContain('Engineering  Roads Bridges')
        ->and(array_map('mb_strlen', $names))->each->toBeLessThanOrEqual(31)
        ->and(count(array_unique(array_map('mb_strtolower', $names))))->toBe(3);
});

/*
| Phase 2 gate: the Excel cells and the PDF data match the screen for 5
| sample employees. "The screen" is LeaveTallyService, which the tally page
| renders as it is.
*/
it('matches the screen for 5 sample employees, cell by cell', function (): void {
    $office = Office::factory()->create(['name' => 'City Treasurer']);
    $types = ['VL', 'SL', 'MFL', 'CTO', 'SPL'];
    $employees = [];

    foreach (['Abad', 'Bautista', 'Cruz', 'Diaz', 'Espino'] as $i => $lastName) {
        $employee = Employee::factory()->inOffice($office)->create(['last_name' => $lastName, 'monthly_rate' => (string) (20000 + $i * 1000)]);
        $employees[] = $employee;
        $type = excelType($types[$i]);

        foreach (['2026-03-23', '2026-03-24', '2026-03-25'] as $date) {
            LeaveRecord::factory()->for($employee)->for($type, 'leaveType')->on($date)->create();
        }
        LeaveRecord::factory()->for($employee)->for(excelType('SL'), 'leaveType')->on('2026-08-04')->create(['day_part' => 'PM']);
    }

    $screen = app(LeaveTallyService::class)->forEmployees(array_map(fn (Employee $e): int => $e->id, $employees), 2026);
    $pdf = app(TallySheetPrint::class)->sheets(new TallyFilters(2026, officeId: $office->id), true)[0];
    $sheet = downloadWorkbook(hrAdmin(), ['office' => $office->id])->getSheet(0);

    $headings = headingRow($sheet);
    $jan = array_search('JAN', $headings, true) + 1;
    $total = array_search('Total', $headings, true) + 1;

    foreach ($employees as $index => $employee) {
        $row = TallyOfficeSheet::HEADING_ROW + 1 + $index;
        $tally = $screen[$employee->id];

        expect($sheet->getCell([2, $row])->getValue())->toBe($employee->last_name)
            ->and($pdf['rows'][$index]['lastName'])->toBe($employee->last_name);

        foreach (range(0, 11) as $month) {
            $text = implode("\n", array_column($tally['months'][$month]['entries'], 'text'));

            expect((string) $sheet->getCell([$jan + $month, $row])->getValue())->toBe($text)
                ->and(implode("\n", $pdf['rows'][$index]['months'][$month]))->toBe($text);
        }

        expect((float) $sheet->getCell([$total, $row])->getValue())->toBe($tally['grandTotal'])
            ->and($pdf['rows'][$index]['grandTotal'])->toBe($tally['grandTotalText']);
    }
});

it('writes SG, rate and totals as numbers and months as text', function (): void {
    $employee = Employee::factory()->create(['salary_grade' => 11, 'monthly_rate' => '28512.00', 'office_id' => null]);
    LeaveRecord::factory()->for($employee)->for(excelType('VL'), 'leaveType')->on('2026-03-23')->create(['day_part' => 'AM']);

    $sheet = downloadWorkbook(hrAdmin())->getSheet(0);
    $headings = headingRow($sheet);
    $row = TallyOfficeSheet::HEADING_ROW + 1;
    $column = static fn (string $name): int => array_search($name, $headings, true) + 1;

    expect($sheet->getCell([$column('SG'), $row])->getValue())->toBe(11)
        ->and($sheet->getCell([$column('Rate'), $row])->getValue())->toEqual(28512)
        ->and($sheet->getCell([$column('Rate'), $row])->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0.00')
        ->and($sheet->getCell([$column('VL'), $row])->getValue())->toEqual(0.5)
        ->and($sheet->getCell([$column('MAR'), $row])->getValue())->toBe('23 AM VL');
});

it('leaves the rate column out for HR Staff', function (): void {
    Employee::factory()->create(['monthly_rate' => '45000.00', 'office_id' => null]);

    $sheet = downloadWorkbook(hrStaff())->getSheet(0);

    expect(headingRow($sheet))->not->toContain('Rate');

    foreach ($sheet->toArray() as $cells) {
        expect($cells)->not->toContain(45000)->not->toContain('45,000.00');
    }
});

it('is set up to print on long bond, landscape, with the headings on every page', function (): void {
    Employee::factory()->create(['office_id' => null]);

    $sheet = downloadWorkbook(hrAdmin())->getSheet(0);
    $setup = $sheet->getPageSetup();

    expect($setup->getOrientation())->toBe(PageSetup::ORIENTATION_LANDSCAPE)
        ->and($setup->getPaperSize())->toBe(PageSetup::PAPERSIZE_FOLIO)
        ->and($setup->getFitToWidth())->toBe(1)
        ->and($setup->getRowsToRepeatAtTop())->toBe([TallyOfficeSheet::HEADING_ROW, TallyOfficeSheet::HEADING_ROW])
        ->and($sheet->getFreezePane())->toBe('D'.(TallyOfficeSheet::HEADING_ROW + 1));
});

it('puts the letterhead and the signatories on every worksheet', function (): void {
    Employee::factory()->inOffice(Office::factory()->create(['name' => 'City Treasurer']))->create();
    Signatory::query()->create(['label' => 'Prepared by', 'name' => 'Ana Reyes', 'position' => 'Administrative Aide IV', 'sort_order' => 1]);

    $sheet = downloadWorkbook(hrAdmin())->getSheet(0);
    $text = implode(' ', array_filter(array_merge(...$sheet->toArray()), 'is_string'));

    expect($sheet->getCell('A2')->getValue())->toBe('CITY OF TANJAY')
        ->and($sheet->getCell('A4')->getValue())->toBe('LEAVE TALLY SHEET — 2026')
        ->and($sheet->getCell('E4')->getValue())->toBe('Office: City Treasurer')
        ->and($text)->toContain('Prepared by:')
        ->and($text)->toContain('ANA REYES')
        ->and($text)->toContain('Administrative Aide IV');
});

it('keeps text as text: a "0" name stays text and "=" is never a formula', function (): void {
    Employee::factory()->create(['last_name' => '=HYPERLINK("x")', 'middle_name' => '0', 'office_id' => null]);

    $sheet = downloadWorkbook(hrAdmin())->getSheet(0);
    $row = TallyOfficeSheet::HEADING_ROW + 1;

    expect($sheet->getCell([2, $row])->getDataType())->toBe('s')
        ->and($sheet->getCell([2, $row])->getValue())->toBe('=HYPERLINK("x")')
        ->and($sheet->getCell([4, $row])->getDataType())->toBe('s')
        ->and($sheet->getCell([4, $row])->getValue())->toBe('0')
        ->and($sheet->getCell([1, $row])->getDataType())->toBe('n');
});
