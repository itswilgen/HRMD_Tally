<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

/**
 * Filling a year's credits from Excel (business rule 20): download the year's
 * sheet, type over it, preview, save — matched by employee number, all rows
 * or none, and a blank cell leaves a figure alone.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-31 09:00:00'));
});

function importType(string $code, bool $active = true, int $order = 1): LeaveType
{
    return LeaveType::factory()->create(['code' => $code, 'name' => "{$code} Leave", 'is_active' => $active, 'sort_order' => $order]);
}

/**
 * @param  list<string>  $lines
 */
function creditsCsv(array $lines): UploadedFile
{
    return UploadedFile::fake()->createWithContent('credits.csv', implode("\r\n", $lines)."\r\n");
}

/**
 * @param  list<list<string|int|float|null>>  $rows
 */
function creditsWorkbook(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows, null, 'A1', true);

    $path = (string) tempnam(sys_get_temp_dir(), 'credits');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return UploadedFile::fake()->createWithContent('credits.xlsx', (string) file_get_contents($path));
}

/**
 * @return array<string, mixed>
 */
function creditsPreview(User $user, UploadedFile $file, int $year = 2026): array
{
    return test()->actingAs($user)
        ->post(route('credits.import.preview'), ['file' => $file, 'year' => $year], ['Accept' => 'application/json'])
        ->assertOk()
        ->json();
}

function importedFigure(Employee $employee, LeaveType $type, int $year = 2026): ?float
{
    return LeaveCredit::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year])->value('days');
}

it('keeps the sheet and the import to HR Admin', function (): void {
    importType('VL');
    $staff = hrStaff();

    $this->actingAs($staff)->get(route('credits.import.template'))->assertForbidden();
    $this->actingAs($staff)
        ->post(route('credits.import.preview'), ['file' => creditsCsv(['EMPLOYEE NO.,VL']), 'year' => 2026], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('downloads the year\'s sheet with the figures already on file', function (): void {
    $vl = importType('VL');
    importType('SL', order: 2);
    importType('XL', active: false, order: 3);
    $employee = Employee::factory()->create(['last_name' => 'Abalos', 'first_name' => 'Maria', 'middle_name' => null, 'suffix' => null]);

    LeaveCredit::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $vl->id, 'year' => 2026, 'days' => 15]);

    $response = $this->actingAs(hrAdmin())->get(route('credits.import.template', ['year' => 2026]))->assertSuccessful();

    expect($response->headers->get('content-disposition'))->toContain('leave-credits-2026.xlsx');

    $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
    $rows = $sheet->toArray(null, true, false);

    // Retired codes take no new figure, so they are not offered to fill in.
    expect($rows[0])->toBe(['NO.', 'EMPLOYEE NO.', 'EMPLOYEE', 'OFFICE', 'VL', 'SL'])
        ->and($rows[1][1])->toBe($employee->employee_no)
        ->and($rows[1][2])->toBe('Abalos, Maria')
        ->and((float) $rows[1][4])->toBe(15.0)
        ->and($rows[1][5])->toBeNull();
});

it('previews what would change, matched by employee number', function (): void {
    $vl = importType('VL');
    $sl = importType('SL', order: 2);
    $changed = Employee::factory()->create();
    $same = Employee::factory()->create();

    LeaveCredit::factory()->create(['employee_id' => $changed->id, 'leave_type_id' => $vl->id, 'year' => 2026, 'days' => 15]);
    LeaveCredit::factory()->create(['employee_id' => $same->id, 'leave_type_id' => $vl->id, 'year' => 2026, 'days' => 15]);

    $preview = creditsPreview(hrAdmin(), creditsCsv([
        'NO.,EMPLOYEE NO.,EMPLOYEE,OFFICE,VL,SL,REMARKS',
        "1,{$changed->employee_no},Someone,Somewhere,16,7.5,typed later",
        "2,{$same->employee_no},Someone else,Somewhere,15,,",
    ]));

    expect($preview['readyCount'])->toBe(1)
        ->and($preview['unchangedCount'])->toBe(1)
        ->and($preview['problemCount'])->toBe(0)
        ->and($preview['figureCount'])->toBe(2)
        ->and($preview['ignoredColumns'])->toBe(['REMARKS'])
        ->and($preview['rows'][0]['name'])->toBe($changed->list_name)
        ->and($preview['rows'][0]['changes'])->toBe([
            ['code' => 'VL', 'from' => '15', 'to' => '16'],
            ['code' => 'SL', 'from' => null, 'to' => '7.5'],
        ])
        ->and($preview['rows'][1]['changes'])->toBe([]);

    // A preview saves nothing.
    expect(importedFigure($changed, $vl))->toBe(15.0)
        ->and(importedFigure($changed, $sl))->toBeNull();
});

it('flags every row that cannot be saved, with the reason', function (): void {
    importType('VL');
    importType('XL', active: false, order: 2);
    $employee = Employee::factory()->create();
    $deleted = Employee::factory()->create();
    $deleted->delete();

    $preview = creditsPreview(hrAdmin(), creditsCsv([
        'EMPLOYEE NO.,VL,XL',
        "{$employee->employee_no},7.25,",
        '2026-99999,15,',
        "{$deleted->employee_no},15,",
        ',15,',
        "{$employee->employee_no},15,",
        "{$employee->employee_no},,3",
    ]));

    $errors = array_column($preview['rows'], 'errors');

    expect($preview['problemCount'])->toBe(6)
        ->and($errors[0][0])->toContain('VL: credits are counted in half days')
        ->and($errors[1][0])->toContain('No employee on the masterlist has the number 2026-99999')
        ->and($errors[2][0])->toContain('has been deleted')
        ->and($errors[3][0])->toContain('EMPLOYEE NO. cell is empty')
        ->and($errors[4][0])->toContain('is also on row 2')
        ->and($errors[5])->toContain('XL has been retired, so a new figure cannot be added against it.');
});

it('saves every change in the file and leaves blank cells alone', function (): void {
    $vl = importType('VL');
    $sl = importType('SL', order: 2);
    $first = Employee::factory()->create();
    $second = Employee::factory()->create();

    LeaveCredit::factory()->create(['employee_id' => $first->id, 'leave_type_id' => $sl->id, 'year' => 2025, 'days' => 10]);

    $this->actingAs(hrAdmin())
        ->from(route('credits.index', ['year' => 2025]))
        ->post(route('credits.import.store'), [
            'year' => 2025,
            'file' => creditsWorkbook([
                ['NO.', 'EMPLOYEE NO.', 'EMPLOYEE', 'OFFICE', 'VL', 'SL'],
                [1, $first->employee_no, 'x', 'y', 15, null],
                [2, $second->employee_no, 'x', 'y', 7.5, 0],
            ]),
        ])
        ->assertRedirect(route('credits.index', ['year' => 2025]))
        ->assertSessionHas('success', '3 2025 credit figures saved for 2 employees.');

    expect(importedFigure($first, $vl, 2025))->toBe(15.0)
        // Blank in the file: the 10 on file stays.
        ->and(importedFigure($first, $sl, 2025))->toBe(10.0)
        ->and(importedFigure($second, $vl, 2025))->toBe(7.5)
        ->and(importedFigure($second, $sl, 2025))->toBe(0.0);

    expect(Activity::query()->where('log_name', 'leave_credit')->where('event', 'imported')->sole()->properties['figures'])->toBe(3);
});

it('saves nothing while any row has a problem', function (): void {
    $vl = importType('VL');
    $employee = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->post(route('credits.import.store'), [
            'year' => 2026,
            'file' => creditsCsv([
                'EMPLOYEE NO.,VL',
                "{$employee->employee_no},15",
                '2026-99999,15',
            ]),
        ])
        ->assertSessionHasErrors(['file' => '1 row still has problems, so nothing was saved. Fix them in the file and preview it again.']);

    expect(importedFigure($employee, $vl))->toBeNull();
});

it('finds the headings under a title and reads a comma decimal', function (): void {
    importType('VL');
    $employee = Employee::factory()->create();

    $preview = creditsPreview(hrAdmin(), creditsCsv([
        'LEAVE CREDITS 2026;;',
        'EMPLOYEE NO.;VL Leave;',
        "{$employee->employee_no};7,5;",
    ]));

    expect($preview['problemCount'])->toBe(0)
        ->and($preview['rows'][0]['line'])->toBe(3)
        ->and($preview['rows'][0]['changes'])->toBe([['code' => 'VL', 'from' => null, 'to' => '7.5']]);
});

it('refuses a file without an employee number column', function (): void {
    importType('VL');

    $this->actingAs(hrAdmin())
        ->post(route('credits.import.preview'), ['file' => creditsCsv(['EMPLOYEE,VL', 'Abalos,15']), 'year' => 2026], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'missing the EMPLOYEE NO. column']);
});

it('refuses a file without any leave code column', function (): void {
    importType('VL');
    $employee = Employee::factory()->create();

    $this->actingAs(hrAdmin())
        ->post(route('credits.import.preview'), ['file' => creditsCsv(['EMPLOYEE NO.,REMARKS', "{$employee->employee_no},x"]), 'year' => 2026], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'no leave code columns']);
});
